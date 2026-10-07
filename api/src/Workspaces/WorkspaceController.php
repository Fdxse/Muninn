<?php

declare(strict_types=1);

namespace Muninn\Api\Workspaces;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\InputReader;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Validation\TextRules;

/**
 * Workspace and membership endpoints (all require a signed-in user):
 *   GET    /api/v1/workspaces
 *   POST   /api/v1/workspaces
 *   GET    /api/v1/workspaces/{id}
 *   PATCH  /api/v1/workspaces/{id}
 *   DELETE /api/v1/workspaces/{id}
 *   GET    /api/v1/workspaces/{id}/members
 *   POST   /api/v1/workspaces/{id}/members
 *   PATCH  /api/v1/workspaces/{id}/members/{userId}
 *   DELETE /api/v1/workspaces/{id}/members/{userId}
 *
 * Every handler that touches an existing workspace starts with WorkspaceAuthorizer.
 */
final class WorkspaceController
{
    private const NAME_MAX_LENGTH = 100;

    public function __construct(
        private readonly WorkspaceService $workspaceService,
        private readonly WorkspaceAuthorizer $workspaceAuthorizer,
        private readonly AuditLog $auditLog,
    ) {
    }

    /** GET /api/v1/workspaces — the caller's workspaces (their personal one is created on first use). */
    public function list(Request $request, RequestContext $context): Response
    {
        $currentUser = $context->requireSession()->user;

        return Response::data(['workspaces' => $this->workspaceService->listForUser($currentUser)]);
    }

    /** POST /api/v1/workspaces  {"name": "..."} — creates a shared workspace owned by the caller. */
    public function create(Request $request, RequestContext $context): Response
    {
        $currentUser = $context->requireSession()->user;
        // System administrators have no note access (D025), so they cannot own workspaces either.
        if ($currentUser->isSystemAdmin) {
            throw HttpException::forbidden('admin_account', 'Administrator accounts cannot use workspaces. Sign in with your everyday account.');
        }

        $workspaceName = $this->readName($request->jsonBody());
        $newWorkspaceId = $this->workspaceService->createShared($currentUser, $workspaceName);
        $this->auditLog->record(AuditLog::WORKSPACE_CREATED, $currentUser->id, 'workspace', $newWorkspaceId, $context->clientIp);

        $ownerMembership = $this->workspaceAuthorizer->requireWorkspacePermission($currentUser, $newWorkspaceId, WorkspacePermission::ReadNotes);

        return Response::data(['workspace' => $this->workspaceService->findForMember($ownerMembership)], 201);
    }

    /** GET /api/v1/workspaces/{id} */
    public function show(Request $request, RequestContext $context): Response
    {
        $membership = $this->authorize($request, $context, WorkspacePermission::ReadNotes);

        return Response::data(['workspace' => $this->workspaceService->findForMember($membership)]);
    }

    /** PATCH /api/v1/workspaces/{id}  {"name": "..."} — Owners only. */
    public function rename(Request $request, RequestContext $context): Response
    {
        $membership = $this->authorize($request, $context, WorkspacePermission::ManageWorkspace);
        $newName = $this->readName($request->jsonBody());

        $this->workspaceService->rename($membership, $newName);
        $this->auditLog->record(AuditLog::WORKSPACE_RENAMED, $membership->userId, 'workspace', $membership->workspaceId, $context->clientIp);

        return Response::data(['workspace' => $this->workspaceService->findForMember($membership)]);
    }

    /** DELETE /api/v1/workspaces/{id} — Owners only, shared and empty workspaces only. */
    public function delete(Request $request, RequestContext $context): Response
    {
        $membership = $this->authorize($request, $context, WorkspacePermission::ManageWorkspace);

        $this->workspaceService->deleteEmptyShared($membership);
        $this->auditLog->record(
            AuditLog::WORKSPACE_DELETED,
            $membership->userId,
            'workspace',
            $membership->workspaceId,
            $context->clientIp,
            ['name' => $membership->workspaceName],
        );

        return Response::noContent();
    }

