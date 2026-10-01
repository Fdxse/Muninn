<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Unit;

use Muninn\Api\Auth\PasswordService;
use PHPUnit\Framework\TestCase;

final class PasswordServiceTest extends TestCase
{
    public function testShortPasswordsAreRejected(): void
    {
        $passwordService = new PasswordService();

        self::assertNotNull($passwordService->policyError('elevenchars'));
        self::assertNull($passwordService->policyError('twelve chars'));
    }

    public function testBcryptCapsLengthAt72Bytes(): void
    {
        // bcrypt ignores bytes after 72, so longer passwords must be refused, not truncated.
        $bcryptPasswordService = new PasswordService(PASSWORD_BCRYPT);

        self::assertNull($bcryptPasswordService->policyError(str_repeat('a', 72)));
        self::assertNotNull($bcryptPasswordService->policyError(str_repeat('a', 73)));
    }

    public function testHashVerifiesAndIsNotPlaintext(): void
    {
        $passwordService = new PasswordService();
        $passwordHash = $passwordService->hash('a sufficiently long password');

        self::assertStringNotContainsString('sufficiently', $passwordHash);
        self::assertTrue($passwordService->verify('a sufficiently long password', $passwordHash));
        self::assertFalse($passwordService->verify('a different long password', $passwordHash));
    }
}
