<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Http\Request;
use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * Sub-folders (D055): tree order and counts, the 3-level limit, moves without loops, deleting
 * moves contents up one level, filtering includes sub-folders, and parents never cross
 * workspaces.
 */
final class SubFolderTest extends WorkspaceTestCase
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

    public function testFoldersAreListedAsATreeWithLevelsAndTotals(): void
    {
        $work = $this->createSubFolder('Work', null);
        $home = $this->createSubFolder('Home', null);
        $projects = $this->createSubFolder('Projects', $work['id']);
        $archive2024 = $this->createSubFolder('2024', $projects['id']);
        $this->createSubFolder('Admin', $work['id']);

        self::assertSame($work['id'], $projects['parent_id']);
        self::assertSame(3, $archive2024['level']);

        $this->createNoteIn($work['id'], 'Work note');
        $this->createNoteIn($archive2024['id'], 'Deep note');

        $listedFolders = $this->listFolders();
        // Depth first, siblings by name.
        self::assertSame(['Home', 'Work', 'Admin', 'Projects', '2024'], array_column($listedFolders, 'name'));
        self::assertSame([1, 1, 2, 2, 3], array_column($listedFolders, 'level'));
        $workFolder = $listedFolders[1];
        self::assertSame($home['id'], $listedFolders[0]['id']);
        self::assertSame(1, $workFolder['note_count'], 'note_count counts only the notes directly in the folder.');
        self::assertSame(2, $workFolder['total_note_count'], 'total_note_count includes sub-folders.');
    }

    public function testFoldersCanBeAtMostThreeLevelsDeep(): void
    {
        $levelOne = $this->createSubFolder('One', null);
        $levelTwo = $this->createSubFolder('Two', $levelOne['id']);
        $levelThree = $this->createSubFolder('Three', $levelTwo['id']);

        $tooDeepResponse = $this->sendAs($this->alice, 'POST', $this->foldersPath(), ['name' => 'Four', 'parent_id' => $levelThree['id']]);
        $this->assertError($tooDeepResponse, 422, 'validation_failed');
        self::assertArrayHasKey('parent_id', $tooDeepResponse->json()['error']['fields']);

        // Moving a two-level branch (Two > Three) under another level-2 folder would make Three level 4.
        $otherTop = $this->createSubFolder('Other', null);
        $otherSecond = $this->createSubFolder('Other second', $otherTop['id']);
        $this->assertError($this->moveFolder($levelTwo['id'], $otherSecond['id']), 422, 'validation_failed');
        // Under a top-level folder it still fits.
        self::assertSame(200, $this->moveFolder($levelTwo['id'], $otherTop['id'])->statusCode());
    }

    public function testFoldersCannotBeMovedIntoThemselves(): void
    {
        $parent = $this->createSubFolder('Parent', null);
        $child = $this->createSubFolder('Child', $parent['id']);

        $this->assertError($this->moveFolder($parent['id'], $parent['id']), 422, 'validation_failed');
        $this->assertError($this->moveFolder($parent['id'], $child['id']), 422, 'validation_failed');

        // Moving to the top level and renaming at the same time works.
        $updateResponse = $this->sendAs($this->alice, 'PATCH', '/api/v1/folders/' . $child['id'], ['name' => 'Free', 'parent_id' => null]);
        self::assertSame(200, $updateResponse->statusCode(), $updateResponse->body());
        self::assertNull($updateResponse->json()['data']['folder']['parent_id']);
        self::assertSame('Free', $updateResponse->json()['data']['folder']['name']);

        // A rename alone keeps the folder where it is.
        $nested = $this->createSubFolder('Nested', $parent['id']);
        $renameResponse = $this->sendAs($this->alice, 'PATCH', '/api/v1/folders/' . $nested['id'], ['name' => 'Renamed']);
        self::assertSame($parent['id'], $renameResponse->json()['data']['folder']['parent_id']);

        $this->assertError($this->sendAs($this->alice, 'PATCH', '/api/v1/folders/' . $nested['id'], []), 422, 'validation_failed');
    }

    public function testDeletingAFolderMovesItsNotesAndSubFoldersUpOneLevel(): void
    {
        $top = $this->createSubFolder('Top', null);
        $middle = $this->createSubFolder('Middle', $top['id']);
        $bottom = $this->createSubFolder('Bottom', $middle['id']);
        $middleNote = $this->createNoteIn($middle['id'], 'Middle note');
        $trashedNote = $this->createNoteIn($middle['id'], 'Trashed note');
        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/notes/' . $trashedNote['id'])->statusCode());

        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/folders/' . $middle['id'])->statusCode());

        // The note (and the one in Trash) now sit in Top; Bottom is now directly inside Top.
        self::assertSame($top['id'], $this->fetchNote($middleNote['id'])['folder_id']);
        self::assertSame($top['id'], $this->scalar('SELECT folder_id FROM notes WHERE id = :id', ['id' => $trashedNote['id']]));
        $foldersAfter = $this->listFolders();
        self::assertSame(['Top', 'Bottom'], array_column($foldersAfter, 'name'));
        self::assertSame($top['id'], $foldersAfter[1]['parent_id']);
        self::assertSame(2, $foldersAfter[1]['level']);
        self::assertSame($bottom['id'], $foldersAfter[1]['id']);

        // A top-level folder's contents go to the top level and "No folder", as before.
        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/folders/' . $top['id'])->statusCode());
        self::assertNull($this->fetchNote($middleNote['id'])['folder_id']);
        $remainingFolders = $this->listFolders();
        self::assertSame(['Bottom'], array_column($remainingFolders, 'name'));
        self::assertNull($remainingFolders[0]['parent_id']);
        self::assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type = 'folder.deleted'"));
    }

    public function testFilteringByAFolderIncludesItsSubFolders(): void
    {
        $parent = $this->createSubFolder('Parent', null);
        $child = $this->createSubFolder('Child', $parent['id']);
        $sibling = $this->createSubFolder('Sibling', null);
        $this->createNoteIn($parent['id'], 'In parent');
        $this->createNoteIn($child['id'], 'In child');
        $this->createNoteIn($sibling['id'], 'In sibling');

        self::assertEqualsCanonicalizing(['In parent', 'In child'], $this->listedTitles($parent['id']));
        self::assertSame(['In child'], $this->listedTitles($child['id']));
    }

    public function testParentsNeverCrossWorkspaces(): void
    {
        $bobFolder = $this->createFolder($this->bob, $this->personalWorkspaceId($this->bob), 'Bob private');
        $aliceFolder = $this->createSubFolder('Alice', null);

        // Bob's folder ID is answered exactly like a made-up one.
        $crossResponse = $this->sendAs($this->alice, 'POST', $this->foldersPath(), ['name' => 'Sneaky', 'parent_id' => $bobFolder['id']]);
        $madeUpResponse = $this->sendAs($this->alice, 'POST', $this->foldersPath(), ['name' => 'Sneaky', 'parent_id' => '00000000-0000-4000-8000-000000000000']);
        $this->assertError($crossResponse, 422, 'validation_failed');
        self::assertSame($madeUpResponse->body(), $crossResponse->body());
        $this->assertError($this->moveFolder($aliceFolder['id'], $bobFolder['id']), 422, 'validation_failed');

        // Bob cannot move Alice's folder into his own, nor see it.
        $this->assertError($this->sendAs($this->bob, 'PATCH', '/api/v1/folders/' . $aliceFolder['id'], ['parent_id' => $bobFolder['id']]), 404, 'not_found');
        // None of the refused requests left a folder with a parent behind.
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM folders WHERE parent_folder_id IS NOT NULL'));
    }

    public function testReadersCannotCreateOrMoveSubFolders(): void
    {
        $sharedWorkspaceId = $this->createSharedWorkspace($this->alice);
        self::assertSame(201, $this->addMember($this->alice, $sharedWorkspaceId, 'bob', 'reader')->statusCode());
        $sharedParent = $this->createFolder($this->alice, $sharedWorkspaceId, 'Shared parent');
        $sharedOther = $this->createFolder($this->alice, $sharedWorkspaceId, 'Shared other');

        $createResponse = $this->sendAs($this->bob, 'POST', '/api/v1/workspaces/' . $sharedWorkspaceId . '/folders', ['name' => 'Child', 'parent_id' => $sharedParent['id']]);
        $this->assertError($createResponse, 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->bob, 'PATCH', '/api/v1/folders/' . $sharedOther['id'], ['parent_id' => $sharedParent['id']]), 403, 'insufficient_role');
    }

    public function testWorkspaceWithNestedFoldersCanStillBeDeleted(): void
    {
        $sharedWorkspaceId = $this->createSharedWorkspace($this->alice);
        $sharedParent = $this->createFolder($this->alice, $sharedWorkspaceId, 'Parent');
        $childResponse = $this->sendAs($this->alice, 'POST', '/api/v1/workspaces/' . $sharedWorkspaceId . '/folders', ['name' => 'Child', 'parent_id' => $sharedParent['id']]);
        self::assertSame(201, $childResponse->statusCode(), $childResponse->body());

        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/workspaces/' . $sharedWorkspaceId)->statusCode());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM folders WHERE workspace_id = :id', ['id' => $sharedWorkspaceId]));
    }

    private function foldersPath(): string
    {
        return '/api/v1/workspaces/' . $this->aliceWorkspaceId . '/folders';
    }

    /** @return array<string, mixed> A folder created in Alice's personal workspace. */
    private function createSubFolder(string $folderName, ?string $parentFolderId): array
    {
        $createResponse = $this->sendAs($this->alice, 'POST', $this->foldersPath(), ['name' => $folderName, 'parent_id' => $parentFolderId]);
        self::assertSame(201, $createResponse->statusCode(), $createResponse->body());

        return $createResponse->json()['data']['folder'];
    }

    private function moveFolder(string $folderId, ?string $newParentFolderId): \Muninn\Api\Http\Response
    {
        return $this->sendAs($this->alice, 'PATCH', '/api/v1/folders/' . $folderId, ['parent_id' => $newParentFolderId]);
    }

    /** @return list<array<string, mixed>> */
    private function listFolders(): array
    {
        $listResponse = $this->sendAs($this->alice, 'GET', $this->foldersPath());
        self::assertSame(200, $listResponse->statusCode(), $listResponse->body());

        return $listResponse->json()['data']['folders'];
    }

    /** @return array<string, mixed> */
    private function createNoteIn(string $folderId, string $noteTitle): array
    {
        $createResponse = $this->sendAs($this->alice, 'POST', '/api/v1/workspaces/' . $this->aliceWorkspaceId . '/notes', ['title' => $noteTitle, 'folder_id' => $folderId]);
        self::assertSame(201, $createResponse->statusCode(), $createResponse->body());

        return $createResponse->json()['data']['note'];
    }

    /** @return array<string, mixed> */
    private function fetchNote(string $noteId): array
    {
        return $this->sendAs($this->alice, 'GET', '/api/v1/notes/' . $noteId)->json()['data']['note'];
    }

    /** @return list<string> Titles in Alice's note list filtered by the folder. */
    private function listedTitles(string $folderId): array
    {
        $listResponse = $this->application->handle(new Request(
            'GET',
            '/api/v1/workspaces/' . $this->aliceWorkspaceId . '/notes',
            [],
            [self::COOKIE_NAME => $this->alice['session_token']],
            '',
            '203.0.113.10',
            ['folder' => $folderId],
        ));
        self::assertSame(200, $listResponse->statusCode(), $listResponse->body());

        return array_column($listResponse->json()['data']['notes'], 'title');
    }
}
