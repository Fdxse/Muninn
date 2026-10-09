<?php

declare(strict_types=1);

namespace Muninn\Api\Http;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Small helpers for reading typed fields from a decoded JSON body.
 */
final class InputReader
{
    /**
     * Returns a string field, or null when it is missing.
     *
     * @param array<string, mixed> $body
     * @throws HttpException 422 when the field is present but not a string.
     */
    public static function optionalString(array $body, string $fieldName): ?string
    {
        if (!array_key_exists($fieldName, $body) || $body[$fieldName] === null) {
            return null;
        }
        if (!is_string($body[$fieldName])) {
            throw HttpException::validation([$fieldName => 'Must be a string.']);
        }

        return $body[$fieldName];
    }

    /**
     * Returns a required string field.
     *
     * @param array<string, mixed> $body
     * @throws HttpException 422 when missing or not a string.
     */
    public static function requiredString(array $body, string $fieldName): string
    {
        $fieldValue = self::optionalString($body, $fieldName);
        if ($fieldValue === null || $fieldValue === '') {
            throw HttpException::validation([$fieldName => 'This field is required.']);
        }

        return $fieldValue;
    }

    /**
     * Returns an integer field, or null when it is missing.
     *
     * @param array<string, mixed> $body
     * @throws HttpException 422 when present but not an integer.
     */
    public static function optionalInt(array $body, string $fieldName): ?int
    {
        if (!array_key_exists($fieldName, $body) || $body[$fieldName] === null) {
            return null;
        }
        if (!is_int($body[$fieldName])) {
            throw HttpException::validation([$fieldName => 'Must be a whole number.']);
        }

        return $body[$fieldName];
    }

    /**
     * Reads an optional ISO 8601 timestamp with an explicit offset ("...Z" or "...+02:00") and
     * returns it in UTC, whole seconds. A timestamp without an offset is refused rather than
     * guessed, so no server time zone is ever assumed.
     *
     * @param array<string, mixed> $requestBody
     * @param array<string, string> $fieldErrors Receives the field's error, if any.
     */
    public static function optionalUtcTimestamp(array $requestBody, string $fieldName, array &$fieldErrors): ?DateTimeImmutable
    {
        $timestampInput = self::optionalString($requestBody, $fieldName);
        if ($timestampInput === null || $timestampInput === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/', $timestampInput)) {
            $fieldErrors[$fieldName] = 'Use a date and time like 2026-10-31T18:00:00Z.';
            return null;
        }
        try {
            $parsedMoment = new DateTimeImmutable($timestampInput);
        } catch (Exception) {
            $fieldErrors[$fieldName] = 'This is not a valid date and time.';
            return null;
        }
        $momentUtc = $parsedMoment->setTimezone(new DateTimeZone('UTC'));

        // Stored as whole seconds; drop any fraction so what is shown is exactly what applies.
        return $momentUtc->setTime((int) $momentUtc->format('G'), (int) $momentUtc->format('i'), (int) $momentUtc->format('s'));
    }
}
