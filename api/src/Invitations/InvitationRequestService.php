<?php

declare(strict_types=1);

namespace Muninn\Api\Invitations;

use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\UuidGenerator;
use PDO;
use Throwable;

/**
 * Invitation requests (decision D049): a user asks for someone to be invited, a system
 * administrator approves or declines, and after approval the requesting user creates the
 * invitation link and sends it themselves.
 *
 * The link is an ordinary invitation (same table, same single-use, time-limited, hashed token)
 * whose creator is the requesting user. Every state change locks the request row, and its
 * current invitation row, in one transaction, so a decline can never race an acceptance.
 */
final class InvitationRequestService
{
    /** Open requests (pending, or approved and not yet used) one user may have at a time. */
    public const MAX_OPEN_REQUESTS_PER_USER = 5;

    /** Columns every listing selects; the joins are the same everywhere. */
    private const SELECT_COLUMNS = 'SELECT invitation_requests.id, invitation_requests.note, invitation_requests.status,
                    invitation_requests.created_at, invitation_requests.decided_at,
                    requester.username AS requested_by_username,
                    requester.display_name AS requested_by_display_name,
                    decider.username AS decided_by_username,
                    invitations.id AS invitation_id, invitations.expires_at AS invitation_expires_at,
                    invitations.accepted_at AS invitation_accepted_at, invitations.revoked_at AS invitation_revoked_at,
                    (invitations.expires_at <= UTC_TIMESTAMP()) AS invitation_is_expired,
                    accepted_user.username AS accepted_username
             FROM invitation_requests
             JOIN users AS requester ON requester.id = invitation_requests.requested_by_user_id
             LEFT JOIN users AS decider ON decider.id = invitation_requests.decided_by_user_id
             LEFT JOIN invitations ON invitations.id = invitation_requests.invitation_id
             LEFT JOIN users AS accepted_user ON accepted_user.id = invitations.accepted_user_id';

    public function __construct(
        private readonly PDO $database,
        private readonly InvitationService $invitationService,
    ) {
    }

