<?php

declare(strict_types=1);

namespace Muninn\Api\Notes;

/**
 * Turns the start of a note's Markdown into a short, readable one-line preview for note lists,
 * the Trash and search results (D051), so "# Shopping" shows as "Shopping".
 *
 * This is deliberately not a Markdown parser: it only removes the common markers line by line
 * (headings, quotes, list bullets, emphasis, code fences, links, images, HTML tags). Checklist
 * boxes become ☐ and ☑ so a to-do list still reads as one. The result is plain text that the
 * frontend shows with textContent, so nothing here needs to be HTML-safe.
 */
final class MarkdownExcerpt
{
    /** Checklist box shown for "- [ ]" items. */
    private const OPEN_CHECKBOX = '☐';

    /** Checklist box shown for "- [x]" items. */
    private const TICKED_CHECKBOX = '☑';

    /**
     * Plain-text preview of at most $maximumLength characters. An ellipsis marks text that was
     * cut, either here or already by the caller ($sourceWasCut, when only the start of the note
     * was read from the database). When the note starts with its own title (usually as a
     * "# Title" heading), that first line is left out, because the list already shows the title.
     */
    public static function preview(string $markdown, int $maximumLength, bool $sourceWasCut = false, string $noteTitle = ''): string
    {
        $plainLines = self::plainLines($markdown);
        $normalisedTitle = mb_strtolower(trim($noteTitle));
        if ($normalisedTitle !== '' && $plainLines !== [] && mb_strtolower($plainLines[0]) === $normalisedTitle) {
            array_shift($plainLines);
        }
        $plainText = self::joinedLines($plainLines);
        if (mb_strlen($plainText) > $maximumLength) {
            // Cut at the last space before the limit, so no word is chopped in half.
            $cutText = mb_substr($plainText, 0, $maximumLength);
            $lastSpacePosition = mb_strrpos($cutText, ' ');
            if ($lastSpacePosition !== false && $lastSpacePosition > $maximumLength / 2) {
                $cutText = mb_substr($cutText, 0, $lastSpacePosition);
            }

            return rtrim($cutText) . '…';
        }

        return ($sourceWasCut && $plainText !== '') ? $plainText . '…' : $plainText;
    }

    /** The Markdown with its markers removed, as one line with single spaces. */
    public static function plainText(string $markdown): string
    {
        return self::joinedLines(self::plainLines($markdown));
    }

    /**
     * The non-empty lines of the Markdown with their markers removed.
     *
     * @return list<string>
     */
    private static function plainLines(string $markdown): array
    {
        $plainLines = [];
        foreach (preg_split('/\R/u', $markdown) ?: [] as $markdownLine) {
            $plainLine = self::plainLine($markdownLine);
            if ($plainLine !== '') {
                $plainLines[] = $plainLine;
            }
        }

        return $plainLines;
    }

    /**
     * Lines joined into one line with single spaces.
     *
     * @param list<string> $plainLines
     */
    private static function joinedLines(array $plainLines): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $plainLines)));
    }

    /** One line without its block markers (heading, quote, bullet) and inline markers. */
    private static function plainLine(string $markdownLine): string
    {
        $line = trim($markdownLine);

        // Lines that are only structure: code fences, horizontal rules, setext heading
        // underlines and table separator rows carry no words.
        if (preg_match('/^(`{3,}|~{3,})/u', $line) === 1
            || preg_match('/^([-*_=]\s*){3,}$/u', $line) === 1
            || preg_match('/^\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?$/u', $line) === 1) {
            return '';
        }

        // Quote markers, possibly nested ("> > text").
        $line = (string) preg_replace('/^(>\s?)+/u', '', $line);
        // Heading markers "# " to "###### ", and optional closing hashes.
        $line = (string) preg_replace('/^#{1,6}\s+(.*?)(\s+#+)?$/u', '$1', $line);
        // Checklist items keep a box; "- [ ] milk" becomes "☐ milk".
        $line = (string) preg_replace('/^([-*+]|\d+[.)])\s+\[ \]\s*/u', self::OPEN_CHECKBOX . ' ', $line);
        $line = (string) preg_replace('/^([-*+]|\d+[.)])\s+\[[xX]\]\s*/u', self::TICKED_CHECKBOX . ' ', $line);
        // Ordinary bullets and numbered-list markers.
        $line = (string) preg_replace('/^([-*+]|\d+[.)])\s+/u', '', $line);
        // Table cell borders become spaces.
        $line = str_replace('|', ' ', $line);

        return trim(self::withoutInlineMarkers($line));
    }

    /** Removes images, links, HTML tags, emphasis, inline code and backslash escapes. */
    private static function withoutInlineMarkers(string $text): string
    {
        $replacementsByPattern = [
            // Images say nothing in a one-line preview (usually "image.png"), so drop them.
            '/!\[[^\]]*\]\([^)]*\)/u' => '',
            // Links keep their text: "[Muninn](https://…)" becomes "Muninn".
            '/\[([^\]]+)\]\([^)]*\)/u' => '$1',
            // Autolinks keep the address: "<https://dx.se>" becomes "https://dx.se".
            '/<((?:https?|mailto):[^>\s]+)>/u' => '$1',
            // Any other HTML tag.
            '/<\/?[a-zA-Z][^>]*>/u' => '',
            // Inline code keeps its text.
            '/`+([^`]*)`+/u' => '$1',
            // Bold, then italic, then strikethrough. Underscores only count at word edges, so
            // snake_case_names stay intact.
            '/(\*\*|__)(\S(?:.*?\S)?)\1/u' => '$2',
            '/(?<![\w*])\*(\S(?:[^*]*?\S)?)\*(?![\w*])/u' => '$1',
            '/(?<!\w)_(\S(?:[^_]*?\S)?)_(?!\w)/u' => '$1',
            '/~~(\S(?:.*?\S)?)~~/u' => '$1',
            // "\#" and friends show the character itself.
            '/\\\\([\\\\`*_{}\[\]()#+\-.!>~|])/u' => '$1',
        ];

        foreach ($replacementsByPattern as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }
}
