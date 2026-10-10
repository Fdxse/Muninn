<?php

declare(strict_types=1);

namespace Muninn\Api\AdminInbox;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\InputReader;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Notifications\AdminNotifier;
use Muninn\Api\Users\User;

/**
 * The administrator's inbox (decision D065). Every "Contact admin" message (D058) starts a
 * conversation; the user and the system administrators can then reply in it until an
 * administrator closes it.
 *
 * Everyday users (only their own conversations; anyone else's answers 404 as if it did not exist):
 *   GET  /api/v1/admin-messages/unread                        {unread_count} for the badge
 *   GET  /api/v1/admin-messages/conversations                 the user's conversations
 *   GET  /api/v1/admin-messages/conversations/{id}            one conversation; marks it read
 *   POST /api/v1/admin-messages/conversations/{id}/messages   {"message": "..."} reply (open ones only)
 *
 * System administrators (routes are ACCESS_SYSTEM_ADMIN):
 *   GET  /api/v1/admin/conversations?status=open|closed|all   the inbox (default: open)
 *   GET  /api/v1/admin/conversations/unread                   {unread_count} for the badge
 *   GET  /api/v1/admin/conversations/{id}                     one conversation; marks it read
 *   POST /api/v1/admin/conversations/{id}/messages            {"message": "..."} answer (reopens a closed one)
 *   POST /api/v1/admin/conversations/{id}/close               the user can no longer reply
 *   POST /api/v1/admin/conversations/{id}/reopen
 *
 * Users see an administrator's answer as from "Administrator", never the administrator's name.
 * The audit log records replies and status changes, never the text.
 */
final class AdminConversationController
{
    /**
     * Replies one user may write per hour, across all their conversations. Each one may push a
     * notification to the administrator's phone, so this keeps the phone quiet.
     */
    public const MAXIMUM_USER_REPLIES_PER_HOUR = 20;

    /** How users see the administrators' answers. */
    private const ADMIN_AUTHOR_LABEL = 'Administrator';

    public function __construct(
        private readonly AdminConversationService $conversationService,
        private readonly AuditLog $auditLog,
        private readonly AdminNotifier $adminNotifier,
        private readonly int $retentionDays,
    ) {
    }

    /* ---------- Everyday users ---------- */

    /** GET /api/v1/admin-messages/unread */
    public function userUnread(Request $request, RequestContext $context): Response
    {
        $currentUser = $this->requireEverydayUser($context);

        return Response::data(['unread_count' => $this->conversationService->countUnreadForUser($currentUser->id)]);
    }

    /** GET /api/v1/admin-messages/conversations */
    public function userList(Request $request, RequestContext $context): Response
    {
        $currentUser = $this->requireEverydayUser($context);

        return Response::data([
            'conversations' => array_map(
                fn (array $conversation): array => $this->conversationForUser($conversation),
                $this->conversationService->listForUser($currentUser->id),
            ),
            'retention_days' => $this->retentionDays,
        ]);
    }

    /** GET /api/v1/admin-messages/conversations/{id} — opening it means the user has read it. */
    public function userShow(Request $request, RequestContext $context): Response
    {
        $currentUser = $this->requireEverydayUser($context);
        $conversation = $this->requireOwnConversation($request, $currentUser);

        if ($conversation['unread_by_user']) {
            $this->conversationService->markReadByUser($conversation['id']);
            $conversation['unread_by_user'] = false;
        }

        return Response::data($this->conversationWithMessagesForUser($conversation, $currentUser));
    }

    /** POST /api/v1/admin-messages/conversations/{id}/messages  {"message": "..."} */
    public function userReply(Request $request, RequestContext $context): Response
    {
        $currentUser = $this->requireEverydayUser($context);
        $conversation = $this->requireOwnConversation($request, $currentUser);
        if ($conversation['status'] !== AdminConversationService::STATUS_OPEN) {
            throw HttpException::conflict('conversation_closed', 'The administrator has closed this conversation. Start a new one with Contact admin.');
        }

        $messageText = $this->readMessageText($request);
        if ($this->conversationService->countUserRepliesInLastHour($currentUser->id) >= self::MAXIMUM_USER_REPLIES_PER_HOUR) {
            throw HttpException::tooManyRequests(3600);
        }

        $this->conversationService->reply($conversation['id'], $currentUser->id, AdminConversationService::ROLE_USER, $messageText);
        // Sent after the response; does nothing when ntfy is off.
        $this->adminNotifier->userReplied($currentUser->displayName, $currentUser->username, $messageText, $conversation['id']);
        $this->auditLog->record(AuditLog::ADMIN_CONVERSATION_USER_REPLIED, $currentUser->id, 'admin_conversation', $conversation['id'], $context->clientIp);

        $updatedConversation = $this->conversationService->find($conversation['id'], $currentUser->id);

        return Response::data($this->conversationWithMessagesForUser($updatedConversation ?? $conversation, $currentUser), 201);
    }

