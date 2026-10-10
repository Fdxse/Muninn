<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Admin\DataResetService;
use Muninn\Api\Tests\Support\TestDatabase;
use Muninn\Api\Tests\Support\WorkspaceTestCase;
use Muninn\Api\Workspaces\OpenWorkspaceService;

/**
 * Shared Workspaces users ask to join (D067): the administrator creates them and manages their
 * members, users see names and descriptions and ask to join, and the administrator never gets
 * any note access (D025).
 */
final class OpenWorkspaceTest extends WorkspaceTestCase
{
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $administrator;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $alice;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->administrator = $this->signedInUser('sysadmin', true);
        $this->alice = $this->signedInUser('alice');
        $this->bob = $this->signedInUser('bob');
    }

    /** Creates an open Shared Workspace as the administrator and returns its ID. */
    private function createOpenWorkspace(string $workspaceName = 'General', string $description = 'For everyone'): string
    {
        $createResponse = $this->sendAs($this->administrator, 'POST', '/api/v1/admin/open-workspaces', [
            'name' => $workspaceName,
            'description' => $description,
        ]);
        self::assertSame(201, $createResponse->statusCode(), $createResponse->body());

        return $createResponse->json()['data']['open_workspace']['id'];
    }

    /** Asks to join as the given user and returns the response. */
    private function askToJoin(array $userCredentials, string $workspaceId, string $note = 'I would like to join')
    {
        return $this->sendAs($userCredentials, 'POST', '/api/v1/open-workspaces/' . $workspaceId . '/join-request', ['note' => $note]);
    }

    /** The ID of the only pending join request, as the administrator sees it. */
    private function onlyPendingRequestId(): string
    {
        $pendingRequests = $this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/workspace-join-requests'))['join_requests'];
        self::assertCount(1, $pendingRequests);

        return $pendingRequests[0]['id'];
    }

    /** The open workspace as the given user sees it in their list. */
    private function openWorkspaceAs(array $userCredentials, string $workspaceId): array
    {
        $openWorkspaces = $this->assertOkData($this->getAs($userCredentials, '/api/v1/open-workspaces'))['open_workspaces'];

        return array_column($openWorkspaces, null, 'id')[$workspaceId];
    }

    public function testUsersSeeOpenWorkspacesButNotTheirContent(): void
    {
        $generalWorkspaceId = $this->createOpenWorkspace('General', 'Everyone is welcome');
        // Ordinary shared workspaces of other users are never listed.
        $this->createSharedWorkspace($this->bob, 'Bob private team');

        $openWorkspaces = $this->assertOkData($this->getAs($this->alice, '/api/v1/open-workspaces'))['open_workspaces'];
        self::assertCount(1, $openWorkspaces);
        self::assertSame('General', $openWorkspaces[0]['name']);
        self::assertSame('Everyone is welcome', $openWorkspaces[0]['description']);
        self::assertFalse($openWorkspaces[0]['is_member']);
        self::assertNull($openWorkspaces[0]['join_request']);

        // Not being a member, Alice gets the same 404 as for any other workspace.
        $this->assertError($this->getAs($this->alice, '/api/v1/workspaces/' . $generalWorkspaceId), 404, 'not_found');
        $this->assertError($this->getAs($this->alice, '/api/v1/workspaces/' . $generalWorkspaceId . '/notes'), 404, 'not_found');
        $this->assertError($this->getAs($this->alice, '/api/v1/workspaces/' . $generalWorkspaceId . '/members'), 404, 'not_found');
    }

    public function testJoinRequestApprovedMakesTheUserAnEditor(): void
    {
        $generalWorkspaceId = $this->createOpenWorkspace();

        $askResponse = $this->askToJoin($this->alice, $generalWorkspaceId, 'I work here');
        self::assertSame(201, $askResponse->statusCode(), $askResponse->body());
        self::assertSame('pending', $askResponse->json()['data']['open_workspace']['join_request']['status']);

        // The administrator sees who asked, for which workspace, with the note.
        $pendingRequests = $this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/workspace-join-requests'))['join_requests'];
        self::assertSame('alice', $pendingRequests[0]['user']['username']);
        self::assertSame('General', $pendingRequests[0]['workspace']['name']);
        self::assertSame('I work here', $pendingRequests[0]['note']);
        self::assertSame(1, $this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/workspace-join-requests/pending-count'))['pending_count']);

        // Approving without a role gives Editor (the default chosen for D067).
        $approveResponse = $this->sendAs($this->administrator, 'POST', '/api/v1/admin/workspace-join-requests/' . $pendingRequests[0]['id'] . '/approve');
        self::assertSame(204, $approveResponse->statusCode(), $approveResponse->body());
        self::assertSame(0, $this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/workspace-join-requests/pending-count'))['pending_count']);

        // Alice is now an Editor: she sees the workspace, can write notes, and cannot manage it.
        $aliceView = $this->openWorkspaceAs($this->alice, $generalWorkspaceId);
        self::assertTrue($aliceView['is_member']);
        self::assertSame('editor', $aliceView['your_role']);
        $workspaceData = $this->assertOkData($this->getAs($this->alice, '/api/v1/workspaces/' . $generalWorkspaceId))['workspace'];
        self::assertSame('open', $workspaceData['kind']);
        self::assertSame('For everyone', $workspaceData['description']);
        self::assertFalse($workspaceData['permissions']['manage_members']);
        self::assertFalse($workspaceData['permissions']['manage_workspace']);
        $this->createNote($this->alice, $generalWorkspaceId, 'Hello', 'First note');
        $this->assertError($this->sendAs($this->alice, 'PATCH', '/api/v1/workspaces/' . $generalWorkspaceId, ['name' => 'Mine now']), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->alice, 'DELETE', '/api/v1/workspaces/' . $generalWorkspaceId), 403, 'insufficient_role');
        $this->assertError($this->addMember($this->alice, $generalWorkspaceId, 'bob', 'reader'), 403, 'insufficient_role');

        // The approval is audited.
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE event_type = \'workspace_join_request.approved\''));
    }

    public function testApprovingAsReaderAndRefusingStrongerRoles(): void
    {
        $generalWorkspaceId = $this->createOpenWorkspace();
        $this->askToJoin($this->alice, $generalWorkspaceId);
        $requestId = $this->onlyPendingRequestId();

        // Owner and Admin do not exist in an open workspace.
        foreach (['owner', 'admin', 'boss'] as $refusedRole) {
            $this->assertError($this->sendAs($this->administrator, 'POST', '/api/v1/admin/workspace-join-requests/' . $requestId . '/approve', ['role' => $refusedRole]), 422, 'validation_failed');
        }

        self::assertSame(204, $this->sendAs($this->administrator, 'POST', '/api/v1/admin/workspace-join-requests/' . $requestId . '/approve', ['role' => 'reader'])->statusCode());
        self::assertSame('reader', $this->openWorkspaceAs($this->alice, $generalWorkspaceId)['your_role']);
        // A Reader cannot write.
        $writeResponse = $this->sendAs($this->alice, 'POST', '/api/v1/workspaces/' . $generalWorkspaceId . '/notes', ['title' => 'Nope', 'content' => '']);
        $this->assertError($writeResponse, 403, 'insufficient_role');

        // A request can only be decided once.
        $this->assertError($this->sendAs($this->administrator, 'POST', '/api/v1/admin/workspace-join-requests/' . $requestId . '/decline'), 404, 'request_not_pending');
    }

    public function testDeclineCancelAndAskingAgain(): void
    {
        $generalWorkspaceId = $this->createOpenWorkspace();

        // One pending request at a time.
        self::assertSame(201, $this->askToJoin($this->alice, $generalWorkspaceId)->statusCode());
        $this->assertError($this->askToJoin($this->alice, $generalWorkspaceId), 409, 'request_pending');

        // Cancelling removes it from the administrator's list.
        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/open-workspaces/' . $generalWorkspaceId . '/join-request')->statusCode());
        self::assertSame([], $this->assertOkData($this->getAs($this->administrator, '/api/v1/admin/workspace-join-requests'))['join_requests']);
        $this->assertError($this->sendAs($this->alice, 'DELETE', '/api/v1/open-workspaces/' . $generalWorkspaceId . '/join-request'), 404, 'not_found');

        // Declined: Alice sees it, is not a member, and may ask again.
        $this->askToJoin($this->alice, $generalWorkspaceId);
        self::assertSame(204, $this->sendAs($this->administrator, 'POST', '/api/v1/admin/workspace-join-requests/' . $this->onlyPendingRequestId() . '/decline')->statusCode());
        $aliceView = $this->openWorkspaceAs($this->alice, $generalWorkspaceId);
        self::assertFalse($aliceView['is_member']);
        self::assertSame('declined', $aliceView['join_request']['status']);
        self::assertSame(201, $this->askToJoin($this->alice, $generalWorkspaceId)->statusCode());
    }

    public function testJoinRequestsAreLimitedPerDay(): void
    {
        $generalWorkspaceId = $this->createOpenWorkspace();
        for ($requestNumber = 1; $requestNumber <= OpenWorkspaceService::MAX_JOIN_REQUESTS_PER_DAY; $requestNumber++) {
            self::assertSame(201, $this->askToJoin($this->alice, $generalWorkspaceId)->statusCode(), 'request ' . $requestNumber);
            self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/open-workspaces/' . $generalWorkspaceId . '/join-request')->statusCode());
        }

        $this->assertError($this->askToJoin($this->alice, $generalWorkspaceId), 429, 'rate_limited');
    }

    public function testMembersCannotAskAndNonOpenWorkspacesCannotBeJoined(): void
    {
        $generalWorkspaceId = $this->createOpenWorkspace();
        $this->askToJoin($this->alice, $generalWorkspaceId);
        $this->sendAs($this->administrator, 'POST', '/api/v1/admin/workspace-join-requests/' . $this->onlyPendingRequestId() . '/approve');
        $this->assertError($this->askToJoin($this->alice, $generalWorkspaceId), 409, 'already_member');

        // Bob's ordinary shared workspace and Bob's personal workspace look like they do not exist.
        $bobTeamId = $this->createSharedWorkspace($this->bob, 'Bob team');
        $this->assertError($this->askToJoin($this->alice, $bobTeamId), 404, 'not_found');
        $this->assertError($this->askToJoin($this->alice, $this->personalWorkspaceId($this->bob)), 404, 'not_found');
        $this->assertError($this->askToJoin($this->alice, 'not-a-uuid'), 404, 'not_found');
    }

    public function testAdministratorManagesMembersButNeverSeesNotes(): void
    {
        $generalWorkspaceId = $this->createOpenWorkspace();
        $membersPath = '/api/v1/admin/workspaces/' . $generalWorkspaceId . '/members';

        // Adding directly by username, Editor or Reader only.
        $this->assertError($this->sendAs($this->administrator, 'POST', $membersPath, ['username' => 'alice', 'role' => 'owner']), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->administrator, 'POST', $membersPath, ['username' => 'alice', 'role' => 'admin']), 422, 'validation_failed');
        self::assertSame(201, $this->sendAs($this->administrator, 'POST', $membersPath, ['username' => 'alice', 'role' => 'editor'])->statusCode());
        self::assertSame(201, $this->sendAs($this->administrator, 'POST', $membersPath, ['username' => 'bob', 'role' => 'reader'])->statusCode());
        // Administrator accounts are never members (D044).
        $this->assertError($this->sendAs($this->administrator, 'POST', $membersPath, ['username' => 'sysadmin', 'role' => 'reader']), 422, 'validation_failed');

        // Changing roles, again never to Owner or Admin.
        $this->assertError($this->sendAs($this->administrator, 'PATCH', $membersPath . '/' . $this->bob['user_id'], ['role' => 'owner']), 422, 'validation_failed');
        self::assertSame(200, $this->sendAs($this->administrator, 'PATCH', $membersPath . '/' . $this->bob['user_id'], ['role' => 'editor'])->statusCode());

        // Alice writes a note; nothing the administrator can reach shows it.
        $secretNote = $this->createNote($this->alice, $generalWorkspaceId, 'Secret plan', 'Top secret content');
        foreach ([
            '/api/v1/admin/open-workspaces',
            $membersPath,
            '/api/v1/admin/workspace-join-requests',
        ] as $adminPath) {
            $adminResponse = $this->getAs($this->administrator, $adminPath);
            self::assertSame(200, $adminResponse->statusCode(), $adminPath);
            self::assertStringNotContainsString('Secret plan', $adminResponse->body());
            self::assertStringNotContainsString('Top secret', $adminResponse->body());
        }
        self::assertNotSame(200, $this->getAs($this->administrator, '/api/v1/workspaces/' . $generalWorkspaceId)->statusCode());
        self::assertNotSame(200, $this->getAs($this->administrator, '/api/v1/workspaces/' . $generalWorkspaceId . '/notes')->statusCode());
        self::assertNotSame(200, $this->getAs($this->administrator, '/api/v1/notes/' . $secretNote['id'])->statusCode());

        // Removing a member, and members leaving on their own.
        self::assertSame(204, $this->sendAs($this->administrator, 'DELETE', $membersPath . '/' . $this->bob['user_id'])->statusCode());
        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/workspaces/' . $generalWorkspaceId . '/members/' . $this->alice['user_id'])->statusCode());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM workspace_members WHERE workspace_id = :id', ['id' => $generalWorkspaceId]));
    }

    public function testRenameDescribeAndDeleteOnlyWhenEmpty(): void
    {
        $generalWorkspaceId = $this->createOpenWorkspace();
        $workspacePath = '/api/v1/admin/open-workspaces/' . $generalWorkspaceId;

        $updateResponse = $this->sendAs($this->administrator, 'PATCH', $workspacePath, ['name' => 'Public', 'description' => '']);
        self::assertSame(200, $updateResponse->statusCode(), $updateResponse->body());
        self::assertSame('Public', $updateResponse->json()['data']['open_workspace']['name']);
        self::assertNull($updateResponse->json()['data']['open_workspace']['description']);
        $this->assertError($this->sendAs($this->administrator, 'PATCH', $workspacePath, ['name' => '']), 422, 'validation_failed');

        // A workspace with notes cannot be deleted; once they are gone for good, it can.
        $this->sendAs($this->administrator, 'POST', '/api/v1/admin/workspaces/' . $generalWorkspaceId . '/members', ['username' => 'alice', 'role' => 'editor']);
        $this->createNote($this->alice, $generalWorkspaceId);
        $this->assertError($this->sendAs($this->administrator, 'DELETE', $workspacePath), 409, 'workspace_not_empty');
        TestDatabase::connection()->exec('DELETE FROM notes');
        self::assertSame(204, $this->sendAs($this->administrator, 'DELETE', $workspacePath)->statusCode());
        self::assertSame([], $this->assertOkData($this->getAs($this->alice, '/api/v1/open-workspaces'))['open_workspaces']);

        // Ordinary shared workspaces can never be changed through these endpoints.
        $bobTeamId = $this->createSharedWorkspace($this->bob, 'Bob team');
        $this->assertError($this->sendAs($this->administrator, 'PATCH', '/api/v1/admin/open-workspaces/' . $bobTeamId, ['name' => 'Taken']), 404, 'not_found');
        $this->assertError($this->sendAs($this->administrator, 'DELETE', '/api/v1/admin/open-workspaces/' . $bobTeamId), 404, 'not_found');
    }

    public function testOnlyTheRightAccountsReachEachSide(): void
    {
        $generalWorkspaceId = $this->createOpenWorkspace();

        // Everyday users cannot use the administrator endpoints.
        self::assertNotSame(200, $this->getAs($this->alice, '/api/v1/admin/open-workspaces')->statusCode());
        self::assertNotSame(201, $this->sendAs($this->alice, 'POST', '/api/v1/admin/open-workspaces', ['name' => 'Mine'])->statusCode());
        self::assertNotSame(200, $this->getAs($this->alice, '/api/v1/admin/workspace-join-requests')->statusCode());
        self::assertNotSame(201, $this->sendAs($this->alice, 'POST', '/api/v1/admin/workspaces/' . $generalWorkspaceId . '/members', ['username' => 'alice', 'role' => 'editor'])->statusCode());

        // Administrator accounts cannot ask to join.
        $this->assertError($this->getAs($this->administrator, '/api/v1/open-workspaces'), 403, 'admin_account');
        $this->assertError($this->askToJoin($this->administrator, $generalWorkspaceId), 403, 'admin_account');
    }

    public function testJoinRequestsRaiseTheAdminAttentionSignal(): void
    {
        $generalWorkspaceId = $this->createOpenWorkspace();
        $this->sendAs($this->administrator, 'PATCH', '/api/v1/admin/attention-recipient', ['user_id' => $this->bob['user_id']]);
        self::assertFalse($this->assertOkData($this->getAs($this->bob, '/api/v1/admin-attention'))['needs_attention']);

        $this->askToJoin($this->alice, $generalWorkspaceId);
        self::assertTrue($this->assertOkData($this->getAs($this->bob, '/api/v1/admin-attention'))['needs_attention']);
    }

    public function testDataResetRemovesJoinRequests(): void
    {
        $generalWorkspaceId = $this->createOpenWorkspace();
        $this->askToJoin($this->alice, $generalWorkspaceId);

        (new DataResetService(TestDatabase::connection(), $this->attachmentStorage()))->resetToAdministratorsOnly();

        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM workspace_join_requests'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM workspaces'));
    }
}
