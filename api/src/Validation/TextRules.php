<?php

declare(strict_types=1);

namespace Muninn\Api\Validation;

/**
 * Validation for user-entered text: workspace names, note titles and note content.
 * Each method returns an error message for the client, or null when the value is acceptable.
 */
final class TextRules
{
    /**
     * A one-line label such as a workspace name or note title (already trimmed by the caller).
     * Control characters (including line breaks) are refused: they can confuse logs and UIs.
     */
    public static function singleLineError(string $trimmedText, int $maximumCharacters, bool $isRequired): ?string
    {
        if ($isRequired && $trimmedText === '') {
            return 'This field is required.';
        }
        if (!mb_check_encoding($trimmedText, 'UTF-8')) {
            return 'The text is not valid UTF-8.';
        }
        if (mb_strlen($trimmedText) > $maximumCharacters) {
            return 'Use at most ' . $maximumCharacters . ' characters.';
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $trimmedText)) {
            return 'The text contains invalid characters.';
        }

        return null;
    }

    /**
     * Multi-line text such as note content. Line breaks and tabs are fine; the NUL character
     * and invalid UTF-8 are refused, and the size is limited in bytes (what the database stores).
     */
    public static function multiLineError(string $text, int $maximumBytes): ?string
    {
        if (strlen($text) > $maximumBytes) {
            return 'The text is too long (at most ' . number_format($maximumBytes / 1000) . ' kB).';
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            return 'The text is not valid UTF-8.';
        }
        if (str_contains($text, "\0")) {
            return 'The text contains invalid characters.';
        }

        return null;
    }
}
