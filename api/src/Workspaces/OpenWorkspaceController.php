<?php

declare(strict_types=1);

namespace Muninn\Api\Workspaces;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\InputReader;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Notifications\AdminNotifier;
use Muninn\Api\Users\User;
use Muninn\Api\Validation\TextRules;

/**
 * Shared Workspaces users ask to join (decision D067), for signed-in everyday users:
 *   GET    /api/v1/open-workspaces                       every one, with the caller's status
 *   POST   /api/v1/open-workspaces/{id}/join-request     {"note": "optional"}
 *   DELETE /api/v1/open-workspaces/{id}/join-request     cancel the caller's pending request
 *
 * Users see only names and descriptions until they are members; then the ordinary workspace,
 * note and member endpoints take over (and they leave with DELETE .../members/{their own id}).
 */
final class OpenWorkspaceController
{
    private const NOTE_MAX_LENGTH = 200;

    public function __construct(
        private readonly OpenWorkspaceService $openWorkspaceService,
        private readonly AuditLog $auditLog,
        private readonly AdminNotifier $adminNotifier,
    ) {
    }

    /** GET /api/v1/open-workspaces */
    public function list(Request $request, RequestContext $context): Response
    {
        $currentUser = $this->requireEverydayUser($context);

        return Response::data(['open_workspaces' => $this->openWorkspaceService->listForUser($currentUser)]);
    }

    /** POST /api/v1/open-workspaces/{id}/join-request  {"note": "..."} */
    public function requestToJoin(Request $request, RequestContext $context): Response
    {
        $currentUser = $this->requireEverydayUser($context);
        $workspaceId = (string) $request->routeParameter('id');
        $note = trim(InputReader::optionalString($request->jsonBody(), 'note') ?? '');
        $noteError = TextRules::singleLineError($note, self::NOTE_MAX_LENGTH, false);
        if ($noteError !== null) {
            throw HttpException::validation(['note' => $noteError]);
        }

        $createdRequest = $this->openWorkspaceService->requestToJoin($currentUser, $workspaceId, $note);
        $this->auditLog->record(
            AuditLog::WORKSPACE_JOIN_REQUESTED,
            $currentUser->id,
            'workspace',
            $workspaceId,
            $context->clientIp,
            ['request_id' => $createdRequest['request_id']],
        );
        // The administrator gets a push notification after this response is sent (D057).
        $this->adminNotifier->workspaceJoinRequested($currentUser->displayName, $createdRequest['workspace_name'], $createdRequest['request_id']);

        return Response::data(['open_workspace' => $this->findForUser($currentUser, $workspaceId)], 201);
    }

    /** DELETE /api/v1/open-workspaces/{id}/join-request */
    public function cancelRequest(Request $request, RequestContext $context): Response
    {
        $currentUser = $this->requireEverydayUser($context);
        $workspaceId = (string) $request->routeParameter('id');

        $cancelledRequestId = $this->openWorkspaceService->cancelOwnRequest($currentUser, $workspaceId);
        $this->auditLog->record(
            AuditLog::WORKSPACE_JOIN_REQUEST_CANCELLED,
            $currentUser->id,
            'workspace',
            $workspaceId,
            $context->clientIp,
            ['request_id' => $cancelledRequestId],
        );

        return Response::noContent();
    }

    /**
     * The caller's view of one open workspace (as in the list).
     *
     * @return array<string, mixed>
     */
    private function findForUser(User $currentUser, string $workspaceId): array
    {
        foreach ($this->openWorkspaceService->listForUser($currentUser) as $openWorkspace) {
            if ($openWorkspace['id'] === $workspaceId) {
                return $openWorkspace;
            }
        }

        throw HttpException::notFound();
    }

    /**
     * Administrators never join workspaces (D044); they manage these under Admin instead.
     *
     * @throws HttpException 403 for administrator accounts.
     */
    private function requireEverydayUser(RequestContext $context): User
    {
        $currentUser = $context->requireSession()->user;
        if ($currentUser->isSystemAdmin) {
            throw HttpException::forbidden('admin_account', 'Administrator accounts manage Shared Workspaces under Admin > Workspaces.');
        }

        return $currentUser;
    }
}
