<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Notifications\AdminMessageController;
use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * "Contact admin" (D058): everyday users write to the administrator. Covers what the ntfy
 * notification contains, that the message is stored in the administrator's inbox (D065), the
 * hourly limit, validation, and who may use it. Replies: AdminConversationTest.
 */
final class AdminMessageTest extends WorkspaceTestCase
{
    private const NTFY_SETTINGS = [
        'ntfy' => [
            'enabled' => true,
            'server_url' => 'http://127.0.0.1:2586',
            'topic' => 'muninn-admin',
            'access_token' => 'tk_secret_test_token',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->application = $this->buildApplication(self::NTFY_SETTINGS);
    }

    public function testMessageIsSentToTheAdministratorStraightAway(): void
    {
        $alice = $this->signedInUser('alice');

        $sendResponse = $this->sendAs($alice, 'POST', '/api/v1/admin-messages', [
            'message' => "Hi!\r\nI cannot open the shared workspace.",
            'contact' => 'alice@example.com',
        ]);
        self::assertSame(201, $sendResponse->statusCode(), $sendResponse->body());
        self::assertSame(AdminMessageController::MAXIMUM_MESSAGES_PER_HOUR - 1, $sendResponse->json()['data']['remaining_this_hour']);

        // Sent during the request, not queued for later.
        self::assertCount(1, $this->ntfyTransport->publishedMessages);
        $published = $this->ntfyTransport->publishedMessages[0]['message'];
        self::assertSame('muninn-admin', $published['topic']);
        self::assertSame('Muninn: message from Alice (alice)', $published['title']);
        self::assertSame("Hi!\nI cannot open the shared workspace.\n\nReply to: alice@example.com", $published['message']);
        // Tapping the notification opens the conversation in the inbox (D065).
        $conversationId = $sendResponse->json()['data']['conversation_id'];
        self::assertSame('https://www.dx.se/admin/messages.php?id=' . $conversationId, $published['click']);
        self::assertTrue($sendResponse->json()['data']['notified']);

        // Stored in the inbox with Windows line endings made plain, and the contact details kept.
        self::assertSame("Hi!\nI cannot open the shared workspace.", $this->scalar('SELECT body FROM admin_conversation_messages'));
        self::assertSame('alice@example.com', $this->scalar('SELECT contact_details FROM admin_conversations WHERE id = :id', ['id' => $conversationId]));

        // The audit log records that a message was sent, never what it said.
        $auditDetails = (string) $this->scalar("SELECT details FROM audit_log WHERE event_type = 'admin_message.sent'");
        self::assertStringNotContainsString('shared workspace', $auditDetails);
        self::assertStringNotContainsString('alice@example.com', $auditDetails);
    }

    public function testMessageWithoutContactDetailsSaysSo(): void
    {
        $alice = $this->signedInUser('alice');

        $this->sendAs($alice, 'POST', '/api/v1/admin-messages', ['message' => 'Hello']);
        self::assertSame("Hello\n\nNo contact details given.", $this->ntfyTransport->publishedMessages[0]['message']['message']);
    }

    public function testControlCharactersAreRemovedButLineBreaksKept(): void
    {
        $alice = $this->signedInUser('alice');

        $this->sendAs($alice, 'POST', '/api/v1/admin-messages', ['message' => "Line one\x1b[31m\n\n\n\nLine two"]);
        self::assertSame("Line one [31m\n\nLine two\n\nNo contact details given.", $this->ntfyTransport->publishedMessages[0]['message']['message']);
    }

    public function testEachUserHasAnHourlyLimit(): void
    {
        $alice = $this->signedInUser('alice');
        $bob = $this->signedInUser('bob');

        for ($messageNumber = 1; $messageNumber <= AdminMessageController::MAXIMUM_MESSAGES_PER_HOUR; $messageNumber++) {
            self::assertSame(201, $this->sendAs($alice, 'POST', '/api/v1/admin-messages', ['message' => 'Message ' . $messageNumber])->statusCode());
        }
        $this->assertError($this->sendAs($alice, 'POST', '/api/v1/admin-messages', ['message' => 'One too many']), 429, 'rate_limited');
        self::assertCount(AdminMessageController::MAXIMUM_MESSAGES_PER_HOUR, $this->ntfyTransport->publishedMessages);

        $statusResponse = $this->sendAs($alice, 'GET', '/api/v1/admin-messages');
        self::assertSame(0, $statusResponse->json()['data']['remaining_this_hour']);

        // Another user's limit is separate.
        self::assertSame(201, $this->sendAs($bob, 'POST', '/api/v1/admin-messages', ['message' => 'Hello'])->statusCode());
    }

    public function testInvalidInputIsRefused(): void
    {
        $alice = $this->signedInUser('alice');

        $this->assertError($this->sendAs($alice, 'POST', '/api/v1/admin-messages', ['message' => '   ']), 422, 'validation_failed');
        $this->assertError($this->sendAs($alice, 'POST', '/api/v1/admin-messages', ['message' => str_repeat('a', 1001)]), 422, 'validation_failed');
        $this->assertError($this->sendAs($alice, 'POST', '/api/v1/admin-messages', ['message' => 'Hi', 'contact' => "two\nlines"]), 422, 'validation_failed');
        self::assertSame([], $this->ntfyTransport->publishedMessages);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM admin_conversations'));
    }

    public function testUndeliveredNotificationStillKeepsTheMessageAndLogsNoToken(): void
    {
        $this->ntfyTransport->answerStatusCode = 0;
        $alice = $this->signedInUser('alice');

        // The inbox has it (D065), so the user is told it was sent, only without the phone alert.
        $sendResponse = $this->sendAs($alice, 'POST', '/api/v1/admin-messages', ['message' => 'Private words']);
        self::assertSame(201, $sendResponse->statusCode(), $sendResponse->body());
        self::assertFalse($sendResponse->json()['data']['notified']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM admin_conversations'));

        $logText = (string) file_get_contents($this->logFilePath);
        self::assertStringContainsString('ntfy user message was not delivered', $logText);
        self::assertStringNotContainsString('tk_secret_test_token', $logText);
        self::assertStringNotContainsString('Private words', $logText);
    }

    public function testWorksWithoutNtfy(): void
    {
        $this->application = $this->buildApplication();
        $alice = $this->signedInUser('alice');

        $sendResponse = $this->sendAs($alice, 'POST', '/api/v1/admin-messages', ['message' => 'Hello']);
        self::assertSame(201, $sendResponse->statusCode(), $sendResponse->body());
        self::assertFalse($sendResponse->json()['data']['notified']);
        self::assertSame([], $this->ntfyTransport->publishedMessages);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM admin_conversations'));
    }

    public function testAdministratorsAndSignedOutVisitorsCannotUseIt(): void
    {
        $this->createUser('root', self::DEFAULT_PASSWORD, true);
        $administrator = $this->login('root');

        $this->assertError($this->sendAs($administrator, 'POST', '/api/v1/admin-messages', ['message' => 'Hello']), 403, 'admin_account');
        $this->assertError($this->send('POST', '/api/v1/admin-messages', ['message' => 'Hello']), 401, 'unauthenticated');
        self::assertSame([], $this->ntfyTransport->publishedMessages);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM admin_conversations'));
    }
}
