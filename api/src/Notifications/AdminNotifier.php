<?php

declare(strict_types=1);

namespace Muninn\Api\Notifications;

use Muninn\Api\Logging\AppLogger;
use Muninn\Api\Logging\AuditLog;
use PDO;
use Throwable;

/**
 * Push notifications to the administrator through the server's own ntfy (D057).
 *
 * Handlers queue a notification while they answer a request; Bootstrap sends the queue after
 * the response has gone to the browser (sendQueued), so a slow or unreachable ntfy server
 * never delays or breaks a request. Sending never throws: failures are logged without the
 * access token.
 *
 * Everything about the ntfy server (address, topic, token) is server-side configuration and
 * never reaches the frontend. Messages carry only what the administrator needs to act: no
 * passwords, tokens, note content or invitation request notes.
 */
final class AdminNotifier
{
    /** Kinds of notification; also stored in the audit log. */
    public const KIND_INVITATION_REQUEST = 'invitation_request';
    public const KIND_SIGN_IN_BLOCKED = 'sign_in_blocked';

    /**
     * At most this many sign-in alerts per hour, so someone guessing passwords from many
     * addresses cannot flood the administrator's phone. Blocked sign-ins stay in the audit log.
     */
    public const MAXIMUM_SIGN_IN_ALERTS_PER_HOUR = 10;

    /** ntfy priorities: 3 is default, 4 is high. */
    private const PRIORITY_DEFAULT = 3;
    private const PRIORITY_HIGH = 4;

    /** @var list<array<string, mixed>> Messages waiting to be sent after the response. */
    private array $queuedMessages = [];

    public function __construct(
        private readonly PDO $database,
        private readonly AuditLog $auditLog,
        private readonly AppLogger $logger,
        private readonly NtfyTransport $transport,
        private readonly bool $isEnabled,
        private readonly string $serverUrl,
        private readonly string $topic,
        private readonly string $accessToken,
        private readonly int $timeoutSeconds,
        private readonly string $frontendBaseUrl,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    /** Someone asked for an invitation (D049); the administrator should review it. */
    public function invitationRequested(string $requesterDisplayName, string $requestId): void
    {
        $this->queue(
            self::KIND_INVITATION_REQUEST,
            'Muninn: new invitation request',
            self::plainText($requesterDisplayName, 100) . ' asked for someone to be invited. Review it under Admin > Invitations.',
            self::PRIORITY_DEFAULT,
            ['envelope'],
            $this->frontendBaseUrl . '/admin/invitations.php',
            $requestId,
        );
    }

    /**
     * Sign-ins were just blocked by the rate limiter. $username is null when the block is for
     * the whole address (many usernames tried), otherwise the username that was guessed at.
     */
    public function signInBlocked(?string $username, string $ipAddress, int $failureCount, int $windowMinutes): void
    {
        // Counted from the audit log, so the cap holds across requests and server processes.
        $countStatement = $this->database->prepare(
            'SELECT COUNT(*) FROM audit_log
             WHERE event_type = :event_type AND details LIKE :kind_pattern
               AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR'
        );
        $countStatement->execute([
            'event_type' => AuditLog::ADMIN_NOTIFICATION_QUEUED,
            'kind_pattern' => '%"kind":"' . self::KIND_SIGN_IN_BLOCKED . '"%',
        ]);
        if ((int) $countStatement->fetchColumn() >= self::MAXIMUM_SIGN_IN_ALERTS_PER_HOUR) {
            return;
        }

        $blockedWhat = $username === null
            ? 'Sign-ins from ' . $ipAddress
            : 'Sign-ins as "' . self::plainText($username, 64) . '" from ' . $ipAddress;
        $this->queue(
            self::KIND_SIGN_IN_BLOCKED,
            'Muninn: sign-ins blocked',
            $blockedWhat . ' are blocked for ' . $windowMinutes . ' minutes after ' . $failureCount . ' failed attempts.',
            self::PRIORITY_HIGH,
            ['warning'],
            null,
            null,
            $ipAddress,
        );
    }

    /**
     * Sends a message straight away (used by bin/send-test-notification.php).
     *
     * @return int The HTTP status ntfy answered with, 0 when it could not be reached.
     */
    public function sendTestMessage(): int
    {
        return $this->transport->publish(
            $this->serverUrl,
            $this->buildMessage('Muninn: test notification', 'Notifications from Muninn reach this topic.', self::PRIORITY_DEFAULT, ['white_check_mark'], null),
            $this->accessToken,
            $this->timeoutSeconds,
        );
    }

    /**
     * Sends everything queued during this request. Called after the response has been sent.
     * Never throws.
     *
     * @return int Number of messages ntfy accepted.
     */
    public function sendQueued(): int
    {
        $messagesToSend = $this->queuedMessages;
        $this->queuedMessages = [];
        $acceptedCount = 0;
        foreach ($messagesToSend as $message) {
            try {
                $statusCode = $this->transport->publish($this->serverUrl, $message, $this->accessToken, $this->timeoutSeconds);
            } catch (Throwable $sendFailure) {
                $statusCode = 0;
            }
            if ($statusCode >= 200 && $statusCode < 300) {
                $acceptedCount++;
                continue;
            }
            // The token is never logged; the status says enough (0 = unreachable, 401/403 = token).
            $this->logger->warning('ntfy notification was not delivered.', ['http_status' => $statusCode, 'title' => $message['title']]);
        }

        return $acceptedCount;
    }

    /**
     * Queues one message and records it in the audit log. Does nothing when ntfy is not
     * configured.
     *
     * @param list<string> $tags ntfy tags (shown as emoji).
     */
    private function queue(
        string $kind,
        string $title,
        string $messageText,
        int $priority,
        array $tags,
        ?string $clickUrl,
        ?string $targetId,
        ?string $ipAddress = null,
    ): void {
        if (!$this->isEnabled) {
            return;
        }
        $this->queuedMessages[] = $this->buildMessage($title, $messageText, $priority, $tags, $clickUrl);
        $this->auditLog->record(
            AuditLog::ADMIN_NOTIFICATION_QUEUED,
            null,
            $targetId === null ? null : 'invitation_request',
            $targetId,
            $ipAddress,
            ['kind' => $kind],
        );
    }

    /**
     * @param list<string> $tags
     * @return array<string, mixed> ntfy's JSON publish format.
     */
    private function buildMessage(string $title, string $messageText, int $priority, array $tags, ?string $clickUrl): array
    {
        $message = [
            'topic' => $this->topic,
            'title' => $title,
            'message' => $messageText,
            'priority' => $priority,
            'tags' => $tags,
        ];
        if ($clickUrl !== null) {
            $message['click'] = $clickUrl;
        }

        return $message;
    }

    /** Removes control characters and shortens text that came from users. */
    private static function plainText(string $userText, int $maximumLength): string
    {
        $withoutControls = (string) preg_replace('/[\p{C}]+/u', ' ', $userText);

        return mb_substr(trim($withoutControls), 0, $maximumLength);
    }
}
