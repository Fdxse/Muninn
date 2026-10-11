<?php

declare(strict_types=1);

namespace Muninn\Api\Auth;

use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\UuidGenerator;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Signing in on another device with a 6-digit code (decision D068).
 *
 * A signed-in device (usually a phone) asks for a code and shows it; another device types it on
 * the sign-in page and gets an ordinary session for the same account. A code:
 * - is stored only as a SHA-256 hash,
 * - works for 5 minutes and only once,
 * - works only while the session that created it is still signed in (signing the phone out,
 *   disabling the account or a password reset therefore kills it too),
 * - is replaced when the same account asks for a new one (one open code per account),
 * - never belongs to a system administrator account.
 *
 * Six digits are guessable in principle, so the controller rate limits wrong codes tightly
 * (RateLimiter::assertDeviceCodeAllowed). This class also makes sure two open codes never share
 * the same digits, so a typed code always means exactly one account.
 */
final class DeviceSignInCodeService
{
    /** How long a code works after it is shown. */
    public const CODE_LIFETIME_MINUTES = 5;

    /** Number of digits in a code. */
    private const CODE_DIGIT_COUNT = 6;

    /** Rows this long past their expiry are deleted when new codes are created. */
    private const PRUNE_AFTER_HOURS = 24;

    /** Attempts at picking digits that no other open code uses, before giving up. */
    private const MAX_UNIQUE_CODE_ATTEMPTS = 20;

    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * Creates a new code for a signed-in user, revoking their older open code. The caller hands
     * the digits to that user exactly once.
     *
     * @return array{id: string, code: string, expires_at: string}
     */
    public function create(string $userId, string $createdBySessionId): array
    {
        $codeId = UuidGenerator::generate();

        $newCode = $this->inTransaction(function () use ($codeId, $userId, $createdBySessionId): string {
            $this->pruneOldRows();
            $this->revokeOpenForUser($userId);
            $newCode = $this->pickUnusedCode();

            $insertStatement = $this->database->prepare(
                'INSERT INTO device_sign_in_codes (id, user_id, code_hash, created_by_session_id, created_at, expires_at)
                 VALUES (:id, :user_id, :code_hash, :created_by_session_id, UTC_TIMESTAMP(),
                         UTC_TIMESTAMP() + INTERVAL :lifetime_minutes MINUTE)'
            );
            $insertStatement->execute([
                'id' => $codeId,
                'user_id' => $userId,
                'code_hash' => self::hashCode($newCode),
                'created_by_session_id' => $createdBySessionId,
                'lifetime_minutes' => self::CODE_LIFETIME_MINUTES,
            ]);

            return $newCode;
        });

        $expiryStatement = $this->database->prepare('SELECT expires_at FROM device_sign_in_codes WHERE id = :id');
        $expiryStatement->execute(['id' => $codeId]);

        return [
            'id' => $codeId,
            'code' => $newCode,
            'expires_at' => UtcTimestamp::toIso((string) $expiryStatement->fetchColumn()),
        ];
    }

    /**
     * The state of one of the user's own codes, so the showing device can say "used" or
     * "expired". Codes of other accounts are reported as not found.
     *
     * @return array{id: string, status: string, expires_at: string, used_at: string|null}
     *         status is 'open', 'used', 'expired' or 'revoked'.
     * @throws HttpException 404 when the code does not exist or belongs to someone else.
     */
    public function describeOwn(string $codeId, string $userId): array
    {
        if (!UuidGenerator::isValid($codeId)) {
            throw HttpException::notFound();
        }
        $selectStatement = $this->database->prepare(
            'SELECT id, expires_at, used_at, revoked_at, expires_at <= UTC_TIMESTAMP() AS has_expired
             FROM device_sign_in_codes
             WHERE id = :id AND user_id = :user_id'
        );
        $selectStatement->execute(['id' => $codeId, 'user_id' => $userId]);
        $codeRow = $selectStatement->fetch();
        if ($codeRow === false) {
            throw HttpException::notFound();
        }

        // Used wins over the rest: it is the answer the person is waiting for.
        $codeStatus = match (true) {
            $codeRow['used_at'] !== null => 'used',
            $codeRow['revoked_at'] !== null => 'revoked',
            (bool) $codeRow['has_expired'] => 'expired',
            default => 'open',
        };

        return [
            'id' => (string) $codeRow['id'],
            'status' => $codeStatus,
            'expires_at' => UtcTimestamp::toIso((string) $codeRow['expires_at']),
            'used_at' => $codeRow['used_at'] === null ? null : UtcTimestamp::toIso((string) $codeRow['used_at']),
        ];
    }

    /**
     * Cancels one of the user's own open codes. Cancelling a code that is already used, expired
     * or revoked changes nothing.
     *
     * @throws HttpException 404 when the code does not exist or belongs to someone else.
     */
    public function cancelOwn(string $codeId, string $userId): void
    {
        // Reuses the ownership check (and its 404) before changing anything.
        $this->describeOwn($codeId, $userId);

        $revokeStatement = $this->database->prepare(
            'UPDATE device_sign_in_codes SET revoked_at = UTC_TIMESTAMP()
             WHERE id = :id AND user_id = :user_id AND used_at IS NULL AND revoked_at IS NULL'
        );
        $revokeStatement->execute(['id' => $codeId, 'user_id' => $userId]);
    }

