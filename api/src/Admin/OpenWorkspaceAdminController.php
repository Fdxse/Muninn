<?php

declare(strict_types=1);

namespace Muninn\Api\Admin;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\InputReader;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Validation\TextRules;
use Muninn\Api\Workspaces\OpenWorkspaceService;
use Muninn\Api\Workspaces\WorkspaceRole;

/**
 * Shared Workspaces users ask to join (decision D067), for system administrators:
 *   GET    /api/v1/admin/open-workspaces
 *   POST   /api/v1/admin/open-workspaces                      {"name": "...", "description": "..."}
 *   PATCH  /api/v1/admin/open-workspaces/{id}                 {"name": "...", "description": "..."}
 *   DELETE /api/v1/admin/open-workspaces/{id}                 only while it holds no notes
 *   GET    /api/v1/admin/workspace-join-requests              pending requests
 *   GET    /api/v1/admin/workspace-join-requests/pending-count
 *   POST   /api/v1/admin/workspace-join-requests/{id}/approve {"role": "editor" | "reader"}
 *   POST   /api/v1/admin/workspace-join-requests/{id}/decline
 *
 * Members are managed with the D050 endpoints under /api/v1/admin/workspaces/{id}/members, which
 * always accept open workspaces. Administrators never see notes, note counts or content (D025).
 */
final class OpenWorkspaceAdminController
{
    private const NAME_MAX_LENGTH = 100;
    private const DESCRIPTION_MAX_LENGTH = 300;

    public function __construct(
        private readonly OpenWorkspaceService $openWorkspaceService,
        private readonly AuditLog $auditLog,
    ) {
    }

    /** GET /api/v1/admin/open-workspaces */
    public function list(Request $request, RequestContext $context): Response
    {
        return Response::data(['open_workspaces' => $this->openWorkspaceService->listForAdministrator()]);
    }

    /** POST /api/v1/admin/open-workspaces */
    public function create(Request $request, RequestContext $context): Response
    {
        $administrator = $context->requireSession()->user;
        [$workspaceName, $description] = $this->readNameAndDescription($request->jsonBody());

        $newWorkspaceId = $this->openWorkspaceService->create($administrator, $workspaceName, $description);
        $this->auditLog->record(
            AuditLog::WORKSPACE_CREATED,
            $administrator->id,
            'workspace',
            $newWorkspaceId,
            $context->clientIp,
            ['kind' => 'open', 'by_system_admin' => true],
        );

        return Response::data(['open_workspace' => $this->openWorkspaceService->findForAdministrator($newWorkspaceId)], 201);
    }

    /** PATCH /api/v1/admin/open-workspaces/{id} */
    public function update(Request $request, RequestContext $context): Response
    {
        $administrator = $context->requireSession()->user;
        $workspaceId = (string) $request->routeParameter('id');
        [$workspaceName, $description] = $this->readNameAndDescription($request->jsonBody());

        $this->openWorkspaceService->update($workspaceId, $workspaceName, $description);
        $this->auditLog->record(AuditLog::WORKSPACE_UPDATED, $administrator->id, 'workspace', $workspaceId, $context->clientIp, ['by_system_admin' => true]);

        return Response::data(['open_workspace' => $this->openWorkspaceService->findForAdministrator($workspaceId)]);
    }

    /** DELETE /api/v1/admin/open-workspaces/{id} */
    public function delete(Request $request, RequestContext $context): Response
    {
        $administrator = $context->requireSession()->user;
        $workspaceId = (string) $request->routeParameter('id');

        $deletedName = $this->openWorkspaceService->deleteEmpty($workspaceId);
        $this->auditLog->record(
            AuditLog::WORKSPACE_DELETED,
            $administrator->id,
            'workspace',
            $workspaceId,
            $context->clientIp,
            ['name' => $deletedName, 'by_system_admin' => true],
        );

        return Response::noContent();
    }

    /** GET /api/v1/admin/workspace-join-requests */
    public function listRequests(Request $request, RequestContext $context): Response
    {
        return Response::data(['join_requests' => $this->openWorkspaceService->listPendingRequests()]);
    }

    /** GET /api/v1/admin/workspace-join-requests/pending-count — for the navigation badge. */
    public function pendingCount(Request $request, RequestContext $context): Response
    {
        return Response::data(['pending_count' => $this->openWorkspaceService->countPending()]);
    }

    /** POST /api/v1/admin/workspace-join-requests/{id}/approve  {"role": "editor"} */
    public function approve(Request $request, RequestContext $context): Response
    {
        $administrator = $context->requireSession()->user;
        $requestId = (string) $request->routeParameter('id');
        // Editor unless the administrator picks Reader (the project owner's choice for D067).
        $roleInput = $request->jsonBody()['role'] ?? WorkspaceRole::Editor->value;
        $newRole = WorkspaceRole::tryFromInput($roleInput);
        if ($newRole === null || !WorkspaceRole::isOpenWorkspaceRole($newRole)) {
            throw HttpException::validation(['role' => 'Choose editor or reader.']);
        }

        $approval = $this->openWorkspaceService->approve($requestId, $administrator, $newRole);
        $this->auditLog->record(
            AuditLog::WORKSPACE_JOIN_REQUEST_APPROVED,
            $administrator->id,
            'workspace',
            $approval['workspace_id'],
            $context->clientIp,
            ['request_id' => $requestId, 'member_user_id' => $approval['user_id'], 'role' => $newRole->value],
        );
        if ($approval['added']) {
            $this->auditLog->record(
                AuditLog::WORKSPACE_MEMBER_ADDED,
                $administrator->id,
                'workspace',
                $approval['workspace_id'],
                $context->clientIp,
                ['member_user_id' => $approval['user_id'], 'role' => $newRole->value, 'by_system_admin' => true],
            );
        }

        return Response::noContent();
    }

    /** POST /api/v1/admin/workspace-join-requests/{id}/decline */
    public function decline(Request $request, RequestContext $context): Response
    {
        $administrator = $context->requireSession()->user;
        $requestId = (string) $request->routeParameter('id');

        $declinedRequest = $this->openWorkspaceService->decline($requestId, $administrator);
        $this->auditLog->record(
            AuditLog::WORKSPACE_JOIN_REQUEST_DECLINED,
            $administrator->id,
            'workspace',
            $declinedRequest['workspace_id'],
            $context->clientIp,
            ['request_id' => $requestId, 'member_user_id' => $declinedRequest['user_id']],
        );

        return Response::noContent();
    }

    /**
     * Reads and validates the name (required) and description (optional; empty means none).
     *
     * @param array<string, mixed> $requestBody
     * @return array{0: string, 1: ?string}
     */
    private function readNameAndDescription(array $requestBody): array
    {
        $workspaceName = trim(InputReader::optionalString($requestBody, 'name') ?? '');
        $description = trim(InputReader::optionalString($requestBody, 'description') ?? '');

        $fieldErrors = [];
        $nameError = TextRules::singleLineError($workspaceName, self::NAME_MAX_LENGTH, true);
        if ($nameError !== null) {
            $fieldErrors['name'] = $nameError;
        }
        $descriptionError = TextRules::singleLineError($description, self::DESCRIPTION_MAX_LENGTH, false);
        if ($descriptionError !== null) {
            $fieldErrors['description'] = $descriptionError;
        }
        if ($fieldErrors !== []) {
            throw HttpException::validation($fieldErrors);
        }

        return [$workspaceName, $description === '' ? null : $description];
    }
}
