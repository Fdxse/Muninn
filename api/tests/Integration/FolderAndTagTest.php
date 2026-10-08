<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * Folders (D033) and tags (D034): CRUD, filtering, role checks and workspace isolation.
 */
final class FolderAndTagTest extends WorkspaceTestCase
{
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $alice;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $bob;
    private string $aliceWorkspaceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->signedInUser('alice');
        $this->bob = $this->signedInUser('bob');
        $this->aliceWorkspaceId = $this->personalWorkspaceId($this->alice);
    }

    public function testFolderLifecycleAndDeletingMovesNotesToNoFolder(): void
    {
        $folder = $this->createFolder($this->alice, $this->aliceWorkspaceId, '  Recipes  ');
        self::assertSame('Recipes', $folder['name'], 'Folder names are trimmed.');
        self::assertSame(0, $folder['note_count']);

        $note = $this->createNoteWith($this->alice, ['title' => 'Bread', 'folder_id' => $folder['id']]);
        self::assertSame($folder['id'], $note['folder_id']);
        self::assertSame('Recipes', $note['folder_name']);

        $renameResponse = $this->sendAs($this->alice, 'PATCH', '/api/v1/folders/' . $folder['id'], ['name' => 'Baking']);
        self::assertSame(200, $renameResponse->statusCode(), $renameResponse->body());
        self::assertSame('Baking', $renameResponse->json()['data']['folder']['name']);
        self::assertSame(1, $renameResponse->json()['data']['folder']['note_count']);

        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/folders/' . $folder['id'])->statusCode());

        // The note survives, unchanged except that it is now in no folder.
        $noteAfter = $this->sendAs($this->alice, 'GET', '/api/v1/notes/' . $note['id'])->json()['data']['note'];
        self::assertNull($noteAfter['folder_id']);
        self::assertSame('Bread', $noteAfter['title']);
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type = 'folder.deleted'"));
        $this->assertError($this->sendAs($this->alice, 'DELETE', '/api/v1/folders/' . $folder['id']), 404, 'not_found');
    }

    public function testFolderNamesAreUniquePerWorkspaceIgnoringCase(): void
    {
        $this->createFolder($this->alice, $this->aliceWorkspaceId, 'Work');
        $duplicateResponse = $this->sendAs($this->alice, 'POST', '/api/v1/workspaces/' . $this->aliceWorkspaceId . '/folders', ['name' => 'WORK']);
        $this->assertError($duplicateResponse, 409, 'folder_name_taken');

        // The same name in another workspace is fine: folders never cross workspaces.
        $this->createFolder($this->bob, $this->personalWorkspaceId($this->bob), 'Work');

        $this->assertError($this->sendAs($this->alice, 'POST', '/api/v1/workspaces/' . $this->aliceWorkspaceId . '/folders', ['name' => '  ']), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->alice, 'POST', '/api/v1/workspaces/' . $this->aliceWorkspaceId . '/folders', ['name' => str_repeat('f', 101)]), 422, 'validation_failed');
    }

    public function testFilteringByFolderAndTag(): void
    {
        $folder = $this->createFolder($this->alice, $this->aliceWorkspaceId, 'Garden');
        $this->createNoteWith($this->alice, ['title' => 'In folder', 'folder_id' => $folder['id'], 'tags' => ['plants']]);
        $this->createNoteWith($this->alice, ['title' => 'Loose', 'tags' => ['Plants', 'todo']]);
        $this->createNoteWith($this->alice, ['title' => 'Plain']);

        self::assertSame(['In folder'], $this->listedTitles('?folder=' . $folder['id']));
        self::assertEqualsCanonicalizing(['Loose', 'Plain'], $this->listedTitles('?folder=none'));
        self::assertEqualsCanonicalizing(['In folder', 'Loose'], $this->listedTitles('?tag=PLANTS'), 'Tag filters ignore case.');
        self::assertSame(['Loose'], $this->listedTitles('?tag=todo'));
        self::assertSame([], $this->listedTitles('?folder=' . $folder['id'] . '&tag=todo'));
        self::assertSame([], $this->listedTitles('?folder=not-a-uuid'));

        // The first spelling of a tag is kept and counts include every active note.
        $tags = $this->sendAs($this->alice, 'GET', '/api/v1/workspaces/' . $this->aliceWorkspaceId . '/tags')->json()['data']['tags'];
        self::assertSame([['name' => 'plants', 'note_count' => 2], ['name' => 'todo', 'note_count' => 1]], $tags);
    }

    public function testTagsAreNormalisedReplacedAndCleanedUp(): void
    {
        $note = $this->createNoteWith($this->alice, ['tags' => ['#Ideas', ' ideas ', 'Later', '', '#']]);
        self::assertSame(['Ideas', 'Later'], $note['tags'], 'Hashes, blanks and case-insensitive duplicates are dropped.');

        // Leaving "tags" out keeps them; sending a list replaces them.
        $titleOnly = $this->sendAs($this->alice, 'PATCH', '/api/v1/notes/' . $note['id'], ['revision' => 1, 'title' => 'Renamed']);
        self::assertSame(['Ideas', 'Later'], $titleOnly->json()['data']['note']['tags']);
        $replaced = $this->sendAs($this->alice, 'PATCH', '/api/v1/notes/' . $note['id'], ['revision' => 2, 'tags' => ['Now']]);
        self::assertSame(['Now'], $replaced->json()['data']['note']['tags']);

        // Tags no note uses any more are removed from the workspace.
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM tags'));

        $notePath = '/api/v1/notes/' . $note['id'];
        $this->assertError($this->sendAs($this->alice, 'PATCH', $notePath, ['revision' => 3, 'tags' => 'not-a-list']), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->alice, 'PATCH', $notePath, ['revision' => 3, 'tags' => ['a,b']]), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->alice, 'PATCH', $notePath, ['revision' => 3, 'tags' => [str_repeat('t', 51)]]), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->alice, 'PATCH', $notePath, ['revision' => 3, 'tags' => [42]]), 422, 'validation_failed');
        $tooManyTags = array_map(static fn (int $tagNumber): string => 'tag' . $tagNumber, range(1, 21));
        $this->assertError($this->sendAs($this->alice, 'PATCH', $notePath, ['revision' => 3, 'tags' => $tooManyTags]), 422, 'validation_failed');
    }

    public function testRefusedSaveChangesNeitherFolderNorTags(): void
    {
        $folder = $this->createFolder($this->alice, $this->aliceWorkspaceId);
        $note = $this->createNoteWith($this->alice, ['tags' => ['original']]);
        $notePath = '/api/v1/notes/' . $note['id'];
        self::assertSame(200, $this->sendAs($this->alice, 'PATCH', $notePath, ['revision' => 1, 'content' => 'saved first'])->statusCode());

        $staleSave = $this->sendAs($this->alice, 'PATCH', $notePath, ['revision' => 1, 'folder_id' => $folder['id'], 'tags' => ['stale']]);
        $this->assertError($staleSave, 409, 'revision_conflict');

        $noteAfter = $this->sendAs($this->alice, 'GET', $notePath)->json()['data']['note'];
        self::assertNull($noteAfter['folder_id']);
        self::assertSame(['original'], $noteAfter['tags']);
    }

    public function testNoteCannotBeFiledIntoAnotherWorkspacesFolder(): void
    {
        $bobFolder = $this->createFolder($this->bob, $this->personalWorkspaceId($this->bob), 'Bob private');

        // Creating or moving a note into Bob's folder fails exactly like a made-up folder ID.
        $createResponse = $this->sendAs($this->alice, 'POST', '/api/v1/workspaces/' . $this->aliceWorkspaceId . '/notes', ['folder_id' => $bobFolder['id']]);
        $this->assertError($createResponse, 422, 'validation_failed');
        $unknownResponse = $this->sendAs($this->alice, 'POST', '/api/v1/workspaces/' . $this->aliceWorkspaceId . '/notes', ['folder_id' => '00000000-0000-4000-8000-000000000000']);
        self::assertSame($unknownResponse->json()['error'], $createResponse->json()['error']);

        $aliceNote = $this->createNoteWith($this->alice, []);
        $this->assertError($this->sendAs($this->alice, 'PATCH', '/api/v1/notes/' . $aliceNote['id'], ['revision' => 1, 'folder_id' => $bobFolder['id']]), 422, 'validation_failed');
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM notes WHERE folder_id IS NOT NULL'));

        // A folder filter naming Bob's folder simply matches nothing in Alice's workspace.
        self::assertSame([], $this->listedTitles('?folder=' . $bobFolder['id']));
    }

    public function testOutsidersCannotSeeOrChangeFoldersAndTags(): void
    {
        $folder = $this->createFolder($this->alice, $this->aliceWorkspaceId, 'Diary');
        $this->createNoteWith($this->alice, ['tags' => ['confidential']]);

        $this->assertError($this->sendAs($this->bob, 'GET', '/api/v1/workspaces/' . $this->aliceWorkspaceId . '/folders'), 404, 'not_found');
        $this->assertError($this->sendAs($this->bob, 'GET', '/api/v1/workspaces/' . $this->aliceWorkspaceId . '/tags'), 404, 'not_found');
        $this->assertError($this->sendAs($this->bob, 'POST', '/api/v1/workspaces/' . $this->aliceWorkspaceId . '/folders', ['name' => 'Intruder']), 404, 'not_found');
        $this->assertError($this->sendAs($this->bob, 'PATCH', '/api/v1/folders/' . $folder['id'], ['name' => 'Hacked']), 404, 'not_found');
        $this->assertError($this->sendAs($this->bob, 'DELETE', '/api/v1/folders/' . $folder['id']), 404, 'not_found');

        // Bob's own tag list never shows Alice's tag names (D034).
        self::assertSame([], $this->sendAs($this->bob, 'GET', '/api/v1/workspaces/' . $this->personalWorkspaceId($this->bob) . '/tags')->json()['data']['tags']);
        self::assertSame('Diary', $this->scalar('SELECT name FROM folders WHERE id = :id', ['id' => $folder['id']]));
    }

    public function testReadersSeeFoldersButCannotChangeThem(): void
    {
        $sharedWorkspaceId = $this->createSharedWorkspace($this->alice);
        self::assertSame(201, $this->addMember($this->alice, $sharedWorkspaceId, 'bob', 'reader')->statusCode());
        $folder = $this->createFolder($this->alice, $sharedWorkspaceId, 'Shared folder');

        $listResponse = $this->sendAs($this->bob, 'GET', '/api/v1/workspaces/' . $sharedWorkspaceId . '/folders');
        self::assertSame(['Shared folder'], array_column($listResponse->json()['data']['folders'], 'name'));

        $this->assertError($this->sendAs($this->bob, 'POST', '/api/v1/workspaces/' . $sharedWorkspaceId . '/folders', ['name' => 'Mine']), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->bob, 'PATCH', '/api/v1/folders/' . $folder['id'], ['name' => 'Renamed']), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->bob, 'DELETE', '/api/v1/folders/' . $folder['id']), 403, 'insufficient_role');
    }

    public function testTrashedNotesDoNotCountAndTheirTagsStayHidden(): void
    {
        $folder = $this->createFolder($this->alice, $this->aliceWorkspaceId);
        $note = $this->createNoteWith($this->alice, ['folder_id' => $folder['id'], 'tags' => ['hidden-topic']]);
        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/notes/' . $note['id'])->statusCode());

        $folders = $this->sendAs($this->alice, 'GET', '/api/v1/workspaces/' . $this->aliceWorkspaceId . '/folders')->json()['data']['folders'];
        self::assertSame(0, $folders[0]['note_count']);
        self::assertSame([], $this->sendAs($this->alice, 'GET', '/api/v1/workspaces/' . $this->aliceWorkspaceId . '/tags')->json()['data']['tags']);
        // The tag row is kept for the trashed note, ready for restore in Week 4.
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM tags'));
    }

    public function testEmptySharedWorkspaceWithFoldersCanStillBeDeleted(): void
    {
        $sharedWorkspaceId = $this->createSharedWorkspace($this->alice);
        $this->createFolder($this->alice, $sharedWorkspaceId);

        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/workspaces/' . $sharedWorkspaceId)->statusCode());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM folders WHERE workspace_id = :id', ['id' => $sharedWorkspaceId]));
    }

    /**
     * Creates a note in Alice's personal workspace with the given body fields.
     *
     * @param array<string, mixed> $noteFields
     * @return array<string, mixed>
     */
    private function createNoteWith(array $credentials, array $noteFields): array
    {
        $createResponse = $this->sendAs($credentials, 'POST', '/api/v1/workspaces/' . $this->personalWorkspaceId($credentials) . '/notes', $noteFields);
        self::assertSame(201, $createResponse->statusCode(), $createResponse->body());

        return $createResponse->json()['data']['note'];
    }

    /** @return list<string> Titles listed in Alice's personal workspace with the given query string. */
    private function listedTitles(string $queryString): array
    {
        parse_str(ltrim($queryString, '?'), $queryParameters);
        $listResponse = $this->application->handle(new \Muninn\Api\Http\Request(
            'GET',
            '/api/v1/workspaces/' . $this->aliceWorkspaceId . '/notes',
            [],
            [self::COOKIE_NAME => $this->alice['session_token']],
            '',
            '203.0.113.10',
            array_map('strval', $queryParameters),
        ));
        self::assertSame(200, $listResponse->statusCode(), $listResponse->body());

        return array_column($listResponse->json()['data']['notes'], 'title');
    }
}
