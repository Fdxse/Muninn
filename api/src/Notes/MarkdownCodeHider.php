<?php

declare(strict_types=1);

namespace Muninn\Api\Notes;

/**
 * Takes every piece of code out of a note's Markdown, for Magic Links that hide code (D073).
 *
 * The API calls this before a note leaves the server, so the code never travels to the visitor's
 * browser at all. Hiding it only on screen would not be enough: it would still cross the network.
 *
 * Removed, each replaced by a short placeholder:
 *   - fenced code blocks (``` or ~~~), including an unclosed fence, which runs to the end
 *   - indented code blocks (4 spaces or a tab, after a blank line)
 *   - inline code (`like this`)
 *   - HTML elements that show raw text: <pre>, <code>, <samp> and similar
 *
 * Like MarkdownExcerpt this is deliberately not a full Markdown parser. Where it cannot be sure,
 * it hides too much rather than too little: a doubtful line is treated as code.
 */
final class MarkdownCodeHider
{
    /** Shown where a code block was. */
    public const BLOCK_PLACEHOLDER = '*[Code hidden]*';

    /** Shown where inline code was. */
    public const INLINE_PLACEHOLDER = '[code hidden]';

    /** Columns of indentation that make an indented code block (CommonMark). */
    private const CODE_INDENT_COLUMNS = 4;

    /** HTML elements whose content the browser shows as raw text or code. */
    private const CODE_HTML_ELEMENTS = 'pre|code|samp|xmp|listing|plaintext|textarea';

    /** The Markdown with all code replaced by placeholders. Line endings become "\n". */
    public static function withoutCode(string $markdown): string
    {
        $outputLines = self::withoutCodeBlocks(preg_split('/\R/u', $markdown) ?: []);
        $textWithoutInlineCode = self::withoutInlineCode($outputLines);

        return self::withoutCodeHtml($textWithoutInlineCode);
    }

    /**
     * Replaces fenced and indented code blocks, line by line.
     *
     * Returns the lines as [isText, line] pairs: isText is false for placeholder lines, so the
     * inline code step never joins text across a hidden block.
     *
     * @param list<string> $markdownLines
     * @return list<array{0: bool, 1: string}>
     */
    private static function withoutCodeBlocks(array $markdownLines): array
    {
        $outputLines = [];
        /** The open fence: its character ("`" or "~") and length, or null outside a fence. */
        $openFenceCharacter = null;
        $openFenceLength = 0;
        $insideIndentedCode = false;
        $insideList = false;
        $previousLineBlank = true;

        foreach ($markdownLines as $markdownLine) {
            // Block quote markers ("> ", "> > ") are kept, and the rest of the line is examined.
            preg_match('/^((?:[ ]{0,3}>[ ]?)*)(.*)$/su', $markdownLine, $lineParts);
            $quotePrefix = $lineParts[1];
            $lineContent = $lineParts[2];
            $lineIsBlank = trim($lineContent) === '';
            $indentColumns = self::indentColumns($lineContent);

            // Inside a fence: hide every line until the closing fence (or the end of the note).
            if ($openFenceCharacter !== null) {
                $closingFencePattern = '/^\s*' . preg_quote($openFenceCharacter, '/') . '{' . $openFenceLength . ',}\s*$/u';
                if (preg_match($closingFencePattern, $lineContent) === 1) {
                    $openFenceCharacter = null;
                    $previousLineBlank = true;
                }
                continue;
            }

            // Inside an indented code block: keep hiding indented lines. Blank lines are kept
            // as they are, since they show nothing.
            if ($insideIndentedCode) {
                if ($lineIsBlank) {
                    $outputLines[] = [true, rtrim($quotePrefix)];
                    $previousLineBlank = true;
                    continue;
                }
                if ($indentColumns >= self::CODE_INDENT_COLUMNS) {
                    continue;
                }
                $insideIndentedCode = false;
            }

            // A fence opens a code block. A backtick fence whose rest holds another backtick is
            // inline code instead (```like this```), which the inline step handles.
            if (preg_match('/^(\s*)(`{3,}|~{3,})(.*)$/u', $lineContent, $fenceParts) === 1
                && !($fenceParts[2][0] === '`' && str_contains($fenceParts[3], '`'))) {
                $openFenceCharacter = $fenceParts[2][0];
                $openFenceLength = strlen($fenceParts[2]);
                self::addPlaceholder($outputLines, $quotePrefix, $insideList ? $fenceParts[1] : '');
                $previousLineBlank = true;
                continue;
            }

            // An indented code block starts after a blank line. Inside a list, indented lines
            // usually belong to the list item, so only deeper indentation counts as code there,
            // and a nested list item is never code.
            $startsListItem = self::startsListItem($lineContent);
            $codeIndentHere = $insideList ? self::CODE_INDENT_COLUMNS * 2 : self::CODE_INDENT_COLUMNS;
            if (!$lineIsBlank && $previousLineBlank && $indentColumns >= $codeIndentHere
                && !($insideList && $startsListItem)) {
                $insideIndentedCode = true;
                self::addPlaceholder($outputLines, $quotePrefix, '');
                $previousLineBlank = true;
                continue;
            }

            // An ordinary line. Keep track of whether we are inside a list.
            if ($startsListItem) {
                $insideList = true;
            } elseif (!$lineIsBlank && $indentColumns === 0 && $previousLineBlank) {
                // Unindented text after a blank line ends the list.
                $insideList = false;
            }
            $outputLines[] = [true, $markdownLine];
            $previousLineBlank = $lineIsBlank;
        }

        return $outputLines;
    }

