<?php

declare(strict_types=1);

namespace Muninn\Api\Workspaces;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Users\User;
use PDO;

/**
 * The central authorization check for everything inside a workspace (SECURITY.md: deny by
 * default, one testable place instead of ad-hoc checks per endpoint).
 *
 * Rules:
 * - Access comes ONLY from a row in workspace_members. Nothing the client sends (IDs, roles,
 *   ownership claims) grants anything by itself.
 * - System administrators never get workspace or note access (decision D025), even if a
 *   membership row somehow existed.
 * - A caller who is not a member gets 404, exactly as if the workspace did not exist, so IDs
 *   of other people's workspaces and notes reveal nothing.
 * - A member whose role is too weak gets 403: they already know the workspace exists.
 */
final class WorkspaceAuthorizer
{
    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * Returns the user's membership of a workspace, or null when they have no access to it.
     * Never throws for unknown or malformed IDs.
     */
    public function findMembership(User $user, string $workspaceId): ?WorkspaceMembership
    {
        if ($user->isSystemAdmin || !$user->isActive() || !UuidGenerator::isValid($workspaceId)) {
            return null;
        }

        $selectStatement = $this->database->prepare(
            'SELECT workspaces.id, workspaces.name, workspaces.kind, workspace_members.role
             FROM workspace_members
             JOIN workspaces ON workspaces.id = workspace_members.workspace_id
             WHERE workspace_members.workspace_id = :workspace_id
               AND workspace_members.user_id = :user_id'
        );
        $selectStatement->execute(['workspace_id' => $workspaceId, 'user_id' => $user->id]);
        $membershipRow = $selectStatement->fetch();
        if ($membershipRow === false) {
            return null;
        }

        return new WorkspaceMembership(
            workspaceId: (string) $membershipRow['id'],
            workspaceName: (string) $membershipRow['name'],
            workspaceKind: (string) $membershipRow['kind'],
            userId: $user->id,
            role: WorkspaceRole::from((string) $membershipRow['role']),
        );
    }

    /**
     * Returns the membership when the user holds $permission in the workspace.
     *
     * @throws HttpException 404 when the user is not a member (or the workspace does not exist),
     *                       403 when they are a member without the permission.
     */
    public function requireWorkspacePermission(User $user, string $workspaceId, WorkspacePermission $permission): WorkspaceMembership
    {
        $membership = $this->findMembership($user, $workspaceId);
        if ($membership === null) {
            throw HttpException::notFound();
        }
        if (!$membership->role->allows($permission)) {
            throw self::insufficientRole();
        }

        return $membership;
    }

    /** The 403 used whenever a member's role is too weak for an action. */
    public static function insufficientRole(): HttpException
    {
        return HttpException::forbidden('insufficient_role', 'Your role in this workspace does not allow this.');
    }
}
