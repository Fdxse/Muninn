<?php

declare(strict_types=1);

namespace Muninn\Api\Workspaces;

use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Users\User;
use Muninn\Api\Users\UserRepository;
use PDO;
use PDOException;
use Throwable;

/**
 * Workspace and membership data operations.
 *
 * This class does NOT decide whether the caller may act: controllers first obtain a
 * WorkspaceMembership from WorkspaceAuthorizer and pass it in. The member-management rules
 * that depend on the target member (which roles an Admin may touch, keeping one Owner) are
 * checked here, inside a transaction that locks the workspace row, so two simultaneous
 * requests can never remove the last Owner.
 */
final class WorkspaceService
{
    /** Name given to every user's automatically created personal workspace. */
    public const PERSONAL_WORKSPACE_NAME = 'Personal';

    /** MySQL/MariaDB SQLSTATE for duplicate keys and foreign key violations. */
    private const SQLSTATE_INTEGRITY_VIOLATION = '23000';

    public function __construct(
        private readonly PDO $database,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * Makes sure an everyday user has their personal workspace (Course MVP item 2). Safe to call
     * repeatedly and concurrently: the unique key on personal_owner_user_id lets only one win.
     * System administrators never get one (decision D025).
     */
    public function ensurePersonalWorkspace(User $user): void
    {
        if ($user->isSystemAdmin || !$user->isActive()) {
            return;
        }

        $existsStatement = $this->database->prepare('SELECT 1 FROM workspaces WHERE personal_owner_user_id = :user_id');
        $existsStatement->execute(['user_id' => $user->id]);
        if ($existsStatement->fetchColumn() !== false) {
            return;
        }

        try {
            $this->createWorkspaceWithOwner(self::PERSONAL_WORKSPACE_NAME, WorkspaceMembership::KIND_PERSONAL, $user->id);
        } catch (PDOException $insertException) {
            // Another request created it a moment ago; that is exactly what we wanted.
            if ($insertException->getCode() !== self::SQLSTATE_INTEGRITY_VIOLATION) {
                throw $insertException;
            }
        }
    }

    /**
     * Lists the workspaces the user is a member of, personal workspace first.
     *
     * @return list<array<string, mixed>>
     */
    public function listForUser(User $user): array
    {
        if ($user->isSystemAdmin) {
            return [];
        }
        $this->ensurePersonalWorkspace($user);

        $selectStatement = $this->database->prepare(
            'SELECT workspaces.id, workspaces.name, workspaces.kind, workspaces.created_at, workspace_members.role
             FROM workspace_members
             JOIN workspaces ON workspaces.id = workspace_members.workspace_id
             WHERE workspace_members.user_id = :user_id
             ORDER BY workspaces.kind = \'personal\' DESC, workspaces.name, workspaces.id'
        );
        $selectStatement->execute(['user_id' => $user->id]);

        return array_map(self::toPublicArray(...), $selectStatement->fetchAll());
    }

    /**
     * Returns one workspace as seen by a member.
     *
     * @return array<string, mixed>
     */
    public function findForMember(WorkspaceMembership $membership): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT workspaces.id, workspaces.name, workspaces.kind, workspaces.created_at, workspace_members.role
             FROM workspace_members
             JOIN workspaces ON workspaces.id = workspace_members.workspace_id
             WHERE workspace_members.workspace_id = :workspace_id AND workspace_members.user_id = :user_id'
        );
        $selectStatement->execute(['workspace_id' => $membership->workspaceId, 'user_id' => $membership->userId]);
        $workspaceRow = $selectStatement->fetch();
        if ($workspaceRow === false) {
            // The membership disappeared between the authorization check and this read.
            throw HttpException::notFound();
        }

