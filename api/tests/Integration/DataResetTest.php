<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Admin\DataResetService;
use Muninn\Api\Tests\Support\TestDatabase;
use Muninn\Api\Tests\Support\WorkspaceTestCase;
use RuntimeException;

/**
 * The "reset to admins only" tool behind bin/reset-data.php (D046).
 */
final class DataResetTest extends WorkspaceTestCase
{
    public function testResetDeletesUserDataButKeepsAdministratorsAndAuditLog(): void
    {
        $admin = $this->signedInUser('sysadmin', true);
        $alice = $this->signedInUser('alice');
        $bob = $this->signedInUser('bob');

        // Content in personal and shared workspaces, including a trashed note and memberships,
        // a folder, a tag and an attachment with its file on disk.
        $aliceNote = $this->createNote($alice, $this->personalWorkspaceId($alice));
        $aliceFolder = $this->createFolder($alice, $this->personalWorkspaceId($alice));
        self::assertSame(200, $this->sendAs($alice, 'PATCH', '/api/v1/notes/' . $aliceNote['id'], [
            'revision' => 1,
            'folder_id' => $aliceFolder['id'],
            'tags' => ['secret'],
        ])->statusCode());
        self::assertSame(201, $this->uploadAttachment($alice, $aliceNote['id'], self::tinyPng())->statusCode());
        $sharedWorkspaceId = $this->createSharedWorkspace($alice);
        self::assertSame(201, $this->addMember($alice, $sharedWorkspaceId, 'bob', 'editor')->statusCode());
        $trashedNote = $this->createNote($bob, $sharedWorkspaceId);
        self::assertSame(204, $this->sendAs($bob, 'DELETE', '/api/v1/notes/' . $trashedNote['id'])->statusCode());

        // One pending invitation created by the admin.
        self::assertSame(201, $this->sendAs($admin, 'POST', '/api/v1/admin/invitations', ['note' => 'test'])->statusCode());

        // An administrator's vote (D061) that Alice answered: her answer goes, the vote stays.
        $pollResponse = $this->sendAs($admin, 'POST', '/api/v1/admin/broadcasts', [
            'kind' => 'vote',
            'message' => 'Coffee or tea?',
            'options' => ['Coffee', 'Tea'],
            'starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
            'ends_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        ]);
        self::assertSame(201, $pollResponse->statusCode(), $pollResponse->body());
        $poll = $pollResponse->json()['data']['broadcast'];
        self::assertSame(204, $this->sendAs($alice, 'POST', '/api/v1/broadcasts/' . $poll['id'] . '/vote', [
            'option_ids' => [$poll['options'][0]['id']],
        ])->statusCode());
        $auditRowsBefore = (int) $this->scalar('SELECT COUNT(*) FROM audit_log');

        $resetService = new DataResetService(TestDatabase::connection(), $this->attachmentStorage());
        $countsBefore = $resetService->countRowsToDelete();
        self::assertSame(2, $countsBefore['user accounts (non-admin)']);
        self::assertSame(1, $countsBefore['invitations (all states)']);
        self::assertSame(1, $countsBefore['attachments']);
        self::assertSame(1, $countsBefore[DataResetService::ATTACHMENT_FILES_LABEL]);

        $deletedRowCounts = $resetService->resetToAdministratorsOnly();
        // The preview must match what is actually deleted (the two lists are in different orders).
        ksort($countsBefore);
        ksort($deletedRowCounts);
        self::assertSame($countsBefore, $deletedRowCounts, 'The preview must match what is deleted.');

        // Only the admin remains, and nothing else is left over.
        self::assertSame(['sysadmin'], array_column(TestDatabase::connection()->query('SELECT username FROM users')->fetchAll(), 'username'));
        foreach (['notes', 'folders', 'tags', 'note_tags', 'attachments', 'workspace_members', 'workspaces', 'invitations', 'invitation_requests', 'password_resets', 'auth_attempts'] as $emptiedTable) {
            self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM ' . $emptiedTable), $emptiedTable . ' should be empty');
        }

        self::assertSame(0, $this->attachmentStorage()->countFiles(), 'Attachment files are deleted too.');
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM broadcast_votes'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM broadcast_receipts'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM broadcasts'), 'The administrator\'s broadcasts stay.');

        // The audit log is untouched by the reset itself.
        self::assertSame($auditRowsBefore, (int) $this->scalar('SELECT COUNT(*) FROM audit_log'));

        // The admin is still signed in and can still work; deleted users' sessions are gone.
        self::assertSame(200, $this->sendAs($admin, 'GET', '/api/v1/admin/users')->statusCode());
        $this->assertError($this->sendAs($alice, 'GET', '/api/v1/workspaces'), 401, 'unauthenticated');
        $this->assertError($this->send('POST', '/api/v1/auth/login', ['username' => 'bob', 'password' => self::DEFAULT_PASSWORD]), 401, 'invalid_credentials');

        // A deleted username can be invited and used again afterwards.
        $this->createUser('alice');
        $this->login('alice');
    }

    public function testResetRefusesWhenNoAdministratorExists(): void
    {
        $alice = $this->signedInUser('alice');
        $this->createNote($alice, $this->personalWorkspaceId($alice));

        try {
            (new DataResetService(TestDatabase::connection(), $this->attachmentStorage()))->resetToAdministratorsOnly();
            self::fail('Reset must refuse to run without an administrator.');
        } catch (RuntimeException) {
            // Expected.
        }

        // Nothing was deleted.
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM users'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM notes'));
    }
}
