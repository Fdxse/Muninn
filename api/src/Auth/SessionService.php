<?php

declare(strict_types=1);

namespace Muninn\Api\Auth;

use Muninn\Api\Security\SecretToken;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Users\User;
use PDO;

/**
 * Database-backed sessions (decision D022).
 *
 * The browser holds an opaque random token in an HttpOnly cookie; the database holds only its
 * SHA-256 hash. Server-side rows make logout and future "revoke all sessions" real revocation
 * instead of merely deleting a cookie.
 */
final class SessionService
{
    /** last_seen_at is refreshed at most this often, to avoid a write on every request. */
    private const LAST_SEEN_REFRESH_SECONDS = 300;

    public function __construct(
        private readonly PDO $database,
        private readonly int $idleTimeoutHours,
        private readonly int $absoluteTimeoutHours,
    ) {
    }

    /** Creates a new session for a user. Always a new token, which prevents session fixation. */
    public function create(string $userId, string $ipAddress, ?string $userAgent): NewSession
    {
        $newSession = new NewSession(
            sessionId: UuidGenerator::generate(),
            rawToken: SecretToken::generate(),
            csrfToken: SecretToken::generate(),
        );

        $insertStatement = $this->database->prepare(
            'INSERT INTO sessions (id, user_id, token_hash, csrf_token, ip_address, user_agent, created_at, last_seen_at, expires_at)
             VALUES (:id, :user_id, :token_hash, :csrf_token, :ip_address, :user_agent, UTC_TIMESTAMP(), UTC_TIMESTAMP(),
                     UTC_TIMESTAMP() + INTERVAL :absolute_hours HOUR)'
        );
        $insertStatement->execute([
            'id' => $newSession->sessionId,
            'user_id' => $userId,
            'token_hash' => SecretToken::hash($newSession->rawToken),
            'csrf_token' => $newSession->csrfToken,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            'absolute_hours' => $this->absoluteTimeoutHours,
        ]);

        return $newSession;
    }

    /**
     * Resolves a raw cookie token to a live session of an active user, or null.
     *
     * A session is live when it is not revoked, not past its absolute expiry,
     * and has been used within the idle timeout.
     */
    public function findActive(?string $rawToken): ?AuthenticatedSession
    {
        if ($rawToken === null || !SecretToken::looksValid($rawToken)) {
            return null;
        }

        $selectStatement = $this->database->prepare(
            'SELECT sessions.id AS session_id, sessions.csrf_token, sessions.last_seen_at,
                    TIMESTAMPDIFF(SECOND, sessions.last_seen_at, UTC_TIMESTAMP()) AS seconds_since_seen,
                    users.*
             FROM sessions
             JOIN users ON users.id = sessions.user_id
             WHERE sessions.token_hash = :token_hash
               AND sessions.revoked_at IS NULL
               AND sessions.expires_at > UTC_TIMESTAMP()
               AND sessions.last_seen_at > UTC_TIMESTAMP() - INTERVAL :idle_hours HOUR'
        );
        $selectStatement->execute([
            'token_hash' => SecretToken::hash($rawToken),
            'idle_hours' => $this->idleTimeoutHours,
        ]);
        $sessionRow = $selectStatement->fetch();
        if ($sessionRow === false) {
            return null;
        }

        $sessionUser = User::fromRow($sessionRow);
        if (!$sessionUser->isActive()) {
            return null;
        }

        if ((int) $sessionRow['seconds_since_seen'] >= self::LAST_SEEN_REFRESH_SECONDS) {
            $touchStatement = $this->database->prepare('UPDATE sessions SET last_seen_at = UTC_TIMESTAMP() WHERE id = :id');
            $touchStatement->execute(['id' => $sessionRow['session_id']]);
        }

        return new AuthenticatedSession((string) $sessionRow['session_id'], $sessionUser, (string) $sessionRow['csrf_token']);
    }

    /** Revokes one session. Revoked rows are kept for auditing. */
    public function revoke(string $sessionId): void
    {
        $revokeStatement = $this->database->prepare(
            'UPDATE sessions SET revoked_at = UTC_TIMESTAMP() WHERE id = :id AND revoked_at IS NULL'
        );
        $revokeStatement->execute(['id' => $sessionId]);
    }

    /** Revokes the session behind a raw token, if any (used when a new login replaces it). */
    public function revokeByRawToken(?string $rawToken): void
    {
        if ($rawToken === null || !SecretToken::looksValid($rawToken)) {
            return;
        }
        $revokeStatement = $this->database->prepare(
            'UPDATE sessions SET revoked_at = UTC_TIMESTAMP() WHERE token_hash = :token_hash AND revoked_at IS NULL'
        );
        $revokeStatement->execute(['token_hash' => SecretToken::hash($rawToken)]);
    }

    /** Revokes every open session of a user (used when an account is disabled). */
    public function revokeAllForUser(string $userId): void
    {
        $revokeStatement = $this->database->prepare(
            'UPDATE sessions SET revoked_at = UTC_TIMESTAMP() WHERE user_id = :user_id AND revoked_at IS NULL'
        );
        $revokeStatement->execute(['user_id' => $userId]);
    }

    /** Revokes every open session of a user except one (used when they change their own password). */
    public function revokeAllForUserExcept(string $userId, string $keptSessionId): void
    {
        $revokeStatement = $this->database->prepare(
            'UPDATE sessions SET revoked_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id AND id <> :kept_session_id AND revoked_at IS NULL'
        );
        $revokeStatement->execute(['user_id' => $userId, 'kept_session_id' => $keptSessionId]);
    }
}
