<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Unit;

use Muninn\Api\Security\PhpSecurityBaseline;
use PHPUnit\Framework\TestCase;

/** D069: which PHP versions are patched against CVE-2026-109618 and CVE-2026-109619. */
final class PhpSecurityBaselineTest extends TestCase
{
    public function testReleasesBeforeTheFixOnEachBranchNeedAnUpdate(): void
    {
        self::assertSame('8.2.35', PhpSecurityBaseline::requiredUpdateFor('8.2.34'));
        self::assertSame('8.3.36', PhpSecurityBaseline::requiredUpdateFor('8.3.6'));
        self::assertSame('8.4.27', PhpSecurityBaseline::requiredUpdateFor('8.4.26'));
        self::assertSame('8.4.27', PhpSecurityBaseline::requiredUpdateFor('8.4.0'));
        self::assertSame('8.5.12', PhpSecurityBaseline::requiredUpdateFor('8.5.11'));
    }

    public function testPatchedAndLaterReleasesNeedNothing(): void
    {
        self::assertNull(PhpSecurityBaseline::requiredUpdateFor('8.2.35'));
        self::assertNull(PhpSecurityBaseline::requiredUpdateFor('8.3.36'));
        self::assertNull(PhpSecurityBaseline::requiredUpdateFor('8.4.27'));
        self::assertNull(PhpSecurityBaseline::requiredUpdateFor('8.4.30'));
        self::assertNull(PhpSecurityBaseline::requiredUpdateFor('8.5.12'));
    }

    public function testDistributionSuffixesAreIgnored(): void
    {
        // Synology package numbering and Debian package versions put text after the PHP release.
        self::assertNull(PhpSecurityBaseline::requiredUpdateFor('8.4.27-0051'));
        self::assertNull(PhpSecurityBaseline::requiredUpdateFor('8.4.27-1~deb13u1'));
        self::assertSame('8.4.27', PhpSecurityBaseline::requiredUpdateFor('8.4.26-0049'));
    }

    public function testNewerBranchesAreAssumedPatchedAndOlderOnesAreNot(): void
    {
        self::assertNull(PhpSecurityBaseline::requiredUpdateFor('8.6.0RC4'));
        self::assertNull(PhpSecurityBaseline::requiredUpdateFor('9.0.0'));
        self::assertSame('8.2.35', PhpSecurityBaseline::requiredUpdateFor('8.1.33'));
        self::assertSame('8.2.35', PhpSecurityBaseline::requiredUpdateFor('7.4.33'));
    }
}
