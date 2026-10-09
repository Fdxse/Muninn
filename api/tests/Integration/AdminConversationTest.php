<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\AdminInbox\AdminConversationController;
use Muninn\Api\AdminInbox\AdminConversationService;
use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * The administrator's inbox (D065): conversations started with "Contact admin", replies from
 * both sides, unread badges, closing and reopening, who may see what, and the one-year cleanup.
 */
final class AdminConversationTest extends WorkspaceTestCase
{
    private const NTFY_SETTINGS = [
        'ntfy' => [
            'enabled' => true,
            'server_url' => 'http://127.0.0.1:2586',
            'topic' => 'muninn-admin',
            'access_token' => 'tk_secret_test_token',
        ],
    ];

    /** @var array{session_token: string, csrf_token: string} */
    private array $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->application = $this->buildApplication(self::NTFY_SETTINGS);
        $this->createUser('root', self::DEFAULT_PASSWORD, true);
        $this->administrator = $this->login('root');
    }

    /**
     * Sends a "Contact admin" message and returns the new conversation's ID.
     *
     * @param array{session_token: string, csrf_token: string} $userCredentials
     */
    private function startConversation(array $userCredentials, string $messageText = 'I need help', string $contactDetails = ''): string
    {
        $sendResponse = $this->sendAs($userCredentials, 'POST', '/api/v1/admin-messages', ['message' => $messageText, 'contact' => $contactDetails]);
        self::assertSame(201, $sendResponse->statusCode(), $sendResponse->body());

        return (string) $sendResponse->json()['data']['conversation_id'];
    }

    public function testAdministratorSeesTheMessageInTheInboxAndAnswers(): void
    {
        $alice = $this->signedInUser('alice');
        $conversationId = $this->startConversation($alice, "I cannot open\nthe shared workspace.", 'alice@example.com');

        // The inbox lists it as unread and waiting, with who wrote it and a preview.
        $inboxData = $this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/conversations'));
        self::assertSame(1, $inboxData['unread_count']);
        self::assertCount(1, $inboxData['conversations']);
        $listedConversation = $inboxData['conversations'][0];
        self::assertSame($conversationId, $listedConversation['id']);
        self::assertSame('alice', $listedConversation['user']['username']);
        self::assertSame('I cannot open the shared workspace.', $listedConversation['excerpt']);
        self::assertSame('alice@example.com', $listedConversation['contact_details']);
        self::assertSame('user', $listedConversation['last_message_by']);
        self::assertTrue($listedConversation['unread']);
        self::assertSame(365, $inboxData['retention_days']);

        // Opening it marks it read for the administrators.
        $conversationData = $this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/conversations/' . $conversationId));
        self::assertSame("I cannot open\nthe shared workspace.", $conversationData['messages'][0]['body']);
        self::assertFalse($conversationData['messages'][0]['is_own']);
        self::assertSame(0, $this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/conversations/unread'))['unread_count']);

        // The answer reaches Alice as an unread reply.
        $answerResponse = $this->sendAs($this->administrator, 'POST', '/api/v1/admin/conversations/' . $conversationId . '/messages', ['message' => "Try again now.\r\nIt is fixed."]);
        self::assertSame(201, $answerResponse->statusCode(), $answerResponse->body());
        self::assertSame('admin', $answerResponse->json()['data']['conversation']['last_message_by']);
        // Administrators see which administrator answered.
        self::assertSame('Root', $answerResponse->json()['data']['messages'][1]['author_name']);

        self::assertSame(1, $this->assertOkData($this->getAs($alice, '/api/v1/admin-messages/unread'))['unread_count']);
        self::assertSame(1, $this->assertOkData($this->getAs($alice, '/api/v1/admin-messages'))['unread_count']);
        $aliceList = $this->assertOkData($this->getAs($alice, '/api/v1/admin-messages/conversations'));
        self::assertTrue($aliceList['conversations'][0]['unread']);

        // Alice reads it: the badge clears, and the answer is from "Administrator", not a name.
        $aliceView = $this->assertOkData($this->getAs($alice, '/api/v1/admin-messages/conversations/' . $conversationId));
        self::assertSame("Try again now.\nIt is fixed.", $aliceView['messages'][1]['body']);
        self::assertSame('Administrator', $aliceView['messages'][1]['author_name']);
        self::assertSame('admin', $aliceView['messages'][1]['from']);
        self::assertTrue($aliceView['messages'][0]['is_own']);
        self::assertTrue($aliceView['conversation']['can_reply']);
        self::assertStringNotContainsString('Root', json_encode($aliceView, JSON_THROW_ON_ERROR));
        self::assertSame(0, $this->assertOkData($this->getAs($alice, '/api/v1/admin-messages/unread'))['unread_count']);
    }

    public function testUserReplyWaitsForTheAdministratorAndPushesANotification(): void
    {
        $alice = $this->signedInUser('alice');
        $conversationId = $this->startConversation($alice);
        $this->getAs($this->administrator, '/api/v1/admin/conversations/' . $conversationId);
        $this->sendAs($this->administrator, 'POST', '/api/v1/admin/conversations/' . $conversationId . '/messages', ['message' => 'What happens?']);
        $this->ntfyTransport->publishedMessages = [];

        $replyResponse = $this->sendAs($alice, 'POST', '/api/v1/admin-messages/conversations/' . $conversationId . '/messages', ['message' => 'It says 404.']);
        self::assertSame(201, $replyResponse->statusCode(), $replyResponse->body());
        self::assertCount(3, $replyResponse->json()['data']['messages']);

        // Unread for the administrators again, and on their phone after the response.
        self::assertSame(1, $this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/conversations/unread'))['unread_count']);
        self::assertSame([], $this->ntfyTransport->publishedMessages);
        self::assertSame(1, $this->application->sendQueuedNotifications());
        $published = $this->ntfyTransport->publishedMessages[0]['message'];
        self::assertSame('Muninn: reply from Alice (alice)', $published['title']);
        self::assertSame('It says 404.', $published['message']);
        self::assertSame('https://www.dx.se/admin/messages.php?id=' . $conversationId, $published['click']);

        // The audit log records the reply, never its text.
        $auditDetails = (string) $this->scalar("SELECT CONCAT_WS('|', target_id, details) FROM audit_log WHERE event_type = 'admin_conversation.user_replied'");
        self::assertStringContainsString($conversationId, $auditDetails);
        self::assertStringNotContainsString('404', $auditDetails);
    }

    public function testUsersNeverSeeEachOthersConversations(): void
    {
        $alice = $this->signedInUser('alice');
        $bob = $this->signedInUser('bob');
        $aliceConversationId = $this->startConversation($alice, 'Alice private words');

        self::assertSame([], $this->assertOkData($this->getAs($bob, '/api/v1/admin-messages/conversations'))['conversations']);
        // Bob gets the same 404 as for a conversation that does not exist.
        $this->assertError($this->getAs($bob, '/api/v1/admin-messages/conversations/' . $aliceConversationId), 404, 'not_found');
        $this->assertError($this->sendAs($bob, 'POST', '/api/v1/admin-messages/conversations/' . $aliceConversationId . '/messages', ['message' => 'Hi']), 404, 'not_found');
        $this->assertError($this->getAs($bob, '/api/v1/admin-messages/conversations/not-a-uuid'), 404, 'not_found');
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM admin_conversation_messages'));

        // Everyday users cannot reach the inbox at all.
        foreach (['/api/v1/admin/conversations', '/api/v1/admin/conversations/unread', '/api/v1/admin/conversations/' . $aliceConversationId] as $adminPath) {
            self::assertContains($this->getAs($bob, $adminPath)->statusCode(), [403, 404], $adminPath);
        }
        self::assertContains(
            $this->sendAs($alice, 'POST', '/api/v1/admin/conversations/' . $aliceConversationId . '/messages', ['message' => 'Pretending'])->statusCode(),
            [403, 404],
        );
        self::assertContains($this->sendAs($alice, 'POST', '/api/v1/admin/conversations/' . $aliceConversationId . '/close')->statusCode(), [403, 404]);

        // Signed-out visitors get nothing.
        $this->assertError($this->send('GET', '/api/v1/admin-messages/conversations'), 401, 'unauthenticated');
        $this->assertError($this->send('GET', '/api/v1/admin/conversations'), 401, 'unauthenticated');
    }

    public function testAdministratorAccountsHaveNoOwnConversations(): void
    {
        $this->assertError($this->getAs($this->administrator, '/api/v1/admin-messages/conversations'), 403, 'admin_account');
        $this->assertError($this->getAs($this->administrator, '/api/v1/admin-messages/unread'), 403, 'admin_account');
    }

    public function testClosedConversationIsReadOnlyForTheUserUntilReopenedOrAnswered(): void
    {
        $alice = $this->signedInUser('alice');
        $conversationId = $this->startConversation($alice);
        $replyPath = '/api/v1/admin-messages/conversations/' . $conversationId . '/messages';

        $closeData = $this->assertOkData($this->sendAs($this->administrator, 'POST', '/api/v1/admin/conversations/' . $conversationId . '/close'));
        self::assertSame('closed', $closeData['conversation']['status']);
        // Closing also clears it from the administrators' unread count.
        self::assertSame(0, $this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/conversations/unread'))['unread_count']);
        self::assertSame([], $this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/conversations'))['conversations']);
        self::assertCount(1, $this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/conversations', ['status' => 'closed']))['conversations']);
        self::assertCount(1, $this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/conversations', ['status' => 'all']))['conversations']);
        $this->assertError($this->getAs($this->administrator, '/api/v1/admin/conversations', ['status' => 'bogus']), 400, 'invalid_status');

        // Alice can still read it, but not reply.
        self::assertFalse($this->assertOkData($this->getAs($alice, '/api/v1/admin-messages/conversations/' . $conversationId))['conversation']['can_reply']);
        $this->assertError($this->sendAs($alice, 'POST', $replyPath, ['message' => 'Wait!']), 409, 'conversation_closed');

        // Reopening lets her reply again.
        self::assertSame('open', $this->assertOkData($this->sendAs($this->administrator, 'POST', '/api/v1/admin/conversations/' . $conversationId . '/reopen'))['conversation']['status']);
        self::assertSame(201, $this->sendAs($alice, 'POST', $replyPath, ['message' => 'Thanks'])->statusCode());

        // An administrator's answer to a closed conversation opens it again.
        $this->sendAs($this->administrator, 'POST', '/api/v1/admin/conversations/' . $conversationId . '/close');
        $answerData = $this->sendAs($this->administrator, 'POST', '/api/v1/admin/conversations/' . $conversationId . '/messages', ['message' => 'One more thing'])->json()['data'];
        self::assertSame('open', $answerData['conversation']['status']);

        // Each change is audited.
        foreach (['admin_conversation.closed', 'admin_conversation.reopened', 'admin_conversation.admin_replied'] as $eventType) {
            self::assertGreaterThan(0, (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE event_type = :event_type', ['event_type' => $eventType]), $eventType);
        }
    }

    public function testRepliesAreValidatedAndLimited(): void
    {
        $alice = $this->signedInUser('alice');
        $conversationId = $this->startConversation($alice);
        $replyPath = '/api/v1/admin-messages/conversations/' . $conversationId . '/messages';

        $this->assertError($this->sendAs($alice, 'POST', $replyPath, ['message' => "  \r\n "]), 422, 'validation_failed');
        $this->assertError($this->sendAs($alice, 'POST', $replyPath, ['message' => str_repeat('a', AdminConversationService::MESSAGE_MAX_LENGTH + 1)]), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->administrator, 'POST', '/api/v1/admin/conversations/' . $conversationId . '/messages', ['message' => '']), 422, 'validation_failed');

        // The first message counts towards the hourly reply limit too.
        for ($replyNumber = 2; $replyNumber <= AdminConversationController::MAXIMUM_USER_REPLIES_PER_HOUR; $replyNumber++) {
            self::assertSame(201, $this->sendAs($alice, 'POST', $replyPath, ['message' => 'Reply ' . $replyNumber])->statusCode());
        }
        $this->assertError($this->sendAs($alice, 'POST', $replyPath, ['message' => 'One too many']), 429, 'rate_limited');
    }

    public function testFullConversationAsksForANewOne(): void
    {
        $alice = $this->signedInUser('alice');
        $conversationId = $this->startConversation($alice);
        // Fill the conversation directly: the hourly limit would stop a user long before.
        $insertStatement = $this->database->prepare(
            "INSERT INTO admin_conversation_messages (id, conversation_id, author_user_id, author_role, body, created_at)
             SELECT UUID(), :conversation_id, user_id, 'user', 'Filler', UTC_TIMESTAMP(6) - INTERVAL 2 HOUR
             FROM admin_conversations WHERE id = :same_conversation_id"
        );
        for ($fillerNumber = 1; $fillerNumber < AdminConversationService::MAXIMUM_MESSAGES_PER_CONVERSATION; $fillerNumber++) {
            $insertStatement->execute(['conversation_id' => $conversationId, 'same_conversation_id' => $conversationId]);
        }

        $this->assertError(
            $this->sendAs($alice, 'POST', '/api/v1/admin-messages/conversations/' . $conversationId . '/messages', ['message' => 'More']),
            409,
            'conversation_full',
        );
    }

    public function testOldConversationsAreDeletedAfterTheRetention(): void
    {
        $alice = $this->signedInUser('alice');
        $oldConversationId = $this->startConversation($alice, 'Old question');
        $recentConversationId = $this->startConversation($alice, 'Recent question');
        $this->database->prepare('UPDATE admin_conversations SET last_message_at = UTC_TIMESTAMP(6) - INTERVAL 366 DAY WHERE id = :id')
            ->execute(['id' => $oldConversationId]);

        // Housekeeping runs after a signed-in request.
        $this->getAs($alice, '/api/v1/admin-messages/conversations');
        $this->application->runHousekeeping();

        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM admin_conversations WHERE id = :id', ['id' => $oldConversationId]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM admin_conversation_messages WHERE conversation_id = :id', ['id' => $oldConversationId]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM admin_conversations WHERE id = :id', ['id' => $recentConversationId]));
        $auditDetails = json_decode((string) $this->scalar("SELECT details FROM audit_log WHERE event_type = 'admin_conversation.expired_deleted'"), true);
        self::assertSame(1, $auditDetails['deleted_conversations']);
        self::assertSame(365, $auditDetails['retention_days']);
    }
}
