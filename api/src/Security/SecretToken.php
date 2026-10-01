<?php

declare(strict_types=1);

namespace Muninn\Api\Security;

/**
 * Cryptographically random bearer secrets (session tokens, invitation tokens).
 *
 * Only the SHA-256 hash is ever stored. A plain SHA-256 is appropriate here (unlike for
 * passwords) because the tokens carry 256 bits of randomness and cannot be guessed.
 * Lookups compare hashes through a unique database index, so an attacker learns nothing
 * useful from timing: they would need a preimage of the hash.
 */
final class SecretToken
{
    /** Number of random bytes per token (256 bits). */
    private const TOKEN_BYTE_LENGTH = 32;

    /** Returns a new URL-safe random token (43 characters, base64url without padding). */
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTE_LENGTH)), '+/', '-_'), '=');
    }

    /** Hex SHA-256 of a raw token, the only form that is stored. */
    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /** Rejects values that cannot possibly be one of our tokens before touching the database. */
    public static function looksValid(string $candidate): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $candidate);
    }
}
