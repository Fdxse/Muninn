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
     * Returns the IDs of every workspace in which the user holds $permission. Used by queries
     * that span workspaces, such as search, so they follow exactly the same rules as
     * findMembership(): administrators and inactive users get none at all.
     *
     * @return list<string>
     */
    public function workspaceIdsWithPermission(User $user, WorkspacePermission $permission): array
    {
        if ($user->isSystemAdmin || !$user->isActive()) {
            return [];
        }

        $selectStatement = $this->database->prepare(
            'SELECT workspace_id, role FROM workspace_members WHERE user_id = :user_id ORDER BY workspace_id'
        );
        $selectStatement->execute(['user_id' => $user->id]);

        $permittedWorkspaceIds = [];
        foreach ($selectStatement->fetchAll() as $membershipRow) {
            if (WorkspaceRole::from((string) $membershipRow['role'])->allows($permission)) {
                $permittedWorkspaceIds[] = (string) $membershipRow['workspace_id'];
            }
        }

        return $permittedWorkspaceIds;
    }

    /**
     * Returns every membership the user holds, ordered by workspace name. Used by lists that
     * span workspaces, such as the chat channel list (D062), so they follow exactly the same
     * rules as findMembership(): administrators and inactive users get none at all.
     *
     * @return list<WorkspaceMembership>
     */
    public function listMemberships(User $user): array
    {
        if ($user->isSystemAdmin || !$user->isActive()) {
            return [];
        }

        $selectStatement = $this->database->prepare(
            'SELECT workspaces.id, workspaces.name, workspaces.kind, workspace_members.role
             FROM workspace_members
             JOIN workspaces ON workspaces.id = workspace_members.workspace_id
             WHERE workspace_members.user_id = :user_id
             ORDER BY workspaces.name, workspaces.id'
        );
        $selectStatement->execute(['user_id' => $user->id]);

        return array_map(
            static fn (array $membershipRow): WorkspaceMembership => new WorkspaceMembership(
                workspaceId: (string) $membershipRow['id'],
                workspaceName: (string) $membershipRow['name'],
                workspaceKind: (string) $membershipRow['kind'],
                userId: $user->id,
                role: WorkspaceRole::from((string) $membershipRow['role']),
            ),
            $selectStatement->fetchAll(),
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

    /**
     * Lets a system administrator manage the MEMBERS of:
     * - an orphaned shared workspace, one with no active Owner left (D050), e.g. after its only
     *   Owner was disabled. Workspaces that still have an active Owner are managed by their Owners
     *   only, so an administrator cannot add an account of their own to them;
     * - an open Shared Workspace (D067), which never has an Owner: the administrator always
     *   manages its members.
     * It returns a membership-shaped value with Owner rights over members, for use with the
     * WorkspaceService member methods only. It grants no note access: note, search and
     * attachment endpoints go through findMembership(), which never returns anything for an
     * administrator (decision D025). Personal workspaces are never manageable this way.
     *
     * @throws HttpException 404 when the caller is not an active administrator, or the
     *                       workspace does not exist, is personal, or is shared with an active Owner.
     */
    public function requireAdministratorMemberManagement(User $administrator, string $workspaceId): WorkspaceMembership
    {
        if (!$administrator->isSystemAdmin || !$administrator->isActive() || !UuidGenerator::isValid($workspaceId)) {
            throw HttpException::notFound();
        }

        $selectStatement = $this->database->prepare(
            'SELECT workspaces.id, workspaces.name, workspaces.kind FROM workspaces
             WHERE workspaces.id = :id
               AND (
                   workspaces.kind = :open_kind
                   OR (
                       workspaces.kind = :shared_kind
                       AND NOT EXISTS (
                           SELECT 1 FROM workspace_members
                           JOIN users ON users.id = workspace_members.user_id
                           WHERE workspace_members.workspace_id = workspaces.id
                             AND workspace_members.role = \'owner\' AND users.status = \'active\'
                       )
                   )
               )'
        );
        $selectStatement->execute([
            'id' => $workspaceId,
            'open_kind' => WorkspaceMembership::KIND_OPEN,
            'shared_kind' => WorkspaceMembership::KIND_SHARED,
        ]);
        $workspaceRow = $selectStatement->fetch();
        if ($workspaceRow === false) {
            throw HttpException::notFound();
        }

        return new WorkspaceMembership(
            workspaceId: (string) $workspaceRow['id'],
            workspaceName: (string) $workspaceRow['name'],
            workspaceKind: (string) $workspaceRow['kind'],
            userId: $administrator->id,
            role: WorkspaceRole::Owner,
        );
    }

    /** The 403 used whenever a member's role is too weak for an action. */
    public static function insufficientRole(): HttpException
    {
        return HttpException::forbidden('insufficient_role', 'Your role in this workspace does not allow this.');
    }
}
