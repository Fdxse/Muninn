<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * Regression tests for the role matrix (D029) as enforced by the API: Reader, Editor, Admin
 * and Owner each get exactly their rights in a shared workspace, and never more.
 */
final class WorkspaceRolesTest extends WorkspaceTestCase
{
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $owner;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $admin;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $editor;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $reader;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $outsider;

    private string $workspaceId;
    private string $workspacePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->signedInUser('olivia');
        $this->admin = $this->signedInUser('adam');
        $this->editor = $this->signedInUser('edith');
        $this->reader = $this->signedInUser('rita');
        $this->outsider = $this->signedInUser('oscar');

        $this->workspaceId = $this->createSharedWorkspace($this->owner, 'Project X');
        $this->workspacePath = '/api/v1/workspaces/' . $this->workspaceId;
        self::assertSame(201, $this->addMember($this->owner, $this->workspaceId, 'adam', 'admin')->statusCode());
        self::assertSame(201, $this->addMember($this->owner, $this->workspaceId, 'edith', 'editor')->statusCode());
        self::assertSame(201, $this->addMember($this->owner, $this->workspaceId, 'rita', 'reader')->statusCode());
    }

    public function testCreatorBecomesOwnerAndMembersSeeTheirOwnRole(): void
    {
        $expectedRoles = ['owner' => $this->owner, 'admin' => $this->admin, 'editor' => $this->editor, 'reader' => $this->reader];
        foreach ($expectedRoles as $expectedRole => $memberCredentials) {
            $workspace = $this->sendAs($memberCredentials, 'GET', $this->workspacePath)->json()['data']['workspace'];
            self::assertSame($expectedRole, $workspace['your_role']);
            self::assertSame('shared', $workspace['kind']);
        }

        $memberList = $this->sendAs($this->reader, 'GET', $this->workspacePath . '/members')->json()['data']['members'];
        self::assertSame(['owner', 'admin', 'editor', 'reader'], array_column($memberList, 'role'));
    }

    public function testReaderCanReadButNotWrite(): void
    {
        $note = $this->createNote($this->editor, $this->workspaceId);

        self::assertSame(200, $this->sendAs($this->reader, 'GET', '/api/v1/notes/' . $note['id'])->statusCode());
        self::assertCount(1, $this->sendAs($this->reader, 'GET', $this->workspacePath . '/notes')->json()['data']['notes']);

        $this->assertError($this->sendAs($this->reader, 'POST', $this->workspacePath . '/notes', ['title' => 'mine']), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->reader, 'PATCH', '/api/v1/notes/' . $note['id'], ['revision' => 1, 'content' => 'changed']), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->reader, 'DELETE', '/api/v1/notes/' . $note['id']), 403, 'insufficient_role');

        self::assertSame('Top secret content', $this->scalar('SELECT content FROM notes WHERE id = :id', ['id' => $note['id']]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM notes WHERE workspace_id = :id AND trashed_at IS NULL', ['id' => $this->workspaceId]));
    }

    public function testEditorCanCreateEditAndTrashNotes(): void
    {
        $note = $this->createNote($this->editor, $this->workspaceId, 'Draft', 'First text');

        $updateResponse = $this->sendAs($this->editor, 'PATCH', '/api/v1/notes/' . $note['id'], ['revision' => 1, 'content' => 'Second text']);
        self::assertSame(200, $updateResponse->statusCode(), $updateResponse->body());
        self::assertSame('Second text', $updateResponse->json()['data']['note']['content']);
        self::assertSame('Draft', $updateResponse->json()['data']['note']['title'], 'Fields left out are unchanged.');
        self::assertSame(2, $updateResponse->json()['data']['note']['revision']);

        // Editors may edit notes written by others too.
        $ownersNote = $this->createNote($this->owner, $this->workspaceId);
        self::assertSame(200, $this->sendAs($this->editor, 'PATCH', '/api/v1/notes/' . $ownersNote['id'], ['revision' => 1, 'title' => 'Edited'])->statusCode());
        self::assertSame(204, $this->sendAs($this->editor, 'DELETE', '/api/v1/notes/' . $ownersNote['id'])->statusCode());
    }

    public function testEditorCannotManageMembersOrTheWorkspace(): void
    {
        $this->assertError($this->addMember($this->editor, $this->workspaceId, 'oscar', 'reader'), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->editor, 'PATCH', $this->workspacePath . '/members/' . $this->reader['user_id'], ['role' => 'editor']), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->editor, 'DELETE', $this->workspacePath . '/members/' . $this->reader['user_id']), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->editor, 'PATCH', $this->workspacePath, ['name' => 'Mine now']), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->editor, 'DELETE', $this->workspacePath), 403, 'insufficient_role');
    }

    public function testAdminManagesEditorsAndReadersOnly(): void
    {
        // Allowed: add a reader, promote them to editor, remove them.
        self::assertSame(201, $this->addMember($this->admin, $this->workspaceId, 'oscar', 'reader')->statusCode());
        self::assertSame(200, $this->sendAs($this->admin, 'PATCH', $this->workspacePath . '/members/' . $this->outsider['user_id'], ['role' => 'editor'])->statusCode());
        self::assertSame(204, $this->sendAs($this->admin, 'DELETE', $this->workspacePath . '/members/' . $this->outsider['user_id'])->statusCode());

        // Refused: handing out Admin or Owner, touching Owners or other Admins, managing the workspace.
        $this->assertError($this->addMember($this->admin, $this->workspaceId, 'oscar', 'admin'), 403, 'insufficient_role');
        $this->assertError($this->addMember($this->admin, $this->workspaceId, 'oscar', 'owner'), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->admin, 'PATCH', $this->workspacePath . '/members/' . $this->editor['user_id'], ['role' => 'owner']), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->admin, 'PATCH', $this->workspacePath . '/members/' . $this->owner['user_id'], ['role' => 'reader']), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->admin, 'DELETE', $this->workspacePath . '/members/' . $this->owner['user_id']), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->admin, 'PATCH', $this->workspacePath . '/members/' . $this->admin['user_id'], ['role' => 'owner']), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->admin, 'PATCH', $this->workspacePath, ['name' => 'Renamed']), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->admin, 'DELETE', $this->workspacePath), 403, 'insufficient_role');

        self::assertSame('owner', $this->scalar('SELECT role FROM workspace_members WHERE user_id = :id', ['id' => $this->owner['user_id']]));
    }

    public function testOwnerManagesEveryoneAndTheWorkspace(): void
    {
        self::assertSame(200, $this->sendAs($this->owner, 'PATCH', $this->workspacePath . '/members/' . $this->admin['user_id'], ['role' => 'owner'])->statusCode());
        self::assertSame(204, $this->sendAs($this->owner, 'DELETE', $this->workspacePath . '/members/' . $this->editor['user_id'])->statusCode());

        $renameResponse = $this->sendAs($this->owner, 'PATCH', $this->workspacePath, ['name' => 'Project Y']);
        self::assertSame(200, $renameResponse->statusCode(), $renameResponse->body());
        self::assertSame('Project Y', $renameResponse->json()['data']['workspace']['name']);
    }

    public function testWorkspaceAlwaysKeepsOneOwner(): void
    {
        $ownerMemberPath = $this->workspacePath . '/members/' . $this->owner['user_id'];

        $this->assertError($this->sendAs($this->owner, 'PATCH', $ownerMemberPath, ['role' => 'admin']), 409, 'last_owner');
        $this->assertError($this->sendAs($this->owner, 'DELETE', $ownerMemberPath), 409, 'last_owner');

        // With a second Owner, the first may step down or leave.
        self::assertSame(200, $this->sendAs($this->owner, 'PATCH', $this->workspacePath . '/members/' . $this->admin['user_id'], ['role' => 'owner'])->statusCode());
        self::assertSame(204, $this->sendAs($this->owner, 'DELETE', $ownerMemberPath)->statusCode());
        $this->assertError($this->sendAs($this->admin, 'DELETE', $this->workspacePath . '/members/' . $this->admin['user_id']), 409, 'last_owner');
    }

    public function testAnyMemberMayLeave(): void
    {
        self::assertSame(204, $this->sendAs($this->reader, 'DELETE', $this->workspacePath . '/members/' . $this->reader['user_id'])->statusCode());
        $this->assertError($this->sendAs($this->reader, 'GET', $this->workspacePath), 404, 'not_found');
    }

    public function testAddingMembersValidatesTheUserAndRole(): void
    {
        $this->assertError($this->addMember($this->owner, $this->workspaceId, 'nobody', 'reader'), 422, 'validation_failed');
        $this->assertError($this->addMember($this->owner, $this->workspaceId, 'oscar', 'superuser'), 422, 'validation_failed');
        $this->assertError($this->addMember($this->owner, $this->workspaceId, 'rita', 'editor'), 409, 'already_member');

        // Disabled accounts get the same answer as unknown ones.
        $this->database->prepare('UPDATE users SET status = \'disabled\' WHERE id = :id')->execute(['id' => $this->outsider['user_id']]);
        $unknownUserResponse = $this->addMember($this->owner, $this->workspaceId, 'nobody', 'reader');
        $disabledUserResponse = $this->addMember($this->owner, $this->workspaceId, 'oscar', 'reader');
        self::assertSame($unknownUserResponse->body(), $disabledUserResponse->body());
    }

    public function testChangingAnUnknownMemberGives404(): void
    {
        $this->assertError($this->sendAs($this->owner, 'PATCH', $this->workspacePath . '/members/' . $this->outsider['user_id'], ['role' => 'reader']), 404, 'not_found');
        $this->assertError($this->sendAs($this->owner, 'DELETE', $this->workspacePath . '/members/not-a-uuid'), 404, 'not_found');
    }

    public function testPersonalWorkspaceCannotBeSharedOrDeleted(): void
    {
        $personalWorkspaceId = $this->personalWorkspaceId($this->owner);

        $this->assertError($this->addMember($this->owner, $personalWorkspaceId, 'oscar', 'reader'), 409, 'personal_workspace');
        $this->assertError($this->sendAs($this->owner, 'DELETE', '/api/v1/workspaces/' . $personalWorkspaceId), 409, 'personal_workspace');
    }

    public function testOnlyEmptySharedWorkspacesCanBeDeleted(): void
    {
        $note = $this->createNote($this->owner, $this->workspaceId);
        $this->assertError($this->sendAs($this->owner, 'DELETE', $this->workspacePath), 409, 'workspace_not_empty');

        // A note in Trash still blocks deletion: deleting a workspace never deletes notes.
        $this->sendAs($this->owner, 'DELETE', '/api/v1/notes/' . $note['id']);
        $this->assertError($this->sendAs($this->owner, 'DELETE', $this->workspacePath), 409, 'workspace_not_empty');

        $emptyWorkspaceId = $this->createSharedWorkspace($this->owner, 'Empty');
        self::assertSame(204, $this->sendAs($this->owner, 'DELETE', '/api/v1/workspaces/' . $emptyWorkspaceId)->statusCode());
        self::assertFalse($this->scalar('SELECT id FROM workspaces WHERE id = :id', ['id' => $emptyWorkspaceId]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM workspace_members WHERE workspace_id = :id', ['id' => $emptyWorkspaceId]));
    }

    public function testMembershipChangesAreAudited(): void
    {
        $this->addMember($this->owner, $this->workspaceId, 'oscar', 'reader');
        $this->sendAs($this->owner, 'PATCH', $this->workspacePath . '/members/' . $this->outsider['user_id'], ['role' => 'editor']);
        $this->sendAs($this->owner, 'DELETE', $this->workspacePath . '/members/' . $this->outsider['user_id']);

        foreach (['workspace.created', 'workspace.member_added', 'workspace.member_role_changed', 'workspace.member_removed'] as $expectedEvent) {
            self::assertGreaterThan(0, (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE event_type = :event', ['event' => $expectedEvent]), $expectedEvent);
        }
    }
}
