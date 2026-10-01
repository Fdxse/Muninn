<?php

declare(strict_types=1);

namespace Muninn\Api\Auth;

/**
 * Password hashing and policy (decision D027).
 *
 * Uses Argon2id when the PHP build supports it, otherwise bcrypt. Because bcrypt silently
 * ignores everything after 72 bytes, the policy caps password length at 72 bytes whenever
 * bcrypt is the active algorithm, so two different long passwords can never collide.
 */
final class PasswordService
{
    public const MINIMUM_LENGTH = 12;

    /** Upper bound for Argon2id, to keep hashing cost bounded. */
    private const ARGON2_MAXIMUM_LENGTH = 1024;

    /** bcrypt only reads the first 72 bytes. */
    private const BCRYPT_MAXIMUM_BYTES = 72;

    private string $algorithm;

    /** A real hash used to spend the same time on unknown usernames as on known ones. */
    private ?string $dummyHash = null;

    public function __construct(?string $forcedAlgorithm = null)
    {
        $this->algorithm = $forcedAlgorithm
            ?? (in_array(PASSWORD_ARGON2ID, password_algos(), true) ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    public function hash(string $plainPassword): string
    {
        return password_hash($plainPassword, $this->algorithm);
    }

    public function verify(string $plainPassword, string $passwordHash): bool
    {
        return password_verify($plainPassword, $passwordHash);
    }

    /** True when a stored hash should be upgraded to the current algorithm or cost. */
    public function needsRehash(string $passwordHash): bool
    {
        return password_needs_rehash($passwordHash, $this->algorithm);
    }

    /**
     * Burns roughly one verification's worth of time. Called when the username does not
     * exist, so response timing does not reveal which usernames are real.
     */
    public function verifyAgainstDummy(string $plainPassword): void
    {
        $this->dummyHash ??= $this->hash('muninn-dummy-password-for-timing');
        password_verify($plainPassword, $this->dummyHash);
    }

    /** Returns an error message, or null when the password meets the policy. */
    public function policyError(string $plainPassword): ?string
    {
        if (mb_strlen($plainPassword) < self::MINIMUM_LENGTH) {
            return 'Use at least ' . self::MINIMUM_LENGTH . ' characters.';
        }
        if ($this->algorithm === PASSWORD_BCRYPT && strlen($plainPassword) > self::BCRYPT_MAXIMUM_BYTES) {
            return 'Use at most ' . self::BCRYPT_MAXIMUM_BYTES . ' bytes.';
        }
        if (mb_strlen($plainPassword) > self::ARGON2_MAXIMUM_LENGTH) {
            return 'Use at most ' . self::ARGON2_MAXIMUM_LENGTH . ' characters.';
        }

        return null;
    }
}