    /**
     * Adds a block placeholder with a blank line before and after it, so it is never glued to
     * the paragraph around it.
     *
     * @param list<array{0: bool, 1: string}> $outputLines
     */
    private static function addPlaceholder(array &$outputLines, string $quotePrefix, string $indentation): void
    {
        $blankLine = rtrim($quotePrefix);
        $lastOutputLine = $outputLines === [] ? null : $outputLines[array_key_last($outputLines)];
        if ($lastOutputLine !== null && trim($lastOutputLine[1], " \t>") !== '') {
            $outputLines[] = [false, $blankLine];
        }
        $outputLines[] = [false, $quotePrefix . $indentation . self::BLOCK_PLACEHOLDER];
        $outputLines[] = [false, $blankLine];
    }

    /**
     * Replaces inline code. Text lines between placeholders are joined first, so a code span
     * that continues onto the next line is found too (but never across a blank line).
     *
     * @param list<array{0: bool, 1: string}> $outputLines
     */
    private static function withoutInlineCode(array $outputLines): string
    {
        $finishedParts = [];
        $paragraphLines = [];
        $finishParagraph = static function () use (&$finishedParts, &$paragraphLines): void {
            if ($paragraphLines === []) {
                return;
            }
            // A run of backticks, any text, and a run of the same length closes it.
            $finishedParts[] = (string) preg_replace(
                '/(?<!`)(`+)(?!`).+?(?<!`)\1(?!`)/su',
                self::INLINE_PLACEHOLDER,
                implode("\n", $paragraphLines),
            );
            $paragraphLines = [];
        };

        foreach ($outputLines as [$isText, $outputLine]) {
            if ($isText && trim($outputLine, " \t>") !== '') {
                $paragraphLines[] = $outputLine;
                continue;
            }
            $finishParagraph();
            $finishedParts[] = $outputLine;
        }
        $finishParagraph();

        return implode("\n", $finishedParts);
    }

    /**
     * Replaces HTML elements that show code. An element that is never closed hides everything
     * after it, because the browser would show all of that inside the element.
     */
    private static function withoutCodeHtml(string $markdown): string
    {
        $codeElementPattern = '#<(' . self::CODE_HTML_ELEMENTS . ')(?=[\s>/])[^>]*>.*?(?:</\1\s*>|\z)#isu';

        return (string) preg_replace($codeElementPattern, self::INLINE_PLACEHOLDER, $markdown);
    }

    /** True when the line starts a list item ("- ", "* ", "+ ", "1. ", "1) "), at any depth. */
    private static function startsListItem(string $lineContent): bool
    {
        return preg_match('/^\s*([-*+]|\d{1,9}[.)])(\s|$)/u', $lineContent) === 1;
    }

    /** How many columns of indentation the line starts with; a tab moves to the next multiple of 4. */
    private static function indentColumns(string $lineContent): int
    {
        $columns = 0;
        $characterCount = strlen($lineContent);
        for ($position = 0; $position < $characterCount; $position++) {
            if ($lineContent[$position] === ' ') {
                $columns++;
            } elseif ($lineContent[$position] === "\t") {
                $columns += self::CODE_INDENT_COLUMNS - ($columns % self::CODE_INDENT_COLUMNS);
            } else {
                break;
            }
        }

        return $columns;
    }
}
