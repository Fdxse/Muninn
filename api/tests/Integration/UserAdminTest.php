<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * Account administration (D031): system admins list, disable and re-enable accounts.
 */
final class UserAdminTest extends WorkspaceTestCase
{
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $admin;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->signedInUser('sysadmin', true);
        $this->alice = $this->signedInUser('alice');
    }

    public function testAdminListsUsersWithoutSecrets(): void
    {
        $listResponse = $this->sendAs($this->admin, 'GET', '/api/v1/admin/users');

        self::assertSame(200, $listResponse->statusCode(), $listResponse->body());
        self::assertEqualsCanonicalizing(['sysadmin', 'alice'], array_column($listResponse->json()['data']['users'], 'username'));
        self::assertStringNotContainsString('password', $listResponse->body());
        self::assertStringNotContainsString('$argon2', $listResponse->body());
        self::assertStringNotContainsString('$2y$', $listResponse->body());
    }

    public function testDisablingEndsSessionsAndBlocksSignInUntilEnabled(): void
    {
        $note = $this->createNote($this->alice, $this->personalWorkspaceId($this->alice));

        self::assertSame(204, $this->sendAs($this->admin, 'POST', '/api/v1/admin/users/' . $this->alice['user_id'] . '/disable')->statusCode());

        // The open session stops working at once, and signing in again fails.
        $this->assertError($this->sendAs($this->alice, 'GET', '/api/v1/notes/' . $note['id']), 401, 'unauthenticated');
        $this->assertError($this->send('POST', '/api/v1/auth/login', ['username' => 'alice', 'password' => self::DEFAULT_PASSWORD]), 401, 'invalid_credentials');
        // Disabled, not deleted: the notes are still there.
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM notes WHERE id = :id', ['id' => $note['id']]));

        self::assertSame(204, $this->sendAs($this->admin, 'POST', '/api/v1/admin/users/' . $this->alice['user_id'] . '/enable')->statusCode());
        $this->login('alice');

        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE event_type = \'user.disabled\''));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE event_type = \'user.enabled\''));
    }

    public function testAdminCannotDisableTheirOwnAccount(): void
    {
        $this->assertError($this->sendAs($this->admin, 'POST', '/api/v1/admin/users/' . $this->admin['user_id'] . '/disable'), 409, 'cannot_disable_self');
    }

    public function testUnknownUserGives404(): void
    {
        $this->assertError($this->sendAs($this->admin, 'POST', '/api/v1/admin/users/00000000-0000-4000-8000-000000000000/disable'), 404, 'not_found');
        $this->assertError($this->sendAs($this->admin, 'POST', '/api/v1/admin/users/not-a-uuid/disable'), 404, 'not_found');
    }

    public function testNonAdminsGet404OnUserAdministration(): void
    {
        $this->assertError($this->sendAs($this->alice, 'GET', '/api/v1/admin/users'), 404, 'not_found');
        $this->assertError($this->sendAs($this->alice, 'POST', '/api/v1/admin/users/' . $this->admin['user_id'] . '/disable'), 404, 'not_found');
        self::assertSame('active', $this->scalar('SELECT status FROM users WHERE id = :id', ['id' => $this->admin['user_id']]));
    }
}
