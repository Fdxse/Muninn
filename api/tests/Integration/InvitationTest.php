<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Http\Response;
use Muninn\Api\Security\SecretToken;
use Muninn\Api\Tests\Support\IntegrationTestCase;

final class InvitationTest extends IntegrationTestCase
{
    /** @var array{session_token: string, csrf_token: string} */
    private array $adminCredentials;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUser('admin', self::DEFAULT_PASSWORD, true);
        $this->adminCredentials = $this->login('admin');
    }

    /** Creates an invitation as admin and returns the raw token taken from the one-time link. */
    private function createInvitation(array $body = []): string
    {
        $createResponse = $this->sendAs($this->adminCredentials, 'POST', '/api/v1/admin/invitations', $body);
        self::assertSame(201, $createResponse->statusCode(), $createResponse->body());

        $invitationUrl = (string) $createResponse->json()['data']['invitation_url'];
        self::assertStringStartsWith('https://www.dx.se/invite.php#token=', $invitationUrl);

        return substr($invitationUrl, strpos($invitationUrl, '#token=') + strlen('#token='));
    }

    /** @param array<string, mixed> $overrides */
    private function accept(string $rawToken, array $overrides = []): Response
    {
        return $this->send('POST', '/api/v1/invitations/accept', $overrides + [
            'token' => $rawToken,
            'username' => 'newbie',
            'display_name' => 'New Person',
            'password' => 'a long enough password',
        ]);
    }

    public function testAdminCreatesInvitationAndOnlyHashIsStored(): void
    {
        $rawToken = $this->createInvitation(['note' => 'For Bob', 'expires_in_hours' => 24]);

        self::assertTrue(SecretToken::looksValid($rawToken));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM invitations WHERE token_hash = :raw', ['raw' => $rawToken]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM invitations WHERE token_hash = :hash', ['hash' => SecretToken::hash($rawToken)]));

        // The listing shows status and note but never the token or its hash.
        $listResponse = $this->sendAs($this->adminCredentials, 'GET', '/api/v1/admin/invitations');
        self::assertSame(200, $listResponse->statusCode());
        self::assertSame('pending', $listResponse->json()['data']['invitations'][0]['status']);
        self::assertSame('For Bob', $listResponse->json()['data']['invitations'][0]['note']);
        self::assertStringNotContainsString($rawToken, $listResponse->body());
        self::assertStringNotContainsString(SecretToken::hash($rawToken), $listResponse->body());
        self::assertStringNotContainsString('token', $listResponse->body());
    }

    public function testInvalidExpiryIsRejected(): void
    {
        $tooLongResponse = $this->sendAs($this->adminCredentials, 'POST', '/api/v1/admin/invitations', ['expires_in_hours' => 10000]);

        $this->assertError($tooLongResponse, 422, 'validation_failed');
        self::assertArrayHasKey('expires_in_hours', $tooLongResponse->json()['error']['fields']);
    }

    public function testNonAdminGets404OnEveryAdminEndpoint(): void
    {
        $this->createUser('regular');
        $regularCredentials = $this->login('regular');
        $someInvitationId = $this->sendAs($this->adminCredentials, 'POST', '/api/v1/admin/invitations', [])->json()['data']['invitation']['id'];

        $this->assertError($this->sendAs($regularCredentials, 'GET', '/api/v1/admin/invitations'), 404, 'not_found');
        $this->assertError($this->sendAs($regularCredentials, 'POST', '/api/v1/admin/invitations', []), 404, 'not_found');
        $this->assertError($this->sendAs($regularCredentials, 'DELETE', '/api/v1/admin/invitations/' . $someInvitationId), 404, 'not_found');

        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM invitations'));
    }

    public function testSignedOutCallerGets401OnAdminEndpoints(): void
    {
        $this->assertError($this->send('GET', '/api/v1/admin/invitations'), 401, 'unauthenticated');
    }

    public function testAcceptCreatesUserSignsInAndCannotBeReused(): void
    {
        $rawToken = $this->createInvitation();

        $inspectResponse = $this->send('POST', '/api/v1/invitations/inspect', ['token' => $rawToken]);
        self::assertSame(200, $inspectResponse->statusCode());
        self::assertTrue($inspectResponse->json()['data']['valid']);

        $acceptResponse = $this->accept($rawToken);
        self::assertSame(201, $acceptResponse->statusCode(), $acceptResponse->body());
        self::assertSame('newbie', $acceptResponse->json()['data']['user']['username']);
        self::assertFalse($acceptResponse->json()['data']['user']['is_system_admin']);

        // The new user is signed in by the returned cookie.
        $newUserCredentials = [
            'session_token' => $this->sessionTokenFrom($acceptResponse),
            'csrf_token' => (string) $acceptResponse->json()['data']['csrf_token'],
        ];
        self::assertSame('newbie', $this->sendAs($newUserCredentials, 'GET', '/api/v1/auth/me')->json()['data']['user']['username']);

        // Second use fails with the generic error, and no second account appears.
        $this->assertError($this->accept($rawToken, ['username' => 'second']), 404, 'invitation_invalid');
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM users WHERE username = 'second'"));

        $listResponse = $this->sendAs($this->adminCredentials, 'GET', '/api/v1/admin/invitations');
        self::assertSame('accepted', $listResponse->json()['data']['invitations'][0]['status']);
        self::assertSame('newbie', $listResponse->json()['data']['invitations'][0]['accepted_username']);
    }

    public function testUsedExpiredRevokedAndUnknownTokensFailIdentically(): void
    {
        $usedToken = $this->createInvitation();
        $this->accept($usedToken, ['username' => 'firstuser']);

        $expiredToken = $this->createInvitation();
        $this->database->prepare('UPDATE invitations SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE token_hash = :hash')
            ->execute(['hash' => SecretToken::hash($expiredToken)]);

        $revokedToken = $this->createInvitation();
        $revokedId = (string) $this->scalar('SELECT id FROM invitations WHERE token_hash = :hash', ['hash' => SecretToken::hash($revokedToken)]);
        self::assertSame(204, $this->sendAs($this->adminCredentials, 'DELETE', '/api/v1/admin/invitations/' . $revokedId)->statusCode());

        $unknownToken = SecretToken::generate();

        $failureBodies = [];
        foreach ([$usedToken, $expiredToken, $revokedToken, $unknownToken] as $unusableToken) {
            $acceptResponse = $this->accept($unusableToken, ['username' => 'attempt' . count($failureBodies)]);
            $this->assertError($acceptResponse, 404, 'invitation_invalid');
            $failureBodies[] = $acceptResponse->body();

            $this->assertError($this->send('POST', '/api/v1/invitations/inspect', ['token' => $unusableToken]), 404, 'invitation_invalid');
        }
        self::assertCount(1, array_unique($failureBodies), 'All failure bodies must be identical.');
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM users WHERE username LIKE 'attempt%'"));
    }

    public function testRevokingTwiceOrRevokingAcceptedIsConflict(): void
    {
        $rawToken = $this->createInvitation();
        $invitationId = (string) $this->scalar('SELECT id FROM invitations WHERE token_hash = :hash', ['hash' => SecretToken::hash($rawToken)]);
        $this->accept($rawToken);

        $this->assertError($this->sendAs($this->adminCredentials, 'DELETE', '/api/v1/admin/invitations/' . $invitationId), 409, 'invitation_not_pending');
        $this->assertError($this->sendAs($this->adminCredentials, 'DELETE', '/api/v1/admin/invitations/not-a-uuid'), 404, 'not_found');
    }

    public function testTakenUsernameIs422AndInvitationStaysUsable(): void
    {
        $this->createUser('taken');
        $rawToken = $this->createInvitation();

        $takenResponse = $this->accept($rawToken, ['username' => 'Taken']);
        $this->assertError($takenResponse, 422, 'validation_failed');
        self::assertArrayHasKey('username', $takenResponse->json()['error']['fields']);

        self::assertSame(201, $this->accept($rawToken, ['username' => 'available'])->statusCode());
    }

    public function testInputValidationReportsEveryField(): void
    {
        $rawToken = $this->createInvitation();

        $invalidResponse = $this->accept($rawToken, ['username' => 'x', 'display_name' => '   ', 'password' => 'short']);

        $this->assertError($invalidResponse, 422, 'validation_failed');
        self::assertEqualsCanonicalizing(['username', 'display_name', 'password'], array_keys($invalidResponse->json()['error']['fields']));
        self::assertSame(200, $this->send('POST', '/api/v1/invitations/inspect', ['token' => $rawToken])->statusCode(), 'Invitation must still be usable.');
    }

    public function testInvalidTokenGuessesAreRateLimitedPerIp(): void
    {
        for ($guessNumber = 1; $guessNumber <= 20; $guessNumber++) {
            $this->send('POST', '/api/v1/invitations/inspect', ['token' => SecretToken::generate()]);
        }

        $this->assertError($this->send('POST', '/api/v1/invitations/inspect', ['token' => SecretToken::generate()]), 429, 'rate_limited');
    }

    public function testConcurrentAcceptsCreateAtMostOneUser(): void
    {
        $rawToken = $this->createInvitation();
        $workerScript = dirname(__DIR__) . '/Support/accept-invitation-worker.php';
        $startAtMicrotime = sprintf('%.6F', microtime(true) + 1.0);

        // Launch two separate PHP processes that accept the same token at the same instant.
        $runningWorkers = [];
        foreach (['racer_one', 'racer_two'] as $racerUsername) {
            $workerProcess = proc_open(
                [PHP_BINARY, $workerScript, $rawToken, $racerUsername, $startAtMicrotime],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $workerPipes,
            );
            self::assertIsResource($workerProcess);
            $runningWorkers[] = ['process' => $workerProcess, 'pipes' => $workerPipes];
        }

        $workerOutcomes = [];
        foreach ($runningWorkers as $runningWorker) {
            $workerOutcomes[] = trim((string) stream_get_contents($runningWorker['pipes'][1]));
            fclose($runningWorker['pipes'][1]);
            fclose($runningWorker['pipes'][2]);
            proc_close($runningWorker['process']);
        }

        sort($workerOutcomes);
        self::assertSame(['accepted', 'refused'], $workerOutcomes);
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM users WHERE username LIKE 'racer%'"));
    }
}
