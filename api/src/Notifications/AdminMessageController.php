<?php

declare(strict_types=1);

namespace Muninn\Api\Notifications;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\InputReader;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Users\User;
use Muninn\Api\Validation\TextRules;
use PDO;

/**
 * "Contact admin" (decision D058): a signed-in everyday user writes a short message that is
 * pushed to the administrator through ntfy (D057). Nothing is stored except an audit entry
 * without the text; the administrator answers outside Muninn, using the contact details the
 * user chose to add.
 *
 *   GET  /api/v1/admin-messages   whether messages can be sent, and how many are left this hour
 *   POST /api/v1/admin-messages   {"message": "...", "contact": "optional e-mail or phone"}
 */
final class AdminMessageController
{
    /** Per user, so the button can never be used to flood the administrator's phone. */
    public const MAXIMUM_MESSAGES_PER_HOUR = 5;

    private const MESSAGE_MAX_LENGTH = 1000;
    private const CONTACT_MAX_LENGTH = 200;

    public function __construct(
        private readonly PDO $database,
        private readonly AuditLog $auditLog,
        private readonly AdminNotifier $adminNotifier,
    ) {
    }

    /** GET /api/v1/admin-messages — lets the form explain why it cannot be used. */
    public function status(Request $request, RequestContext $context): Response
    {
        $currentUser = $this->requireEverydayUser($context);

        return Response::data([
            'enabled' => $this->adminNotifier->isEnabled(),
            'max_per_hour' => self::MAXIMUM_MESSAGES_PER_HOUR,
            'remaining_this_hour' => max(0, self::MAXIMUM_MESSAGES_PER_HOUR - $this->messagesSentInLastHour($currentUser->id)),
        ]);
    }

    /** POST /api/v1/admin-messages  {"message": "...", "contact": "..."} */
    public function send(Request $request, RequestContext $context): Response
    {
        $currentUser = $this->requireEverydayUser($context);
        if (!$this->adminNotifier->isEnabled()) {
            throw new HttpException(503, 'messages_unavailable', 'Messages to the administrator are not set up on this server.');
        }

        $requestBody = $request->jsonBody();
        $messageText = trim(InputReader::optionalString($requestBody, 'message') ?? '');
        $contactDetails = trim(InputReader::optionalString($requestBody, 'contact') ?? '');
        $fieldErrors = [];
        if ($messageText === '') {
            $fieldErrors['message'] = 'Write a message.';
        } elseif (mb_strlen($messageText) > self::MESSAGE_MAX_LENGTH) {
            $fieldErrors['message'] = 'Use at most ' . self::MESSAGE_MAX_LENGTH . ' characters.';
        } else {
            $messageError = TextRules::multiLineError($messageText, self::MESSAGE_MAX_LENGTH * 4);
            if ($messageError !== null) {
                $fieldErrors['message'] = $messageError;
            }
        }
        $contactError = TextRules::singleLineError($contactDetails, self::CONTACT_MAX_LENGTH, false);
        if ($contactError !== null) {
            $fieldErrors['contact'] = $contactError;
        }
        if ($fieldErrors !== []) {
            throw HttpException::validation($fieldErrors);
        }

        // Every attempt counts, delivered or not, so a stopped ntfy cannot be hammered either.
        if ($this->messagesSentInLastHour($currentUser->id) >= self::MAXIMUM_MESSAGES_PER_HOUR) {
            throw HttpException::tooManyRequests(3600);
        }

        $wasDelivered = $this->adminNotifier->sendUserMessage($currentUser->displayName, $currentUser->username, $messageText, $contactDetails);
        // The audit entry never holds the text: what users write to the administrator stays private.
        $this->auditLog->record(AuditLog::ADMIN_MESSAGE_SENT, $currentUser->id, null, null, $context->clientIp, ['delivered' => $wasDelivered]);
        if (!$wasDelivered) {
            throw new HttpException(503, 'delivery_failed', 'The message could not be delivered right now. Please try again later.');
        }

        return Response::data([
            'sent' => true,
            'remaining_this_hour' => max(0, self::MAXIMUM_MESSAGES_PER_HOUR - $this->messagesSentInLastHour($currentUser->id)),
        ], 201);
    }

    /** Messages (attempts) this user made during the last hour, counted from the audit log. */
    private function messagesSentInLastHour(string $userId): int
    {
        $countStatement = $this->database->prepare(
            'SELECT COUNT(*) FROM audit_log
             WHERE event_type = :event_type AND actor_user_id = :user_id
               AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR'
        );
        $countStatement->execute(['event_type' => AuditLog::ADMIN_MESSAGE_SENT, 'user_id' => $userId]);

        return (int) $countStatement->fetchColumn();
    }

    /**
     * The administrator is the one being contacted, so administrator accounts cannot use it.
     *
     * @throws HttpException 403 for administrator accounts.
     */
    private function requireEverydayUser(RequestContext $context): User
    {
        $currentUser = $context->requireSession()->user;
        if ($currentUser->isSystemAdmin) {
            throw HttpException::forbidden('admin_account', 'Administrator accounts cannot message the administrator.');
        }

        return $currentUser;
    }
}
