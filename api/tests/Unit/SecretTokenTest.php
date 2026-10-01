<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Unit;

use Muninn\Api\Security\SecretToken;
use Muninn\Api\Security\UuidGenerator;
use PHPUnit\Framework\TestCase;

final class SecretTokenTest extends TestCase
{
    public function testTokensAreUniqueUrlSafeAndHashed(): void
    {
        $firstToken = SecretToken::generate();
        $secondToken = SecretToken::generate();

        self::assertNotSame($firstToken, $secondToken);
        self::assertTrue(SecretToken::looksValid($firstToken));
        self::assertSame(64, strlen(SecretToken::hash($firstToken)));
        self::assertFalse(SecretToken::looksValid('../../etc/passwd'));
    }

    public function testUuidsAreVersion4(): void
    {
        $generatedUuid = UuidGenerator::generate();

        self::assertTrue(UuidGenerator::isValid($generatedUuid));
        self::assertSame('4', $generatedUuid[14]);
    }
}
