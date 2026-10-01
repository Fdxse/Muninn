<?php

declare(strict_types=1);

namespace Muninn\Api\Users;

/**
 * Validation rules for usernames and display names, shared by the API and the CLI.
 */
final class UserInputRules
{
    /** Lowercase letters, digits, dot, underscore and hyphen; 3 to 32 characters. */
    private const USERNAME_PATTERN = '/^[a-z0-9._-]{3,32}$/';

    private const DISPLAY_NAME_MAX_LENGTH = 100;

    public static function normaliseUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    /** Returns an error message, or null when the username is acceptable. */
    public static function usernameError(string $username): ?string
    {
        if (!preg_match(self::USERNAME_PATTERN, self::normaliseUsername($username))) {
            return 'Use 3 to 32 characters: letters, digits, dot, underscore or hyphen.';
        }

        return null;
    }

    /** Returns an error message, or null when the display name is acceptable. */
    public static function displayNameError(string $displayName): ?string
    {
        $trimmedDisplayName = trim($displayName);
        if ($trimmedDisplayName === '') {
            return 'Enter a display name.';
        }
        if (mb_strlen($trimmedDisplayName) > self::DISPLAY_NAME_MAX_LENGTH) {
            return 'Use at most ' . self::DISPLAY_NAME_MAX_LENGTH . ' characters.';
        }
        // Control characters have no place in a name and can confuse logs and UIs.
        if (preg_match('/[\x00-\x1F\x7F]/u', $trimmedDisplayName)) {
            return 'The display name contains invalid characters.';
        }

        return null;
    }
}
