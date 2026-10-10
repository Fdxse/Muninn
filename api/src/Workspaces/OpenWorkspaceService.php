<?php

declare(strict_types=1);

namespace Muninn\Api\Workspaces;

use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Users\User;
use PDO;
use Throwable;

/**
 * Shared Workspaces that users ask to join (decision D067), stored as workspaces of kind 'open'.
 *
 * - A system administrator creates them (name and short description), renames them, deletes
 *   empty ones and manages their members (through WorkspaceAuthorizer::
 *   requireAdministratorMemberManagement and the ordinary WorkspaceService member methods).
 * - Every everyday user sees their names and descriptions, and asks to join with an optional note.
 * - The administrator approves a request (the user becomes an Editor or Reader) or declines it.
 *
 * Nothing here ever reads notes: administrators see names, descriptions, members and requests,
 * never note titles, counts or content (D025). Like WorkspaceService, this class does not decide
 * who may call it; the controllers do (everyday user versus system administrator).
 */
final class OpenWorkspaceService
{
    /** Join requests one user may send per 24 hours, so nobody can flood the administrator's phone. */
    public const MAX_JOIN_REQUESTS_PER_DAY = 10;

    /** Upper bound for every list, as elsewhere in the API. */
    private const LIST_LIMIT = 500;

    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * Every open Shared Workspace as seen by one everyday user: name, description, whether they
     * are a member (and with which role), and their newest join request, if any. No member counts
     * or member names: those are for members only.
     *
     * @return list<array<string, mixed>>
     */
    public function listForUser(User $user): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT workspaces.id, workspaces.name, workspaces.description, workspace_members.role AS your_role,
                    newest_request.id AS request_id, newest_request.status AS request_status,
                    newest_request.created_at AS request_created_at, newest_request.decided_at AS request_decided_at
             FROM workspaces
             LEFT JOIN workspace_members
                    ON workspace_members.workspace_id = workspaces.id AND workspace_members.user_id = :member_user_id
             LEFT JOIN workspace_join_requests AS newest_request
                    ON newest_request.id = (
                        SELECT latest.id FROM workspace_join_requests AS latest
                        WHERE latest.workspace_id = workspaces.id AND latest.user_id = :request_user_id
                        ORDER BY latest.sequence_number DESC
                        LIMIT 1
                    )
             WHERE workspaces.kind = :kind
             ORDER BY workspaces.name, workspaces.id
             LIMIT ' . self::LIST_LIMIT
        );
        $selectStatement->execute([
            'member_user_id' => $user->id,
            'request_user_id' => $user->id,
            'kind' => WorkspaceMembership::KIND_OPEN,
        ]);

        return array_map(static function (array $workspaceRow): array {
            $hasRequest = $workspaceRow['request_id'] !== null;

            return [
                'id' => $workspaceRow['id'],
                'name' => $workspaceRow['name'],
                'description' => $workspaceRow['description'],
                'is_member' => $workspaceRow['your_role'] !== null,
                'your_role' => $workspaceRow['your_role'],
                // The user's newest request: shows "Waiting for the administrator" or "Declined".
                'join_request' => $hasRequest ? [
                    'id' => $workspaceRow['request_id'],
                    'status' => $workspaceRow['request_status'],
                    'created_at' => UtcTimestamp::toIso((string) $workspaceRow['request_created_at']),
                    'decided_at' => $workspaceRow['request_decided_at'] === null ? null : UtcTimestamp::toIso((string) $workspaceRow['request_decided_at']),
                ] : null,
            ];
        }, $selectStatement->fetchAll());
    }

    /**
     * Files a pending join request.
     *
     * @return array{request_id: string, workspace_name: string}
     * @throws HttpException 404 when there is no such open workspace, 409 when the user is already
     *                       a member or already has a pending request, 429 over the daily limit.
     */
    public function requestToJoin(User $user, string $workspaceId, string $note): array
    {
        if (!UuidGenerator::isValid($workspaceId)) {
            throw HttpException::notFound();
        }

        return $this->inTransaction(function () use ($user, $workspaceId, $note): array {
            // Locking the workspace row serialises two simultaneous requests from the same user.
            $workspaceName = $this->lockOpenWorkspace($workspaceId);

            if ($this->isMember($workspaceId, $user->id)) {
                throw HttpException::conflict('already_member', 'You are already a member of this workspace.');
            }
            if ($this->findPendingRequestId($workspaceId, $user->id) !== null) {
                throw HttpException::conflict('request_pending', 'You have already asked to join. The administrator will decide.');
            }

            $recentCountStatement = $this->database->prepare(
                'SELECT COUNT(*) FROM workspace_join_requests
                 WHERE user_id = :user_id AND created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY'
            );
            $recentCountStatement->execute(['user_id' => $user->id]);
            if ((int) $recentCountStatement->fetchColumn() >= self::MAX_JOIN_REQUESTS_PER_DAY) {
                throw HttpException::tooManyRequests(3600);
            }

            $requestId = UuidGenerator::generate();
            $insertStatement = $this->database->prepare(
                'INSERT INTO workspace_join_requests (id, workspace_id, user_id, note, status, created_at)
                 VALUES (:id, :workspace_id, :user_id, :note, \'pending\', UTC_TIMESTAMP())'
            );
            $insertStatement->execute([
                'id' => $requestId,
                'workspace_id' => $workspaceId,
                'user_id' => $user->id,
                'note' => $note,
            ]);

            return ['request_id' => $requestId, 'workspace_name' => $workspaceName];
        });
    }

    /**
     * Cancels the user's pending request for this workspace and returns its ID.
     *
     * @throws HttpException 404 when the user has no pending request for it.
     */
    public function cancelOwnRequest(User $user, string $workspaceId): string
    {
        if (!UuidGenerator::isValid($workspaceId)) {
            throw HttpException::notFound();
        }

        return $this->inTransaction(function () use ($user, $workspaceId): string {
            $this->lockOpenWorkspace($workspaceId);
            $pendingRequestId = $this->findPendingRequestId($workspaceId, $user->id);
            if ($pendingRequestId === null) {
                throw HttpException::notFound();
            }

            $cancelStatement = $this->database->prepare(
                'UPDATE workspace_join_requests SET status = \'cancelled\', decided_at = UTC_TIMESTAMP() WHERE id = :id'
            );
            $cancelStatement->execute(['id' => $pendingRequestId]);

            return $pendingRequestId;
        });
    }

    /**
     * Every open Shared Workspace for the system administrator: name, description, member count
     * and waiting requests. Never anything about notes (D025).
     *
     * @return list<array<string, mixed>>
     */
    public function listForAdministrator(): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT workspaces.id, workspaces.name, workspaces.description, workspaces.created_at,
                    (SELECT COUNT(*) FROM workspace_members WHERE workspace_members.workspace_id = workspaces.id) AS member_count,
                    (SELECT COUNT(*) FROM workspace_join_requests
                     WHERE workspace_join_requests.workspace_id = workspaces.id
                       AND workspace_join_requests.status = \'pending\') AS pending_request_count
             FROM workspaces
             WHERE workspaces.kind = :kind
             ORDER BY workspaces.name, workspaces.id
             LIMIT ' . self::LIST_LIMIT
        );
        $selectStatement->execute(['kind' => WorkspaceMembership::KIND_OPEN]);

        return array_map(static fn (array $workspaceRow): array => [
            'id' => $workspaceRow['id'],
            'name' => $workspaceRow['name'],
            'description' => $workspaceRow['description'],
            'member_count' => (int) $workspaceRow['member_count'],
            'pending_request_count' => (int) $workspaceRow['pending_request_count'],
            'created_at' => UtcTimestamp::toIso((string) $workspaceRow['created_at']),
        ], $selectStatement->fetchAll());
    }

    /**
     * One open workspace for the administrator, or 404.
     *
     * @return array<string, mixed>
     */
    public function findForAdministrator(string $workspaceId): array
    {
        foreach ($this->listForAdministrator() as $openWorkspace) {
            if ($openWorkspace['id'] === $workspaceId) {
                return $openWorkspace;
            }
        }

        throw HttpException::notFound();
    }

    /**
     * Creates an open Shared Workspace with no members and returns its ID. The administrator is
     * recorded as its creator only; administrators never become members (D044).
     */
    public function create(User $administrator, string $workspaceName, ?string $description): string
    {
        $newWorkspaceId = UuidGenerator::generate();
        $insertStatement = $this->database->prepare(
            'INSERT INTO workspaces (id, name, description, kind, personal_owner_user_id, created_by_user_id, created_at, updated_at)
             VALUES (:id, :name, :description, :kind, NULL, :created_by_user_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $insertStatement->execute([
            'id' => $newWorkspaceId,
            'name' => $workspaceName,
            'description' => $description,
            'kind' => WorkspaceMembership::KIND_OPEN,
            'created_by_user_id' => $administrator->id,
        ]);

        return $newWorkspaceId;
    }

    /**
     * Changes an open workspace's name and description.
     *
     * @throws HttpException 404 when there is no such open workspace.
     */
    public function update(string $workspaceId, string $workspaceName, ?string $description): void
    {
        if (!UuidGenerator::isValid($workspaceId)) {
            throw HttpException::notFound();
        }

        $this->inTransaction(function () use ($workspaceId, $workspaceName, $description): void {
            $this->lockOpenWorkspace($workspaceId);
            $updateStatement = $this->database->prepare(
                'UPDATE workspaces SET name = :name, description = :description, updated_at = UTC_TIMESTAMP() WHERE id = :id'
            );
            $updateStatement->execute(['name' => $workspaceName, 'description' => $description, 'id' => $workspaceId]);
        });
    }

    /**
     * Deletes an open workspace that holds no notes (Trash included), like an Owner's delete
     * (D045).
     *
     * @return string The deleted workspace's name (for the audit log).
     * @throws HttpException 404 when there is no such open workspace, 409 while it holds notes.
     */
    public function deleteEmpty(string $workspaceId): string
    {
        if (!UuidGenerator::isValid($workspaceId)) {
            throw HttpException::notFound();
        }

        return $this->inTransaction(function () use ($workspaceId): string {
            $workspaceName = $this->lockOpenWorkspace($workspaceId);

            // Only whether ANY note exists is checked; the administrator learns no count or title.
            $anyNoteStatement = $this->database->prepare('SELECT 1 FROM notes WHERE workspace_id = :workspace_id LIMIT 1');
            $anyNoteStatement->execute(['workspace_id' => $workspaceId]);
            if ($anyNoteStatement->fetchColumn() !== false) {
                throw HttpException::conflict('workspace_not_empty', 'The workspace still holds notes. Its members must delete them and empty the Trash first.');
            }

            // Members, join requests, folders, tags and chat messages go with it (ON DELETE CASCADE).
            $deleteStatement = $this->database->prepare('DELETE FROM workspaces WHERE id = :id');
            $deleteStatement->execute(['id' => $workspaceId]);

            return $workspaceName;
        });
    }

    /**
     * Every pending join request, oldest first, for the administrator.
     *
     * @return list<array<string, mixed>>
     */
    public function listPendingRequests(): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT workspace_join_requests.id, workspace_join_requests.note, workspace_join_requests.created_at,
                    workspaces.id AS workspace_id, workspaces.name AS workspace_name,
                    users.id AS user_id, users.username, users.display_name, users.status AS user_status
             FROM workspace_join_requests
             JOIN workspaces ON workspaces.id = workspace_join_requests.workspace_id
             JOIN users ON users.id = workspace_join_requests.user_id
             WHERE workspace_join_requests.status = \'pending\'
             ORDER BY workspace_join_requests.created_at, workspace_join_requests.id
             LIMIT ' . self::LIST_LIMIT
        );
        $selectStatement->execute();

        return array_map(static fn (array $requestRow): array => [
            'id' => $requestRow['id'],
            'note' => $requestRow['note'],
            'created_at' => UtcTimestamp::toIso((string) $requestRow['created_at']),
            'workspace' => ['id' => $requestRow['workspace_id'], 'name' => $requestRow['workspace_name']],
            'user' => [
                'id' => $requestRow['user_id'],
                'username' => $requestRow['username'],
                'display_name' => $requestRow['display_name'],
                'is_disabled' => $requestRow['user_status'] !== 'active',
            ],
        ], $selectStatement->fetchAll());
    }

    /** Number of join requests waiting for a decision (navigation badge and D066 shield). */
    public function countPending(): int
    {
        return (int) $this->database->query(
            'SELECT COUNT(*) FROM workspace_join_requests WHERE status = \'pending\''
        )->fetchColumn();
    }

    /**
     * Approves a pending request: the user becomes a member with $role (Editor or Reader).
     *
     * @return array{workspace_id: string, user_id: string, added: bool} added is false when the
     *         user had meanwhile become a member some other way (the request is still approved).
     * @throws HttpException 404 for unknown or no longer pending requests, 409 when the user's
     *                       account is disabled, 422 for a role other than Editor or Reader.
     */
    public function approve(string $requestId, User $administrator, WorkspaceRole $role): array
    {
        if (!WorkspaceRole::isOpenWorkspaceRole($role)) {
            throw HttpException::validation(['role' => 'Members of a Shared Workspace are Editors or Readers.']);
        }

        return $this->inTransaction(function () use ($requestId, $administrator, $role): array {
            $pendingRequest = $this->lockPendingRequest($requestId);
            $userStatusStatement = $this->database->prepare('SELECT status, is_system_admin FROM users WHERE id = :id');
            $userStatusStatement->execute(['id' => $pendingRequest['user_id']]);
            $userRow = $userStatusStatement->fetch();
            if ($userRow === false || $userRow['status'] !== 'active' || (int) $userRow['is_system_admin'] === 1) {
                throw HttpException::conflict('user_not_active', 'This account is disabled. Decline the request, or enable the account first.');
            }

            $wasAdded = false;
            if (!$this->isMember($pendingRequest['workspace_id'], $pendingRequest['user_id'])) {
                $insertStatement = $this->database->prepare(
                    'INSERT INTO workspace_members (workspace_id, user_id, role, created_at, updated_at)
                     VALUES (:workspace_id, :user_id, :role, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
                );
                $insertStatement->execute([
                    'workspace_id' => $pendingRequest['workspace_id'],
                    'user_id' => $pendingRequest['user_id'],
                    'role' => $role->value,
                ]);
                $wasAdded = true;
            }
            $this->recordDecision($requestId, 'approved', $administrator->id);

            return [
                'workspace_id' => $pendingRequest['workspace_id'],
                'user_id' => $pendingRequest['user_id'],
                'added' => $wasAdded,
            ];
        });
    }

    /**
     * Declines a pending request. The user may ask again later.
     *
     * @return array{workspace_id: string, user_id: string}
     * @throws HttpException 404 for unknown or no longer pending requests.
     */
    public function decline(string $requestId, User $administrator): array
    {
        return $this->inTransaction(function () use ($requestId, $administrator): array {
            $pendingRequest = $this->lockPendingRequest($requestId);
            $this->recordDecision($requestId, 'declined', $administrator->id);

            return ['workspace_id' => $pendingRequest['workspace_id'], 'user_id' => $pendingRequest['user_id']];
        });
    }

    /**
     * Locks an open workspace's row until the transaction ends and returns its name.
     *
     * @throws HttpException 404 when there is no open workspace with this ID.
     */
    private function lockOpenWorkspace(string $workspaceId): string
    {
        $lockStatement = $this->database->prepare('SELECT name FROM workspaces WHERE id = :id AND kind = :kind FOR UPDATE');
        $lockStatement->execute(['id' => $workspaceId, 'kind' => WorkspaceMembership::KIND_OPEN]);
        $workspaceName = $lockStatement->fetchColumn();
        if ($workspaceName === false) {
            throw HttpException::notFound();
        }

        return (string) $workspaceName;
    }

    /**
     * Locks the workspace, then the request, and returns the request while it is pending.
     *
     * @return array{workspace_id: string, user_id: string}
     * @throws HttpException 404 for unknown or no longer pending requests.
     */
    private function lockPendingRequest(string $requestId): array
    {
        if (!UuidGenerator::isValid($requestId)) {
            throw HttpException::notFound();
        }

        $findStatement = $this->database->prepare('SELECT workspace_id FROM workspace_join_requests WHERE id = :id');
        $findStatement->execute(['id' => $requestId]);
        $workspaceId = $findStatement->fetchColumn();
        if ($workspaceId === false) {
            throw HttpException::notFound();
        }
        // Same lock order as requestToJoin() and cancelOwnRequest(): workspace first.
        $this->lockOpenWorkspace((string) $workspaceId);

        $requestStatement = $this->database->prepare(
            'SELECT workspace_id, user_id FROM workspace_join_requests WHERE id = :id AND status = \'pending\' FOR UPDATE'
        );
        $requestStatement->execute(['id' => $requestId]);
        $requestRow = $requestStatement->fetch();
        if ($requestRow === false) {
            // Already decided or cancelled, perhaps by another administrator a moment ago.
            throw HttpException::notFound('request_not_pending', 'This request has already been decided or cancelled.');
        }

        return ['workspace_id' => (string) $requestRow['workspace_id'], 'user_id' => (string) $requestRow['user_id']];
    }

    private function recordDecision(string $requestId, string $newStatus, string $administratorUserId): void
    {
        $updateStatement = $this->database->prepare(
            'UPDATE workspace_join_requests
             SET status = :status, decided_by_user_id = :decided_by_user_id, decided_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $updateStatement->execute(['status' => $newStatus, 'decided_by_user_id' => $administratorUserId, 'id' => $requestId]);
    }

    private function isMember(string $workspaceId, string $userId): bool
    {
        $memberStatement = $this->database->prepare(
            'SELECT 1 FROM workspace_members WHERE workspace_id = :workspace_id AND user_id = :user_id'
        );
        $memberStatement->execute(['workspace_id' => $workspaceId, 'user_id' => $userId]);

        return $memberStatement->fetchColumn() !== false;
    }

    private function findPendingRequestId(string $workspaceId, string $userId): ?string
    {
        $pendingStatement = $this->database->prepare(
            'SELECT id FROM workspace_join_requests
             WHERE workspace_id = :workspace_id AND user_id = :user_id AND status = \'pending\'
             LIMIT 1'
        );
        $pendingStatement->execute(['workspace_id' => $workspaceId, 'user_id' => $userId]);
        $pendingRequestId = $pendingStatement->fetchColumn();

        return $pendingRequestId === false ? null : (string) $pendingRequestId;
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
}
