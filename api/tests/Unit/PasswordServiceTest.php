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

    public function testPreferredAlgorithmIsSupportedByThisBuild(): void
    {
        $preferredAlgorithm = PasswordService::preferredAlgorithm();

        self::assertContains($preferredAlgorithm, password_algos());
    }

    public function testSourceNeverUsesArgon2ConstantDirectly(): void
    {
        // Regression: PASSWORD_ARGON2ID is undefined on PHP builds without Argon2 support
        // (e.g. Synology with sodium off), and a bare reference is a fatal error there.
        // It may only appear as a quoted string, inside defined()/constant().
        $sourceFolder = dirname(__DIR__, 2) . '/src';
        $sourceFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceFolder, \FilesystemIterator::SKIP_DOTS));
        foreach ($sourceFiles as $sourceFile) {
            // Tokenize so that comments and string literals are ignored; only bare identifiers count.
            foreach (token_get_all((string) file_get_contents($sourceFile->getPathname())) as $sourceToken) {
                if (is_array($sourceToken) && $sourceToken[0] === T_STRING) {
                    self::assertStringStartsNotWith(
                        'PASSWORD_ARGON2',
                        $sourceToken[1],
                        $sourceFile->getFilename() . ' must check PASSWORD_ARGON2* with defined() and read it with constant().'
                    );
                }
            }
        }
    }
}
