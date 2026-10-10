<?php

declare(strict_types=1);

namespace Muninn\Api\Notifications;

use Muninn\Api\AdminInbox\AdminConversationService;
use Muninn\Api\AdminInbox\AdminMessageText;
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
 * "Contact admin" (decision D058): a signed-in everyday user writes a short message to the
 * administrator. Since D065 the message starts a conversation in the administrator's inbox
 * (AdminConversationController), where both sides can reply; ntfy (D057) still pushes it to the
 * administrator's phone when it is set up. The audit entry never holds the text.
 *
 *   GET  /api/v1/admin-messages   how many messages are left this hour, and unread replies
 *   POST /api/v1/admin-messages   {"message": "...", "contact": "optional e-mail or phone"}
 */
final class AdminMessageController
{
    /** Per user, so the button can never be used to flood the administrator's phone. */
    public const MAXIMUM_MESSAGES_PER_HOUR = 5;

    public function __construct(
        private readonly PDO $database,
        private readonly AuditLog $auditLog,
        private readonly AdminNotifier $adminNotifier,
        private readonly AdminConversationService $conversationService,
    ) {
    }

    /** GET /api/v1/admin-messages — lets the dialog explain the hourly limit and show new replies. */
    public function status(Request $request, RequestContext $context): Response
    {
        $currentUser = $this->requireEverydayUser($context);

        return Response::data([
            'max_per_hour' => self::MAXIMUM_MESSAGES_PER_HOUR,
            'remaining_this_hour' => max(0, self::MAXIMUM_MESSAGES_PER_HOUR - $this->messagesSentInLastHour($currentUser->id)),
            // Conversations with an administrator reply the user has not opened yet (D065).
            'unread_count' => $this->conversationService->countUnreadForUser($currentUser->id),
        ]);
    }

    /** POST /api/v1/admin-messages  {"message": "...", "contact": "..."} */
    public function send(Request $request, RequestContext $context): Response
    {
        $currentUser = $this->requireEverydayUser($context);

        $requestBody = $request->jsonBody();
        $messageText = AdminMessageText::normalize(InputReader::optionalString($requestBody, 'message'));
        $contactDetails = trim(InputReader::optionalString($requestBody, 'contact') ?? '');
        $fieldErrors = [];
        $messageError = AdminMessageText::error($messageText);
        if ($messageError !== null) {
            $fieldErrors['message'] = $messageError;
        }
        $contactError = TextRules::singleLineError($contactDetails, AdminConversationService::CONTACT_MAX_LENGTH, false);
        if ($contactError !== null) {
            $fieldErrors['contact'] = $contactError;
        }
        if ($fieldErrors !== []) {
            throw HttpException::validation($fieldErrors);
        }

        // Counted from the audit log, so the limit holds however the messages were sent.
        if ($this->messagesSentInLastHour($currentUser->id) >= self::MAXIMUM_MESSAGES_PER_HOUR) {
            throw HttpException::tooManyRequests(3600);
        }

        // Stored first (D065): the administrator finds it in the inbox even when ntfy is off or down.
        $conversationId = $this->conversationService->start($currentUser->id, $messageText, $contactDetails);
        $wasNotified = $this->adminNotifier->sendUserMessage($currentUser->displayName, $currentUser->username, $messageText, $contactDetails, $conversationId);
        // The audit entry never holds the text: what users write to the administrator stays private.
        $this->auditLog->record(
            AuditLog::ADMIN_MESSAGE_SENT,
            $currentUser->id,
            'admin_conversation',
            $conversationId,
            $context->clientIp,
            ['notified' => $wasNotified],
        );

        return Response::data([
            'sent' => true,
            'conversation_id' => $conversationId,
            // Whether the administrator's phone was told as well; the inbox has it either way.
            'notified' => $wasNotified,
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
