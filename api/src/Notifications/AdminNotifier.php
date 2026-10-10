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
    public const KIND_USER_MESSAGE = 'user_message';
    public const KIND_USER_REPLY = 'user_reply';
    public const KIND_BROADCAST_VOTE = 'broadcast_vote';
    public const KIND_WORKSPACE_JOIN_REQUEST = 'workspace_join_request';

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
            'invitation_request',
        );
    }

    /**
     * Someone asked to join a Shared Workspace (D067); the administrator should approve or decline.
     * Workspace names of Shared Workspaces are chosen by the administrator, so they are safe to show.
     * The user's note is not sent, like invitation request notes.
     */
    public function workspaceJoinRequested(string $requesterDisplayName, string $workspaceName, string $requestId): void
    {
        $this->queue(
            self::KIND_WORKSPACE_JOIN_REQUEST,
            'Muninn: request to join ' . self::plainText($workspaceName, 100),
            self::plainText($requesterDisplayName, 100) . ' asked to join "' . self::plainText($workspaceName, 100) . '". Review it under Admin > Workspaces.',
            self::PRIORITY_DEFAULT,
            ['busts_in_silhouette'],
            $this->frontendBaseUrl . '/admin/workspaces.php',
            $requestId,
            'workspace_join_request',
        );
    }

    /**
     * A user answered one of the administrator's votes (D061). The project owner asked to hear
     * about every vote as it comes in. Each user votes once per vote, so this cannot flood.
     *
     * @param string $question The vote's question, shortened in the message.
     * @param list<string> $chosenLabels The answers the user picked.
     */
    public function broadcastVoted(string $voterDisplayName, string $voterUsername, string $question, array $chosenLabels, string $broadcastId): void
    {
        $chosenText = implode(', ', array_map(static fn (string $chosenLabel): string => self::plainText($chosenLabel, 100), $chosenLabels));
        $this->queue(
            self::KIND_BROADCAST_VOTE,
            'Muninn: new vote from ' . self::plainText($voterDisplayName, 100) . ' (' . self::plainText($voterUsername, 64) . ')',
            'Answered: ' . $chosenText . "\n\nQuestion: " . self::plainText($question, 200),
            self::PRIORITY_DEFAULT,
            ['ballot_box_with_check'],
            $this->frontendBaseUrl . '/admin/broadcasts.php',
            $broadcastId,
            'broadcast',
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
            null,
            $ipAddress,
        );
    }

    /**
     * A signed-in user wrote to the administrator with "Contact admin" (D058).
     *
     * Unlike the other notifications this one is sent straight away, not after the response:
     * the user is waiting to hear whether the message arrived, so they can try again later
     * instead of believing it was delivered. The caller has already validated the text and
     * checked the user's hourly limit.
     *
     * @param string $messageText What the user wrote; line breaks are kept.
     * @param string $contactDetails How the administrator can answer (optional, may be '').
     * @param string $conversationId The conversation in the administrator's inbox (D065); tapping
     *                               the notification opens it.
     * @return bool True when ntfy accepted the message; false when it did not or ntfy is off.
     */
    public function sendUserMessage(string $displayName, string $username, string $messageText, string $contactDetails, string $conversationId): bool
    {
        if (!$this->isEnabled) {
            return false;
        }

        $notificationText = self::plainMultiLineText($messageText, 2000);
        $notificationText .= "\n\n" . ($contactDetails === ''
            ? 'No contact details given.'
            : 'Reply to: ' . self::plainText($contactDetails, 200));
        $message = $this->buildMessage(
            'Muninn: message from ' . self::plainText($displayName, 100) . ' (' . self::plainText($username, 64) . ')',
            $notificationText,
            self::PRIORITY_DEFAULT,
            ['speech_balloon'],
            $this->conversationUrl($conversationId),
        );

        try {
            $statusCode = $this->transport->publish($this->serverUrl, $message, $this->accessToken, $this->timeoutSeconds);
        } catch (Throwable $sendFailure) {
            $statusCode = 0;
        }
        if ($statusCode >= 200 && $statusCode < 300) {
            return true;
        }
        // Neither the token nor the user's text is logged.
        $this->logger->warning('ntfy user message was not delivered.', ['http_status' => $statusCode]);

        return false;
    }

    /**
     * A user answered in a conversation with the administrator (D065). Queued like the other
     * alerts: the reply is already stored in the inbox, so the user need not wait for ntfy. The
     * user's hourly reply limit keeps this from flooding the administrator's phone.
     */
    public function userReplied(string $displayName, string $username, string $messageText, string $conversationId): void
    {
        $this->queue(
            self::KIND_USER_REPLY,
            'Muninn: reply from ' . self::plainText($displayName, 100) . ' (' . self::plainText($username, 64) . ')',
            self::plainMultiLineText($messageText, 2000),
            self::PRIORITY_DEFAULT,
            ['speech_balloon'],
            $this->conversationUrl($conversationId),
            $conversationId,
            'admin_conversation',
        );
    }

    /** The administrator's page for one conversation (D065). */
    private function conversationUrl(string $conversationId): string
    {
        return $this->frontendBaseUrl . '/admin/messages.php?id=' . rawurlencode($conversationId);
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
        ?string $targetType,
        ?string $ipAddress = null,
    ): void {
        if (!$this->isEnabled) {
            return;
        }
        $this->queuedMessages[] = $this->buildMessage($title, $messageText, $priority, $tags, $clickUrl);
        $this->auditLog->record(
            AuditLog::ADMIN_NOTIFICATION_QUEUED,
            null,
            $targetId === null ? null : $targetType,
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

    /**
     * Like plainText, but keeps line breaks (at most two in a row) for multi-line messages.
     * Windows line endings become plain "\n" first.
     */
    private static function plainMultiLineText(string $userText, int $maximumLength): string
    {
        $unixLineEndings = str_replace(["\r\n", "\r"], "\n", $userText);
        $cleanLines = array_map(
            static fn (string $lineText): string => rtrim((string) preg_replace('/[\p{C}]+/u', ' ', $lineText)),
            explode("\n", $unixLineEndings),
        );
        $joinedText = (string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $cleanLines));

        return mb_substr(trim($joinedText), 0, $maximumLength);
    }

    /** Removes control characters and shortens text that came from users. */
    private static function plainText(string $userText, int $maximumLength): string
    {
        $withoutControls = (string) preg_replace('/[\p{C}]+/u', ' ', $userText);

        return mb_substr(trim($withoutControls), 0, $maximumLength);
    }
}
