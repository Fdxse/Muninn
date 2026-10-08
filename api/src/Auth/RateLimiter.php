<?php

declare(strict_types=1);

namespace Muninn\Api\Auth;

use Muninn\Api\Http\HttpException;
use PDO;

/**
 * Counts failed authentication attempts in a sliding time window (stored in auth_attempts).
 *
 * Two independent limits apply to logins: per username + IP (stops guessing one account)
 * and per IP across all usernames (slows password spraying). Invitation token guesses are
 * limited per IP, and so are password reset token guesses (with the same limit, counted separately).
 * Successful attempts are recorded too, for auditing, but are not counted.
 */
final class RateLimiter
{
    public const TYPE_LOGIN = 'login';
    public const TYPE_INVITATION = 'invitation';
    public const TYPE_PASSWORD_RESET = 'password_reset';

    /** Rows older than this are deleted opportunistically. */
    private const RETENTION_HOURS = 24;

    public function __construct(
        private readonly PDO $database,
        private readonly int $windowMinutes,
        private readonly int $maxLoginFailuresPerUsername,
        private readonly int $maxLoginFailuresPerIp,
        private readonly int $maxInvitationFailuresPerIp,
    ) {
    }

    /**
     * Throws 429 when a login from this IP for this username must be refused.
     *
     * @throws HttpException
     */
    public function assertLoginAllowed(string $normalisedUsername, string $ipAddress): void
    {
        $failuresForUsername = $this->countFailures(
            'attempt_type = :attempt_type AND subject = :subject AND ip_address = :ip_address',
            ['attempt_type' => self::TYPE_LOGIN, 'subject' => $normalisedUsername, 'ip_address' => $ipAddress],
        );
        $failuresForIp = $this->countFailures(
            'attempt_type = :attempt_type AND ip_address = :ip_address',
            ['attempt_type' => self::TYPE_LOGIN, 'ip_address' => $ipAddress],
        );

        if ($failuresForUsername >= $this->maxLoginFailuresPerUsername || $failuresForIp >= $this->maxLoginFailuresPerIp) {
            throw HttpException::tooManyRequests($this->windowMinutes * 60);
        }
    }

    /**
     * Throws 429 when this IP has guessed too many invalid invitation tokens.
     *
     * @throws HttpException
     */
    public function assertInvitationAllowed(string $ipAddress): void
    {
        $failuresForIp = $this->countFailures(
            'attempt_type = :attempt_type AND ip_address = :ip_address',
            ['attempt_type' => self::TYPE_INVITATION, 'ip_address' => $ipAddress],
        );

        if ($failuresForIp >= $this->maxInvitationFailuresPerIp) {
            throw HttpException::tooManyRequests($this->windowMinutes * 60);
        }
    }

    /**
     * Throws 429 when this IP has guessed too many invalid password reset tokens (D040).
     * Uses the same per-IP limit as invitation tokens: both are 256-bit link tokens.
     *
     * @throws HttpException
     */
    public function assertPasswordResetAllowed(string $ipAddress): void
    {
        $failuresForIp = $this->countFailures(
            'attempt_type = :attempt_type AND ip_address = :ip_address',
            ['attempt_type' => self::TYPE_PASSWORD_RESET, 'ip_address' => $ipAddress],
        );

        if ($failuresForIp >= $this->maxInvitationFailuresPerIp) {
            throw HttpException::tooManyRequests($this->windowMinutes * 60);
        }
    }

    public function recordAttempt(string $attemptType, ?string $subject, string $ipAddress, bool $succeeded): void
    {
        $insertStatement = $this->database->prepare(
            'INSERT INTO auth_attempts (attempt_type, subject, ip_address, succeeded, attempted_at)
             VALUES (:attempt_type, :subject, :ip_address, :succeeded, UTC_TIMESTAMP())'
        );
        $insertStatement->execute([
            'attempt_type' => $attemptType,
            'subject' => $subject === null ? null : mb_substr($subject, 0, 64),
            'ip_address' => $ipAddress,
            'succeeded' => $succeeded ? 1 : 0,
        ]);

        // Housekeeping: keep the table small without needing a scheduled job.
        $pruneStatement = $this->database->prepare(
            'DELETE FROM auth_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL :retention_hours HOUR'
        );
        $pruneStatement->execute(['retention_hours' => self::RETENTION_HOURS]);
    }

    /** @param array<string, mixed> $parameters */
    private function countFailures(string $whereClause, array $parameters): int
    {
        $countStatement = $this->database->prepare(
            'SELECT COUNT(*) FROM auth_attempts
             WHERE ' . $whereClause . ' AND succeeded = 0
               AND attempted_at > UTC_TIMESTAMP() - INTERVAL :window_minutes MINUTE'
        );
        $countStatement->execute($parameters + ['window_minutes' => $this->windowMinutes]);

        return (int) $countStatement->fetchColumn();
    }
}
