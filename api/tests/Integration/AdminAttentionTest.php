<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Admin\DataResetService;
use Muninn\Api\Tests\Support\TestDatabase;
use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * "Something is waiting on the admin side" (D066): the administrator picks one everyday account,
 * and only that account learns, as a plain yes or no, that admin work is waiting.
 */
final class AdminAttentionTest extends WorkspaceTestCase
{
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $administrator;

    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $fredrik;

    protected function setUp(): void
    {
        parent::setUp();
        $this->administrator = $this->signedInUser('root', true);
        $this->fredrik = $this->signedInUser('fredrik');
    }

    /** Picks the recipient as the administrator and checks the call succeeded. */
    private function pickRecipient(?string $recipientUserId): array
    {
        return $this->assertOkData($this->sendAs($this->administrator, 'PATCH', '/api/v1/admin/attention-recipient', ['user_id' => $recipientUserId]));
    }

    /** What the given account is told. */
    private function attentionFor(array $userCredentials): array
    {
        return $this->assertOkData($this->getAs($userCredentials, '/api/v1/admin-attention'));
    }

    public function testNobodyIsToldUntilTheAdministratorPicksSomeone(): void
    {
        // Admin work is waiting: an invitation request.
        $bob = $this->signedInUser('bob');
        self::assertSame(201, $this->sendAs($bob, 'POST', '/api/v1/invitation-requests', ['note' => 'My colleague'])->statusCode());

        self::assertNull($this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/attention-recipient'))['recipient']);
        self::assertSame(['is_recipient' => false, 'needs_attention' => false], $this->attentionFor($this->fredrik));
    }

    public function testTheRecipientSeesInvitationRequestsAndUnreadInboxMessages(): void
    {
        $pickedData = $this->pickRecipient($this->fredrik['user_id']);
        self::assertSame('fredrik', $pickedData['recipient']['username']);
        self::assertSame($this->fredrik['user_id'], $this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/attention-recipient'))['recipient']['id']);

        // Nothing waits yet.
        self::assertSame(['is_recipient' => true, 'needs_attention' => false], $this->attentionFor($this->fredrik));

        // A pending invitation request needs a decision.
        $bob = $this->signedInUser('bob');
        $requestResponse = $this->sendAs($bob, 'POST', '/api/v1/invitation-requests', ['note' => 'My colleague']);
        self::assertSame(201, $requestResponse->statusCode(), $requestResponse->body());
        self::assertTrue($this->attentionFor($this->fredrik)['needs_attention']);

        // Declined: nothing waits any more.
        $requestId = (string) $requestResponse->json()['data']['invitation_request']['id'];
        self::assertSame(200, $this->sendAs($this->administrator, 'POST', '/api/v1/admin/invitation-requests/' . $requestId . '/decline')->statusCode());
        self::assertFalse($this->attentionFor($this->fredrik)['needs_attention']);

        // A "Contact admin" message is unread in the inbox.
        $sendResponse = $this->sendAs($bob, 'POST', '/api/v1/admin-messages', ['message' => 'Help please', 'contact' => '']);
        self::assertSame(201, $sendResponse->statusCode(), $sendResponse->body());
        self::assertTrue($this->attentionFor($this->fredrik)['needs_attention']);

        // The administrator opens it: read, so nothing waits.
        $conversationId = (string) $sendResponse->json()['data']['conversation_id'];
        $this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/conversations/' . $conversationId));
        self::assertFalse($this->attentionFor($this->fredrik)['needs_attention']);
    }

    public function testOnlyTheRecipientLearnsThatWorkIsWaiting(): void
    {
        $this->pickRecipient($this->fredrik['user_id']);
        $bob = $this->signedInUser('bob');
        $this->sendAs($bob, 'POST', '/api/v1/admin-messages', ['message' => 'Help please', 'contact' => '']);

        self::assertTrue($this->attentionFor($this->fredrik)['needs_attention']);
        // Everyone else, the administrator included, is told nothing.
        self::assertSame(['is_recipient' => false, 'needs_attention' => false], $this->attentionFor($bob));
        self::assertSame(['is_recipient' => false, 'needs_attention' => false], $this->attentionFor($this->administrator));

        // A newly picked recipient takes over; the previous one is told nothing any more.
        $this->pickRecipient($bob['user_id']);
        self::assertTrue($this->attentionFor($bob)['needs_attention']);
        self::assertSame(['is_recipient' => false, 'needs_attention' => false], $this->attentionFor($this->fredrik));

        // Nobody.
        self::assertNull($this->pickRecipient(null)['recipient']);
        self::assertFalse($this->attentionFor($bob)['is_recipient']);
    }

    public function testEverydayUsersCannotPickOrSeeTheRecipient(): void
    {
        $this->assertError($this->sendAs($this->fredrik, 'PATCH', '/api/v1/admin/attention-recipient', ['user_id' => $this->fredrik['user_id']]), 404, 'not_found');
        $this->assertError($this->getAs($this->fredrik, '/api/v1/admin/attention-recipient'), 404, 'not_found');
        self::assertFalse($this->attentionFor($this->fredrik)['is_recipient']);
    }

    public function testTheRecipientMustBeAnActiveEverydayAccount(): void
    {
        $otherAdministrator = $this->signedInUser('root2', true);
        $carol = $this->signedInUser('carol');
        self::assertSame(204, $this->sendAs($this->administrator, 'POST', '/api/v1/admin/users/' . $carol['user_id'] . '/disable')->statusCode());

        foreach ([$otherAdministrator['user_id'], $carol['user_id'], 'not-a-uuid', '00000000-0000-4000-8000-000000000000', 42] as $invalidUserId) {
            $this->assertError($this->sendAs($this->administrator, 'PATCH', '/api/v1/admin/attention-recipient', ['user_id' => $invalidUserId]), 422, 'validation_failed');
        }
        $this->assertError($this->sendAs($this->administrator, 'PATCH', '/api/v1/admin/attention-recipient', []), 422, 'validation_failed');
        self::assertNull($this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/attention-recipient'))['recipient']);
    }

    public function testChangingTheRecipientIsAudited(): void
    {
        $this->pickRecipient($this->fredrik['user_id']);
        // Picking the same account again changes nothing and adds no entry.
        $this->pickRecipient($this->fredrik['user_id']);
        $this->pickRecipient(null);

        self::assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type = 'admin.attention_recipient_changed'"));
    }

    public function testResettingTestDataForgetsTheRecipient(): void
    {
        $this->pickRecipient($this->fredrik['user_id']);

        (new DataResetService(TestDatabase::connection(), $this->attachmentStorage()))->resetToAdministratorsOnly();

        self::assertNull($this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/attention-recipient'))['recipient']);
    }
}
