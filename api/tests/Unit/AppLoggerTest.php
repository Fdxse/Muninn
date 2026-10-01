<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Unit;

use Muninn\Api\Logging\AppLogger;
use PHPUnit\Framework\TestCase;

final class AppLoggerTest extends TestCase
{
    public function testSecretLookingKeysAreRedactedRecursively(): void
    {
        $redactedContext = AppLogger::redact([
            'username' => 'alice',
            'password' => 'hunter2hunter2',
            'nested' => ['session_token' => 'abc', 'CSRF-Token' => 'def', 'safe' => 'kept'],
        ]);

        self::assertSame('alice', $redactedContext['username']);
        self::assertSame('[redacted]', $redactedContext['password']);
        self::assertSame('[redacted]', $redactedContext['nested']['session_token']);
        self::assertSame('[redacted]', $redactedContext['nested']['CSRF-Token']);
        self::assertSame('kept', $redactedContext['nested']['safe']);
    }
}
