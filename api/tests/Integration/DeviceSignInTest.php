<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Auth\RateLimiter;
use Muninn\Api\Http\Response;
use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * Signing in on another device with a 6-digit code from a signed-in device (D068): the code
 * signs in as the right account once, dies with its session, is never usable by or for system
 * administrators, and wrong guesses are rate limited per IP and from everywhere together.
 */
final class DeviceSignInTest extends WorkspaceTestCase
{
    private const NTFY_SETTINGS = [
        'ntfy' => [
            'enabled' => true,
            'server_url' => 'http://127.0.0.1:2586/',
            'topic' => 'muninn-admin',
            'access_token' => 'tk_secret_test_token',
        ],
    ];

    /** The IP address of the second device (the "PC") in these tests. */
    private const OTHER_DEVICE_IP = '198.51.100.20';

    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->application = $this->buildApplication(self::NTFY_SETTINGS);
        $this->alice = $this->signedInUser('alice');
    }

    /**
     * Asks for a code as the given signed-in user.
     *
     * @return array{id: string, code: string, expires_at: string}
     */
    private function createCode(array $credentials): array
    {
        $createResponse = $this->sendAs($credentials, 'POST', '/api/v1/auth/device-codes');
        self::assertSame(201, $createResponse->statusCode(), $createResponse->body());

        return $createResponse->json()['data'];
    }

    /** Types a code on the "other device". */
    private function signInWithCode(string $typedCode, string $ipAddress = self::OTHER_DEVICE_IP): Response
    {
        return $this->send('POST', '/api/v1/auth/device-code-login', ['code' => $typedCode], [], [], $ipAddress);
    }

    /** A 6-digit code that is certainly not the given one. */
    private static function differentCode(string $realCode): string
    {
        return str_pad((string) (((int) $realCode + 1) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function testCodeSignsInAsTheSameAccountOnce(): void
    {
        $createdCode = $this->createCode($this->alice);
        self::assertMatchesRegularExpression('/^[0-9]{6}$/', $createdCode['code']);

        // Only the hash is stored.
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM device_sign_in_codes WHERE code_hash = :raw', ['raw' => $createdCode['code']]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM device_sign_in_codes WHERE code_hash = :hash', ['hash' => hash('sha256', $createdCode['code'])]));

        // People may type the digits in groups.
        $signInResponse = $this->signInWithCode(substr($createdCode['code'], 0, 3) . ' ' . substr($createdCode['code'], 3));
        self::assertSame(200, $signInResponse->statusCode(), $signInResponse->body());
        self::assertSame('alice', $signInResponse->json()['data']['user']['username']);

        // The new device has a working session of its own, and the phone stays signed in.
        $otherDevice = [
            'session_token' => $this->sessionTokenFrom($signInResponse),
            'csrf_token' => (string) $signInResponse->json()['data']['csrf_token'],
        ];
        self::assertSame('alice', $this->assertOkData($this->getAs($otherDevice, '/api/v1/auth/me'))['user']['username']);
        self::assertSame('alice', $this->assertOkData($this->getAs($this->alice, '/api/v1/auth/me'))['user']['username']);

        // The phone sees the code was used; the code cannot be used again.
        self::assertSame('used', $this->assertOkData($this->getAs($this->alice, '/api/v1/auth/device-codes/' . $createdCode['id']))['status']);
        $this->assertError($this->signInWithCode($createdCode['code']), 401, 'invalid_code');

        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE event_type = \'device_code.created\''));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE event_type = \'device_code.used\''));
        self::assertNotNull($this->scalar('SELECT used_by_session_id FROM device_sign_in_codes WHERE id = :id', ['id' => $createdCode['id']]));

        // The administrator hears about it.
        self::assertSame(1, $this->application->sendQueuedNotifications());
        $message = $this->ntfyTransport->publishedMessages[0]['message'];
        self::assertSame('Muninn: sign-in with a device code', $message['title']);
        self::assertStringContainsString('Alice (alice) signed in on another device from ' . self::OTHER_DEVICE_IP, $message['message']);
    }

    public function testUnusableCodesFailIdentically(): void
    {
        // Replaced by a newer code.
        $replacedCode = $this->createCode($this->alice);
        $currentCode = $this->createCode($this->alice);
        self::assertSame('revoked', $this->assertOkData($this->getAs($this->alice, '/api/v1/auth/device-codes/' . $replacedCode['id']))['status']);

        // Expired.
        $bob = $this->signedInUser('bob');
        $expiredCode = $this->createCode($bob);
        $this->database->prepare('UPDATE device_sign_in_codes SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = :id')
            ->execute(['id' => $expiredCode['id']]);
        self::assertSame('expired', $this->assertOkData($this->getAs($bob, '/api/v1/auth/device-codes/' . $expiredCode['id']))['status']);

        $failureBodies = [];
        foreach ([$replacedCode['code'], $expiredCode['code'], self::differentCode($currentCode['code'])] as $unusableCode) {
            // Distinct codes could collide by chance with the current one; skip that rare case.
            if ($unusableCode === $currentCode['code']) {
                continue;
            }
            $failedResponse = $this->signInWithCode($unusableCode);
            $this->assertError($failedResponse, 401, 'invalid_code');
            $failureBodies[] = $failedResponse->body();
        }
        self::assertCount(1, array_unique($failureBodies));

        // Not a code at all: a typo, refused without touching the database or the counters.
        $this->assertError($this->signInWithCode('12345'), 422, 'validation_failed');
        $this->assertError($this->signInWithCode('abcdef'), 422, 'validation_failed');

        self::assertSame(200, $this->signInWithCode($currentCode['code'])->statusCode());
    }

    public function testSigningOutTheShowingDeviceKillsTheCode(): void
    {
        $createdCode = $this->createCode($this->alice);
        self::assertSame(204, $this->sendAs($this->alice, 'POST', '/api/v1/auth/logout')->statusCode());

        $this->assertError($this->signInWithCode($createdCode['code']), 401, 'invalid_code');
    }

    public function testCancellingAndChangingPasswordKillTheCode(): void
    {
        $cancelledCode = $this->createCode($this->alice);
        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/auth/device-codes/' . $cancelledCode['id'])->statusCode());
        $this->assertError($this->signInWithCode($cancelledCode['code']), 401, 'invalid_code');

        $openCode = $this->createCode($this->alice);
        $changeResponse = $this->sendAs($this->alice, 'POST', '/api/v1/auth/password', [
            'current_password' => self::DEFAULT_PASSWORD,
            'new_password' => 'a brand new long password',
        ]);
        self::assertSame(204, $changeResponse->statusCode(), $changeResponse->body());
        $this->assertError($this->signInWithCode($openCode['code']), 401, 'invalid_code');
    }

    public function testDisablingTheAccountKillsTheCode(): void
    {
        $administrator = $this->signedInUser('root', true);
        $createdCode = $this->createCode($this->alice);
        self::assertSame(204, $this->sendAs($administrator, 'POST', '/api/v1/admin/users/' . $this->alice['user_id'] . '/disable')->statusCode());
        self::assertSame(204, $this->sendAs($administrator, 'POST', '/api/v1/admin/users/' . $this->alice['user_id'] . '/enable')->statusCode());

        $this->assertError($this->signInWithCode($createdCode['code']), 401, 'invalid_code');
    }

    public function testUsersOnlySeeAndCancelTheirOwnCodes(): void
    {
        $bob = $this->signedInUser('bob');
        $aliceCode = $this->createCode($this->alice);

        $this->assertError($this->getAs($bob, '/api/v1/auth/device-codes/' . $aliceCode['id']), 404, 'not_found');
        $this->assertError($this->sendAs($bob, 'DELETE', '/api/v1/auth/device-codes/' . $aliceCode['id']), 404, 'not_found');
        $this->assertError($this->getAs($bob, '/api/v1/auth/device-codes/not-a-uuid'), 404, 'not_found');

        // Bob's attempt did not cancel Alice's code.
        self::assertSame(200, $this->signInWithCode($aliceCode['code'])->statusCode());
    }

    public function testSystemAdministratorsCannotUseCodes(): void
    {
        $administrator = $this->signedInUser('root', true);
        $this->assertError($this->sendAs($administrator, 'POST', '/api/v1/auth/device-codes'), 403, 'not_for_admins');

        // Even a code row for an administrator (which the API never creates) does not sign in.
        $aliceCode = $this->createCode($this->alice);
        $this->database->prepare('UPDATE users SET is_system_admin = 1 WHERE id = :id')->execute(['id' => $this->alice['user_id']]);
        $this->assertError($this->signInWithCode($aliceCode['code']), 401, 'invalid_code');
    }

    public function testCodeSignInNeedsNoSessionButCodeCreationDoes(): void
    {
        $this->assertError($this->send('POST', '/api/v1/auth/device-codes'), 401, 'unauthenticated');
    }

    public function testWrongCodesAreLimitedPerIp(): void
    {
        $createdCode = $this->createCode($this->alice);
        $wrongCode = self::differentCode($createdCode['code']);

        for ($attemptNumber = 1; $attemptNumber <= RateLimiter::DEVICE_CODE_MAX_FAILURES_PER_IP; $attemptNumber++) {
            $this->assertError($this->signInWithCode($wrongCode), 401, 'invalid_code');
        }
        // Even the right code is refused from this address now...
        $this->assertError($this->signInWithCode($createdCode['code']), 429, 'rate_limited');
        // ...but works from another one.
        self::assertSame(200, $this->signInWithCode($createdCode['code'], '198.51.100.99')->statusCode());
    }

    public function testWrongCodesFromEverywherePauseCodeSignInAndTellTheAdministrator(): void
    {
        $createdCode = $this->createCode($this->alice);
        $wrongCode = self::differentCode($createdCode['code']);

        // Spread the guesses over many addresses so no single address hits its own limit.
        for ($attemptNumber = 1; $attemptNumber <= RateLimiter::DEVICE_CODE_MAX_FAILURES_EVERYWHERE; $attemptNumber++) {
            $guessingAddress = '192.0.2.' . $attemptNumber;
            $this->assertError($this->signInWithCode($wrongCode, $guessingAddress), 401, 'invalid_code');
        }
        $this->assertError($this->signInWithCode($createdCode['code'], '198.51.100.99'), 429, 'rate_limited');

        // Password sign-in is unaffected.
        $this->login('alice');

        self::assertSame(1, $this->application->sendQueuedNotifications());
        $message = $this->ntfyTransport->publishedMessages[0]['message'];
        self::assertSame('Muninn: code sign-in paused', $message['title']);
    }
}