        return self::toPublicArray($workspaceRow);
    }

    /**
     * Every shared workspace for the system administrator (D050): names, member counts and Owners
     * only. No note titles, note counts or other content metadata (D025). Only the ones without an
     * active Owner can be managed (see WorkspaceAuthorizer::requireAdministratorMemberManagement).
     *
     * @return list<array<string, mixed>>
     */
    public function listSharedForAdministrator(): array
    {
        $workspaceRows = $this->database->query(
            'SELECT workspaces.id, workspaces.name, workspaces.created_at,
                    COUNT(workspace_members.user_id) AS member_count,
                    SUM(workspace_members.role = \'owner\' AND users.status = \'active\') AS active_owner_count,
                    GROUP_CONCAT(CASE WHEN workspace_members.role = \'owner\' THEN users.username END
                                 ORDER BY users.username SEPARATOR \',\') AS owner_usernames
             FROM workspaces
             LEFT JOIN workspace_members ON workspace_members.workspace_id = workspaces.id
             LEFT JOIN users ON users.id = workspace_members.user_id
             WHERE workspaces.kind = \'shared\'
             GROUP BY workspaces.id, workspaces.name, workspaces.created_at
             ORDER BY workspaces.name, workspaces.id
             LIMIT 500'
        )->fetchAll();

        return array_map(static fn (array $workspaceRow): array => [
            'id' => $workspaceRow['id'],
            'name' => $workspaceRow['name'],
            'member_count' => (int) $workspaceRow['member_count'],
            'owners' => $workspaceRow['owner_usernames'] === null ? [] : explode(',', (string) $workspaceRow['owner_usernames']),
            // Zero means only an administrator can manage the members now.
            'active_owner_count' => (int) $workspaceRow['active_owner_count'],
            'created_at' => UtcTimestamp::toIso((string) $workspaceRow['created_at']),
        ], $workspaceRows);
    }

    /** Creates a shared workspace with the creator as its Owner (decision D030) and returns its ID. */
    public function createShared(User $creator, string $workspaceName): string
    {
        return $this->createWorkspaceWithOwner($workspaceName, WorkspaceMembership::KIND_SHARED, $creator->id);
    }

    /** Renames a workspace. The caller must hold WorkspacePermission::ManageWorkspace. */
    public function rename(WorkspaceMembership $ownerMembership, string $newName): void
    {
        $renameStatement = $this->database->prepare(
            'UPDATE workspaces SET name = :name, updated_at = UTC_TIMESTAMP() WHERE id = :id'
        );
        $renameStatement->execute(['name' => $newName, 'id' => $ownerMembership->workspaceId]);
    }

    /**
     * Deletes an empty shared workspace. The caller must hold WorkspacePermission::ManageWorkspace.
     *
     * A workspace that still holds notes (trashed ones included) is refused with 409, so deleting
     * a workspace can never delete notes: they must first be trashed and deleted from Trash for good.
     */
    public function deleteEmptyShared(WorkspaceMembership $ownerMembership): void
    {
        if ($ownerMembership->isPersonal()) {
            throw HttpException::conflict('personal_workspace', 'Your personal workspace cannot be deleted.');
        }

        $this->inTransaction(function () use ($ownerMembership): void {
            $this->lockWorkspace($ownerMembership->workspaceId);

            $noteCountStatement = $this->database->prepare('SELECT COUNT(*) FROM notes WHERE workspace_id = :workspace_id');
            $noteCountStatement->execute(['workspace_id' => $ownerMembership->workspaceId]);
            if ((int) $noteCountStatement->fetchColumn() > 0) {
                throw HttpException::conflict('workspace_not_empty', 'Delete every note and empty the Trash before deleting the workspace.');
            }

            // Memberships go with it (ON DELETE CASCADE).
            $deleteStatement = $this->database->prepare('DELETE FROM workspaces WHERE id = :id');
            $deleteStatement->execute(['id' => $ownerMembership->workspaceId]);
        });
    }

    /**
     * Lists a workspace's members. Visible to every member, since they share its notes.
     *
     * @return list<array<string, mixed>>
     */
    public function listMembers(WorkspaceMembership $readerMembership): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT users.id, users.username, users.display_name, users.status, workspace_members.role, workspace_members.created_at
             FROM workspace_members
             JOIN users ON users.id = workspace_members.user_id
             WHERE workspace_members.workspace_id = :workspace_id
             ORDER BY FIELD(workspace_members.role, \'owner\', \'admin\', \'editor\', \'reader\'), users.display_name, users.id'
        );
        $selectStatement->execute(['workspace_id' => $readerMembership->workspaceId]);

        return array_map(self::memberToPublicArray(...), $selectStatement->fetchAll());
    }

    /**
     * Adds an existing active user to a shared workspace.
     *
     * @return array<string, mixed> The new member.
     * @throws HttpException 403 for a role the actor may not hand out, 409 for personal workspaces
     *                       and existing members, 422 when no such active user exists.
     */
    public function addMember(WorkspaceMembership $actorMembership, string $username, WorkspaceRole $newRole): array
    {
        if ($actorMembership->isPersonal()) {
            throw HttpException::conflict('personal_workspace', 'Personal workspaces cannot be shared. Create a shared workspace instead.');
        }
        if (!$actorMembership->role->canAssign($newRole)) {
            throw WorkspaceAuthorizer::insufficientRole();
        }

        // Unknown, disabled and administrator accounts get the same answer.
        $newMemberUser = $this->userRepository->findByUsername($username);
        if ($newMemberUser === null || !$newMemberUser->isActive() || $newMemberUser->isSystemAdmin) {
            throw HttpException::validation(['username' => 'There is no active user with that username.']);
        }

        $this->inTransaction(function () use ($actorMembership, $newMemberUser, $newRole): void {
            $this->lockWorkspace($actorMembership->workspaceId);
            if ($this->memberRole($actorMembership->workspaceId, $newMemberUser->id) !== null) {
                throw HttpException::conflict('already_member', 'That user is already a member of this workspace.');
            }
            $this->insertMember($actorMembership->workspaceId, $newMemberUser->id, $newRole);
        });

        return $this->findMember($actorMembership->workspaceId, $newMemberUser->id);
    }

    /**
     * Changes a member's role.
     *
     * @return array<string, mixed> The updated member.
     * @throws HttpException 404 for non-members, 403 when the actor may not manage that member or
     *                       hand out that role, 409 when it would leave the workspace without an Owner.
     */
    public function changeMemberRole(WorkspaceMembership $actorMembership, string $memberUserId, WorkspaceRole $newRole): array
    {
        if (!UuidGenerator::isValid($memberUserId)) {
            throw HttpException::notFound();
        }

        $this->inTransaction(function () use ($actorMembership, $memberUserId, $newRole): void {
            $this->lockWorkspace($actorMembership->workspaceId);
            $currentRole = $this->memberRole($actorMembership->workspaceId, $memberUserId);
            if ($currentRole === null) {
                throw HttpException::notFound();
            }
            if (!$actorMembership->role->canManageMember($currentRole) || !$actorMembership->role->canAssign($newRole)) {
                throw WorkspaceAuthorizer::insufficientRole();
            }
            if ($currentRole === WorkspaceRole::Owner && $newRole !== WorkspaceRole::Owner) {
                $this->assertAnotherOwnerRemains($actorMembership->workspaceId);
            }

            $updateStatement = $this->database->prepare(
                'UPDATE workspace_members SET role = :role, updated_at = UTC_TIMESTAMP()
                 WHERE workspace_id = :workspace_id AND user_id = :user_id'
            );
            $updateStatement->execute([
                'role' => $newRole->value,
                'workspace_id' => $actorMembership->workspaceId,
                'user_id' => $memberUserId,
            ]);
        });

        return $this->findMember($actorMembership->workspaceId, $memberUserId);
    }

    /**
     * Removes a member, or lets a member leave (when $memberUserId is the actor themselves).
     *
     * @return WorkspaceRole The role the removed member had (for the audit log).
     * @throws HttpException 404 for non-members, 403 when the actor may not remove that member,
     *                       409 for the last Owner.
     */
    public function removeMember(WorkspaceMembership $actorMembership, string $memberUserId): WorkspaceRole
    {
        if (!UuidGenerator::isValid($memberUserId)) {
            throw HttpException::notFound();
        }

        return $this->inTransaction(function () use ($actorMembership, $memberUserId): WorkspaceRole {
            $this->lockWorkspace($actorMembership->workspaceId);
            $currentRole = $this->memberRole($actorMembership->workspaceId, $memberUserId);
            if ($currentRole === null) {
                throw HttpException::notFound();
            }

            // Anyone may leave; removing someone else needs the right to manage their role.
            $isLeaving = $memberUserId === $actorMembership->userId;
            if (!$isLeaving && !$actorMembership->role->canManageMember($currentRole)) {
                throw WorkspaceAuthorizer::insufficientRole();
            }
            if ($currentRole === WorkspaceRole::Owner) {
                $this->assertAnotherOwnerRemains($actorMembership->workspaceId);
            }

            $deleteStatement = $this->database->prepare(
                'DELETE FROM workspace_members WHERE workspace_id = :workspace_id AND user_id = :user_id'
            );
            $deleteStatement->execute(['workspace_id' => $actorMembership->workspaceId, 'user_id' => $memberUserId]);

            return $currentRole;
        });
    }

    /** Creates a workspace and its Owner membership atomically, returning the workspace ID. */
    private function createWorkspaceWithOwner(string $workspaceName, string $workspaceKind, string $ownerUserId): string
    {
        $newWorkspaceId = UuidGenerator::generate();

        $this->inTransaction(function () use ($newWorkspaceId, $workspaceName, $workspaceKind, $ownerUserId): void {
            $insertStatement = $this->database->prepare(
                'INSERT INTO workspaces (id, name, kind, personal_owner_user_id, created_by_user_id, created_at, updated_at)
                 VALUES (:id, :name, :kind, :personal_owner_user_id, :created_by_user_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $insertStatement->execute([
                'id' => $newWorkspaceId,
                'name' => $workspaceName,
                'kind' => $workspaceKind,
                'personal_owner_user_id' => $workspaceKind === WorkspaceMembership::KIND_PERSONAL ? $ownerUserId : null,
                'created_by_user_id' => $ownerUserId,
            ]);
            $this->insertMember($newWorkspaceId, $ownerUserId, WorkspaceRole::Owner);
        });

        return $newWorkspaceId;
    }

    private function insertMember(string $workspaceId, string $userId, WorkspaceRole $role): void
    {
        $insertStatement = $this->database->prepare(
            'INSERT INTO workspace_members (workspace_id, user_id, role, created_at, updated_at)
             VALUES (:workspace_id, :user_id, :role, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $insertStatement->execute(['workspace_id' => $workspaceId, 'user_id' => $userId, 'role' => $role->value]);
    }

    /** Returns a member's current role, or null when the user is not a member. */
    private function memberRole(string $workspaceId, string $userId): ?WorkspaceRole
    {
        $selectStatement = $this->database->prepare(
            'SELECT role FROM workspace_members WHERE workspace_id = :workspace_id AND user_id = :user_id'
        );
        $selectStatement->execute(['workspace_id' => $workspaceId, 'user_id' => $userId]);
        $roleValue = $selectStatement->fetchColumn();

        return $roleValue === false ? null : WorkspaceRole::from((string) $roleValue);
    }

    /** @return array<string, mixed> */
    private function findMember(string $workspaceId, string $userId): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT users.id, users.username, users.display_name, users.status, workspace_members.role, workspace_members.created_at
             FROM workspace_members
             JOIN users ON users.id = workspace_members.user_id
             WHERE workspace_members.workspace_id = :workspace_id AND workspace_members.user_id = :user_id'
        );
        $selectStatement->execute(['workspace_id' => $workspaceId, 'user_id' => $userId]);
        $memberRow = $selectStatement->fetch();
        if ($memberRow === false) {
            throw HttpException::notFound();
        }

        return self::memberToPublicArray($memberRow);
    }

    /** @throws HttpException 409 when the workspace has only one Owner left. */
    private function assertAnotherOwnerRemains(string $workspaceId): void
    {
        $ownerCountStatement = $this->database->prepare(
            'SELECT COUNT(*) FROM workspace_members WHERE workspace_id = :workspace_id AND role = \'owner\''
        );
        $ownerCountStatement->execute(['workspace_id' => $workspaceId]);
        if ((int) $ownerCountStatement->fetchColumn() <= 1) {
            throw HttpException::conflict('last_owner', 'A workspace must keep at least one Owner. Make someone else Owner first.');
        }
    }

    /** Locks the workspace row until the transaction ends, serialising membership changes. */
    private function lockWorkspace(string $workspaceId): void
    {
        $lockStatement = $this->database->prepare('SELECT id FROM workspaces WHERE id = :id FOR UPDATE');
        $lockStatement->execute(['id' => $workspaceId]);
        if ($lockStatement->fetchColumn() === false) {
            throw HttpException::notFound();
        }
    }

    /**
     * Runs $work in a transaction, rolling back on any failure.
     *
     * @template TResult
     * @param callable(): TResult $work
     * @return TResult
     */
    private function inTransaction(callable $work): mixed
    {
        $this->database->beginTransaction();
        try {
            $workResult = $work();
            $this->database->commit();
        } catch (Throwable $workFailure) {
            $this->database->rollBack();
            throw $workFailure;
        }

        return $workResult;
    }

    /**
     * @param array<string, mixed> $workspaceRow
     * @return array<string, mixed>
     */
    private static function toPublicArray(array $workspaceRow): array
    {
        $callerRole = WorkspaceRole::from((string) $workspaceRow['role']);

        return [
            'id' => $workspaceRow['id'],
            'name' => $workspaceRow['name'],
            'kind' => $workspaceRow['kind'],
            'your_role' => $callerRole->value,
            // Convenience flags for the UI. The API enforces the same rules on every request.
            'permissions' => [
                'write_notes' => $callerRole->allows(WorkspacePermission::WriteNotes),
                'purge_notes' => $callerRole->allows(WorkspacePermission::PurgeNotes),
                'manage_members' => $callerRole->allows(WorkspacePermission::ManageMembers) && $workspaceRow['kind'] === WorkspaceMembership::KIND_SHARED,
                'manage_workspace' => $callerRole->allows(WorkspacePermission::ManageWorkspace),
            ],
            'created_at' => UtcTimestamp::toIso((string) $workspaceRow['created_at']),
        ];
    }

    /**
     * @param array<string, mixed> $memberRow
     * @return array<string, mixed>
     */
    private static function memberToPublicArray(array $memberRow): array
    {
        return [
            'user_id' => $memberRow['id'],
            'username' => $memberRow['username'],
            'display_name' => $memberRow['display_name'],
            'role' => $memberRow['role'],
            'is_disabled' => $memberRow['status'] !== 'active',
            'member_since' => UtcTimestamp::toIso((string) $memberRow['created_at']),
        ];
    }
}
