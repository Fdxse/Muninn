<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Unit;

use Muninn\Api\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The API can be served from a host root (api.dx.se) or a sub-folder (fehre.synology.me/muninn/).
 * Routes must see the same /api/v1/... path in both cases.
 */
final class RequestInstallFolderTest extends TestCase
{
    public function testHostRootInstallLeavesPathUnchanged(): void
    {
        self::assertSame('/api/v1/health', Request::stripInstallFolder('/api/v1/health', '/index.php'));
    }

    public function testSubFolderInstallIsRemovedFromPath(): void
    {
        self::assertSame('/api/v1/health', Request::stripInstallFolder('/muninn/api/v1/health', '/muninn/index.php'));
    }

    public function testNestedSubFolderInstallIsRemovedFromPath(): void
    {
        self::assertSame(
            '/api/v1/auth/me',
            Request::stripInstallFolder('/apps/muninn/api/v1/auth/me', '/apps/muninn/index.php'),
        );
    }

    public function testFolderNameThatOnlySharesAPrefixIsNotStripped(): void
    {
        // "/muninnx/..." is not inside "/muninn", so nothing may be removed.
        self::assertSame('/muninnx/api/v1/health', Request::stripInstallFolder('/muninnx/api/v1/health', '/muninn/index.php'));
    }

    public function testMissingScriptNameLeavesPathUnchanged(): void
    {
        self::assertSame('/api/v1/health', Request::stripInstallFolder('/api/v1/health', ''));
    }
}
