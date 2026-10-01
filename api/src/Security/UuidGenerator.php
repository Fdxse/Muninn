<?php

declare(strict_types=1);

namespace Muninn\Api\Security;

/**
 * Generates random (version 4) UUIDs for primary keys (decision D024).
 *
 * Random IDs reveal nothing about how many records exist or their order,
 * unlike auto-increment integers.
 */
final class UuidGenerator
{
    public static function generate(): string
    {
        $randomBytes = random_bytes(16);
        // Set version (4) and variant (RFC 4122) bits.
        $randomBytes[6] = chr((ord($randomBytes[6]) & 0x0f) | 0x40);
        $randomBytes[8] = chr((ord($randomBytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($randomBytes), 4));
    }

    /** True for a syntactically valid lowercase UUID string. */
    public static function isValid(string $candidate): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $candidate);
    }
}
