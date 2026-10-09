<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Muninn\Api\Http\Response;
use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * Administrator broadcast messages (D061): one-time banners, sticky banners and votes, each
 * shown only between their start and end, and only until the user is done with them.
 */
final class BroadcastTest extends WorkspaceTestCase
{
    private const NTFY_SETTINGS = [
        'ntfy' => [
            'enabled' => true,
            'server_url' => 'http://127.0.0.1:2586',
            'topic' => 'muninn-admin',
            'access_token' => '',
        ],
    ];

    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $admin;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $alice;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->application = $this->buildApplication(self::NTFY_SETTINGS);
        $this->admin = $this->signedInUser('sysadmin', true);
        $this->alice = $this->signedInUser('alice');
        $this->bob = $this->signedInUser('bob');
    }

    /** An ISO 8601 UTC timestamp the given number of minutes from now (negative = in the past). */
    private static function minutesFromNow(int $minuteOffset): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify(($minuteOffset >= 0 ? '+' : '') . $minuteOffset . ' minutes')->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Creates a broadcast as the administrator, showing from a minute ago for an hour unless
     * the fields say otherwise, and returns the created broadcast.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function createBroadcast(array $fields): array
    {
        $createResponse = $this->sendAs($this->admin, 'POST', '/api/v1/admin/broadcasts', $fields + [
            'message' => 'Hello everyone',
            'starts_at' => self::minutesFromNow(-1),
            'ends_at' => self::minutesFromNow(60),
        ]);
        self::assertSame(201, $createResponse->statusCode(), $createResponse->body());

        return $createResponse->json()['data']['broadcast'];
    }

    /** @return list<array<string, mixed>> The broadcasts showing to $credentials right now. */
    private function showing(array $credentials): array
    {
        $listResponse = $this->sendAs($credentials, 'GET', '/api/v1/broadcasts');
        self::assertSame(200, $listResponse->statusCode(), $listResponse->body());

        return $listResponse->json()['data']['broadcasts'];
    }

    /** @param list<string> $optionIds */
    private function vote(array $credentials, string $broadcastId, array $optionIds): Response
    {
        return $this->sendAs($credentials, 'POST', '/api/v1/broadcasts/' . $broadcastId . '/vote', ['option_ids' => $optionIds]);
    }

    public function testOneTimeBannerIsShownOnlyUntilSeen(): void
    {
        $banner = $this->createBroadcast(['kind' => 'once', 'message' => "Welcome!\nNew feature: folders."]);

        $aliceShowing = $this->showing($this->alice);
        self::assertCount(1, $aliceShowing);
        self::assertSame("Welcome!\nNew feature: folders.", $aliceShowing[0]['message']);
        self::assertSame('once', $aliceShowing[0]['kind']);

        self::assertSame(204, $this->sendAs($this->alice, 'POST', '/api/v1/broadcasts/' . $banner['id'] . '/seen')->statusCode());
        // Seeing it twice (two tabs) is harmless.
        self::assertSame(204, $this->sendAs($this->alice, 'POST', '/api/v1/broadcasts/' . $banner['id'] . '/seen')->statusCode());

        self::assertSame([], $this->showing($this->alice), 'Alice has seen it.');
        self::assertCount(1, $this->showing($this->bob), 'Bob has not.');

        $adminList = $this->sendAs($this->admin, 'GET', '/api/v1/admin/broadcasts')->json()['data']['broadcasts'];
        self::assertSame(1, $adminList[0]['done_count']);
        self::assertSame('showing', $adminList[0]['status']);
    }

    public function testStickyBannerStaysUntilClosed(): void
    {
        $banner = $this->createBroadcast(['kind' => 'sticky', 'message' => 'Maintenance tonight 22:00-23:00']);

        // Showing it does not make it go away, unlike a one-time banner.
        self::assertCount(1, $this->showing($this->alice));
        self::assertCount(1, $this->showing($this->alice));
        // "seen" is only for one-time banners.
        $this->assertError($this->sendAs($this->alice, 'POST', '/api/v1/broadcasts/' . $banner['id'] . '/seen'), 404, 'not_found');

        self::assertSame(204, $this->sendAs($this->alice, 'POST', '/api/v1/broadcasts/' . $banner['id'] . '/dismiss')->statusCode());
        self::assertSame([], $this->showing($this->alice));
        self::assertCount(1, $this->showing($this->bob));
    }

    public function testBroadcastsShowOnlyBetweenStartAndEnd(): void
    {
        $this->createBroadcast(['kind' => 'sticky', 'message' => 'Later', 'starts_at' => self::minutesFromNow(30), 'ends_at' => self::minutesFromNow(90)]);
        $runningBanner = $this->createBroadcast(['kind' => 'sticky', 'message' => 'Now']);

        $aliceShowing = $this->showing($this->alice);
        self::assertSame(['Now'], array_column($aliceShowing, 'message'), 'The scheduled one waits for its start.');

        // "End now": the administrator moves the end into the past.
        $endResponse = $this->sendAs($this->admin, 'PATCH', '/api/v1/admin/broadcasts/' . $runningBanner['id'], [
            'message' => 'Now',
            'starts_at' => $runningBanner['starts_at'],
            'ends_at' => self::minutesFromNow(0),
        ]);
        self::assertSame(200, $endResponse->statusCode(), $endResponse->body());
        self::assertSame('ended', $endResponse->json()['data']['broadcast']['status']);
        self::assertSame([], $this->showing($this->alice));

        // An ended broadcast can no longer be answered, and looks like an unknown one.
        $this->assertError($this->sendAs($this->alice, 'POST', '/api/v1/broadcasts/' . $runningBanner['id'] . '/dismiss'), 404, 'not_found');
    }

    public function testSingleChoiceVote(): void
    {
        $poll = $this->createBroadcast(['kind' => 'vote', 'message' => 'Which day for the meetup?', 'options' => ['Monday', ' Tuesday ', '', 'Friday']]);
        self::assertSame(['Monday', 'Tuesday', 'Friday'], array_column($poll['options'], 'label'), 'Trimmed, empty boxes left out.');
        $optionIds = array_column($poll['options'], 'id');

        $aliceShowing = $this->showing($this->alice);
        self::assertSame(['Monday', 'Tuesday', 'Friday'], array_column($aliceShowing[0]['options'], 'label'));
        self::assertFalse($aliceShowing[0]['allows_multiple_choices']);
        self::assertArrayNotHasKey('vote_count', $aliceShowing[0]['options'][0], 'Users never see the results.');

        $this->assertError($this->vote($this->alice, $poll['id'], [$optionIds[0], $optionIds[1]]), 422, 'validation_failed');
        $this->assertError($this->vote($this->alice, $poll['id'], []), 422, 'validation_failed');

        self::assertSame(204, $this->vote($this->alice, $poll['id'], [$optionIds[1]])->statusCode());
        self::assertSame([], $this->showing($this->alice), 'Gone once voted.');
        $this->assertError($this->vote($this->alice, $poll['id'], [$optionIds[0]]), 409, 'already_voted');

        $adminPoll = $this->sendAs($this->admin, 'GET', '/api/v1/admin/broadcasts')->json()['data']['broadcasts'][0];
        self::assertSame(1, $adminPoll['done_count']);
        self::assertSame([0, 1, 0], array_column($adminPoll['options'], 'vote_count'));
        self::assertSame('alice', $adminPoll['options'][1]['voters'][0]['username']);
    }

    public function testMultipleChoiceVoteNotifiesTheAdministrator(): void
    {
        $poll = $this->createBroadcast([
            'kind' => 'vote',
            'message' => 'Which features do you want?',
            'allows_multiple_choices' => true,
            'options' => ['Dark mode', 'Calendar', 'Export'],
        ]);
        $optionIds = array_column($poll['options'], 'id');
        $this->application->sendQueuedNotifications();
        $this->ntfyTransport->publishedMessages = [];

        self::assertSame(204, $this->vote($this->bob, $poll['id'], [$optionIds[2], $optionIds[0]])->statusCode());
        // Sent after the response, like every administrator notification (D057).
        self::assertSame([], $this->ntfyTransport->publishedMessages);
        self::assertSame(1, $this->application->sendQueuedNotifications());

        $published = $this->ntfyTransport->publishedMessages[0]['message'];
        self::assertSame('Muninn: new vote from Bob (bob)', $published['title']);
        // Answers in the vote's own order, then the question.
        self::assertStringContainsString('Answered: Dark mode, Export', $published['message']);
        self::assertStringContainsString('Which features do you want?', $published['message']);
        self::assertSame('https://www.dx.se/admin/broadcasts.php', $published['click']);
    }

    public function testAnOptionFromAnotherVoteIsRefused(): void
    {
        $firstPoll = $this->createBroadcast(['kind' => 'vote', 'message' => 'First?', 'options' => ['Yes', 'No']]);
        $secondPoll = $this->createBroadcast(['kind' => 'vote', 'message' => 'Second?', 'options' => ['Yes', 'No']]);

        $this->assertError($this->vote($this->alice, $firstPoll['id'], [$secondPoll['options'][0]['id']]), 422, 'validation_failed');
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM broadcast_votes'));
        self::assertCount(2, $this->showing($this->alice), 'A refused vote leaves both showing.');
    }

    public function testCreateValidation(): void
    {
        $invalidRequests = [
            'kind' => ['kind' => 'popup', 'message' => 'x', 'starts_at' => self::minutesFromNow(0), 'ends_at' => self::minutesFromNow(5)],
            'message' => ['kind' => 'once', 'message' => '  ', 'starts_at' => self::minutesFromNow(0), 'ends_at' => self::minutesFromNow(5)],
            'ends_at' => ['kind' => 'once', 'message' => 'x', 'starts_at' => self::minutesFromNow(10), 'ends_at' => self::minutesFromNow(5)],
            // A local time without an offset is refused instead of guessing the time zone.
            'starts_at' => ['kind' => 'once', 'message' => 'x', 'starts_at' => '2026-10-09T10:00:00', 'ends_at' => self::minutesFromNow(5)],
        ];
        foreach ($invalidRequests as $expectedField => $invalidBody) {
            $createResponse = $this->sendAs($this->admin, 'POST', '/api/v1/admin/broadcasts', $invalidBody);
            $this->assertError($createResponse, 422, 'validation_failed');
            self::assertArrayHasKey($expectedField, $createResponse->json()['error']['fields'], $expectedField);
        }

        // Already ended when created.
        $pastResponse = $this->sendAs($this->admin, 'POST', '/api/v1/admin/broadcasts', [
            'kind' => 'once', 'message' => 'x', 'starts_at' => self::minutesFromNow(-60), 'ends_at' => self::minutesFromNow(-30),
        ]);
        self::assertSame('The end must be in the future.', $pastResponse->json()['error']['fields']['ends_at'] ?? null);

        // Votes need 2-5 different answers.
        foreach ([['Only one'], ['A', 'B', 'C', 'D', 'E', 'F'], ['Same', 'same']] as $invalidOptions) {
            $voteResponse = $this->sendAs($this->admin, 'POST', '/api/v1/admin/broadcasts', [
                'kind' => 'vote', 'message' => 'Q?', 'options' => $invalidOptions,
                'starts_at' => self::minutesFromNow(0), 'ends_at' => self::minutesFromNow(5),
            ]);
            $this->assertError($voteResponse, 422, 'validation_failed');
            self::assertArrayHasKey('options', $voteResponse->json()['error']['fields']);
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM broadcasts'));
    }

    public function testOptionsCannotChangeAfterCreation(): void
    {
        $poll = $this->createBroadcast(['kind' => 'vote', 'message' => 'Q?', 'options' => ['A', 'B']]);

        $updateResponse = $this->sendAs($this->admin, 'PATCH', '/api/v1/admin/broadcasts/' . $poll['id'], [
            'message' => 'Q?', 'starts_at' => $poll['starts_at'], 'ends_at' => $poll['ends_at'], 'options' => ['C', 'D'],
        ]);
        $this->assertError($updateResponse, 422, 'validation_failed');
        self::assertSame(['A', 'B'], array_column($this->showing($this->alice)[0]['options'], 'label'));
    }

    public function testDeleteRemovesTheBroadcastAndItsVotes(): void
    {
        $poll = $this->createBroadcast(['kind' => 'vote', 'message' => 'Q?', 'options' => ['A', 'B']]);
        $this->vote($this->alice, $poll['id'], [$poll['options'][0]['id']]);

        self::assertSame(204, $this->sendAs($this->admin, 'DELETE', '/api/v1/admin/broadcasts/' . $poll['id'])->statusCode());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM broadcast_votes'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM broadcast_receipts'));
        self::assertSame([], $this->showing($this->bob));
        $this->assertError($this->sendAs($this->admin, 'DELETE', '/api/v1/admin/broadcasts/' . $poll['id']), 404, 'not_found');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type = 'broadcast.deleted'"));
    }

    public function testOnlyTheAdministratorManagesBroadcasts(): void
    {
        $banner = $this->createBroadcast(['kind' => 'once']);

        // Admin endpoints are hidden from everyday users (404, not 403).
        $this->assertError($this->sendAs($this->alice, 'GET', '/api/v1/admin/broadcasts'), 404, 'not_found');
        $this->assertError($this->sendAs($this->alice, 'POST', '/api/v1/admin/broadcasts', ['kind' => 'once', 'message' => 'Fake']), 404, 'not_found');
        $this->assertError($this->sendAs($this->alice, 'DELETE', '/api/v1/admin/broadcasts/' . $banner['id']), 404, 'not_found');
        // Signed-out visitors get nothing.
        self::assertSame(401, $this->send('GET', '/api/v1/broadcasts')->statusCode());
        // The administrator's own account is not the audience.
        self::assertSame([], $this->showing($this->admin));
    }
}
