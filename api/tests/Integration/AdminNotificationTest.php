<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Config\Config;
use Muninn\Api\Config\ConfigException;
use Muninn\Api\Notifications\AdminNotifier;
use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * Administrator push notifications through ntfy (D057): what triggers them, what they contain,
 * that they are sent only after the response, and that ntfy problems never break a request.
 */
final class AdminNotificationTest extends WorkspaceTestCase
{
    private const NTFY_SETTINGS = [
        'ntfy' => [
            'enabled' => true,
            'server_url' => 'http://127.0.0.1:2586/',
            'topic' => 'muninn-admin',
            'access_token' => 'tk_secret_test_token',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->application = $this->buildApplication(self::NTFY_SETTINGS);
    }

    public function testWorkspaceJoinRequestNotifiesTheAdministrator(): void
    {
        $administrator = $this->signedInUser('root', true);
        $alice = $this->signedInUser('alice');
        $generalWorkspaceId = $this->sendAs($administrator, 'POST', '/api/v1/admin/open-workspaces', ['name' => 'General'])->json()['data']['open_workspace']['id'];

        $askResponse = $this->sendAs($alice, 'POST', '/api/v1/open-workspaces/' . $generalWorkspaceId . '/join-request', ['note' => 'Private reason']);
        self::assertSame(201, $askResponse->statusCode(), $askResponse->body());

        self::assertSame(1, $this->application->sendQueuedNotifications());
        $message = $this->ntfyTransport->publishedMessages[0]['message'];
        self::assertSame('Muninn: request to join General', $message['title']);
        self::assertStringContainsString('Alice asked to join "General"', $message['message']);
        // The user's note stays inside Muninn, like invitation request notes.
        self::assertStringNotContainsString('Private reason', json_encode($message));
        self::assertSame('https://www.dx.se/admin/workspaces.php', $message['click']);
    }

    public function testInvitationRequestNotifiesTheAdministratorAfterTheResponse(): void
    {
        $alice = $this->signedInUser('alice');

        $createResponse = $this->sendAs($alice, 'POST', '/api/v1/invitation-requests', ['note' => 'Please invite my colleague Sven']);
        self::assertSame(201, $createResponse->statusCode(), $createResponse->body());
        // Nothing has been sent while the request was being answered.
        self::assertSame([], $this->ntfyTransport->publishedMessages);

        self::assertSame(1, $this->application->sendQueuedNotifications());
        self::assertCount(1, $this->ntfyTransport->publishedMessages);
        $published = $this->ntfyTransport->publishedMessages[0];
        self::assertSame('http://127.0.0.1:2586', $published['server_url'], 'The trailing slash is dropped.');
        self::assertSame('tk_secret_test_token', $published['access_token']);
        self::assertSame('muninn-admin', $published['message']['topic']);
        self::assertSame('Muninn: new invitation request', $published['message']['title']);
        self::assertStringContainsString('Alice asked', $published['message']['message']);
        self::assertSame('https://www.dx.se/admin/invitations.php', $published['message']['click']);
        // The request's note (who and why) stays in Muninn.
        self::assertStringNotContainsString('Sven', json_encode($published['message'], JSON_THROW_ON_ERROR));

        // The queue is empty afterwards.
        self::assertSame(0, $this->application->sendQueuedNotifications());
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type = 'notification.admin_queued'"));
    }

    public function testBlockedSignInsNotifyOncePerBlock(): void
    {
        $this->createUser('alice');

        // Four wrong passwords: no alert yet. The fifth reaches the limit and alerts.
        for ($attemptNumber = 1; $attemptNumber <= 5; $attemptNumber++) {
            $this->assertError($this->send('POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => 'wrong']), 401, 'invalid_credentials');
            $this->application->sendQueuedNotifications();
            self::assertCount($attemptNumber < 5 ? 0 : 1, $this->ntfyTransport->publishedMessages, 'after attempt ' . $attemptNumber);
        }
        // Further tries are refused with 429 and do not alert again.
        $this->assertError($this->send('POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => 'wrong']), 429, 'rate_limited');
        $this->application->sendQueuedNotifications();
        self::assertCount(1, $this->ntfyTransport->publishedMessages);

        $alert = $this->ntfyTransport->publishedMessages[0]['message'];
        self::assertSame('Muninn: sign-ins blocked', $alert['title']);
        self::assertSame(4, $alert['priority']);
        self::assertSame('Sign-ins as "alice" from 203.0.113.10 are blocked for 15 minutes after 5 failed attempts.', $alert['message']);
        // The password that was tried never appears anywhere in the alert.
        self::assertStringNotContainsString('wrong', json_encode($alert, JSON_THROW_ON_ERROR));
    }

    public function testSignInAlertsAreCappedPerHour(): void
    {
        // Many usernames from many addresses: each reaches its own limit, but alerts stop at the cap.
        for ($addressNumber = 1; $addressNumber <= AdminNotifier::MAXIMUM_SIGN_IN_ALERTS_PER_HOUR + 3; $addressNumber++) {
            for ($attemptNumber = 1; $attemptNumber <= 5; $attemptNumber++) {
                $this->send('POST', '/api/v1/auth/login', ['username' => 'nobody' . $addressNumber, 'password' => 'x'], [], [], '198.51.100.' . $addressNumber);
            }
        }
        $this->application->sendQueuedNotifications();
        self::assertCount(AdminNotifier::MAXIMUM_SIGN_IN_ALERTS_PER_HOUR, $this->ntfyTransport->publishedMessages);
    }

    public function testControlCharactersInUsernamesAreRemoved(): void
    {
        for ($attemptNumber = 1; $attemptNumber <= 5; $attemptNumber++) {
            $this->send('POST', '/api/v1/auth/login', ['username' => "evil\nTitle: fake", 'password' => 'x']);
        }
        $this->application->sendQueuedNotifications();
        $alertText = $this->ntfyTransport->publishedMessages[0]['message']['message'];
        self::assertStringNotContainsString("\n", $alertText);
    }

    public function testUnreachableNtfyNeverBreaksARequestAndIsLoggedWithoutTheToken(): void
    {
        $this->ntfyTransport->answerStatusCode = 0;
        $alice = $this->signedInUser('alice');

        $createResponse = $this->sendAs($alice, 'POST', '/api/v1/invitation-requests', ['note' => 'Someone']);
        self::assertSame(201, $createResponse->statusCode());
        self::assertSame(0, $this->application->sendQueuedNotifications());

        $logText = (string) file_get_contents($this->logFilePath);
        self::assertStringContainsString('ntfy notification was not delivered', $logText);
        self::assertStringNotContainsString('tk_secret_test_token', $logText);
    }

    public function testNothingIsSentWhenNtfyIsOff(): void
    {
        $this->application = $this->buildApplication();
        $alice = $this->signedInUser('alice');
        $this->sendAs($alice, 'POST', '/api/v1/invitation-requests', ['note' => 'Someone']);
        for ($attemptNumber = 1; $attemptNumber <= 5; $attemptNumber++) {
            $this->send('POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => 'wrong']);
        }

        self::assertSame(0, $this->application->sendQueuedNotifications());
        self::assertSame([], $this->ntfyTransport->publishedMessages);
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type = 'notification.admin_queued'"));
    }

    public function testNtfySettingsAreValidatedWhenSwitchedOn(): void
    {
        $badSettings = [
            ['server_url' => 'http://user:password@127.0.0.1:2586'],
            ['server_url' => 'ftp://127.0.0.1'],
            ['server_url' => 'http://127.0.0.1:2586/?token=x'],
            ['topic' => 'has spaces'],
            ['topic' => ''],
            ['timeout_seconds' => 60],
        ];
        foreach ($badSettings as $badSetting) {
            try {
                new Config($this->configValues(['ntfy' => $badSetting + self::NTFY_SETTINGS['ntfy']]));
                self::fail('Accepted invalid ntfy settings: ' . json_encode($badSetting));
            } catch (ConfigException $expectedException) {
                self::assertStringContainsString('ntfy.', $expectedException->getMessage());
            }
        }
        // Switched off, nothing is checked.
        new Config($this->configValues(['ntfy' => ['enabled' => false, 'topic' => 'has spaces']]));
        $this->addToAssertionCount(1);
    }
}
