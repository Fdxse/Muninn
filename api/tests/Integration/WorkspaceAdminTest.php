<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * System administrators manage members of shared workspaces without an active Owner (D050),
 * without any note access (D025).
 */
final class WorkspaceAdminTest extends WorkspaceTestCase
{
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $admin;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $alice;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->signedInUser('sysadmin', true);
        $this->alice = $this->signedInUser('alice');
        $this->bob = $this->signedInUser('bob');
    }

    public function testListShowsEverySharedWorkspaceWithoutContent(): void
    {
        $orphanedWorkspaceId = $this->createSharedWorkspace($this->alice, 'Orphaned');
        $this->createNote($this->alice, $orphanedWorkspaceId, 'Secret plan', 'Top secret content');
        $this->createSharedWorkspace($this->bob, 'Healthy');
        $this->createNote($this->alice, $this->personalWorkspaceId($this->alice), 'Diary', 'Personal content');
        $this->sendAs($this->admin, 'POST', '/api/v1/admin/users/' . $this->alice['user_id'] . '/disable');

        $listResponse = $this->sendAs($this->admin, 'GET', '/api/v1/admin/workspaces');
        self::assertSame(200, $listResponse->statusCode(), $listResponse->body());

        // Every shared workspace with its Owners and whether one is active; personal ones and notes stay invisible.
        $listedWorkspaces = array_column($listResponse->json()['data']['workspaces'], null, 'name');
        self::assertSame(['Healthy', 'Orphaned'], array_keys($listedWorkspaces));
        self::assertSame(['alice'], $listedWorkspaces['Orphaned']['owners']);
        self::assertSame(0, $listedWorkspaces['Orphaned']['active_owner_count']);
        self::assertSame(1, $listedWorkspaces['Healthy']['active_owner_count']);
        foreach (['Secret plan', 'Top secret', 'Diary', 'Personal'] as $hiddenText) {
            self::assertStringNotContainsString($hiddenText, $listResponse->body());
        }
    }

    public function testWorkspacesWithAnActiveOwnerAreNotManageable(): void
    {
        $teamWorkspaceId = $this->createSharedWorkspace($this->alice, 'Team');
        $membersPath = '/api/v1/admin/workspaces/' . $teamWorkspaceId . '/members';

        // Same answer as for a workspace that does not exist; nothing changes.
        $this->assertError($this->sendAs($this->admin, 'GET', $membersPath), 404, 'not_found');
        $this->assertError($this->sendAs($this->admin, 'POST', $membersPath, ['username' => 'bob', 'role' => 'owner']), 404, 'not_found');
        $this->assertError($this->sendAs($this->admin, 'PATCH', $membersPath . '/' . $this->alice['user_id'], ['role' => 'reader']), 404, 'not_found');
        $this->assertError($this->sendAs($this->admin, 'DELETE', $membersPath . '/' . $this->alice['user_id']), 404, 'not_found');
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM workspace_members WHERE workspace_id = :id', ['id' => $teamWorkspaceId]));
    }

    public function testAdminRescuesWorkspaceWhoseOnlyOwnerIsDisabled(): void
    {
        $teamWorkspaceId = $this->createSharedWorkspace($this->alice, 'Team');
        $this->sendAs($this->admin, 'POST', '/api/v1/admin/users/' . $this->alice['user_id'] . '/disable');
        $membersPath = '/api/v1/admin/workspaces/' . $teamWorkspaceId . '/members';

        $membersResponse = $this->sendAs($this->admin, 'GET', $membersPath);
        self::assertSame(['alice'], array_column($membersResponse->json()['data']['members'], 'username'));

        // Add Bob as Owner: the workspace has an active Owner again, so the admin can no longer change it.
        $addResponse = $this->sendAs($this->admin, 'POST', $membersPath, ['username' => 'bob', 'role' => 'owner']);
        self::assertSame(201, $addResponse->statusCode(), $addResponse->body());
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE details LIKE \'%by_system_admin%\''));
        self::assertSame(1, $this->sendAs($this->admin, 'GET', '/api/v1/admin/workspaces')->json()['data']['workspaces'][0]['active_owner_count']);
        $this->assertError($this->sendAs($this->admin, 'DELETE', $membersPath . '/' . $this->alice['user_id']), 404, 'not_found');

        // Bob now manages it himself, including removing the disabled Owner.
        self::assertSame(204, $this->sendAs($this->bob, 'DELETE', '/api/v1/workspaces/' . $teamWorkspaceId . '/members/' . $this->alice['user_id'])->statusCode());
        $this->createUser('carol');
        self::assertSame(201, $this->addMember($this->bob, $teamWorkspaceId, 'carol', 'reader')->statusCode());
    }

    public function testLastOwnerRuleStillApplies(): void
    {
        $teamWorkspaceId = $this->createSharedWorkspace($this->alice, 'Team');
        $this->sendAs($this->admin, 'POST', '/api/v1/admin/users/' . $this->alice['user_id'] . '/disable');

        // Even a disabled Owner cannot be removed or demoted while they are the only Owner.
        $this->assertError($this->sendAs($this->admin, 'DELETE', '/api/v1/admin/workspaces/' . $teamWorkspaceId . '/members/' . $this->alice['user_id']), 409, 'last_owner');
        $this->assertError($this->sendAs($this->admin, 'PATCH', '/api/v1/admin/workspaces/' . $teamWorkspaceId . '/members/' . $this->alice['user_id'], ['role' => 'reader']), 409, 'last_owner');
    }

    public function testPersonalWorkspacesAreNotManageable(): void
    {
        $personalWorkspaceId = $this->personalWorkspaceId($this->alice);

        $this->assertError($this->sendAs($this->admin, 'GET', '/api/v1/admin/workspaces/' . $personalWorkspaceId . '/members'), 404, 'not_found');
        $this->assertError($this->sendAs($this->admin, 'POST', '/api/v1/admin/workspaces/' . $personalWorkspaceId . '/members', ['username' => 'bob', 'role' => 'reader']), 404, 'not_found');
    }

    public function testAdminStillHasNoNoteAccess(): void
    {
        $teamWorkspaceId = $this->createSharedWorkspace($this->alice, 'Team');
        $note = $this->createNote($this->alice, $teamWorkspaceId);
        $this->sendAs($this->admin, 'POST', '/api/v1/admin/users/' . $this->alice['user_id'] . '/disable');

        // Managing members gives no way into the notes.
        self::assertSame(200, $this->sendAs($this->admin, 'GET', '/api/v1/admin/workspaces/' . $teamWorkspaceId . '/members')->statusCode());
        $this->assertError($this->sendAs($this->admin, 'GET', '/api/v1/workspaces/' . $teamWorkspaceId . '/notes'), 404, 'not_found');
        $this->assertError($this->sendAs($this->admin, 'GET', '/api/v1/notes/' . $note['id']), 404, 'not_found');
        $searchResponse = $this->getAs($this->admin, '/api/v1/search', ['q' => 'Secret']);
        self::assertSame(200, $searchResponse->statusCode(), $searchResponse->body());
        self::assertSame([], $searchResponse->json()['data']['results']);

        // And administrators cannot add themselves (or any administrator) as members.
        $this->assertError($this->sendAs($this->admin, 'POST', '/api/v1/admin/workspaces/' . $teamWorkspaceId . '/members', ['username' => 'sysadmin', 'role' => 'owner']), 422, 'validation_failed');
    }

    public function testEverydayUsersCannotUseAdminWorkspaceEndpoints(): void
    {
        $teamWorkspaceId = $this->createSharedWorkspace($this->alice, 'Team');

        // Even the workspace's own Owner gets 404 from the admin endpoints.
        $this->assertError($this->sendAs($this->alice, 'GET', '/api/v1/admin/workspaces'), 404, 'not_found');
        $this->assertError($this->sendAs($this->alice, 'GET', '/api/v1/admin/workspaces/' . $teamWorkspaceId . '/members'), 404, 'not_found');
        $this->assertError($this->sendAs($this->bob, 'POST', '/api/v1/admin/workspaces/' . $teamWorkspaceId . '/members', ['username' => 'bob', 'role' => 'owner']), 404, 'not_found');
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM workspace_members WHERE workspace_id = :id', ['id' => $teamWorkspaceId]));
    }
}