    /** GET /api/v1/workspaces/{id}/members — any member. */
    public function listMembers(Request $request, RequestContext $context): Response
    {
        $membership = $this->authorize($request, $context, WorkspacePermission::ReadNotes);

        return Response::data(['members' => $this->workspaceService->listMembers($membership)]);
    }

    /** POST /api/v1/workspaces/{id}/members  {"username": "...", "role": "editor"} */
    public function addMember(Request $request, RequestContext $context): Response
    {
        $membership = $this->authorize($request, $context, WorkspacePermission::ManageMembers);
        $requestBody = $request->jsonBody();
        $username = InputReader::requiredString($requestBody, 'username');
        $newRole = $this->readRole($requestBody);

        $newMember = $this->workspaceService->addMember($membership, $username, $newRole);
        $this->auditLog->record(
            AuditLog::WORKSPACE_MEMBER_ADDED,
            $membership->userId,
            'workspace',
            $membership->workspaceId,
            $context->clientIp,
            ['member_user_id' => $newMember['user_id'], 'role' => $newRole->value],
        );

        return Response::data(['member' => $newMember], 201);
    }

    /** PATCH /api/v1/workspaces/{id}/members/{userId}  {"role": "reader"} */
    public function changeMemberRole(Request $request, RequestContext $context): Response
    {
        $membership = $this->authorize($request, $context, WorkspacePermission::ManageMembers);
        $memberUserId = (string) $request->routeParameter('userId');
        $newRole = $this->readRole($request->jsonBody());

        $updatedMember = $this->workspaceService->changeMemberRole($membership, $memberUserId, $newRole);
        $this->auditLog->record(
            AuditLog::WORKSPACE_MEMBER_ROLE_CHANGED,
            $membership->userId,
            'workspace',
            $membership->workspaceId,
            $context->clientIp,
            ['member_user_id' => $memberUserId, 'role' => $newRole->value],
        );

        return Response::data(['member' => $updatedMember]);
    }

    /**
     * DELETE /api/v1/workspaces/{id}/members/{userId}
     *
     * Any member may remove themselves (leave). Removing someone else needs ManageMembers and
     * a role strong enough for that member; WorkspaceService checks the second part.
     */
    public function removeMember(Request $request, RequestContext $context): Response
    {
        $membership = $this->authorize($request, $context, WorkspacePermission::ReadNotes);
        $memberUserId = (string) $request->routeParameter('userId');
        if ($memberUserId !== $membership->userId && !$membership->role->allows(WorkspacePermission::ManageMembers)) {
            throw WorkspaceAuthorizer::insufficientRole();
        }

        $removedRole = $this->workspaceService->removeMember($membership, $memberUserId);
        $this->auditLog->record(
            AuditLog::WORKSPACE_MEMBER_REMOVED,
            $membership->userId,
            'workspace',
            $membership->workspaceId,
            $context->clientIp,
            ['member_user_id' => $memberUserId, 'previous_role' => $removedRole->value],
        );

        return Response::noContent();
    }

    /** Resolves the {id} route parameter into the caller's membership, or throws 404/403. */
    private function authorize(Request $request, RequestContext $context, WorkspacePermission $permission): WorkspaceMembership
    {
        return $this->workspaceAuthorizer->requireWorkspacePermission(
            $context->requireSession()->user,
            (string) $request->routeParameter('id'),
            $permission,
        );
    }

    /** @param array<string, mixed> $requestBody */
    private function readName(array $requestBody): string
    {
        $workspaceName = trim(InputReader::optionalString($requestBody, 'name') ?? '');
        $nameError = TextRules::singleLineError($workspaceName, self::NAME_MAX_LENGTH, true);
        if ($nameError !== null) {
            throw HttpException::validation(['name' => $nameError]);
        }

        return $workspaceName;
    }

    /** @param array<string, mixed> $requestBody */
    private function readRole(array $requestBody): WorkspaceRole
    {
        $requestedRole = WorkspaceRole::tryFromInput($requestBody['role'] ?? null);
        if ($requestedRole === null) {
            throw HttpException::validation(['role' => 'Choose owner, admin, editor or reader.']);
        }

        return $requestedRole;
    }
}
