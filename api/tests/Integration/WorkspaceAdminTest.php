<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * System administrators manage shared workspace members (D050) without any note access (D025).
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

    public function testListShowsSharedWorkspacesWithoutContent(): void
    {
        $teamWorkspaceId = $this->createSharedWorkspace($this->alice, 'Team');
        $this->createNote($this->alice, $teamWorkspaceId, 'Secret plan', 'Top secret content');
        $this->createNote($this->alice, $this->personalWorkspaceId($this->alice), 'Diary', 'Personal content');

        $listResponse = $this->sendAs($this->admin, 'GET', '/api/v1/admin/workspaces');
        self::assertSame(200, $listResponse->statusCode(), $listResponse->body());

        // Only the shared workspace, with its Owners; personal workspaces and notes stay invisible.
        $listedWorkspaces = $listResponse->json()['data']['workspaces'];
        self::assertSame(['Team'], array_column($listedWorkspaces, 'name'));
        self::assertSame(['alice'], $listedWorkspaces[0]['owners']);
        self::assertSame(1, $listedWorkspaces[0]['active_owner_count']);
        foreach (['Secret plan', 'Top secret', 'Diary', 'Personal'] as $hiddenText) {
            self::assertStringNotContainsString($hiddenText, $listResponse->body());
        }
    }

    public function testAdminRescuesWorkspaceWhoseOnlyOwnerIsDisabled(): void
    {
        $teamWorkspaceId = $this->createSharedWorkspace($this->alice, 'Team');
        $this->sendAs($this->admin, 'POST', '/api/v1/admin/users/' . $this->alice['user_id'] . '/disable');

        $listedWorkspaces = $this->sendAs($this->admin, 'GET', '/api/v1/admin/workspaces')->json()['data']['workspaces'];
        self::assertSame(0, $listedWorkspaces[0]['active_owner_count']);

        // Add Bob as Owner, then remove the disabled Owner.
        $addResponse = $this->sendAs($this->admin, 'POST', '/api/v1/admin/workspaces/' . $teamWorkspaceId . '/members', ['username' => 'bob', 'role' => 'owner']);
        self::assertSame(201, $addResponse->statusCode(), $addResponse->body());
        self::assertSame(204, $this->sendAs($this->admin, 'DELETE', '/api/v1/admin/workspaces/' . $teamWorkspaceId . '/members/' . $this->alice['user_id'])->statusCode());

        $membersResponse = $this->sendAs($this->admin, 'GET', '/api/v1/admin/workspaces/' . $teamWorkspaceId . '/members');
        self::assertSame(['bob'], array_column($membersResponse->json()['data']['members'], 'username'));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE details LIKE \'%by_system_admin%\''));

        // Bob now manages it himself.
        $this->createUser('carol');
        self::assertSame(201, $this->addMember($this->bob, $teamWorkspaceId, 'carol', 'reader')->statusCode());
    }

    public function testLastOwnerRuleStillApplies(): void
    {
        $teamWorkspaceId = $this->createSharedWorkspace($this->alice, 'Team');

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

        // Managing members gives no way into the notes.
        $this->sendAs($this->admin, 'GET', '/api/v1/admin/workspaces/' . $teamWorkspaceId . '/members');
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
