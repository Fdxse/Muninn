<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Http\Response;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * Regression tests for the core data-isolation promise (CLAUDE.md, SECURITY.md):
 * a user never receives anything from a workspace or note they cannot access, and an
 * inaccessible resource looks exactly like one that does not exist.
 */
final class DataIsolationTest extends WorkspaceTestCase
{
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $alice;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->signedInUser('alice');
        $this->bob = $this->signedInUser('bob');
    }

    public function testEveryUserGetsExactlyOnePersonalWorkspaceAsOwner(): void
    {
        $firstListing = $this->sendAs($this->alice, 'GET', '/api/v1/workspaces')->json()['data']['workspaces'];
        $secondListing = $this->sendAs($this->alice, 'GET', '/api/v1/workspaces')->json()['data']['workspaces'];

        self::assertCount(1, $firstListing);
        self::assertSame($firstListing, $secondListing, 'Listing again must not create another workspace.');
        self::assertSame('personal', $firstListing[0]['kind']);
        self::assertSame('owner', $firstListing[0]['your_role']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM workspaces WHERE personal_owner_user_id = :id', ['id' => $this->alice['user_id']]));
    }

    public function testPersonalWorkspacesAreSeparate(): void
    {
        self::assertNotSame($this->personalWorkspaceId($this->alice), $this->personalWorkspaceId($this->bob));
    }

    public function testUserCannotReadUpdateOrTrashAnotherUsersNote(): void
    {
        $bobsNote = $this->createNote($this->bob, $this->personalWorkspaceId($this->bob));
        $notePath = '/api/v1/notes/' . $bobsNote['id'];

        $this->assertLooksNonexistent($this->sendAs($this->alice, 'GET', $notePath));
        $this->assertLooksNonexistent($this->sendAs($this->alice, 'PATCH', $notePath, ['revision' => 1, 'content' => 'hijacked']));
        $this->assertLooksNonexistent($this->sendAs($this->alice, 'DELETE', $notePath));

        // Bob's note is untouched.
        $bobsView = $this->sendAs($this->bob, 'GET', $notePath)->json()['data']['note'];
        self::assertSame('Top secret content', $bobsView['content']);
        self::assertSame(1, $bobsView['revision']);
    }

    public function testUserCannotReachAnotherUsersWorkspaceInAnyWay(): void
    {
        $bobsWorkspaceId = $this->personalWorkspaceId($this->bob);
        $this->createNote($this->bob, $bobsWorkspaceId);
        $workspacePath = '/api/v1/workspaces/' . $bobsWorkspaceId;

        $this->assertLooksNonexistent($this->sendAs($this->alice, 'GET', $workspacePath));
        $this->assertLooksNonexistent($this->sendAs($this->alice, 'GET', $workspacePath . '/notes'));
        $this->assertLooksNonexistent($this->sendAs($this->alice, 'GET', $workspacePath . '/members'));
        $this->assertLooksNonexistent($this->sendAs($this->alice, 'POST', $workspacePath . '/notes', ['title' => 'planted']));
        $this->assertLooksNonexistent($this->sendAs($this->alice, 'PATCH', $workspacePath, ['name' => 'renamed']));
        $this->assertLooksNonexistent($this->sendAs($this->alice, 'DELETE', $workspacePath));
        $this->assertLooksNonexistent($this->addMember($this->alice, $bobsWorkspaceId, 'alice', 'owner'));
        $this->assertLooksNonexistent($this->sendAs($this->alice, 'DELETE', $workspacePath . '/members/' . $this->bob['user_id']));

        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM notes WHERE workspace_id = :id', ['id' => $bobsWorkspaceId]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM workspace_members WHERE workspace_id = :id', ['id' => $bobsWorkspaceId]));
    }

    public function testListingsNeverIncludeOtherUsersWorkspacesOrNotes(): void
    {
        $bobsSharedWorkspaceId = $this->createSharedWorkspace($this->bob, 'Bob only');
        $this->createNote($this->bob, $bobsSharedWorkspaceId, 'Bob shared title');
        $this->createNote($this->bob, $this->personalWorkspaceId($this->bob), 'Bob private title');

        $aliceListing = $this->sendAs($this->alice, 'GET', '/api/v1/workspaces');
        self::assertCount(1, $aliceListing->json()['data']['workspaces']);
        self::assertStringNotContainsString('Bob', $aliceListing->body());
        self::assertStringNotContainsString($bobsSharedWorkspaceId, $aliceListing->body());

        $aliceNotes = $this->sendAs($this->alice, 'GET', '/api/v1/workspaces/' . $this->personalWorkspaceId($this->alice) . '/notes');
        self::assertSame([], $aliceNotes->json()['data']['notes']);
    }

    public function testRemovedMemberLosesAccessImmediately(): void
    {
        $sharedWorkspaceId = $this->createSharedWorkspace($this->bob);
        $sharedNote = $this->createNote($this->bob, $sharedWorkspaceId);
        self::assertSame(201, $this->addMember($this->bob, $sharedWorkspaceId, 'alice', 'editor')->statusCode());
        self::assertSame(200, $this->sendAs($this->alice, 'GET', '/api/v1/notes/' . $sharedNote['id'])->statusCode());

        $removeResponse = $this->sendAs($this->bob, 'DELETE', '/api/v1/workspaces/' . $sharedWorkspaceId . '/members/' . $this->alice['user_id']);
        self::assertSame(204, $removeResponse->statusCode(), $removeResponse->body());

        $this->assertLooksNonexistent($this->sendAs($this->alice, 'GET', '/api/v1/notes/' . $sharedNote['id']));
        $this->assertLooksNonexistent($this->sendAs($this->alice, 'GET', '/api/v1/workspaces/' . $sharedWorkspaceId . '/notes'));
    }

    public function testTrashedNoteDisappearsFromReadsAndListings(): void
    {
        $workspaceId = $this->personalWorkspaceId($this->alice);
        $trashedNote = $this->createNote($this->alice, $workspaceId);

        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/notes/' . $trashedNote['id'])->statusCode());

        $this->assertLooksNonexistent($this->sendAs($this->alice, 'GET', '/api/v1/notes/' . $trashedNote['id']));
        $this->assertLooksNonexistent($this->sendAs($this->alice, 'PATCH', '/api/v1/notes/' . $trashedNote['id'], ['revision' => 1, 'title' => 'x']));
        self::assertSame([], $this->sendAs($this->alice, 'GET', '/api/v1/workspaces/' . $workspaceId . '/notes')->json()['data']['notes']);
        // Moved to Trash, not destroyed: Week 4 adds restore.
        self::assertNotFalse($this->scalar('SELECT trashed_at FROM notes WHERE id = :id AND trashed_at IS NOT NULL', ['id' => $trashedNote['id']]));
    }

    public function testSystemAdministratorHasNoWorkspaceOrNoteAccess(): void
    {
        $admin = $this->signedInUser('sysadmin', true);
        $alicesNote = $this->createNote($this->alice, $this->personalWorkspaceId($this->alice));

        self::assertSame([], $this->sendAs($admin, 'GET', '/api/v1/workspaces')->json()['data']['workspaces']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM workspaces WHERE created_by_user_id = :id', ['id' => $admin['user_id']]));
        $this->assertError($this->sendAs($admin, 'POST', '/api/v1/workspaces', ['name' => 'Admin space']), 403, 'admin_account');
        $this->assertLooksNonexistent($this->sendAs($admin, 'GET', '/api/v1/notes/' . $alicesNote['id']));

        // Even a membership row planted directly in the database grants an administrator nothing (D025).
        $insertStatement = $this->database->prepare(
            'INSERT INTO workspace_members (workspace_id, user_id, role, created_at, updated_at)
             VALUES (:workspace_id, :user_id, \'owner\', UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $insertStatement->execute(['workspace_id' => $alicesNote['workspace_id'], 'user_id' => $admin['user_id']]);
        $this->assertLooksNonexistent($this->sendAs($admin, 'GET', '/api/v1/notes/' . $alicesNote['id']));
        $this->assertLooksNonexistent($this->sendAs($admin, 'GET', '/api/v1/workspaces/' . $alicesNote['workspace_id'] . '/notes'));
    }

    public function testAdministratorAccountsCannotBeAddedAsMembers(): void
    {
        $this->createUser('sysadmin', self::DEFAULT_PASSWORD, true);
        $sharedWorkspaceId = $this->createSharedWorkspace($this->alice);

        $this->assertError($this->addMember($this->alice, $sharedWorkspaceId, 'sysadmin', 'reader'), 422, 'validation_failed');
    }

    public function testMalformedAndRandomIdsGiveTheSame404(): void
    {
        foreach (['not-a-uuid', UuidGenerator::generate(), '../../etc/passwd', str_repeat('a', 500)] as $bogusId) {
            $this->assertLooksNonexistent($this->sendAs($this->alice, 'GET', '/api/v1/notes/' . rawurlencode($bogusId)));
            $this->assertLooksNonexistent($this->sendAs($this->alice, 'GET', '/api/v1/workspaces/' . rawurlencode($bogusId) . '/notes'));
        }
    }

    public function testWorkspaceAndNoteEndpointsRequireSignIn(): void
    {
        $workspaceId = $this->personalWorkspaceId($this->alice);
        $note = $this->createNote($this->alice, $workspaceId);

        $this->assertError($this->send('GET', '/api/v1/workspaces'), 401, 'unauthenticated');
        $this->assertError($this->send('GET', '/api/v1/workspaces/' . $workspaceId . '/notes'), 401, 'unauthenticated');
        $this->assertError($this->send('GET', '/api/v1/notes/' . $note['id']), 401, 'unauthenticated');
    }

    public function testStateChangesWithoutCsrfTokenAreRefused(): void
    {
        $workspaceId = $this->personalWorkspaceId($this->alice);
        $note = $this->createNote($this->alice, $workspaceId);
        $withoutCsrf = fn (string $method, string $path, ?array $jsonBody = null): Response => $this->send(
            $method,
            $path,
            $jsonBody,
            [],
            [self::COOKIE_NAME => $this->alice['session_token']],
        );

        $this->assertError($withoutCsrf('POST', '/api/v1/workspaces/' . $workspaceId . '/notes', ['title' => 'x']), 403, 'csrf_failed');
        $this->assertError($withoutCsrf('PATCH', '/api/v1/notes/' . $note['id'], ['revision' => 1, 'title' => 'x']), 403, 'csrf_failed');
        $this->assertError($withoutCsrf('DELETE', '/api/v1/notes/' . $note['id']), 403, 'csrf_failed');
        $this->assertError($withoutCsrf('POST', '/api/v1/workspaces', ['name' => 'x']), 403, 'csrf_failed');
    }

    /**
     * An inaccessible resource must be indistinguishable from a missing one: same status,
     * same body, and nothing about the resource itself.
     */
    private function assertLooksNonexistent(Response $response): void
    {
        $this->assertError($response, 404, 'not_found');
        self::assertSame(['error' => ['code' => 'not_found', 'message' => 'The requested resource was not found.']], $response->json());
    }
}
