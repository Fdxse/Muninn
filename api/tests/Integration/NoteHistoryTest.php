<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Notes\NoteHistory;
use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * Note version history (D009, D037): what a save keeps, merging of quick saves, the 100-version
 * limit, restoring, and that history never leaks to people without access to the note.
 */
final class NoteHistoryTest extends WorkspaceTestCase
{
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $alice;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $bob;
    private string $sharedWorkspaceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->signedInUser('alice');
        $this->bob = $this->signedInUser('bob');
        $this->sharedWorkspaceId = $this->createSharedWorkspace($this->alice);
        self::assertSame(201, $this->addMember($this->alice, $this->sharedWorkspaceId, 'bob', 'editor')->statusCode());
    }

    public function testASaveKeepsTheStateItReplaced(): void
    {
        $folder = $this->createFolder($this->alice, $this->sharedWorkspaceId, 'Recipes');
        $note = $this->createNote($this->alice, $this->sharedWorkspaceId, 'Soup', 'v1');
        self::assertSame(0, $note['history_count']);
        self::assertSame(100, $note['history_limit']);
        $this->assertOkData($this->updateNote($this->alice, $note['id'], 1, ['folder_id' => $folder['id'], 'tags' => ['dinner']]));

        // Bob saves next: Alice's state (with folder and tag) is kept.
        $savedNote = $this->assertOkData($this->updateNote($this->bob, $note['id'], 2, ['title' => 'Tomato soup', 'content' => 'v3', 'tags' => []]))['note'];
        self::assertSame(2, $savedNote['history_count']);

        $historyData = $this->assertOkData($this->sendAs($this->bob, 'GET', '/api/v1/notes/' . $note['id'] . '/versions'));
        self::assertSame(2, $historyData['history_count']);
        self::assertSame(100, $historyData['history_limit']);
        self::assertSame([2, 1], array_column($historyData['versions'], 'revision'), 'Newest first.');
        self::assertArrayNotHasKey('content', $historyData['versions'][0], 'The list carries no content.');

        $version = $this->assertOkData($this->sendAs($this->bob, 'GET', '/api/v1/notes/' . $note['id'] . '/versions/' . $historyData['versions'][0]['id']))['version'];
        self::assertSame('Soup', $version['title']);
        self::assertSame('v1', $version['content']);
        self::assertSame('Recipes', $version['folder_name']);
        self::assertSame(['dinner'], $version['tags']);
        self::assertSame('Alice', $version['edited_by']);
    }

    public function testASaveThatChangesNothingAddsNoVersion(): void
    {
        $note = $this->createNote($this->alice, $this->sharedWorkspaceId, 'Same', 'same');

        $savedNote = $this->assertOkData($this->updateNote($this->bob, $note['id'], 1, ['title' => 'Same', 'content' => 'same', 'tags' => []]))['note'];

        self::assertSame(2, $savedNote['revision'], 'The save itself still counts as a revision.');
        self::assertSame(0, $savedNote['history_count']);
    }

    public function testQuickSavesBySamePersonMergeButOthersNeverDo(): void
    {
        $note = $this->createNote($this->alice, $this->sharedWorkspaceId, 'Draft', 'original');

        // Alice saves three times in a row: only the state from before her burst is kept.
        $this->assertOkData($this->updateNote($this->alice, $note['id'], 1, ['content' => 'alice 1']));
        $this->assertOkData($this->updateNote($this->alice, $note['id'], 2, ['content' => 'alice 2']));
        $this->assertOkData($this->updateNote($this->alice, $note['id'], 3, ['content' => 'alice 3']));
        self::assertSame(['original'], $this->versionContents($note['id']));

        // Bob's save always keeps Alice's latest state, and Alice's next save keeps Bob's.
        $this->assertOkData($this->updateNote($this->bob, $note['id'], 4, ['content' => 'bob 1']));
        $this->assertOkData($this->updateNote($this->alice, $note['id'], 5, ['content' => 'alice 4']));
        self::assertSame(['bob 1', 'alice 3', 'original'], $this->versionContents($note['id']));
    }

    public function testSavesFurtherApartThanTheMergeWindowAreKeptSeparately(): void
    {
        $note = $this->createNote($this->alice, $this->sharedWorkspaceId, 'Diary', 'monday');
        $this->assertOkData($this->updateNote($this->alice, $note['id'], 1, ['content' => 'tuesday']));

        // Pretend the kept version is older than the merge window.
        $this->database->prepare('UPDATE note_versions SET replaced_at = UTC_TIMESTAMP() - INTERVAL :minutes MINUTE')
            ->execute(['minutes' => NoteHistory::MERGE_WINDOW_MINUTES + 1]);
        $this->assertOkData($this->updateNote($this->alice, $note['id'], 2, ['content' => 'wednesday']));

        self::assertSame(['tuesday', 'monday'], $this->versionContents($note['id']));
    }

    public function testOnlyTheNewestHundredVersionsAreKept(): void
    {
        $note = $this->createNote($this->alice, $this->sharedWorkspaceId, 'Busy', 'save 0');

        // Alternate editors so no save merges with the one before.
        for ($saveNumber = 1; $saveNumber <= NoteHistory::MAXIMUM_VERSIONS + 5; $saveNumber++) {
            $editorCredentials = $saveNumber % 2 === 0 ? $this->alice : $this->bob;
            $this->assertOkData($this->updateNote($editorCredentials, $note['id'], $saveNumber, ['content' => 'save ' . $saveNumber]));
        }

        $versionContents = $this->versionContents($note['id']);
        self::assertCount(NoteHistory::MAXIMUM_VERSIONS, $versionContents);
        self::assertSame('save ' . (NoteHistory::MAXIMUM_VERSIONS + 4), $versionContents[0], 'The newest replaced state is kept.');
        self::assertSame('save 5', end($versionContents), 'The five oldest states were dropped.');
    }

    public function testRestoringAVersionIsANewSaveThatCanBeUndone(): void
    {
        $folder = $this->createFolder($this->alice, $this->sharedWorkspaceId, 'Old folder');
        $note = $this->createNote($this->alice, $this->sharedWorkspaceId, 'Old title', 'old text');
        $this->assertOkData($this->updateNote($this->alice, $note['id'], 1, ['folder_id' => $folder['id'], 'tags' => ['Keep']]));
        $this->assertOkData($this->updateNote($this->bob, $note['id'], 2, ['title' => 'New title', 'content' => 'new text', 'folder_id' => null, 'tags' => ['other']]));
        $oldVersionId = $this->sendAs($this->bob, 'GET', '/api/v1/notes/' . $note['id'] . '/versions')->json()['data']['versions'][0]['id'];

        // A restore based on a stale revision is refused like any other save.
        $restorePath = '/api/v1/notes/' . $note['id'] . '/versions/' . $oldVersionId . '/restore';
        $this->assertError($this->sendAs($this->bob, 'POST', $restorePath, ['revision' => 2]), 409, 'revision_conflict');
        $this->assertError($this->sendAs($this->bob, 'POST', $restorePath, []), 422, 'validation_failed');

        $restoredNote = $this->assertOkData($this->sendAs($this->bob, 'POST', $restorePath, ['revision' => 3]))['note'];
        self::assertSame('Old title', $restoredNote['title']);
        self::assertSame('old text', $restoredNote['content']);
        self::assertSame($folder['id'], $restoredNote['folder_id']);
        self::assertSame(['Keep'], $restoredNote['tags'], 'Tags removed since then are created again.');
        self::assertSame(4, $restoredNote['revision']);

        // The state the restore replaced is in the history, even though Bob saved it moments ago.
        self::assertSame('new text', $this->versionContents($note['id'])[0]);
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type = 'note.version_restored'"));
    }

    public function testReadersSeeHistoryButCannotRestore(): void
    {
        $carol = $this->signedInUser('carol');
        self::assertSame(201, $this->addMember($this->alice, $this->sharedWorkspaceId, 'carol', 'reader')->statusCode());
        $note = $this->createNote($this->alice, $this->sharedWorkspaceId, 'Plan', 'v1');
        $this->assertOkData($this->updateNote($this->bob, $note['id'], 1, ['content' => 'v2']));
        $versionId = $this->sendAs($carol, 'GET', '/api/v1/notes/' . $note['id'] . '/versions')->json()['data']['versions'][0]['id'];

        $this->assertOkData($this->sendAs($carol, 'GET', '/api/v1/notes/' . $note['id'] . '/versions/' . $versionId));
        $this->assertError($this->sendAs($carol, 'POST', '/api/v1/notes/' . $note['id'] . '/versions/' . $versionId . '/restore', ['revision' => 2]), 403, 'insufficient_role');
    }

    public function testHistoryIsInvisibleWithoutAccessToTheNote(): void
    {
        $mallory = $this->signedInUser('mallory');
        $administrator = $this->signedInUser('sysadmin', true);
        $note = $this->createNote($this->alice, $this->sharedWorkspaceId, 'Secret', 'secret v1');
        $this->assertOkData($this->updateNote($this->alice, $note['id'], 1, ['content' => 'secret v2']));
        $versionId = $this->sendAs($this->alice, 'GET', '/api/v1/notes/' . $note['id'] . '/versions')->json()['data']['versions'][0]['id'];
        $versionPath = '/api/v1/notes/' . $note['id'] . '/versions/' . $versionId;

        foreach ([$mallory, $administrator] as $outsiderCredentials) {
            $this->assertError($this->sendAs($outsiderCredentials, 'GET', '/api/v1/notes/' . $note['id'] . '/versions'), 404, 'not_found');
            $this->assertError($this->sendAs($outsiderCredentials, 'GET', $versionPath), 404, 'not_found');
            $this->assertError($this->sendAs($outsiderCredentials, 'POST', $versionPath . '/restore', ['revision' => 2]), 404, 'not_found');
        }

        // A version ID only works together with its own note, even inside an accessible workspace.
        $otherNote = $this->createNote($this->alice, $this->sharedWorkspaceId, 'Other', 'other');
        $this->assertError($this->sendAs($this->alice, 'GET', '/api/v1/notes/' . $otherNote['id'] . '/versions/' . $versionId), 404, 'not_found');
        $this->assertError($this->sendAs($this->alice, 'GET', '/api/v1/notes/' . $note['id'] . '/versions/not-a-uuid'), 404, 'not_found');

        // Once the note is in Trash its history is unavailable too.
        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/notes/' . $note['id'])->statusCode());
        $this->assertError($this->sendAs($this->alice, 'GET', $versionPath), 404, 'not_found');
    }

    /**
     * Returns a note's kept versions' contents, newest first, read straight from the database.
     *
     * @return list<string>
     */
    private function versionContents(string $noteId): array
    {
        $selectStatement = $this->database->prepare('SELECT content FROM note_versions WHERE note_id = :note_id ORDER BY revision DESC');
        $selectStatement->execute(['note_id' => $noteId]);

        return array_map('strval', $selectStatement->fetchAll(\PDO::FETCH_COLUMN));
    }
}
