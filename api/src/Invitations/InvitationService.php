<?php

declare(strict_types=1);

namespace Muninn\Api\Invitations;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\SecretToken;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Users\UserRepository;
use PDO;
use PDOException;
use Throwable;

/**
 * Invitation lifecycle: create, list, revoke, inspect and accept.
 *
 * Tokens are 256-bit random, stored only as SHA-256 hashes, time limited and single use.
 * Acceptance locks the invitation row (SELECT ... FOR UPDATE) inside the same transaction
 * that creates the user, so two simultaneous accepts can never create two accounts.
 */
final class InvitationService
{
    /** One message for used, expired, revoked and unknown tokens, so they are indistinguishable. */
    private const INVALID_INVITATION_MESSAGE = 'This invitation link is invalid or has expired.';

    public function __construct(
        private readonly PDO $database,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * Creates an invitation and returns its ID and raw token. The raw token is not stored;
     * the caller must hand it to the admin exactly once.
     *
     * @return array{id: string, raw_token: string}
     */
    public function create(string $createdByUserId, ?string $note, int $expiresInHours): array
    {
        $invitationId = UuidGenerator::generate();
        $rawToken = SecretToken::generate();

        $insertStatement = $this->database->prepare(
            'INSERT INTO invitations (id, token_hash, created_by_user_id, note, created_at, expires_at)
             VALUES (:id, :token_hash, :created_by_user_id, :note, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL :expires_in_hours HOUR)'
        );
        $insertStatement->execute([
            'id' => $invitationId,
            'token_hash' => SecretToken::hash($rawToken),
            'created_by_user_id' => $createdByUserId,
            'note' => $note,
            'expires_in_hours' => $expiresInHours,
        ]);

        return ['id' => $invitationId, 'raw_token' => $rawToken];
    }

    /**
     * Lists invitations, newest first, for the admin overview. Never returns hashes or tokens.
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        $invitationRows = $this->database->query(
            'SELECT invitations.id, invitations.note, invitations.created_at, invitations.expires_at,
                    invitations.accepted_at, invitations.revoked_at,
                    creator.username AS created_by_username,
                    accepted_user.username AS accepted_username,
                    (invitations.expires_at <= UTC_TIMESTAMP()) AS is_expired
             FROM invitations
             JOIN users AS creator ON creator.id = invitations.created_by_user_id
             LEFT JOIN users AS accepted_user ON accepted_user.id = invitations.accepted_user_id
             ORDER BY invitations.created_at DESC
             LIMIT 200'
        )->fetchAll();

        return array_map(fn (array $invitationRow): array => $this->toPublicArray($invitationRow), $invitationRows);
    }

    /**
     * Loads one invitation for the admin API, or null.
     *
     * @return array<string, mixed>|null
     */
    public function findPublic(string $invitationId): ?array
    {
        $selectStatement = $this->database->prepare(
            'SELECT invitations.id, invitations.note, invitations.created_at, invitations.expires_at,
                    invitations.accepted_at, invitations.revoked_at,
                    creator.username AS created_by_username,
                    accepted_user.username AS accepted_username,
                    (invitations.expires_at <= UTC_TIMESTAMP()) AS is_expired
             FROM invitations
             JOIN users AS creator ON creator.id = invitations.created_by_user_id
             LEFT JOIN users AS accepted_user ON accepted_user.id = invitations.accepted_user_id
             WHERE invitations.id = :id'
        );
        $selectStatement->execute(['id' => $invitationId]);
        $invitationRow = $selectStatement->fetch();

        return $invitationRow === false ? null : $this->toPublicArray($invitationRow);
    }

    /**
     * Revokes a pending invitation.
     *
     * @throws HttpException 404 when it does not exist, 409 when it is no longer pending.
     */
    public function revoke(string $invitationId): void
    {
        $existingInvitation = $this->findPublic($invitationId);
        if ($existingInvitation === null) {
            throw HttpException::notFound();
        }
        if ($existingInvitation['status'] !== 'pending') {
            throw HttpException::conflict('invitation_not_pending', 'Only pending invitations can be revoked.');
        }

        $revokeStatement = $this->database->prepare(
            'UPDATE invitations SET revoked_at = UTC_TIMESTAMP()
             WHERE id = :id AND accepted_at IS NULL AND revoked_at IS NULL'
        );
        $revokeStatement->execute(['id' => $invitationId]);
    }

    /**
     * Returns the expiry of a usable invitation.
     *
     * @return array{expires_at: string}
     * @throws HttpException 404 when the token is not usable.
     */
    public function inspect(string $rawToken): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT expires_at FROM invitations
             WHERE token_hash = :token_hash AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()'
        );
        $selectStatement->execute(['token_hash' => SecretToken::hash($rawToken)]);
        $expiresAt = $selectStatement->fetchColumn();
        if ($expiresAt === false) {
            throw self::invalidInvitation();
        }