    /* ---------- System administrators ---------- */

    /** GET /api/v1/admin/conversations/unread */
    public function adminUnread(Request $request, RequestContext $context): Response
    {
        return Response::data(['unread_count' => $this->conversationService->countUnreadForAdmin()]);
    }

    /** GET /api/v1/admin/conversations?status=open|closed|all */
    public function adminList(Request $request, RequestContext $context): Response
    {
        $statusFilter = $request->queryParameter('status') ?? AdminConversationService::STATUS_OPEN;
        if (!in_array($statusFilter, [AdminConversationService::STATUS_OPEN, AdminConversationService::STATUS_CLOSED, 'all'], true)) {
            throw HttpException::badRequest('invalid_status', 'Use status=open, closed or all.');
        }

        $conversations = $this->conversationService->listForAdmin($statusFilter === 'all' ? null : $statusFilter);

        return Response::data([
            'conversations' => array_map(fn (array $conversation): array => $this->conversationForAdmin($conversation), $conversations),
            'status' => $statusFilter,
            'unread_count' => $this->conversationService->countUnreadForAdmin(),
            'retention_days' => $this->retentionDays,
        ]);
    }

    /** GET /api/v1/admin/conversations/{id} — opening it means the administrators have read it. */
    public function adminShow(Request $request, RequestContext $context): Response
    {
        $conversation = $this->requireAnyConversation($request);
        if ($conversation['unread_by_admin']) {
            $this->conversationService->markReadByAdmin($conversation['id']);
            $conversation['unread_by_admin'] = false;
        }

        return Response::data($this->conversationWithMessagesForAdmin($conversation));
    }

    /** POST /api/v1/admin/conversations/{id}/messages  {"message": "..."} */
    public function adminReply(Request $request, RequestContext $context): Response
    {
        $currentAdmin = $context->requireSession()->user;
        $conversation = $this->requireAnyConversation($request);
        $messageText = $this->readMessageText($request);

        $this->conversationService->reply($conversation['id'], $currentAdmin->id, AdminConversationService::ROLE_ADMIN, $messageText);
        $this->auditLog->record(AuditLog::ADMIN_CONVERSATION_ADMIN_REPLIED, $currentAdmin->id, 'admin_conversation', $conversation['id'], $context->clientIp);

        return Response::data($this->conversationWithMessagesForAdmin($this->requireAnyConversation($request)), 201);
    }

    /** POST /api/v1/admin/conversations/{id}/close */
    public function close(Request $request, RequestContext $context): Response
    {
        $currentAdmin = $context->requireSession()->user;
        $conversation = $this->requireAnyConversation($request);
        if ($conversation['status'] === AdminConversationService::STATUS_OPEN) {
            $this->conversationService->close($conversation['id'], $currentAdmin->id);
            $this->auditLog->record(AuditLog::ADMIN_CONVERSATION_CLOSED, $currentAdmin->id, 'admin_conversation', $conversation['id'], $context->clientIp);
        }

        return Response::data($this->conversationWithMessagesForAdmin($this->requireAnyConversation($request)));
    }

    /** POST /api/v1/admin/conversations/{id}/reopen */
    public function reopen(Request $request, RequestContext $context): Response
    {
        $currentAdmin = $context->requireSession()->user;
        $conversation = $this->requireAnyConversation($request);
        if ($conversation['status'] === AdminConversationService::STATUS_CLOSED) {
            $this->conversationService->reopen($conversation['id']);
            $this->auditLog->record(AuditLog::ADMIN_CONVERSATION_REOPENED, $currentAdmin->id, 'admin_conversation', $conversation['id'], $context->clientIp);
        }

        return Response::data($this->conversationWithMessagesForAdmin($this->requireAnyConversation($request)));
    }

    /* ---------- Shared ---------- */

    /**
     * The administrator is the one being contacted, so administrator accounts have no
     * "My messages" of their own.
     *
     * @throws HttpException 403 for administrator accounts.
     */
    private function requireEverydayUser(RequestContext $context): User
    {
        $currentUser = $context->requireSession()->user;
        if ($currentUser->isSystemAdmin) {
            throw HttpException::forbidden('admin_account', 'Administrator accounts use the Messages page instead.');
        }

        return $currentUser;
    }

    /**
     * The caller's own conversation from the route.
     *
     * @return array<string, mixed>
     * @throws HttpException 404 for unknown conversations and for anyone else's.
     */
    private function requireOwnConversation(Request $request, User $currentUser): array
    {
        $conversation = $this->conversationService->find((string) $request->routeParameter('id'), $currentUser->id);
        if ($conversation === null) {
            throw HttpException::notFound();
        }

        return $conversation;
    }