    /** Revokes the user's open code, if any (a new code replaces it, or the password changed). */
    public function revokeOpenForUser(string $userId): void
    {
        $revokeStatement = $this->database->prepare(
            'UPDATE device_sign_in_codes SET revoked_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id AND used_at IS NULL AND revoked_at IS NULL'
        );
        $revokeStatement->execute(['user_id' => $userId]);
    }

    /**
     * Uses a code: finds the one open code with these digits and marks it used, atomically, so
     * two devices typing the same code at once cannot both get in.
     *
     * @return array{code_id: string, user_id: string}|null Null when no usable code matches.
     */
    public function redeem(string $typedCode): ?array
    {
        return $this->inTransaction(function () use ($typedCode): ?array {
            // Lock the row: a concurrent use of the same code waits here, then sees it used.
            $lockStatement = $this->database->prepare(
                'SELECT device_sign_in_codes.id, device_sign_in_codes.user_id
                 FROM device_sign_in_codes
                 JOIN users ON users.id = device_sign_in_codes.user_id
                 JOIN sessions ON sessions.id = device_sign_in_codes.created_by_session_id
                 WHERE device_sign_in_codes.code_hash = :code_hash
                   AND device_sign_in_codes.expires_at > UTC_TIMESTAMP()
                   AND device_sign_in_codes.used_at IS NULL
                   AND device_sign_in_codes.revoked_at IS NULL
                   AND users.status = \'active\'
                   AND users.is_system_admin = 0
                   AND sessions.revoked_at IS NULL
                   AND sessions.expires_at > UTC_TIMESTAMP()
                 ORDER BY device_sign_in_codes.created_at DESC
                 LIMIT 1
                 FOR UPDATE'
            );
            $lockStatement->execute(['code_hash' => self::hashCode($typedCode)]);
            $codeRow = $lockStatement->fetch();
            if ($codeRow === false) {
                return null;
            }

            $markUsedStatement = $this->database->prepare(
                'UPDATE device_sign_in_codes SET used_at = UTC_TIMESTAMP() WHERE id = :id AND used_at IS NULL'
            );
            $markUsedStatement->execute(['id' => $codeRow['id']]);
            if ($markUsedStatement->rowCount() !== 1) {
                return null;
            }

            return ['code_id' => (string) $codeRow['id'], 'user_id' => (string) $codeRow['user_id']];
        });
    }

    /** Records which session a used code created, for the audit trail. */
    public function recordUsedBySession(string $codeId, string $newSessionId): void
    {
        $updateStatement = $this->database->prepare(
            'UPDATE device_sign_in_codes SET used_by_session_id = :session_id WHERE id = :id'
        );
        $updateStatement->execute(['session_id' => $newSessionId, 'id' => $codeId]);
    }

    /**
     * Removes spaces and dashes people type between digit groups ("123 456", "123-456").
     * Returns null when what is left is not exactly 6 digits.
     */
    public static function normaliseTypedCode(string $typedCode): ?string
    {
        $digitsOnly = preg_replace('/[\s-]+/u', '', $typedCode) ?? '';

        return preg_match('/^[0-9]{' . self::CODE_DIGIT_COUNT . '}$/', $digitsOnly) === 1 ? $digitsOnly : null;
    }

    /**
     * Hex SHA-256 of a code, the only form that is stored. Six digits are easy to reverse from
     * a hash, but a code lives 5 minutes, and anyone who can read the database already sees far
     * more than a code would give them; the hash keeps codes out of backups and logs.
     */
    private static function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }

    /**
     * Picks random digits that no other open code uses, so a typed code matches one account.
     *
     * @throws RuntimeException When no free code was found (only with ~a million open codes).
     */
    private function pickUnusedCode(): string
    {
        $inUseStatement = $this->database->prepare(
            'SELECT COUNT(*) FROM device_sign_in_codes
             WHERE code_hash = :code_hash AND expires_at > UTC_TIMESTAMP()
               AND used_at IS NULL AND revoked_at IS NULL'
        );
        for ($attemptNumber = 1; $attemptNumber <= self::MAX_UNIQUE_CODE_ATTEMPTS; $attemptNumber++) {
            // random_int is cryptographically secure; leading zeros are kept ("004213").
            $candidateCode = str_pad((string) random_int(0, 10 ** self::CODE_DIGIT_COUNT - 1), self::CODE_DIGIT_COUNT, '0', STR_PAD_LEFT);
            $inUseStatement->execute(['code_hash' => self::hashCode($candidateCode)]);
            if ((int) $inUseStatement->fetchColumn() === 0) {
                return $candidateCode;
            }
        }

        throw new RuntimeException('Could not pick a free device sign-in code.');
    }

    /** Deletes rows that stopped mattering a while ago, without needing a scheduled job. */
    private function pruneOldRows(): void
    {
        $pruneStatement = $this->database->prepare(
            'DELETE FROM device_sign_in_codes WHERE expires_at < UTC_TIMESTAMP() - INTERVAL :prune_hours HOUR'
        );
        $pruneStatement->execute(['prune_hours' => self::PRUNE_AFTER_HOURS]);
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
