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

        // Content in personal and shared workspaces, including a trashed note and memberships.
        $this->createNote($alice, $this->personalWorkspaceId($alice));
        $sharedWorkspaceId = $this->createSharedWorkspace($alice);
        self::assertSame(201, $this->addMember($alice, $sharedWorkspaceId, 'bob', 'editor')->statusCode());
        $trashedNote = $this->createNote($bob, $sharedWorkspaceId);
        self::assertSame(204, $this->sendAs($bob, 'DELETE', '/api/v1/notes/' . $trashedNote['id'])->statusCode());

        // One pending invitation created by the admin.
        self::assertSame(201, $this->sendAs($admin, 'POST', '/api/v1/admin/invitations', ['note' => 'test'])->statusCode());
        $auditRowsBefore = (int) $this->scalar('SELECT COUNT(*) FROM audit_log');

        $resetService = new DataResetService(TestDatabase::connection());
        $countsBefore = $resetService->countRowsToDelete();
        self::assertSame(2, $countsBefore['user accounts (non-admin)']);
        self::assertSame(1, $countsBefore['invitations (all states)']);

        $deletedRowCounts = $resetService->resetToAdministratorsOnly();
        // The preview must match what is actually deleted (the two lists are in different orders).
        ksort($countsBefore);
        ksort($deletedRowCounts);
        self::assertSame($countsBefore, $deletedRowCounts, 'The preview must match what is deleted.');

        // Only the admin remains, and nothing else is left over.
        self::assertSame(['sysadmin'], array_column(TestDatabase::connection()->query('SELECT username FROM users')->fetchAll(), 'username'));
        foreach (['notes', 'workspace_members', 'workspaces', 'invitations', 'auth_attempts'] as $emptiedTable) {
            self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM ' . $emptiedTable), $emptiedTable . ' should be empty');
        }

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
            (new DataResetService(TestDatabase::connection()))->resetToAdministratorsOnly();
            self::fail('Reset must refuse to run without an administrator.');
        } catch (RuntimeException) {
            // Expected.
        }

        // Nothing was deleted.
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM users'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM notes'));
    }
}
