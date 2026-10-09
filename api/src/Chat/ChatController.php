<?php

declare(strict_types=1);

namespace Muninn\Api\Chat;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\InputReader;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Users\User;
use Muninn\Api\Validation\TextRules;
use Muninn\Api\Workspaces\WorkspaceAuthorizer;

/**
 * Chat (decision D062): one channel per shared workspace, plus one global channel.
 *
 * Signed-in everyday users (who may use what: ChatPolicy):
 *   GET    /api/v1/chat                             the channels the caller may use
 *   GET    /api/v1/chat/global/messages             newest page; ?before=<message id> for older,
 *   GET    /api/v1/workspaces/{id}/chat/messages      ?since=<cursor> for changes since the last look
 *   POST   /api/v1/chat/global/messages             {"body": "..."}
 *   POST   /api/v1/workspaces/{id}/chat/messages    {"body": "..."}
 *   DELETE /api/v1/chat/messages/{id}               own message, or any message as workspace Admin/Owner
 *
 * System administrator accounts cannot read or write any chat (403/404 everywhere).
 */
final class ChatController
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly ChatPolicy $chatPolicy,
        private readonly WorkspaceAuthorizer $workspaceAuthorizer,
        private readonly AuditLog $auditLog,
        private readonly int $retentionDays,
    ) {
    }

    /** GET /api/v1/chat */
    public function overview(Request $request, RequestContext $context): Response
    {
        $currentUser = $context->requireSession()->user;

        $workspaceChannels = [];
        foreach ($this->workspaceAuthorizer->listMemberships($currentUser) as $membership) {
            if (!$this->chatPolicy->canReadWorkspace($currentUser, $membership)) {
                continue;
            }
            $workspaceChannels[] = [
                'workspace_id' => $membership->workspaceId,
                'name' => $membership->workspaceName,
                'your_role' => $membership->role->value,
                'can_write' => $this->chatPolicy->canWriteWorkspace($currentUser, $membership),
            ];
        }

        return Response::data([
            'chat_access' => $currentUser->isSystemAdmin ? ChatAccessLevel::Off->value : $currentUser->chatAccess->value,
            'global' => [
                'can_read' => $this->chatPolicy->canReadGlobal($currentUser),
                'can_write' => $this->chatPolicy->canWriteGlobal($currentUser),
            ],
            'workspaces' => $workspaceChannels,
            'limits' => [
                'message_max_length' => ChatService::MESSAGE_MAX_LENGTH,
                'messages_per_minute' => ChatService::MAXIMUM_MESSAGES_PER_MINUTE,
                'retention_days' => $this->retentionDays,
            ],
        ]);
    }

    /** GET /api/v1/chat/global/messages */
    public function listGlobal(Request $request, RequestContext $context): Response
    {
        $currentUser = $context->requireSession()->user;
        $this->chatPolicy->requireGlobalReader($currentUser);

        $channel = [
            'kind' => 'global',
            'workspace_id' => null,
            'name' => 'Everyone',
            'can_write' => $this->chatPolicy->canWriteGlobal($currentUser),
            'can_moderate' => false,
        ];

        return $this->listMessages($request, $currentUser, null, $channel);
    }

    /** GET /api/v1/workspaces/{id}/chat/messages */
    public function listWorkspace(Request $request, RequestContext $context): Response
    {
        $currentUser = $context->requireSession()->user;
        $membership = $this->chatPolicy->requireWorkspaceReader($currentUser, (string) $request->routeParameter('id'));

        $channel = [
            'kind' => 'workspace',
            'workspace_id' => $membership->workspaceId,
            'name' => $membership->workspaceName,
            'can_write' => $this->chatPolicy->canWriteWorkspace($currentUser, $membership),
            'can_moderate' => $this->chatPolicy->canModerateWorkspace($currentUser, $membership),
        ];

        return $this->listMessages($request, $currentUser, $membership->workspaceId, $channel);
    }

    /** POST /api/v1/chat/global/messages  {"body": "..."} */
    public function postGlobal(Request $request, RequestContext $context): Response
    {
        $currentUser = $context->requireSession()->user;
        $this->chatPolicy->requireGlobalReader($currentUser);
        if (!$this->chatPolicy->canWriteGlobal($currentUser)) {
            throw HttpException::forbidden('chat_read_only', 'You can read this channel but not write in it.');
        }

        return $this->postMessage($request, $currentUser, null);
    }

    /** POST /api/v1/workspaces/{id}/chat/messages  {"body": "..."} */
    public function postWorkspace(Request $request, RequestContext $context): Response
    {
        $currentUser = $context->requireSession()->user;
        $membership = $this->chatPolicy->requireWorkspaceReader($currentUser, (string) $request->routeParameter('id'));
        if (!$this->chatPolicy->canWriteWorkspace($currentUser, $membership)) {
            throw WorkspaceAuthorizer::insufficientRole();
        }

        return $this->postMessage($request, $currentUser, $membership->workspaceId);
    }

    /**
     * DELETE /api/v1/chat/messages/{id}
     *
     * The caller must still be able to read the message's channel. Authors delete their own
     * messages; workspace Admins and Owners delete anyone's in their workspace (audited, no text).
     */
    public function delete(Request $request, RequestContext $context): Response
    {
        $currentUser = $context->requireSession()->user;
        $chatMessage = $this->chatService->findUndeleted((string) $request->routeParameter('id'));
        if ($chatMessage === null) {
            throw HttpException::notFound();
        }

        $isOwnMessage = $chatMessage['author_user_id'] === $currentUser->id;
        if ($chatMessage['workspace_id'] === null) {
            $this->chatPolicy->requireGlobalReader($currentUser);
            $mayDelete = $isOwnMessage;
        } else {
            // A non-member gets 404 here, exactly as for an unknown message.
            $membership = $this->chatPolicy->requireWorkspaceReader($currentUser, $chatMessage['workspace_id']);
            $mayDelete = $isOwnMessage || $this->chatPolicy->canModerateWorkspace($currentUser, $membership);
        }
        if (!$mayDelete) {
            throw HttpException::forbidden('not_your_message', 'You can only delete your own messages.');
        }

        $this->chatService->markDeleted($chatMessage['id'], $currentUser->id);
        if (!$isOwnMessage) {
            $this->auditLog->record(
                AuditLog::CHAT_MESSAGE_REMOVED_BY_MODERATOR,
                $currentUser->id,
                'chat_message',
                $chatMessage['id'],
                $context->clientIp,
                ['workspace_id' => $chatMessage['workspace_id'], 'author_user_id' => $chatMessage['author_user_id']],
            );
        }

        return Response::noContent();
    }

    /**
     * Shared by both channel kinds: the newest page, an older page (?before=) or the changes
     * since a cursor (?since=).
     *
     * @param array<string, mixed> $channel What the browser needs to know about the channel.
     */
    private function listMessages(Request $request, User $currentUser, ?string $workspaceId, array $channel): Response
    {
        $beforeMessageId = $request->queryParameter('before');
        $sinceCursor = $request->queryParameter('since');
        $viewerCanModerate = (bool) $channel['can_moderate'];

        if ($beforeMessageId !== null && $sinceCursor !== null) {
            throw HttpException::badRequest('invalid_query', 'Use either "before" or "since", not both.');
        }
        if ($sinceCursor !== null) {
            $messagePage = $this->chatService->listChangedSince($workspaceId, $sinceCursor, $currentUser->id, $viewerCanModerate);
        } elseif ($beforeMessageId !== null) {
            $messagePage = $this->chatService->listBefore($workspaceId, $beforeMessageId, $currentUser->id, $viewerCanModerate);
        } else {
            $messagePage = $this->chatService->listLatest($workspaceId, $currentUser->id, $viewerCanModerate);
        }

        return Response::data(['channel' => $channel] + $messagePage);
    }

    /** Validates, rate limits and stores a message in an already authorized channel. */
    private function postMessage(Request $request, User $currentUser, ?string $workspaceId): Response
    {
        $requestBody = $request->jsonBody();
        $messageBody = trim(str_replace(["\r\n", "\r"], "\n", InputReader::optionalString($requestBody, 'body') ?? ''));

        $bodyError = null;
        if ($messageBody === '') {
            $bodyError = 'Write a message.';
        } elseif (mb_strlen($messageBody) > ChatService::MESSAGE_MAX_LENGTH) {
            $bodyError = 'Use at most ' . ChatService::MESSAGE_MAX_LENGTH . ' characters.';
        } else {
            // Four bytes per character is the most UTF-8 needs, so this only catches bad encoding and NUL.
            $bodyError = TextRules::multiLineError($messageBody, ChatService::MESSAGE_MAX_LENGTH * 4);
        }
        if ($bodyError !== null) {
            throw HttpException::validation(['body' => $bodyError]);
        }

        if ($this->chatService->countSentInLastMinute($currentUser->id) >= ChatService::MAXIMUM_MESSAGES_PER_MINUTE) {
            throw new HttpException(429, 'chat_rate_limited', 'You are sending messages too quickly. Wait a moment and try again.', [], ['Retry-After' => '60']);
        }

        return Response::data(['message' => $this->chatService->post($workspaceId, $currentUser->id, $messageBody)], 201);
    }
}
