<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Http\Response;
use Muninn\Api\Security\SecretToken;
use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * Password reset links created by an administrator (D040), and changing one's own password.
 */
final class PasswordResetTest extends WorkspaceTestCase
{
    private const NEW_PASSWORD = 'a brand new long password';

    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $admin;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->signedInUser('sysadmin', true);
        $this->alice = $this->signedInUser('alice');
    }

    /** Creates a reset link for $userId as admin and returns the raw token from it. */
    private function createResetToken(string $userId, array $body = []): string
    {
        $createResponse = $this->sendAs($this->admin, 'POST', '/api/v1/admin/users/' . $userId . '/password-reset', $body);
        self::assertSame(201, $createResponse->statusCode(), $createResponse->body());

        $resetUrl = (string) $createResponse->json()['data']['reset_url'];
        self::assertStringStartsWith('https://www.dx.se/reset-password.php#token=', $resetUrl);

        return substr($resetUrl, strpos($resetUrl, '#token=') + strlen('#token='));
    }

    private function complete(string $rawToken, string $newPassword = self::NEW_PASSWORD): Response
    {
        return $this->send('POST', '/api/v1/password-resets/complete', ['token' => $rawToken, 'password' => $newPassword]);
    }

    public function testResetLinkSetsNewPasswordAndSignsOutEverywhere(): void
    {
        $rawToken = $this->createResetToken($this->alice['user_id']);

        // Only the hash is stored.
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM password_resets WHERE token_hash = :hash', ['hash' => SecretToken::hash($rawToken)]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM password_resets WHERE token_hash = :raw', ['raw' => $rawToken]));

        $inspectResponse = $this->send('POST', '/api/v1/password-resets/inspect', ['token' => $rawToken]);
        self::assertSame(200, $inspectResponse->statusCode(), $inspectResponse->body());
        self::assertSame('alice', $inspectResponse->json()['data']['username']);

        self::assertSame(204, $this->complete($rawToken)->statusCode());

        // Alice's open session is gone, the old password fails and the new one works.
        $this->assertError($this->sendAs($this->alice, 'GET', '/api/v1/auth/me'), 401, 'unauthenticated');
        $this->assertError($this->send('POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => self::DEFAULT_PASSWORD]), 401, 'invalid_credentials');
        $this->login('alice', self::NEW_PASSWORD);

        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE event_type = \'password_reset.created\''));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE event_type = \'password_reset.completed\''));
    }

    public function testLinkCannotBeReused(): void
    {
        $rawToken = $this->createResetToken($this->alice['user_id']);
        self::assertSame(204, $this->complete($rawToken)->statusCode());

        $this->assertError($this->complete($rawToken, 'yet another long password'), 404, 'password_reset_invalid');
        $this->login('alice', self::NEW_PASSWORD);
    }

    public function testNewLinkRevokesOlderOneAndUnusableLinksFailIdentically(): void
    {
        $replacedToken = $this->createResetToken($this->alice['user_id']);
        $currentToken = $this->createResetToken($this->alice['user_id']);

        $this->signedInUser('bob');
        $bobId = (string) $this->scalar('SELECT id FROM users WHERE username = \'bob\'');
        $expiredToken = $this->createResetToken($bobId);
        $this->database->prepare('UPDATE password_resets SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE token_hash = :hash')
            ->execute(['hash' => SecretToken::hash($expiredToken)]);

        $failureBodies = [];
        foreach ([$replacedToken, $expiredToken, SecretToken::generate(), 'not-a-token'] as $unusableToken) {
            $completeResponse = $this->complete($unusableToken);
            $this->assertError($completeResponse, 404, 'password_reset_invalid');
            $failureBodies[] = $completeResponse->body();
            $this->assertError($this->send('POST', '/api/v1/password-resets/inspect', ['token' => $unusableToken]), 404, 'password_reset_invalid');
        }
        self::assertCount(1, array_unique($failureBodies));

        self::assertSame(204, $this->complete($currentToken)->statusCode());
    }

    public function testDisablingTheAccountRevokesItsLink(): void
    {
        $rawToken = $this->createResetToken($this->alice['user_id']);
        self::assertSame(204, $this->sendAs($this->admin, 'POST', '/api/v1/admin/users/' . $this->alice['user_id'] . '/disable')->statusCode());
        self::assertSame(204, $this->sendAs($this->admin, 'POST', '/api/v1/admin/users/' . $this->alice['user_id'] . '/enable')->statusCode());

        $this->assertError($this->complete($rawToken), 404, 'password_reset_invalid');
    }

    public function testAdminRulesForCreatingLinks(): void
    {
        // Not for yourself, not for disabled accounts, not for unknown users, and a bounded expiry.
        $this->assertError($this->sendAs($this->admin, 'POST', '/api/v1/admin/users/' . $this->admin['user_id'] . '/password-reset', []), 409, 'cannot_reset_self');
        $this->assertError($this->sendAs($this->admin, 'POST', '/api/v1/admin/users/' . $this->alice['user_id'] . '/password-reset', ['expires_in_hours' => 73]), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->admin, 'POST', '/api/v1/admin/users/00000000-0000-4000-8000-000000000000/password-reset', []), 404, 'not_found');

        $this->sendAs($this->admin, 'POST', '/api/v1/admin/users/' . $this->alice['user_id'] . '/disable');
        $this->assertError($this->sendAs($this->admin, 'POST', '/api/v1/admin/users/' . $this->alice['user_id'] . '/password-reset', []), 409, 'user_disabled');
    }

    public function testEverydayUserCannotCreateResetLinks(): void
    {
        $bob = $this->signedInUser('bob');

        // Admin endpoints are hidden from everyone else.
        $this->assertError($this->sendAs($bob, 'POST', '/api/v1/admin/users/' . $this->alice['user_id'] . '/password-reset', []), 404, 'not_found');
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM password_resets'));
    }

    public function testWeakPasswordIsRejectedAndLinkStaysUsable(): void
    {
        $rawToken = $this->createResetToken($this->alice['user_id']);

        $this->assertError($this->complete($rawToken, 'short'), 422, 'validation_failed');
        self::assertSame(204, $this->complete($rawToken)->statusCode());
    }

    public function testInvalidTokenGuessesAreRateLimitedPerIp(): void
    {
        for ($guessNumber = 0; $guessNumber < 20; $guessNumber++) {
            $this->complete(SecretToken::generate());
        }

        $this->assertError($this->complete(SecretToken::generate()), 429, 'rate_limited');
    }

    public function testUserChangesOwnPasswordAndOtherSessionsEnd(): void
    {
        $aliceOnSecondDevice = $this->login('alice');

        $changeResponse = $this->sendAs($this->alice, 'POST', '/api/v1/auth/password', [
            'current_password' => self::DEFAULT_PASSWORD,
            'new_password' => self::NEW_PASSWORD,
        ]);
        self::assertSame(204, $changeResponse->statusCode(), $changeResponse->body());

        // This session continues, the other device is signed out.
        self::assertSame(200, $this->sendAs($this->alice, 'GET', '/api/v1/auth/me')->statusCode());
        $this->assertError($this->sendAs($aliceOnSecondDevice, 'GET', '/api/v1/auth/me'), 401, 'unauthenticated');
        $this->login('alice', self::NEW_PASSWORD);
    }

    public function testWrongCurrentPasswordIsRejectedAndRateLimited(): void
    {
        for ($attemptNumber = 0; $attemptNumber < 5; $attemptNumber++) {
            $wrongResponse = $this->sendAs($this->alice, 'POST', '/api/v1/auth/password', [
                'current_password' => 'not my password at all',
                'new_password' => self::NEW_PASSWORD,
            ]);
            $this->assertError($wrongResponse, 422, 'validation_failed');
            self::assertArrayHasKey('current_password', $wrongResponse->json()['error']['fields']);
        }

        // Further guesses are refused even with the right password, exactly like sign-in.
        $this->assertError($this->sendAs($this->alice, 'POST', '/api/v1/auth/password', [
            'current_password' => self::DEFAULT_PASSWORD,
            'new_password' => self::NEW_PASSWORD,
        ]), 429, 'rate_limited');
    }

    public function testChangingOwnPasswordNeedsCsrfToken(): void
    {
        $noCsrfResponse = $this->send(
            'POST',
            '/api/v1/auth/password',
            ['current_password' => self::DEFAULT_PASSWORD, 'new_password' => self::NEW_PASSWORD],
            [],
            [self::COOKIE_NAME => $this->alice['session_token']],
        );

        $this->assertError($noCsrfResponse, 403, 'csrf_failed');
    }
}