        return ['expires_at' => self::toIsoUtc((string) $expiresAt)];
    }

    /**
     * Accepts an invitation: creates the user and marks the invitation used, atomically.
     *
     * @return array{user_id: string, invitation_id: string}
     * @throws HttpException 404 when the token is not usable, 422 when the username is taken.
     */
    public function accept(string $rawToken, string $username, string $displayName, string $passwordHash): array
    {
        $this->database->beginTransaction();
        try {
            // Lock the row: a concurrent accept of the same token waits here, then sees it used.
            $lockStatement = $this->database->prepare(
                'SELECT id FROM invitations
                 WHERE token_hash = :token_hash AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
                 FOR UPDATE'
            );
            $lockStatement->execute(['token_hash' => SecretToken::hash($rawToken)]);
            $invitationId = $lockStatement->fetchColumn();
            if ($invitationId === false) {
                throw self::invalidInvitation();
            }

            try {
                $newUserId = $this->userRepository->create($username, $displayName, $passwordHash, false);
            } catch (PDOException $insertException) {
                if ($insertException->getCode() === '23000') {
                    throw HttpException::validation(['username' => 'This username is already taken.']);
                }
                throw $insertException;
            }

            $markAcceptedStatement = $this->database->prepare(
                'UPDATE invitations SET accepted_at = UTC_TIMESTAMP(), accepted_user_id = :user_id
                 WHERE id = :id AND accepted_at IS NULL'
            );
            $markAcceptedStatement->execute(['user_id' => $newUserId, 'id' => $invitationId]);
            if ($markAcceptedStatement->rowCount() !== 1) {
                throw self::invalidInvitation();
            }

            $this->database->commit();
        } catch (Throwable $acceptFailure) {
            // Any failure leaves both the invitation and the users table untouched.
            $this->database->rollBack();
            throw $acceptFailure;
        }

        return ['user_id' => $newUserId, 'invitation_id' => (string) $invitationId];
    }

    public static function invalidInvitation(): HttpException
    {
        return HttpException::notFound('invitation_invalid', self::INVALID_INVITATION_MESSAGE);
    }

    /**
     * @param array<string, mixed> $invitationRow
     * @return array<string, mixed>
     */
    private function toPublicArray(array $invitationRow): array
    {
        $invitationStatus = match (true) {
            $invitationRow['accepted_at'] !== null => 'accepted',
            $invitationRow['revoked_at'] !== null => 'revoked',
            (bool) $invitationRow['is_expired'] => 'expired',
            default => 'pending',
        };

        return [
            'id' => $invitationRow['id'],
            'note' => $invitationRow['note'],
            'status' => $invitationStatus,
            'created_by' => $invitationRow['created_by_username'],
            'accepted_username' => $invitationRow['accepted_username'],
            'created_at' => self::toIsoUtc((string) $invitationRow['created_at']),
            'expires_at' => self::toIsoUtc((string) $invitationRow['expires_at']),
        ];
    }

    /** Converts a stored UTC DATETIME ("2026-10-01 10:00:00") to ISO 8601 with Z. */
    private static function toIsoUtc(string $databaseDateTime): string
    {
        return str_replace(' ', 'T', $databaseDateTime) . 'Z';
    }
}