    /**
     * Creates a pending request and returns its ID.
     *
     * @throws HttpException 409 when the user already has too many open requests.
     */
    public function create(string $requesterUserId, string $note): string
    {
        $openCountStatement = $this->database->prepare(
            'SELECT COUNT(*) FROM invitation_requests
             LEFT JOIN invitations ON invitations.id = invitation_requests.invitation_id
             WHERE invitation_requests.requested_by_user_id = :user_id
               AND invitation_requests.status IN (\'pending\', \'approved\')
               AND invitations.accepted_at IS NULL'
        );
        $openCountStatement->execute(['user_id' => $requesterUserId]);
        if ((int) $openCountStatement->fetchColumn() >= self::MAX_OPEN_REQUESTS_PER_USER) {
            throw HttpException::conflict(
                'too_many_open_requests',
                'You already have ' . self::MAX_OPEN_REQUESTS_PER_USER . ' open requests. Cancel one or wait for the administrator.',
            );
        }

        $requestId = UuidGenerator::generate();
        $insertStatement = $this->database->prepare(
            'INSERT INTO invitation_requests (id, requested_by_user_id, note, status, created_at)
             VALUES (:id, :requested_by_user_id, :note, \'pending\', UTC_TIMESTAMP())'
        );
        $insertStatement->execute(['id' => $requestId, 'requested_by_user_id' => $requesterUserId, 'note' => $note]);

        return $requestId;
    }

    /**
     * The requesting user's own requests, newest first. Nobody else's requests are ever included.
     *
     * @return list<array<string, mixed>>
     */
    public function listForRequester(string $requesterUserId): array
    {
        $selectStatement = $this->database->prepare(
            self::SELECT_COLUMNS . '
             WHERE invitation_requests.requested_by_user_id = :user_id
             ORDER BY invitation_requests.created_at DESC, invitation_requests.id
             LIMIT 100'
        );
        $selectStatement->execute(['user_id' => $requesterUserId]);

        return array_map(self::toPublicArray(...), $selectStatement->fetchAll());
    }

    /**
     * Every request for the administrator, pending ones first, then newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        $requestRows = $this->database->query(
            self::SELECT_COLUMNS . '
             ORDER BY invitation_requests.status = \'pending\' DESC, invitation_requests.created_at DESC, invitation_requests.id
             LIMIT 200'
        )->fetchAll();

        return array_map(self::toPublicArray(...), $requestRows);
    }

    /**
     * Loads one request, optionally only when it belongs to the given requester.
     *
     * @return array<string, mixed>
     * @throws HttpException 404 when it does not exist (or belongs to someone else).
     */
    public function find(string $requestId, ?string $requesterUserId = null): array
    {
        $selectStatement = $this->database->prepare(
            self::SELECT_COLUMNS . '
             WHERE invitation_requests.id = :id
               AND (:requester_user_id IS NULL OR invitation_requests.requested_by_user_id = :requester_user_id_again)'
        );
        $selectStatement->execute([
            'id' => $requestId,
            'requester_user_id' => $requesterUserId,
            'requester_user_id_again' => $requesterUserId,
        ]);
        $requestRow = $selectStatement->fetch();
        if ($requestRow === false) {
            throw HttpException::notFound();
        }

        return self::toPublicArray($requestRow);
    }

    /**
     * Approves a pending request (administrator).
     *
     * @throws HttpException 404 when unknown, 409 when it is not pending.
     */
    public function approve(string $requestId, string $adminUserId): void
    {
        $this->inTransaction(function () use ($requestId, $adminUserId): void {
            $lockedRequest = $this->lockRequest($requestId, null);
            if ($lockedRequest['status'] !== 'pending') {
                throw HttpException::conflict('request_not_pending', 'Only pending requests can be approved.');
            }
            $this->setStatus($requestId, 'approved', $adminUserId);
        });
    }

    /**
     * Declines a pending request, or withdraws an approval whose link has not been used yet
     * (administrator). Any link already created for it stops working.
     *
     * @throws HttpException 404 when unknown, 409 when it is already closed.
     */
    public function decline(string $requestId, string $adminUserId): void
    {
        $this->inTransaction(function () use ($requestId, $adminUserId): void {
            $lockedRequest = $this->lockRequest($requestId, null);
            $this->assertOpen($lockedRequest);
            $this->revokeCurrentInvitation($lockedRequest);
            $this->setStatus($requestId, 'declined', $adminUserId);
        });
    }

    /**
     * Cancels the requester's own open request. Any link already created for it stops working.
     *
     * @throws HttpException 404 when unknown or someone else's, 409 when it is already closed.
     */
    public function cancel(string $requestId, string $requesterUserId): void
    {
        $this->inTransaction(function () use ($requestId, $requesterUserId): void {
            $lockedRequest = $this->lockRequest($requestId, $requesterUserId);
            $this->assertOpen($lockedRequest);
            $this->revokeCurrentInvitation($lockedRequest);

            $cancelStatement = $this->database->prepare(
                'UPDATE invitation_requests SET status = \'cancelled\' WHERE id = :id'
            );
            $cancelStatement->execute(['id' => $requestId]);
        });
    }

    /**
     * Creates the invitation link for the requester's own approved request. A link created
     * earlier for the same request stops working, so a lost link can simply be replaced.
     *
     * @return array{invitation_id: string, raw_token: string}
     * @throws HttpException 404 when unknown or someone else's, 409 when not approved or already used.
     */
    public function createLink(string $requestId, string $requesterUserId, string $note, int $expiresInHours): array
    {
        return $this->inTransaction(function () use ($requestId, $requesterUserId, $note, $expiresInHours): array {
            $lockedRequest = $this->lockRequest($requestId, $requesterUserId);
            if ($lockedRequest['invitation_accepted_at'] !== null) {
                throw HttpException::conflict('request_completed', 'This invitation has already been used.');
            }
            if ($lockedRequest['status'] !== 'approved') {
                throw HttpException::conflict('request_not_approved', 'An administrator must approve this request first.');
            }

            $this->revokeCurrentInvitation($lockedRequest);
            $createdInvitation = $this->invitationService->create($requesterUserId, $note, $expiresInHours);

            $linkStatement = $this->database->prepare(
                'UPDATE invitation_requests SET invitation_id = :invitation_id WHERE id = :id'
            );
            $linkStatement->execute(['invitation_id' => $createdInvitation['id'], 'id' => $requestId]);

            return ['invitation_id' => $createdInvitation['id'], 'raw_token' => $createdInvitation['raw_token']];
        });
    }

    /**
     * Locks a request row and its current invitation row until the transaction ends.
     *
     * @return array{status: string, invitation_id: ?string, invitation_accepted_at: ?string}
     * @throws HttpException 404 when unknown, or not the given requester's.
     */
    private function lockRequest(string $requestId, ?string $requesterUserId): array
    {
        if (!UuidGenerator::isValid($requestId)) {
            throw HttpException::notFound();
        }

        $lockStatement = $this->database->prepare(
            'SELECT invitation_requests.status, invitation_requests.requested_by_user_id,
                    invitations.id AS invitation_id, invitations.accepted_at AS invitation_accepted_at
             FROM invitation_requests
             LEFT JOIN invitations ON invitations.id = invitation_requests.invitation_id
             WHERE invitation_requests.id = :id
             FOR UPDATE'
        );
        $lockStatement->execute(['id' => $requestId]);
        $requestRow = $lockStatement->fetch();
        // Someone else's request looks exactly like one that does not exist.
        if ($requestRow === false || ($requesterUserId !== null && $requestRow['requested_by_user_id'] !== $requesterUserId)) {
            throw HttpException::notFound();
        }

        return [
            'status' => (string) $requestRow['status'],
            'invitation_id' => $requestRow['invitation_id'] === null ? null : (string) $requestRow['invitation_id'],
            'invitation_accepted_at' => $requestRow['invitation_accepted_at'] === null ? null : (string) $requestRow['invitation_accepted_at'],
        ];
    }

    /**
     * @param array{status: string, invitation_accepted_at: ?string} $lockedRequest
     * @throws HttpException 409 when the request is declined, cancelled or already used.
     */
    private function assertOpen(array $lockedRequest): void
    {
        $isOpen = in_array($lockedRequest['status'], ['pending', 'approved'], true) && $lockedRequest['invitation_accepted_at'] === null;
        if (!$isOpen) {
            throw HttpException::conflict('request_closed', 'This request is already closed.');
        }
    }

    /** @param array{invitation_id: ?string} $lockedRequest */
    private function revokeCurrentInvitation(array $lockedRequest): void
    {
        if ($lockedRequest['invitation_id'] === null) {
            return;
        }
        $revokeStatement = $this->database->prepare(
            'UPDATE invitations SET revoked_at = UTC_TIMESTAMP()
             WHERE id = :id AND accepted_at IS NULL AND revoked_at IS NULL'
        );
        $revokeStatement->execute(['id' => $lockedRequest['invitation_id']]);
    }

    private function setStatus(string $requestId, string $newStatus, string $adminUserId): void
    {
        $updateStatement = $this->database->prepare(
            'UPDATE invitation_requests
             SET status = :status, decided_by_user_id = :decided_by_user_id, decided_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $updateStatement->execute(['status' => $newStatus, 'decided_by_user_id' => $adminUserId, 'id' => $requestId]);
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
     * @param array<string, mixed> $requestRow
     * @return array<string, mixed>
     */
    private static function toPublicArray(array $requestRow): array
    {
        $invitationLink = null;
        if ($requestRow['invitation_id'] !== null) {
            $linkStatus = match (true) {
                $requestRow['invitation_accepted_at'] !== null => 'accepted',
                $requestRow['invitation_revoked_at'] !== null => 'revoked',
                (bool) $requestRow['invitation_is_expired'] => 'expired',
                default => 'pending',
            };
            $invitationLink = [
                'status' => $linkStatus,
                'expires_at' => UtcTimestamp::toIso((string) $requestRow['invitation_expires_at']),
            ];
        }

        // An approved request whose link has been used is finished.
        $requestStatus = $requestRow['invitation_accepted_at'] !== null ? 'completed' : (string) $requestRow['status'];

        return [
            'id' => $requestRow['id'],
            'note' => $requestRow['note'],
            'status' => $requestStatus,
            'requested_by' => $requestRow['requested_by_username'],
            'requested_by_display_name' => $requestRow['requested_by_display_name'],
            'decided_by' => $requestRow['decided_by_username'],
            'created_at' => UtcTimestamp::toIso((string) $requestRow['created_at']),
            'decided_at' => UtcTimestamp::toIsoOrNull($requestRow['decided_at']),
            'link' => $invitationLink,
            'accepted_username' => $requestRow['accepted_username'],
        ];
    }
}
