<?php

declare(strict_types=1);

namespace Muninn\Api\Database;

/**
 * Converts stored UTC DATETIME values ("2026-10-01 10:00:00") to the ISO 8601 form the API
 * returns ("2026-10-01T10:00:00Z"). The connection pins time_zone = '+00:00', so stored
 * values are always UTC.
 */
final class UtcTimestamp
{
    public static function toIso(string $databaseDateTime): string
    {
        return str_replace(' ', 'T', $databaseDateTime) . 'Z';
    }

    /** Same as toIso(), but passes NULL columns through. */
    public static function toIsoOrNull(mixed $databaseDateTime): ?string
    {
        return $databaseDateTime === null ? null : self::toIso((string) $databaseDateTime);
    }
}
