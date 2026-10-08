<?php

declare(strict_types=1);

namespace Muninn\Api\Admin;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\InputReader;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Workspaces\WorkspaceAuthorizer;
use Muninn\Api\Workspaces\WorkspaceMembership;
use Muninn\Api\Workspaces\WorkspaceRole;
use Muninn\Api\Workspaces\WorkspaceService;

/**
 * Shared workspace membership administration for system administrators (decision D050):
 *   GET    /api/v1/admin/workspaces
 *   GET    /api/v1/admin/workspaces/{id}/members
 *   POST   /api/v1/admin/workspaces/{id}/members            {"username": "...", "role": "owner"}
 *   PATCH  /api/v1/admin/workspaces/{id}/members/{userId}   {"role": "owner"}
 *   DELETE /api/v1/admin/workspaces/{id}/members/{userId}
 *
 * Administrators manage members with Owner rights, so a workspace whose only Owner was disabled
 * can be given a new Owner. They never see notes, note counts or any other content (D025).
 * The same rules as for Owners apply, including "a workspace keeps at least one Owner".
 */
final class WorkspaceAdminController
{
    public function __construct(
        private readonly WorkspaceService $workspaceService,
        private readonly WorkspaceAuthorizer $workspaceAuthorizer,
        private readonly AuditLog $auditLog,
    ) {
    }

    /** GET /api/v1/admin/workspaces */
    public function list(Request $request, RequestContext $context): Response
    {
        return Response::data(['workspaces' => $this->workspaceService->listSharedForAdministrator()]);
    }

    /** GET /api/v1/admin/workspaces/{id}/members */
    public function listMembers(Request $request, RequestContext $context): Response
    {
        $administratorMembership = $this->authorize($request, $context);

        return Response::data([
            'workspace' => ['id' => $administratorMembership->workspaceId, 'name' => $administratorMembership->workspaceName],
            'members' => $this->workspaceService->listMembers($administratorMembership),
        ]);
    }

    /** POST /api/v1/admin/workspaces/{id}/members  {"username": "...", "role": "owner"} */
    public function addMember(Request $request, RequestContext $context): Response
    {
        $administratorMembership = $this->authorize($request, $context);
        $requestBody = $request->jsonBody();
        $username = InputReader::requiredString($requestBody, 'username');
        $newRole = $this->readRole($requestBody);

        $newMember = $this->workspaceService->addMember($administratorMembership, $username, $newRole);
        $this->auditLog->record(
            AuditLog::WORKSPACE_MEMBER_ADDED,
            $administratorMembership->userId,
            'workspace',
            $administratorMembership->workspaceId,
            $context->clientIp,
            ['member_user_id' => $newMember['user_id'], 'role' => $newRole->value, 'by_system_admin' => true],
        );

        return Response::data(['member' => $newMember], 201);
    }

    /** PATCH /api/v1/admin/workspaces/{id}/members/{userId}  {"role": "owner"} */
    public function changeMemberRole(Request $request, RequestContext $context): Response
    {
        $administratorMembership = $this->authorize($request, $context);
        $memberUserId = (string) $request->routeParameter('userId');
        $newRole = $this->readRole($request->jsonBody());

        $updatedMember = $this->workspaceService->changeMemberRole($administratorMembership, $memberUserId, $newRole);
        $this->auditLog->record(
            AuditLog::WORKSPACE_MEMBER_ROLE_CHANGED,
            $administratorMembership->userId,
            'workspace',
            $administratorMembership->workspaceId,
            $context->clientIp,
            ['member_user_id' => $memberUserId, 'role' => $newRole->value, 'by_system_admin' => true],
        );

        return Response::data(['member' => $updatedMember]);
    }

    /** DELETE /api/v1/admin/workspaces/{id}/members/{userId} */
    public function removeMember(Request $request, RequestContext $context): Response
    {
        $administratorMembership = $this->authorize($request, $context);
        $memberUserId = (string) $request->routeParameter('userId');

        $removedRole = $this->workspaceService->removeMember($administratorMembership, $memberUserId);
        $this->auditLog->record(
            AuditLog::WORKSPACE_MEMBER_REMOVED,
            $administratorMembership->userId,
            'workspace',
            $administratorMembership->workspaceId,
            $context->clientIp,
            ['member_user_id' => $memberUserId, 'previous_role' => $removedRole->value, 'by_system_admin' => true],
        );

        return Response::noContent();
    }

    /** Resolves {id} to a shared workspace the administrator may manage members of, or throws 404. */
    private function authorize(Request $request, RequestContext $context): WorkspaceMembership
    {
        return $this->workspaceAuthorizer->requireAdministratorMemberManagement(
            $context->requireSession()->user,
            (string) $request->routeParameter('id'),
        );
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
