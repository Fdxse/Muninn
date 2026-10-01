<?php

declare(strict_types=1);

namespace Muninn\Api\Http;

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
}
