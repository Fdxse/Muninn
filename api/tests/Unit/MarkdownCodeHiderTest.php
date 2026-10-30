<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Unit;

use Muninn\Api\Notes\MarkdownCodeHider;
use PHPUnit\Framework\TestCase;

/** Magic Links that hide code get notes with every piece of code taken out (D073). */
final class MarkdownCodeHiderTest extends TestCase
{
    public function testFencedCodeBlocksBecomeAPlaceholder(): void
    {
        $markdown = "Before\n```powershell\nGet-ChildItem | Remove-Item\n```\nAfter";

        $hiddenMarkdown = MarkdownCodeHider::withoutCode($markdown);

        self::assertStringNotContainsString('Get-ChildItem', $hiddenMarkdown);
        self::assertStringNotContainsString('```', $hiddenMarkdown);
        self::assertSame("Before\n\n*[Code hidden]*\n\nAfter", $hiddenMarkdown);
    }

    public function testTildeFencesAndLongerFencesAreHiddenToTheirOwnClosingFence(): void
    {
        // A ``` line inside a ```` fence does not close it.
        $markdown = "````\n```\nsecret one\n```\n````\n~~~ps1\nsecret two\n~~~\nVisible";

        $hiddenMarkdown = MarkdownCodeHider::withoutCode($markdown);

        self::assertStringNotContainsString('secret', $hiddenMarkdown);
        self::assertStringContainsString('Visible', $hiddenMarkdown);
    }

    public function testAnUnclosedFenceHidesEverythingAfterIt(): void
    {
        $hiddenMarkdown = MarkdownCodeHider::withoutCode("Intro\n```\nsecret\nstill secret");

        self::assertSame("Intro\n\n*[Code hidden]*\n", $hiddenMarkdown);
    }

    public function testIndentedCodeBlocksAreHiddenButParagraphContinuationsAreNot(): void
    {
        $markdown = "Text\n\n    secret code\n\tsecret tab line\n\nBack to text\n    still the same paragraph";

        $hiddenMarkdown = MarkdownCodeHider::withoutCode($markdown);

        self::assertStringNotContainsString('secret', $hiddenMarkdown);
        self::assertStringContainsString('*[Code hidden]*', $hiddenMarkdown);
        self::assertStringContainsString('    still the same paragraph', $hiddenMarkdown);
    }

    public function testListContinuationsStayButDeeplyIndentedCodeInAListIsHidden(): void
    {
        $markdown = "- First item\n\n    more about the first item\n- Second item\n\n        secret code in a list";

        $hiddenMarkdown = MarkdownCodeHider::withoutCode($markdown);

        self::assertStringContainsString('more about the first item', $hiddenMarkdown);
        self::assertStringNotContainsString('secret', $hiddenMarkdown);
    }

    public function testFencesInsideListsAndQuotesAreHidden(): void
    {
        $markdown = "- Step:\n  ```\n  secret in list\n  ```\n\n> ```\n> secret in quote\n> ```";

        $hiddenMarkdown = MarkdownCodeHider::withoutCode($markdown);

        self::assertStringNotContainsString('secret', $hiddenMarkdown);
        self::assertStringContainsString('  *[Code hidden]*', $hiddenMarkdown);
        self::assertStringContainsString('> *[Code hidden]*', $hiddenMarkdown);
    }

    public function testInlineCodeIsHiddenAlsoAcrossALineBreak(): void
    {
        $markdown = "Run `Invoke-Expression` or ``Get-Item `x` ``\nand `Start-\nProcess` too.";

        $hiddenMarkdown = MarkdownCodeHider::withoutCode($markdown);

        self::assertSame("Run [code hidden] or [code hidden]\nand [code hidden] too.", $hiddenMarkdown);
    }

    public function testBackticksOnOneLineAreInlineCodeNotAFence(): void
    {
        self::assertSame('Use [code hidden] here', MarkdownCodeHider::withoutCode('Use ```Get-Item``` here'));
    }

    public function testCodeHtmlElementsAreHiddenAndAnUnclosedOneHidesTheRest(): void
    {
        self::assertSame('A [code hidden] B', MarkdownCodeHider::withoutCode("A <pre class=\"x\">secret\nlines</pre> B"));
        self::assertSame('A [code hidden] B', MarkdownCodeHider::withoutCode('A <CODE>secret</CODE> B'));
        self::assertSame('A [code hidden]', MarkdownCodeHider::withoutCode("A <samp>secret\nand more"));
        // Other tags are left alone.
        self::assertSame('A <b>bold</b> <presentation>', MarkdownCodeHider::withoutCode('A <b>bold</b> <presentation>'));
    }

    public function testTextWithoutCodeIsUnchanged(): void
    {
        $markdown = "# Shopping\n\n- [ ] Milk\n- [x] Bread\n\n> A quote with *emphasis*.";

        self::assertSame($markdown, MarkdownCodeHider::withoutCode($markdown));
    }
}
