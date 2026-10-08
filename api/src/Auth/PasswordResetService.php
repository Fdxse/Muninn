<?php

declare(strict_types=1);

namespace Muninn\Api\Auth;

use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\SecretToken;
use Muninn\Api\Security\UuidGenerator;
use PDO;
use Throwable;

/**
 * One-time password reset links (decision D040).
 *
 * A system administrator creates a link for a user and sends it to them personally (no email in
 * the MVP). Tokens are 256-bit random, stored only as SHA-256 hashes, time limited and single
 * use. Creating a new link revokes the user's older unused links, so only one works at a time.
 * Completing a reset locks the link row (SELECT ... FOR UPDATE) in the same transaction that
 * changes the password, so a link can never be used twice.
 */
final class PasswordResetService
{
    /** One message for used, expired, revoked and unknown links, so they are indistinguishable. */
    private const INVALID_LINK_MESSAGE = 'This password reset link is invalid or has expired.';

    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * Creates a reset link for a user, revoking their older unused links, and returns the new
     * link's ID, raw token and expiry. The raw token is not stored; the caller must hand it to
     * the administrator exactly once.
     *
     * @return array{id: string, raw_token: string, expires_at: string}
     */
    public function create(string $targetUserId, string $createdByUserId, int $expiresInHours): array
    {
        $resetId = UuidGenerator::generate();
        $rawToken = SecretToken::generate();

        $this->inTransaction(function () use ($resetId, $rawToken, $targetUserId, $createdByUserId, $expiresInHours): void {
            $this->revokeUnusedForUser($targetUserId);

            $insertStatement = $this->database->prepare(
                'INSERT INTO password_resets (id, user_id, token_hash, created_by_user_id, created_at, expires_at)
                 VALUES (:id, :user_id, :token_hash, :created_by_user_id, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL :expires_in_hours HOUR)'
            );
            $insertStatement->execute([
                'id' => $resetId,
                'user_id' => $targetUserId,
                'token_hash' => SecretToken::hash($rawToken),
                'created_by_user_id' => $createdByUserId,
                'expires_in_hours' => $expiresInHours,
            ]);
        });

        $expiryStatement = $this->database->prepare('SELECT expires_at FROM password_resets WHERE id = :id');
        $expiryStatement->execute(['id' => $resetId]);

        return [
            'id' => $resetId,
            'raw_token' => $rawToken,
            'expires_at' => UtcTimestamp::toIso((string) $expiryStatement->fetchColumn()),
        ];
    }

    /** Revokes every unused link of a user (a newer link replaces them, or the account was disabled). */
    public function revokeUnusedForUser(string $userId): void
    {
        $revokeStatement = $this->database->prepare(
            'UPDATE password_resets SET revoked_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id AND used_at IS NULL AND revoked_at IS NULL'
        );
        $revokeStatement->execute(['user_id' => $userId]);
    }

    /**
     * Returns who a usable link belongs to, so the reset page can show the username.
     *
     * @return array{username: string, expires_at: string}
     * @throws HttpException 404 when the link is not usable.
     */
    public function inspect(string $rawToken): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT users.username, password_resets.expires_at
             FROM password_resets
             JOIN users ON users.id = password_resets.user_id
             WHERE password_resets.token_hash = :token_hash
               AND password_resets.used_at IS NULL
               AND password_resets.revoked_at IS NULL
               AND password_resets.expires_at > UTC_TIMESTAMP()
               AND users.status = \'active\''
        );
        $selectStatement->execute(['token_hash' => SecretToken::hash($rawToken)]);
        $resetRow = $selectStatement->fetch();
        if ($resetRow === false) {
            throw self::invalidLink();
        }

        return [
            'username' => (string) $resetRow['username'],
            'expires_at' => UtcTimestamp::toIso((string) $resetRow['expires_at']),
        ];
    }

    /**
     * Uses a link: stores the new password hash and marks the link used, atomically.
     * The caller revokes the user's sessions afterwards.
     *
     * @return array{user_id: string, reset_id: string}
     * @throws HttpException 404 when the link is not usable.
     */
    public function complete(string $rawToken, string $newPasswordHash): array
    {
        return $this->inTransaction(function () use ($rawToken, $newPasswordHash): array {
            // Lock the row: a concurrent use of the same link waits here, then sees it used.
            $lockStatement = $this->database->prepare(
                'SELECT password_resets.id, password_resets.user_id
                 FROM password_resets
                 JOIN users ON users.id = password_resets.user_id
                 WHERE password_resets.token_hash = :token_hash
                   AND password_resets.used_at IS NULL
                   AND password_resets.revoked_at IS NULL
                   AND password_resets.expires_at > UTC_TIMESTAMP()
                   AND users.status = \'active\'
                 FOR UPDATE'
            );
            $lockStatement->execute(['token_hash' => SecretToken::hash($rawToken)]);
            $resetRow = $lockStatement->fetch();
            if ($resetRow === false) {
                throw self::invalidLink();
            }

            $passwordStatement = $this->database->prepare(
                'UPDATE users SET password_hash = :password_hash, updated_at = UTC_TIMESTAMP() WHERE id = :id'
            );
            $passwordStatement->execute(['password_hash' => $newPasswordHash, 'id' => $resetRow['user_id']]);

            $markUsedStatement = $this->database->prepare(
                'UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = :id AND used_at IS NULL'
            );
            $markUsedStatement->execute(['id' => $resetRow['id']]);
            if ($markUsedStatement->rowCount() !== 1) {
                throw self::invalidLink();
            }

            return ['user_id' => (string) $resetRow['user_id'], 'reset_id' => (string) $resetRow['id']];
        });
    }

    public static function invalidLink(): HttpException
    {
        return HttpException::notFound('password_reset_invalid', self::INVALID_LINK_MESSAGE);
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
