<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Attachments\AttachmentStorage;
use Muninn\Api\Notes\NotePurger;
use Muninn\Api\Tags\TagService;
use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * Archive (D048), Trash, restore and permanent deletion (D012, D039, D045): who may do what,
 * that purging removes everything that belonged to the note, and that the retention cleanup
 * never touches active content.
 */
final class TrashAndArchiveTest extends WorkspaceTestCase
{
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $owner;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $editor;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $reader;
    private string $workspaceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->signedInUser('olivia');
        $this->editor = $this->signedInUser('edward');
        $this->reader = $this->signedInUser('rita');
        $this->workspaceId = $this->createSharedWorkspace($this->owner);
        self::assertSame(201, $this->addMember($this->owner, $this->workspaceId, 'edward', 'editor')->statusCode());
        self::assertSame(201, $this->addMember($this->owner, $this->workspaceId, 'rita', 'reader')->statusCode());
    }

    public function testArchivedNotesLeaveTheNormalListButStayAvailable(): void
    {
        $note = $this->createNote($this->editor, $this->workspaceId, 'Old project');
        $this->createNote($this->editor, $this->workspaceId, 'Current project');

        $archivedNote = $this->assertOkData($this->sendAs($this->editor, 'POST', '/api/v1/notes/' . $note['id'] . '/archive'))['note'];
        self::assertNotNull($archivedNote['archived_at']);
        self::assertSame(1, $archivedNote['revision'], 'Archiving is not an edit.');

        self::assertSame(['Current project'], $this->listedTitles([]));
        self::assertSame(['Old project'], $this->listedTitles(['archived' => '1']));
        // Still readable and editable.
        $this->assertOkData($this->sendAs($this->reader, 'GET', '/api/v1/notes/' . $note['id']));
        $this->assertOkData($this->updateNote($this->editor, $note['id'], 1, ['content' => 'edited in the archive']));

        $this->assertError($this->sendAs($this->reader, 'POST', '/api/v1/notes/' . $note['id'] . '/unarchive'), 403, 'insufficient_role');
        $unarchivedNote = $this->assertOkData($this->sendAs($this->editor, 'POST', '/api/v1/notes/' . $note['id'] . '/unarchive'))['note'];
        self::assertNull($unarchivedNote['archived_at']);
        self::assertEqualsCanonicalizing(['Old project', 'Current project'], $this->listedTitles([]));
    }

    public function testTrashListRestoreAndRoles(): void
    {
        $note = $this->createNote($this->editor, $this->workspaceId, 'Oops', 'deleted by mistake');
        self::assertSame(204, $this->sendAs($this->editor, 'DELETE', '/api/v1/notes/' . $note['id'])->statusCode());

        // Readers see the Trash, with when each note goes for good.
        $trashData = $this->assertOkData($this->sendAs($this->reader, 'GET', '/api/v1/workspaces/' . $this->workspaceId . '/trash'));
        self::assertSame(30, $trashData['retention_days']);
        self::assertSame(['Oops'], array_column($trashData['notes'], 'title'));
        self::assertSame('Edward', $trashData['notes'][0]['trashed_by']);
        self::assertSame(
            30 * 86400,
            strtotime($trashData['notes'][0]['purge_after']) - strtotime($trashData['notes'][0]['trashed_at']),
        );
        self::assertSame([], $this->listedTitles([]), 'Trashed notes leave the note list.');

        // Readers cannot restore; Editors can. An active note cannot be "restored".
        $restorePath = '/api/v1/trash/' . $note['id'] . '/restore';
        $this->assertError($this->sendAs($this->reader, 'POST', $restorePath), 403, 'insufficient_role');
        $restoredNote = $this->assertOkData($this->sendAs($this->editor, 'POST', $restorePath))['note'];
        self::assertSame('deleted by mistake', $restoredNote['content']);
        $this->assertError($this->sendAs($this->editor, 'POST', $restorePath), 404, 'not_found');
        self::assertSame(['Oops'], $this->listedTitles([]));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type = 'note.restored_from_trash'"));
    }

    public function testOnlyAdminsAndOwnersDeleteForGood(): void
    {
        $note = $this->createNote($this->editor, $this->workspaceId);
        $purgePath = '/api/v1/trash/' . $note['id'];

        // An active note can never be purged through the Trash endpoints.
        $this->assertError($this->sendAs($this->owner, 'DELETE', $purgePath), 404, 'not_found');

        self::assertSame(204, $this->sendAs($this->editor, 'DELETE', '/api/v1/notes/' . $note['id'])->statusCode());
        $this->assertError($this->sendAs($this->reader, 'DELETE', $purgePath), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->editor, 'DELETE', $purgePath), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->editor, 'DELETE', '/api/v1/workspaces/' . $this->workspaceId . '/trash'), 403, 'insufficient_role');

        self::assertSame(204, $this->sendAs($this->owner, 'DELETE', $purgePath)->statusCode());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM notes'));
        $this->assertError($this->sendAs($this->owner, 'DELETE', $purgePath), 404, 'not_found');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type = 'note.purged'"));
    }

    public function testPurgingRemovesHistoryTagsAttachmentsAndFiles(): void
    {
        $doomedNote = $this->createNote($this->editor, $this->workspaceId, 'Doomed', 'v1');
        $keptNote = $this->createNote($this->editor, $this->workspaceId, 'Kept', 'kept');
        $this->assertOkData($this->updateNote($this->editor, $doomedNote['id'], 1, ['content' => 'v2', 'tags' => ['only-here', 'shared']]));
        $this->assertOkData($this->updateNote($this->editor, $keptNote['id'], 1, ['tags' => ['shared']]));
        $doomedImage = $this->uploadAttachment($this->editor, $doomedNote['id'], self::tinyPng())->json()['data']['attachment'];
        $keptImage = $this->uploadAttachment($this->editor, $keptNote['id'], self::tinyPng())->json()['data']['attachment'];
        self::assertSame(2, $this->attachmentStorage()->countFiles());

        self::assertSame(204, $this->sendAs($this->editor, 'DELETE', '/api/v1/notes/' . $doomedNote['id'])->statusCode());
        // While the note is in Trash everything is kept, so it can be restored whole.
        self::assertSame(2, $this->attachmentStorage()->countFiles());
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM tags WHERE name = 'only-here'"));

        $emptyData = $this->assertOkData($this->sendAs($this->owner, 'DELETE', '/api/v1/workspaces/' . $this->workspaceId . '/trash'));
        self::assertSame(1, $emptyData['deleted_notes']);

        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM notes WHERE id = :id', ['id' => $doomedNote['id']]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM note_versions WHERE note_id = :id', ['id' => $doomedNote['id']]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM note_tags WHERE note_id = :id', ['id' => $doomedNote['id']]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM attachments WHERE id = :id', ['id' => $doomedImage['id']]));
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM tags WHERE name = 'only-here'"), 'Unused tags go too.');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM tags WHERE name = 'shared'"), 'Tags still in use stay.');
        self::assertSame(1, $this->attachmentStorage()->countFiles(), 'Only the purged note\'s file is deleted.');
        self::assertNotNull($this->attachmentStorage()->read($keptImage['id']));
        self::assertNull($this->attachmentStorage()->read($doomedImage['id']));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type = 'trash.emptied'"));

        // With its Trash empty, the shared workspace can now be deleted once its last note goes too.
        self::assertSame(204, $this->sendAs($this->editor, 'DELETE', '/api/v1/notes/' . $keptNote['id'])->statusCode());
        $this->assertOkData($this->sendAs($this->owner, 'DELETE', '/api/v1/workspaces/' . $this->workspaceId . '/trash'));
        self::assertSame(204, $this->sendAs($this->owner, 'DELETE', '/api/v1/workspaces/' . $this->workspaceId)->statusCode());
    }

    public function testRetentionCleanupDeletesOnlyExpiredTrash(): void
    {
        $activeNote = $this->createNote($this->editor, $this->workspaceId, 'Active, untouched for a year');
        $archivedNote = $this->createNote($this->editor, $this->workspaceId, 'Archived long ago');
        $recentlyTrashedNote = $this->createNote($this->editor, $this->workspaceId, 'Trashed yesterday');
        $expiredNote = $this->createNote($this->editor, $this->workspaceId, 'Trashed 31 days ago');
        $this->assertOkData($this->sendAs($this->editor, 'POST', '/api/v1/notes/' . $archivedNote['id'] . '/archive'));
        self::assertSame(204, $this->sendAs($this->editor, 'DELETE', '/api/v1/notes/' . $recentlyTrashedNote['id'])->statusCode());
        self::assertSame(204, $this->sendAs($this->editor, 'DELETE', '/api/v1/notes/' . $expiredNote['id'])->statusCode());

        // Age everything: active and archived notes are old, but only Trash age counts.
        $this->database->exec('UPDATE notes SET created_at = UTC_TIMESTAMP() - INTERVAL 400 DAY, updated_at = UTC_TIMESTAMP() - INTERVAL 400 DAY');
        $this->database->exec('UPDATE notes SET archived_at = UTC_TIMESTAMP() - INTERVAL 400 DAY WHERE archived_at IS NOT NULL');
        $this->database->prepare('UPDATE notes SET trashed_at = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE id = :id')->execute(['id' => $recentlyTrashedNote['id']]);
        $this->database->prepare('UPDATE notes SET trashed_at = UTC_TIMESTAMP() - INTERVAL 31 DAY WHERE id = :id')->execute(['id' => $expiredNote['id']]);

        $notePurger = new NotePurger($this->database, new TagService($this->database), new AttachmentStorage($this->attachmentFolder));
        self::assertSame(1, $notePurger->countExpired(30));
        self::assertSame(1, $notePurger->purgeExpired(30));

        $remainingNoteIds = array_map('strval', $this->database->query('SELECT id FROM notes')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertEqualsCanonicalizing([$activeNote['id'], $archivedNote['id'], $recentlyTrashedNote['id']], $remainingNoteIds);
        self::assertSame(0, $notePurger->purgeExpired(30), 'Running again deletes nothing more.');
    }

    public function testTheApiCleansUpExpiredTrashAtMostOncePerHour(): void
    {
        $firstExpiredNote = $this->createNote($this->editor, $this->workspaceId, 'Trashed long ago');
        $secondExpiredNote = $this->createNote($this->editor, $this->workspaceId, 'Also trashed long ago');
        $activeNote = $this->createNote($this->editor, $this->workspaceId, 'Still in use');
        foreach ([$firstExpiredNote, $secondExpiredNote] as $expiredNote) {
            self::assertSame(204, $this->sendAs($this->editor, 'DELETE', '/api/v1/notes/' . $expiredNote['id'])->statusCode());
        }
        $this->database->prepare('UPDATE notes SET trashed_at = UTC_TIMESTAMP() - INTERVAL 31 DAY WHERE id = :id')->execute(['id' => $firstExpiredNote['id']]);

        // An anonymous request never triggers housekeeping.
        $this->send('GET', '/api/v1/health');
        self::assertNull($this->application->runHousekeeping());
        self::assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM notes'));

        // The first signed-in request deletes the expired note and records it in the audit log.
        $this->getAs($this->reader, '/api/v1/workspaces/' . $this->workspaceId . '/notes');
        self::assertSame(1, $this->application->runHousekeeping());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM notes WHERE id = :id', ['id' => $firstExpiredNote['id']]));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type = 'trash.expired_purged'"));
        self::assertNull($this->application->runHousekeeping(), 'Housekeeping runs once per request at most.');

        // Within the hour, later requests leave newly expired notes for the next run.
        $this->database->prepare('UPDATE notes SET trashed_at = UTC_TIMESTAMP() - INTERVAL 31 DAY WHERE id = :id')->execute(['id' => $secondExpiredNote['id']]);
        $this->getAs($this->reader, '/api/v1/workspaces/' . $this->workspaceId . '/notes');
        self::assertNull($this->application->runHousekeeping());
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM notes WHERE id = :id', ['id' => $secondExpiredNote['id']]));

        // Once the hour has passed, the next signed-in request runs it again.
        $this->database->exec('UPDATE maintenance_runs SET last_started_at = UTC_TIMESTAMP() - INTERVAL 61 MINUTE');
        $this->getAs($this->reader, '/api/v1/workspaces/' . $this->workspaceId . '/notes');
        self::assertSame(1, $this->application->runHousekeeping());
        $remainingNoteIds = array_map('strval', $this->database->query('SELECT id FROM notes')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame([$activeNote['id']], $remainingNoteIds);
    }

    public function testOutsidersCannotSeeOrTouchTheTrash(): void
    {
        $mallory = $this->signedInUser('mallory');
        $administrator = $this->signedInUser('sysadmin', true);
        $note = $this->createNote($this->editor, $this->workspaceId, 'Confidential');
        self::assertSame(204, $this->sendAs($this->editor, 'DELETE', '/api/v1/notes/' . $note['id'])->statusCode());

        foreach ([$mallory, $administrator] as $outsiderCredentials) {
            $this->assertError($this->sendAs($outsiderCredentials, 'GET', '/api/v1/workspaces/' . $this->workspaceId . '/trash'), 404, 'not_found');
            $this->assertError($this->sendAs($outsiderCredentials, 'DELETE', '/api/v1/workspaces/' . $this->workspaceId . '/trash'), 404, 'not_found');
            $this->assertError($this->sendAs($outsiderCredentials, 'POST', '/api/v1/trash/' . $note['id'] . '/restore'), 404, 'not_found');
            $this->assertError($this->sendAs($outsiderCredentials, 'DELETE', '/api/v1/trash/' . $note['id']), 404, 'not_found');
            $this->assertError($this->sendAs($outsiderCredentials, 'POST', '/api/v1/notes/' . $note['id'] . '/archive'), 404, 'not_found');
        }
        $this->assertError($this->sendAs($mallory, 'POST', '/api/v1/trash/not-a-uuid/restore'), 404, 'not_found');
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM notes WHERE trashed_at IS NOT NULL'));
    }

    public function testRetentionDaysMustBeSensible(): void
    {
        $this->expectException(\Muninn\Api\Config\ConfigException::class);
        $this->buildApplication(['trash' => ['retention_days' => 0]]);
    }

    /**
     * Titles in the workspace's note list for the given query parameters.
     *
     * @param array<string, string> $queryParameters
     * @return list<string>
     */
    private function listedTitles(array $queryParameters): array
    {
        $listData = $this->assertOkData($this->getAs($this->reader, '/api/v1/workspaces/' . $this->workspaceId . '/notes', $queryParameters));

        return array_column($listData['notes'], 'title');
    }
}
