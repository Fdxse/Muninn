<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Unit;

use Muninn\Api\Notes\MarkdownExcerpt;
use PHPUnit\Framework\TestCase;

/** The note-list preview shows readable text instead of raw Markdown (D051). */
final class MarkdownExcerptTest extends TestCase
{
    public function testHeadingsQuotesAndBulletsLoseTheirMarkers(): void
    {
        $markdown = "# Shopping list\n\n> Remember the *good* coffee\n\n- Milk\n- **Bread**\n1. Eggs";

        self::assertSame('Shopping list Remember the good coffee Milk Bread Eggs', MarkdownExcerpt::plainText($markdown));
    }

    public function testChecklistBoxesStayVisible(): void
    {
        self::assertSame('☐ Call Anna ☑ Book room', MarkdownExcerpt::plainText("- [ ] Call Anna\n- [x] Book room"));
    }

    public function testLinksKeepTheirTextAndImagesDisappear(): void
    {
        $markdown = "See [the manual](https://example.com/manual) ![image.png](attachment:0b1c2d3e-0000-4000-8000-000000000000) and <https://dx.se>";

        self::assertSame('See the manual and https://dx.se', MarkdownExcerpt::plainText($markdown));
    }

    public function testCodeFencesAndRulesDisappearButCodeStays(): void
    {
        $markdown = "Run this:\n\n```sh\nsudo php84 bin/migrate.php\n```\n\n---\n\nUse `snake_case_names` and ~~old~~ text";

        self::assertSame('Run this: sudo php84 bin/migrate.php Use snake_case_names and old text', MarkdownExcerpt::plainText($markdown));
    }

    public function testTablesBecomePlainWords(): void
    {
        self::assertSame('Day Task Mon Gym', MarkdownExcerpt::plainText("| Day | Task |\n|---|---|\n| Mon | Gym |"));
    }

    public function testLongTextIsCutAtAWordWithAnEllipsis(): void
    {
        $preview = MarkdownExcerpt::preview('# Title ' . str_repeat('word ', 50), 40);

        self::assertSame('Title word word word word word word…', $preview);
        self::assertLessThanOrEqual(41, mb_strlen($preview));
    }

    public function testAHeadingRepeatingTheTitleIsLeftOut(): void
    {
        self::assertSame('Milk Bread', MarkdownExcerpt::preview("# Shopping list\n\n- Milk\n- Bread", 160, false, 'Shopping List'));
        self::assertSame('Shopping list for Friday Milk', MarkdownExcerpt::preview("# Shopping list for Friday\n- Milk", 160, false, 'Shopping list'));
    }

    public function testTextCutByTheCallerGetsAnEllipsis(): void
    {
        self::assertSame('Short…', MarkdownExcerpt::preview('Short', 160, true));
        self::assertSame('Short', MarkdownExcerpt::preview('Short', 160));
        self::assertSame('', MarkdownExcerpt::preview('', 160, true));
    }
}
