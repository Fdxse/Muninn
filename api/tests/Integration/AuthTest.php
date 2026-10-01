<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Auth\PasswordService;
use Muninn\Api\Security\SecretToken;
use Muninn\Api\Tests\Support\IntegrationTestCase;

final class AuthTest extends IntegrationTestCase
{
    public function testSuccessfulLoginSetsHardenedCookieAndStoresOnlyHash(): void
    {
        $this->createUser('alice');

        $loginResponse = $this->send('POST', '/api/v1/auth/login', ['username' => 'Alice', 'password' => self::DEFAULT_PASSWORD]);

        self::assertSame(200, $loginResponse->statusCode(), $loginResponse->body());
        self::assertSame('alice', $loginResponse->json()['data']['user']['username']);
        self::assertArrayNotHasKey('password_hash', $loginResponse->json()['data']['user']);
        self::assertNotEmpty($loginResponse->json()['data']['csrf_token']);

        $setCookieHeader = $loginResponse->setCookieHeaders()[0];
        foreach (['Secure', 'HttpOnly', 'SameSite=Lax', 'Path=/'] as $requiredAttribute) {
            self::assertStringContainsString($requiredAttribute, $setCookieHeader);
        }
        self::assertStringNotContainsString('Domain=', $setCookieHeader);

        $rawSessionToken = $this->sessionTokenFrom($loginResponse);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM sessions WHERE token_hash = :raw', ['raw' => $rawSessionToken]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM sessions WHERE token_hash = :hash', ['hash' => SecretToken::hash($rawSessionToken)]));
    }

    public function testFailedLoginsAreIndistinguishable(): void
    {
        $this->createUser('alice');
        $this->createUser('dora');
        $this->database->exec("UPDATE users SET status = 'disabled' WHERE username = 'dora'");

        $wrongPasswordResponse = $this->send('POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => 'wrong password here'], [], [], '198.51.100.1');
        $unknownUserResponse = $this->send('POST', '/api/v1/auth/login', ['username' => 'nobody', 'password' => self::DEFAULT_PASSWORD], [], [], '198.51.100.2');
        $disabledUserResponse = $this->send('POST', '/api/v1/auth/login', ['username' => 'dora', 'password' => self::DEFAULT_PASSWORD], [], [], '198.51.100.3');

        foreach ([$wrongPasswordResponse, $unknownUserResponse, $disabledUserResponse] as $failedResponse) {
            self::assertSame(401, $failedResponse->statusCode());
            self::assertSame($wrongPasswordResponse->body(), $failedResponse->body(), 'Bodies must be byte-identical.');
            self::assertSame([], $failedResponse->setCookieHeaders());
        }
    }

    public function testRateLimitBlocksEvenTheCorrectPassword(): void
    {
        $this->createUser('alice');

        for ($attemptNumber = 1; $attemptNumber <= 5; $attemptNumber++) {
            $failedResponse = $this->send('POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => 'wrong password ' . $attemptNumber]);
            self::assertSame(401, $failedResponse->statusCode());
        }

        $blockedResponse = $this->send('POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => self::DEFAULT_PASSWORD]);
        $this->assertError($blockedResponse, 429, 'rate_limited');
        self::assertSame('900', $blockedResponse->header('Retry-After'));

        // Another IP is not affected by this username + IP limit.
        $otherIpResponse = $this->send('POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => self::DEFAULT_PASSWORD], [], [], '192.0.2.99');
        self::assertSame(200, $otherIpResponse->statusCode());
    }

    public function testRateLimitExpiresAfterTheWindow(): void
    {
        $this->createUser('alice');
        for ($attemptNumber = 1; $attemptNumber <= 5; $attemptNumber++) {
            $this->send('POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => 'wrong password ' . $attemptNumber]);
        }

        // Simulate the passage of 16 minutes.
        $this->database->exec('UPDATE auth_attempts SET attempted_at = attempted_at - INTERVAL 16 MINUTE');

        $laterResponse = $this->send('POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => self::DEFAULT_PASSWORD]);
        self::assertSame(200, $laterResponse->statusCode());
    }

    public function testPerIpLimitSlowsPasswordSpraying(): void
    {
        for ($attemptNumber = 1; $attemptNumber <= 20; $attemptNumber++) {
            $this->send('POST', '/api/v1/auth/login', ['username' => 'user' . $attemptNumber, 'password' => 'some guessed password']);
        }

        $sprayResponse = $this->send('POST', '/api/v1/auth/login', ['username' => 'user21', 'password' => 'some guessed password']);
        $this->assertError($sprayResponse, 429, 'rate_limited');
    }

    public function testMeReturnsUserAndCsrfToken(): void
    {
        $this->createUser('alice');
        $credentials = $this->login('alice');

        $meResponse = $this->sendAs($credentials, 'GET', '/api/v1/auth/me');

        self::assertSame(200, $meResponse->statusCode());
        self::assertSame('alice', $meResponse->json()['data']['user']['username']);
        self::assertSame($credentials['csrf_token'], $meResponse->json()['data']['csrf_token']);
    }

    public function testMeWithoutSessionIs401(): void
    {
        $this->assertError($this->send('GET', '/api/v1/auth/me'), 401, 'unauthenticated');
        $this->assertError($this->send('GET', '/api/v1/auth/me', null, [], [self::COOKIE_NAME => 'garbage']), 401, 'unauthenticated');
    }

    public function testLogoutRevokesSessionServerSide(): void
    {
        $this->createUser('alice');
        $credentials = $this->login('alice');

        $logoutResponse = $this->sendAs($credentials, 'POST', '/api/v1/auth/logout');
        self::assertSame(204, $logoutResponse->statusCode());
        self::assertStringContainsString('Max-Age=0', $logoutResponse->setCookieHeaders()[0]);

        // Replaying the old cookie must fail, even though the browser was not involved.
        $this->assertError($this->sendAs($credentials, 'GET', '/api/v1/auth/me'), 401, 'unauthenticated');
        self::assertNotFalse($this->scalar('SELECT revoked_at FROM sessions WHERE token_hash = :hash', ['hash' => SecretToken::hash($credentials['session_token'])]));
    }

    public function testStateChangeWithoutValidCsrfTokenIsRefused(): void
    {
        $this->createUser('alice');
        $credentials = $this->login('alice');

        $missingTokenResponse = $this->send('POST', '/api/v1/auth/logout', null, [], [self::COOKIE_NAME => $credentials['session_token']]);
        $wrongTokenResponse = $this->send('POST', '/api/v1/auth/logout', null, ['X-CSRF-Token' => SecretToken::generate()], [self::COOKIE_NAME => $credentials['session_token']]);

        $this->assertError($missingTokenResponse, 403, 'csrf_failed');
        $this->assertError($wrongTokenResponse, 403, 'csrf_failed');

        // Nothing changed: the session still works.
        self::assertSame(200, $this->sendAs($credentials, 'GET', '/api/v1/auth/me')->statusCode());
    }

    public function testNewLoginReplacesPreviousSessionInSameBrowser(): void
    {
        $this->createUser('alice');
        $firstCredentials = $this->login('alice');

        $secondLogin = $this->send(
            'POST',
            '/api/v1/auth/login',
            ['username' => 'alice', 'password' => self::DEFAULT_PASSWORD],
            [],
            [self::COOKIE_NAME => $firstCredentials['session_token']],
        );

        self::assertNotSame($firstCredentials['session_token'], $this->sessionTokenFrom($secondLogin));
        $this->assertError($this->sendAs($firstCredentials, 'GET', '/api/v1/auth/me'), 401, 'unauthenticated');
    }

    public function testIdleAndAbsoluteExpiryEndSessions(): void
    {
        $this->createUser('alice');
        $idleCredentials = $this->login('alice');
        $this->database->exec('UPDATE sessions SET last_seen_at = last_seen_at - INTERVAL 169 HOUR');
        $this->assertError($this->sendAs($idleCredentials, 'GET', '/api/v1/auth/me'), 401, 'unauthenticated');

        $expiredCredentials = $this->login('alice');
        $this->database->prepare('UPDATE sessions SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE token_hash = :hash')
            ->execute(['hash' => SecretToken::hash($expiredCredentials['session_token'])]);
        $this->assertError($this->sendAs($expiredCredentials, 'GET', '/api/v1/auth/me'), 401, 'unauthenticated');
    }

    public function testDisablingUserEndsExistingSessions(): void
    {
        $this->createUser('alice');
        $credentials = $this->login('alice');
        $this->database->exec("UPDATE users SET status = 'disabled' WHERE username = 'alice'");

        $this->assertError($this->sendAs($credentials, 'GET', '/api/v1/auth/me'), 401, 'unauthenticated');
    }

    public function testOutdatedHashIsUpgradedOnLogin(): void
    {
        $userId = $this->createUser('alice');
        $bcryptHash = (new PasswordService(PASSWORD_BCRYPT))->hash(self::DEFAULT_PASSWORD);
        $this->database->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')->execute(['hash' => $bcryptHash, 'id' => $userId]);

        $this->login('alice');

        $storedHash = (string) $this->scalar('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);
        self::assertFalse((new PasswordService())->needsRehash($storedHash));
    }
}