    /**
     * Any conversation from the route (administrators only; the route checks that).
     *
     * @return array<string, mixed>
     */
    private function requireAnyConversation(Request $request): array
    {
        $conversation = $this->conversationService->find((string) $request->routeParameter('id'));
        if ($conversation === null) {
            throw HttpException::notFound();
        }

        return $conversation;
    }

    /** Validated text of a reply. */
    private function readMessageText(Request $request): string
    {
        $messageText = AdminMessageText::normalize(InputReader::optionalString($request->jsonBody(), 'message'));
        $messageError = AdminMessageText::error($messageText);
        if ($messageError !== null) {
            throw HttpException::validation(['message' => $messageError]);
        }

        return $messageText;
    }

    /**
     * A conversation as its user sees it in a list.
     *
     * @param array<string, mixed> $conversation
     * @return array<string, mixed>
     */
    private function conversationForUser(array $conversation): array
    {
        return [
            'id' => $conversation['id'],
            'status' => $conversation['status'],
            'excerpt' => $conversation['excerpt'],
            'contact_details' => $conversation['contact_details'],
            'message_count' => $conversation['message_count'],
            // 'admin' = answered; 'user' = waiting for the administrator.
            'last_message_by' => $conversation['last_message_by'],
            'unread' => $conversation['unread_by_user'],
            'created_at' => $conversation['created_at'],
            'last_message_at' => $conversation['last_message_at'],
        ];
    }

    /**
     * A conversation with its messages, as its user sees it. Administrators appear only as
     * "Administrator".
     *
     * @param array<string, mixed> $conversation
     * @return array<string, mixed>
     */
    private function conversationWithMessagesForUser(array $conversation, User $currentUser): array
    {
        $publicMessages = array_map(
            static fn (array $message): array => [
                'id' => $message['id'],
                'body' => $message['body'],
                'created_at' => $message['created_at'],
                'from' => $message['author_role'],
                'author_name' => $message['author_role'] === AdminConversationService::ROLE_ADMIN ? self::ADMIN_AUTHOR_LABEL : $currentUser->displayName,
                'is_own' => $message['author_role'] === AdminConversationService::ROLE_USER,
            ],
            $this->conversationService->messages($conversation['id']),
        );

        return [
            'conversation' => $this->conversationForUser($conversation) + [
                'can_reply' => $conversation['status'] === AdminConversationService::STATUS_OPEN,
            ],
            'messages' => $publicMessages,
            'limits' => $this->limits(),
        ];
    }

    /**
     * A conversation as the administrators see it in the inbox.
     *
     * @param array<string, mixed> $conversation
     * @return array<string, mixed>
     */
    private function conversationForAdmin(array $conversation): array
    {
        return [
            'id' => $conversation['id'],
            'user' => [
                'id' => $conversation['user_id'],
                'username' => $conversation['username'],
                'display_name' => $conversation['display_name'],
                'status' => $conversation['user_status'],
            ],
            'status' => $conversation['status'],
            'excerpt' => $conversation['excerpt'],
            'contact_details' => $conversation['contact_details'],
            'message_count' => $conversation['message_count'],
            // 'user' = waiting for an administrator's answer.
            'last_message_by' => $conversation['last_message_by'],
            'unread' => $conversation['unread_by_admin'],
            'created_at' => $conversation['created_at'],
            'last_message_at' => $conversation['last_message_at'],
            'closed_at' => $conversation['closed_at'],
        ];
    }

    /**
     * A conversation with its messages, as the administrators see it: which administrator
     * answered is shown to the administrators only.
     *
     * @param array<string, mixed> $conversation
     * @return array<string, mixed>
     */
    private function conversationWithMessagesForAdmin(array $conversation): array
    {
        $publicMessages = array_map(
            static fn (array $message): array => [
                'id' => $message['id'],
                'body' => $message['body'],
                'created_at' => $message['created_at'],
                'from' => $message['author_role'],
                'author_name' => $message['author_display_name'],
                'is_own' => $message['author_role'] === AdminConversationService::ROLE_ADMIN,
            ],
            $this->conversationService->messages($conversation['id']),
        );

        return [
            'conversation' => $this->conversationForAdmin($conversation),
            'messages' => $publicMessages,
            'limits' => $this->limits(),
        ];
    }

    /** @return array<string, int> */
    private function limits(): array
    {
        return [
            'message_max_length' => AdminConversationService::MESSAGE_MAX_LENGTH,
            'messages_per_conversation' => AdminConversationService::MAXIMUM_MESSAGES_PER_CONVERSATION,
            'retention_days' => $this->retentionDays,
        ];
    }
}
