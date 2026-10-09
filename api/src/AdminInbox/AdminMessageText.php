<?php

declare(strict_types=1);

namespace Muninn\Api\AdminInbox;

use Muninn\Api\Validation\TextRules;

/**
 * The rules for the text of a message to or from the administrator (D058, D065), shared by the
 * first "Contact admin" message and every reply, so both sides follow the same limits.
 */
final class AdminMessageText
{
    /** Windows and old Mac line endings become "\n"; surrounding spaces and blank lines go. */
    public static function normalize(?string $rawText): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $rawText ?? ''));
    }

    /** Why the (normalized) text cannot be stored, or null when it can. */
    public static function error(string $messageText): ?string
    {
        if ($messageText === '') {
            return 'Write a message.';
        }
        if (mb_strlen($messageText) > AdminConversationService::MESSAGE_MAX_LENGTH) {
            return 'Use at most ' . AdminConversationService::MESSAGE_MAX_LENGTH . ' characters.';
        }

        // Four bytes per character is the most UTF-8 needs, so this only catches bad encoding and NUL.
        return TextRules::multiLineError($messageText, AdminConversationService::MESSAGE_MAX_LENGTH * 4);
    }
}
