<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Admin\DataResetService;
use Muninn\Api\Http\Response;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * Chat (D062): one channel per shared workspace plus a global one, limited by each account's
 * chat level, the workspace role, and the rule that administrator accounts never read chat.
 */
final class ChatTest extends WorkspaceTestCase
{
    /** @var array{session_token: string, csrf_token: string, user_id: string} Owner of the team workspace. */
    private array $owner;
    /** @var array{session_token: string, csrf_token: string, user_id: string} Editor in the team workspace. */
    private array $editor;
    /** @var array{session_token: string, csrf_token: string, user_id: string} Reader in the team workspace. */
    private array $reader;
    /** @var array{session_token: string, csrf_token: string, user_id: string} Not a member of the team workspace. */
    private array $outsider;
    /** @var array{session_token: string, csrf_token: string, user_id: string} System administrator. */
    private array $admin;
    private string $teamWorkspaceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->signedInUser('olivia');
        $this->editor = $this->signedInUser('erik');
        $this->reader = $this->signedInUser('rita');
        $this->outsider = $this->signedInUser('oscar');
        $this->admin = $this->signedInUser('sysadmin', true);

        $this->teamWorkspaceId = $this->createSharedWorkspace($this->owner, 'Team');
        self::assertSame(201, $this->addMember($this->owner, $this->teamWorkspaceId, 'erik', 'editor')->statusCode());
        self::assertSame(201, $this->addMember($this->owner, $this->teamWorkspaceId, 'rita', 'reader')->statusCode());
    }

    /** Sets a user's chat level directly in the database (the admin endpoint has its own test). */
    private function setChatAccess(array $credentials, string $chatAccess): void
    {
        $this->database->prepare('UPDATE users SET chat_access = :chat_access WHERE id = :id')
            ->execute(['chat_access' => $chatAccess, 'id' => $credentials['user_id']]);
    }

    private function workspaceMessagesPath(?string $workspaceId = null): string
    {
        return '/api/v1/workspaces/' . ($workspaceId ?? $this->teamWorkspaceId) . '/chat/messages';
    }

    /** Posts a message and returns the response. */
    private function post(array $credentials, string $path, string $messageBody): Response
    {
        return $this->sendAs($credentials, 'POST', $path, ['body' => $messageBody]);
    }

    /**
     * Posts a message that must succeed and returns it.
     *
     * @return array<string, mixed>
     */
    private function postOk(array $credentials, string $path, string $messageBody): array
    {
        $postResponse = $this->post($credentials, $path, $messageBody);
        self::assertSame(201, $postResponse->statusCode(), $postResponse->body());

        return $postResponse->json()['data']['message'];
    }

    /** @return list<string> The texts of the messages a channel shows, oldest first. */
    private function messageBodies(array $credentials, string $path): array
    {
        $listData = $this->assertOkData($this->getAs($credentials, $path));

        return array_column($listData['messages'], 'body');
    }

    /** Inserts a message straight into the database (for paging and retention tests). */
    private function insertMessage(?string $workspaceId, string $authorUserId, string $messageBody, string $ageExpression = '0 SECOND'): string
    {
        $messageId = UuidGenerator::generate();
        $this->database->prepare(
            'INSERT INTO chat_messages (id, workspace_id, author_user_id, body, created_at, changed_at)
             VALUES (:id, :workspace_id, :author_user_id, :body,
                     UTC_TIMESTAMP(6) - INTERVAL ' . $ageExpression . ', UTC_TIMESTAMP(6) - INTERVAL ' . $ageExpression . ')'
        )->execute(['id' => $messageId, 'workspace_id' => $workspaceId, 'author_user_id' => $authorUserId, 'body' => $messageBody]);

        return $messageId;
    }

    public function testMembersChatInTheirSharedWorkspace(): void
    {
        $sentMessage = $this->postOk($this->editor, $this->workspaceMessagesPath(), "Hello team\nSecond line");
        self::assertSame("Hello team\nSecond line", $sentMessage['body']);
        self::assertSame('erik', $sentMessage['author']['username']);
        self::assertTrue($sentMessage['is_own']);
        $this->postOk($this->owner, $this->workspaceMessagesPath(), 'Hi Erik');

        // Every member reads the channel, in order.
        foreach ([$this->owner, $this->editor, $this->reader] as $memberCredentials) {
            self::assertSame(["Hello team\nSecond line", 'Hi Erik'], $this->messageBodies($memberCredentials, $this->workspaceMessagesPath()));
        }

        $readerView = $this->assertOkData($this->getAs($this->reader, $this->workspaceMessagesPath()));
        self::assertFalse($readerView['channel']['can_write']);
        self::assertFalse($readerView['messages'][0]['can_delete']);
        $ownerView = $this->assertOkData($this->getAs($this->owner, $this->workspaceMessagesPath()));
        self::assertTrue($ownerView['channel']['can_moderate']);
        self::assertTrue($ownerView['messages'][0]['can_delete'], 'The Owner may delete anyone\'s message.');
    }

    public function testReadersCanReadButNotWrite(): void
    {
        $this->assertError($this->post($this->reader, $this->workspaceMessagesPath(), 'Can I?'), 403, 'insufficient_role');
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM chat_messages'));
    }

    public function testNonMembersCannotReadWriteOrDiscoverAWorkspaceChat(): void
    {
        $teamMessage = $this->postOk($this->owner, $this->workspaceMessagesPath(), 'Secret team talk');

        // Exactly like an unknown workspace: 404 for reading, writing and deleting.
        $this->assertError($this->getAs($this->outsider, $this->workspaceMessagesPath()), 404, 'not_found');
        $this->assertError($this->post($this->outsider, $this->workspaceMessagesPath(), 'Let me in'), 404, 'not_found');
        $this->assertError($this->sendAs($this->outsider, 'DELETE', '/api/v1/chat/messages/' . $teamMessage['id']), 404, 'not_found');
        $this->assertError($this->getAs($this->outsider, $this->workspaceMessagesPath(UuidGenerator::generate())), 404, 'not_found');

        // The channel list does not mention it either.
        $outsiderOverview = $this->assertOkData($this->getAs($this->outsider, '/api/v1/chat'));
        self::assertSame([], $outsiderOverview['workspaces']);
        self::assertStringNotContainsString('Team', json_encode($outsiderOverview, JSON_THROW_ON_ERROR));
    }

    public function testARemovedMemberLosesTheChatAtOnce(): void
    {
        $this->postOk($this->owner, $this->workspaceMessagesPath(), 'Before');
        self::assertSame(204, $this->sendAs($this->owner, 'DELETE', '/api/v1/workspaces/' . $this->teamWorkspaceId . '/members/' . $this->editor['user_id'])->statusCode());

        $this->assertError($this->getAs($this->editor, $this->workspaceMessagesPath()), 404, 'not_found');
    }

    public function testPersonalWorkspacesHaveNoChat(): void
    {
        $personalWorkspaceId = $this->personalWorkspaceId($this->owner);

        $this->assertError($this->getAs($this->owner, $this->workspaceMessagesPath($personalWorkspaceId)), 403, 'chat_not_allowed');
        $this->assertError($this->post($this->owner, $this->workspaceMessagesPath($personalWorkspaceId), 'Note to self'), 403, 'chat_not_allowed');
        $ownerOverview = $this->assertOkData($this->getAs($this->owner, '/api/v1/chat'));
        self::assertSame([$this->teamWorkspaceId], array_column($ownerOverview['workspaces'], 'workspace_id'));
    }

    public function testChatLevelOffBlocksEveryChat(): void
    {
        $this->postOk($this->owner, $this->workspaceMessagesPath(), 'Hello');
        $this->setChatAccess($this->editor, 'off');

        $this->assertError($this->getAs($this->editor, $this->workspaceMessagesPath()), 403, 'chat_not_allowed');
        $this->assertError($this->post($this->editor, $this->workspaceMessagesPath(), 'Hi'), 403, 'chat_not_allowed');
        $this->assertError($this->getAs($this->editor, '/api/v1/chat/global/messages'), 403, 'chat_not_allowed');

        $editorOverview = $this->assertOkData($this->getAs($this->editor, '/api/v1/chat'));
        self::assertSame('off', $editorOverview['chat_access']);
        self::assertFalse($editorOverview['global']['can_read']);
        self::assertSame([], $editorOverview['workspaces']);
        self::assertSame('off', $this->assertOkData($this->getAs($this->editor, '/api/v1/auth/me'))['user']['chat_access']);
    }

    public function testOwnWorkspacesLevelOnlyAllowsWorkspacesTheUserOwns(): void
    {
        // Erik owns a workspace of his own, and is only an Editor in the team workspace.
        $erikWorkspaceId = $this->createSharedWorkspace($this->editor, 'Erik\'s');
        $this->setChatAccess($this->editor, 'own_workspaces');

        $this->postOk($this->editor, $this->workspaceMessagesPath($erikWorkspaceId), 'In my own workspace');
        $this->assertError($this->getAs($this->editor, $this->workspaceMessagesPath()), 403, 'chat_not_allowed');
        $this->assertError($this->post($this->editor, $this->workspaceMessagesPath(), 'In the team'), 403, 'chat_not_allowed');

        $editorOverview = $this->assertOkData($this->getAs($this->editor, '/api/v1/chat'));
        self::assertSame([$erikWorkspaceId], array_column($editorOverview['workspaces'], 'workspace_id'));
        // The global channel stays readable, but not writable.
        self::assertTrue($editorOverview['global']['can_read']);
        self::assertFalse($editorOverview['global']['can_write']);
    }

    public function testOnlyTheGlobalLevelMayShoutOutButEveryoneReads(): void
    {
        $this->assertError($this->post($this->owner, '/api/v1/chat/global/messages', 'Hello everyone'), 403, 'chat_read_only');

        $this->setChatAccess($this->owner, 'global');
        $shout = $this->postOk($this->owner, '/api/v1/chat/global/messages', 'Hello everyone');

        // Everyone except "off" reads it, also users who share no workspace with the author.
        self::assertSame(['Hello everyone'], $this->messageBodies($this->outsider, '/api/v1/chat/global/messages'));
        self::assertSame(['Hello everyone'], $this->messageBodies($this->reader, '/api/v1/chat/global/messages'));
        // Global messages never show up in a workspace channel, and the other way round.
        self::assertSame([], $this->messageBodies($this->owner, $this->workspaceMessagesPath()));

        // In the global channel nobody moderates: only the author deletes.
        $outsiderView = $this->assertOkData($this->getAs($this->outsider, '/api/v1/chat/global/messages'));
        self::assertFalse($outsiderView['messages'][0]['can_delete']);
        $this->assertError($this->sendAs($this->outsider, 'DELETE', '/api/v1/chat/messages/' . $shout['id']), 403, 'not_your_message');
        self::assertSame(204, $this->sendAs($this->owner, 'DELETE', '/api/v1/chat/messages/' . $shout['id'])->statusCode());
    }

    public function testAdministratorAccountsCannotReadAnyChat(): void
    {
        $this->setChatAccess($this->owner, 'global');
        $this->postOk($this->owner, '/api/v1/chat/global/messages', 'Hello everyone');
        $teamMessage = $this->postOk($this->owner, $this->workspaceMessagesPath(), 'Team only');

        $this->assertError($this->getAs($this->admin, '/api/v1/chat/global/messages'), 403, 'chat_not_allowed');
        $this->assertError($this->post($this->admin, '/api/v1/chat/global/messages', 'Admin here'), 403, 'chat_not_allowed');
        $this->assertError($this->getAs($this->admin, $this->workspaceMessagesPath()), 404, 'not_found');
        $this->assertError($this->sendAs($this->admin, 'DELETE', '/api/v1/chat/messages/' . $teamMessage['id']), 404, 'not_found');

        $adminOverview = $this->assertOkData($this->getAs($this->admin, '/api/v1/chat'));
        self::assertSame('off', $adminOverview['chat_access']);
        self::assertFalse($adminOverview['global']['can_read']);
        self::assertSame([], $adminOverview['workspaces']);

        // Not even the admin pages carry chat text.
        $adminUsersBody = $this->getAs($this->admin, '/api/v1/admin/users')->body();
        self::assertStringNotContainsString('Team only', $adminUsersBody);
    }

    public function testTheAdministratorSetsChatLevels(): void
    {
        $changeResponse = $this->sendAs($this->admin, 'PATCH', '/api/v1/admin/users/' . $this->editor['user_id'] . '/chat-access', ['chat_access' => 'global']);
        self::assertSame('global', $this->assertOkData($changeResponse)['chat_access']);

        // It takes effect on the user's next request.
        $this->postOk($this->editor, '/api/v1/chat/global/messages', 'Now I can shout');

        $adminUsers = $this->assertOkData($this->getAs($this->admin, '/api/v1/admin/users'))['users'];
        $levelsByUsername = array_column($adminUsers, 'chat_access', 'username');
        self::assertSame('global', $levelsByUsername['erik']);
        self::assertSame('member_workspaces', $levelsByUsername['rita'], 'Accounts start at the default level.');
        self::assertSame('off', $levelsByUsername['sysadmin']);

        $auditDetails = json_decode((string) $this->scalar(
            "SELECT details FROM audit_log WHERE event_type = 'user.chat_access_changed' AND target_id = :id",
            ['id' => $this->editor['user_id']],
        ), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['from' => 'member_workspaces', 'to' => 'global'], $auditDetails);

        // Unknown levels and administrator accounts are refused.
        $this->assertError($this->sendAs($this->admin, 'PATCH', '/api/v1/admin/users/' . $this->editor['user_id'] . '/chat-access', ['chat_access' => 'everything']), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->admin, 'PATCH', '/api/v1/admin/users/' . $this->admin['user_id'] . '/chat-access', ['chat_access' => 'global']), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->admin, 'PATCH', '/api/v1/admin/users/' . UuidGenerator::generate() . '/chat-access', ['chat_access' => 'off']), 404, 'not_found');
    }

    public function testEverydayUsersCannotChangeChatLevels(): void
    {
        $this->assertError(
            $this->sendAs($this->owner, 'PATCH', '/api/v1/admin/users/' . $this->owner['user_id'] . '/chat-access', ['chat_access' => 'global']),
            404,
            'not_found',
        );
        self::assertSame('member_workspaces', $this->scalar('SELECT chat_access FROM users WHERE id = :id', ['id' => $this->owner['user_id']]));
    }

    public function testDeletingMessages(): void
    {
        $editorMessage = $this->postOk($this->editor, $this->workspaceMessagesPath(), 'Oops, wrong channel');
        $secondEditorMessage = $this->postOk($this->editor, $this->workspaceMessagesPath(), 'Keep this one');

        // Authors delete their own messages; nobody else below Admin may.
        $this->assertError($this->sendAs($this->reader, 'DELETE', '/api/v1/chat/messages/' . $editorMessage['id']), 403, 'not_your_message');
        self::assertSame(204, $this->sendAs($this->editor, 'DELETE', '/api/v1/chat/messages/' . $editorMessage['id'])->statusCode());
        $this->assertError($this->sendAs($this->editor, 'DELETE', '/api/v1/chat/messages/' . $editorMessage['id']), 404, 'not_found');

        // The workspace Owner removes anyone's message; that is audited without the text.
        self::assertSame(204, $this->sendAs($this->owner, 'DELETE', '/api/v1/chat/messages/' . $secondEditorMessage['id'])->statusCode());
        $auditRow = $this->database->query("SELECT target_id, details FROM audit_log WHERE event_type = 'chat.message_removed_by_moderator'")->fetch();
        self::assertSame($secondEditorMessage['id'], $auditRow['target_id']);
        self::assertStringNotContainsString('Keep this one', $auditRow['details']);
        // Deleting your own message is not audited.
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type LIKE 'chat.%'"));

        // Deleted messages keep no text and are gone from the channel.
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM chat_messages WHERE body <> ''"));
        self::assertSame([], $this->messageBodies($this->reader, $this->workspaceMessagesPath()));
    }

    public function testPollingReturnsNewAndDeletedMessages(): void
    {
        $firstMessage = $this->postOk($this->owner, $this->workspaceMessagesPath(), 'First');
        $openedChannel = $this->assertOkData($this->getAs($this->reader, $this->workspaceMessagesPath()));
        $cursor = $openedChannel['cursor'];

        $this->postOk($this->editor, $this->workspaceMessagesPath(), 'Second');
        self::assertSame(204, $this->sendAs($this->owner, 'DELETE', '/api/v1/chat/messages/' . $firstMessage['id'])->statusCode());

        $changes = $this->assertOkData($this->getAs($this->reader, $this->workspaceMessagesPath(), ['since' => $cursor]));
        self::assertFalse($changes['reset']);
        $changesById = array_column($changes['messages'], null, 'id');
        self::assertTrue($changesById[$firstMessage['id']]['is_deleted']);
        self::assertArrayNotHasKey('body', $changesById[$firstMessage['id']], 'A deleted message carries no text.');
        self::assertContains('Second', array_column($changes['messages'], 'body'));
        self::assertNotSame($cursor, $changes['cursor']);

        $this->assertError($this->getAs($this->reader, $this->workspaceMessagesPath(), ['since' => '2026-10-09 12:00']), 400, 'invalid_since');
        $this->assertError($this->getAs($this->reader, $this->workspaceMessagesPath(), ['since' => $cursor, 'before' => $firstMessage['id']]), 400, 'invalid_query');
    }

    public function testEarlierMessagesArePaged(): void
    {
        // 55 messages, one second apart, oldest first.
        for ($messageNumber = 1; $messageNumber <= 55; $messageNumber++) {
            $this->insertMessage($this->teamWorkspaceId, $this->owner['user_id'], 'Message ' . $messageNumber, (100 - $messageNumber) . ' SECOND');
        }

        $latestPage = $this->assertOkData($this->getAs($this->reader, $this->workspaceMessagesPath()));
        self::assertCount(50, $latestPage['messages']);
        self::assertTrue($latestPage['has_older']);
        self::assertSame('Message 6', $latestPage['messages'][0]['body']);
        self::assertSame('Message 55', $latestPage['messages'][49]['body']);

        $olderPage = $this->assertOkData($this->getAs($this->reader, $this->workspaceMessagesPath(), ['before' => $latestPage['messages'][0]['id']]));
        self::assertSame(['Message 1', 'Message 2', 'Message 3', 'Message 4', 'Message 5'], array_column($olderPage['messages'], 'body'));
        self::assertFalse($olderPage['has_older']);

        // A message ID from another channel is no anchor here.
        $globalMessageId = $this->insertMessage(null, $this->owner['user_id'], 'Global');
        self::assertSame([], $this->assertOkData($this->getAs($this->reader, $this->workspaceMessagesPath(), ['before' => $globalMessageId]))['messages']);
    }

    public function testMessagesAreValidated(): void
    {
        $this->assertError($this->post($this->editor, $this->workspaceMessagesPath(), "  \n "), 422, 'validation_failed');
        $this->assertError($this->post($this->editor, $this->workspaceMessagesPath(), str_repeat('a', 2001)), 422, 'validation_failed');
        $this->assertError($this->post($this->editor, $this->workspaceMessagesPath(), "Bad \0 byte"), 422, 'validation_failed');
        // 2000 characters of four-byte emoji are fine.
        $this->postOk($this->editor, $this->workspaceMessagesPath(), str_repeat("\u{1F426}", 2000));
    }

    public function testSendingIsRateLimited(): void
    {
        for ($messageNumber = 1; $messageNumber <= 10; $messageNumber++) {
            $this->postOk($this->editor, $this->workspaceMessagesPath(), 'Message ' . $messageNumber);
        }
        $limitedResponse = $this->post($this->editor, $this->workspaceMessagesPath(), 'One too many');
        $this->assertError($limitedResponse, 429, 'chat_rate_limited');
        self::assertSame('60', $limitedResponse->header('Retry-After'));

        // The limit is per user.
        $this->postOk($this->owner, $this->workspaceMessagesPath(), 'I can still talk');
    }

    public function testOldMessagesAreDeletedAfterTheRetentionPeriod(): void
    {
        $expiredMessageId = $this->insertMessage($this->teamWorkspaceId, $this->owner['user_id'], 'Long ago', '91 DAY');
        $recentMessageId = $this->insertMessage($this->teamWorkspaceId, $this->owner['user_id'], 'Recent', '89 DAY');

        $this->getAs($this->reader, '/api/v1/chat');
        $this->application->runHousekeeping();

        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM chat_messages WHERE id = :id', ['id' => $expiredMessageId]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM chat_messages WHERE id = :id', ['id' => $recentMessageId]));
        $auditDetails = json_decode((string) $this->scalar("SELECT details FROM audit_log WHERE event_type = 'chat.expired_deleted'"), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $auditDetails['deleted_messages']);
        self::assertSame(90, $auditDetails['retention_days']);
    }

    public function testDeletingAWorkspaceDeletesItsChat(): void
    {
        $this->postOk($this->owner, $this->workspaceMessagesPath(), 'Bye');
        self::assertSame(204, $this->sendAs($this->owner, 'DELETE', '/api/v1/workspaces/' . $this->teamWorkspaceId)->statusCode());

        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM chat_messages'));
    }

    public function testDataResetDeletesAllChat(): void
    {
        $this->setChatAccess($this->owner, 'global');
        $this->postOk($this->owner, '/api/v1/chat/global/messages', 'Global');
        $this->postOk($this->owner, $this->workspaceMessagesPath(), 'Team');

        $deletedRowCounts = (new DataResetService($this->database, $this->attachmentStorage()))->resetToAdministratorsOnly();

        self::assertSame(2, $deletedRowCounts['chat messages (D062)']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM chat_messages'));
    }
}
