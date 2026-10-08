<?php

declare(strict_types=1);

namespace Muninn\Api\Notes;

use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Workspaces\WorkspaceMembership;
use PDO;

/**
 * Version history of notes (decisions D009 and D037).
 *
 * The note's current state lives in `notes`; `note_versions` holds earlier states. Whenever a
 * save replaces a state, NoteService hands the old state to keepReplacedState() first. Rules:
 * - Saves by the same person within MERGE_WINDOW_MINUTES of the last kept version count as one
 *   change: only the state from before that burst of saves is kept. A save by someone else
 *   always keeps the previous state, so nobody's work is lost to merging.
 * - At most MAXIMUM_VERSIONS earlier states are kept per note; the oldest go first.
 * - Restoring a version is an ordinary save of that old state, so it is itself undoable.
 *
 * Every read is scoped to the caller's workspace membership, like every other note query.
 */
final class NoteHistory
{
    /** Most earlier versions kept per note (D009). */
    public const MAXIMUM_VERSIONS = 100;

    /** Saves by the same person this close together count as one version (D037). */
    public const MERGE_WINDOW_MINUTES = 10;

    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * Stores the note state a save is about to replace. Must run inside the save's transaction,
     * with the note row locked, before the note is updated.
     *
     * @param array<string, mixed> $replacedNoteRow The full `notes` row as it is before the save.
     * @param list<string> $replacedTagNames The note's tag names before the save.
     * @param bool $neverMerge True for restores, which must always be undoable.
     */
    public function keepReplacedState(
        WorkspaceMembership $membership,
        array $replacedNoteRow,
        array $replacedTagNames,
        bool $neverMerge = false,
    ): void {
        $noteId = (string) $replacedNoteRow['id'];
        if (!$neverMerge && $this->continuesRecentBurstOfSaves($membership, $replacedNoteRow)) {
            return;
        }

        $insertStatement = $this->database->prepare(
            'INSERT INTO note_versions (id, note_id, workspace_id, revision, title, content, folder_id, tag_names,
                                        edited_by_user_id, edited_at, replaced_by_user_id, replaced_at)
             VALUES (:id, :note_id, :workspace_id, :revision, :title, :content, :folder_id, :tag_names,
                     :edited_by_user_id, :edited_at, :replaced_by_user_id, UTC_TIMESTAMP())'
        );
        $insertStatement->execute([
            'id' => UuidGenerator::generate(),
            'note_id' => $noteId,
            'workspace_id' => $membership->workspaceId,
            'revision' => (int) $replacedNoteRow['revision'],
            'title' => (string) $replacedNoteRow['title'],
            'content' => (string) $replacedNoteRow['content'],
            'folder_id' => $replacedNoteRow['folder_id'],
            'tag_names' => json_encode(array_values($replacedTagNames), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'edited_by_user_id' => (string) $replacedNoteRow['updated_by_user_id'],
            'edited_at' => (string) $replacedNoteRow['updated_at'],
            'replaced_by_user_id' => $membership->userId,
        ]);

        $this->dropVersionsBeyondLimit($noteId);
    }

    /**
     * Number of earlier versions a note has. The note must already be authorized.
     */
    public function countVersions(WorkspaceMembership $membership, string $noteId): int
    {
        $countStatement = $this->database->prepare(
            'SELECT COUNT(*) FROM note_versions WHERE note_id = :note_id AND workspace_id = :workspace_id'
        );
        $countStatement->execute(['note_id' => $noteId, 'workspace_id' => $membership->workspaceId]);

        return (int) $countStatement->fetchColumn();
    }

    /**
     * Lists a note's earlier versions, newest first, without their content.
     * The note must already be authorized.
     *
     * @return list<array<string, mixed>>
     */
    public function listVersions(WorkspaceMembership $membership, string $noteId): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT note_versions.id, note_versions.revision, note_versions.title, note_versions.edited_at,
                    editor.display_name AS edited_by_name, CHAR_LENGTH(note_versions.content) AS content_length
             FROM note_versions
             JOIN users AS editor ON editor.id = note_versions.edited_by_user_id
             WHERE note_versions.note_id = :note_id AND note_versions.workspace_id = :workspace_id
             ORDER BY note_versions.revision DESC'
        );
        $selectStatement->execute(['note_id' => $noteId, 'workspace_id' => $membership->workspaceId]);

        return array_map(static fn (array $versionRow): array => [
            'id' => $versionRow['id'],
            'revision' => (int) $versionRow['revision'],
            'title' => $versionRow['title'],
            'content_length' => (int) $versionRow['content_length'],
            'edited_at' => UtcTimestamp::toIso((string) $versionRow['edited_at']),
            'edited_by' => $versionRow['edited_by_name'],
        ], $selectStatement->fetchAll());
    }

    /**
     * Returns one earlier version of a note, with its content. The note must already be
     * authorized; the version must belong to that note.
     *
     * @return array<string, mixed>
     * @throws HttpException 404 when it is not a version of that note.
     */
    public function findVersion(WorkspaceMembership $membership, string $noteId, string $versionId): array
    {
        if (!UuidGenerator::isValid($versionId)) {
            throw HttpException::notFound();
        }
        $selectStatement = $this->database->prepare(
            'SELECT note_versions.*, editor.display_name AS edited_by_name, folders.name AS folder_name
             FROM note_versions
             JOIN users AS editor ON editor.id = note_versions.edited_by_user_id
             LEFT JOIN folders ON folders.id = note_versions.folder_id
             WHERE note_versions.id = :id AND note_versions.note_id = :note_id
               AND note_versions.workspace_id = :workspace_id'
        );
        $selectStatement->execute(['id' => $versionId, 'note_id' => $noteId, 'workspace_id' => $membership->workspaceId]);
        $versionRow = $selectStatement->fetch();
        if ($versionRow === false) {
            throw HttpException::notFound();
        }

        return [
            'id' => $versionRow['id'],
            'note_id' => $versionRow['note_id'],
            'revision' => (int) $versionRow['revision'],
            'title' => $versionRow['title'],
            'content' => $versionRow['content'],
            'folder_id' => $versionRow['folder_id'],
            'folder_name' => $versionRow['folder_name'],
            'tags' => self::decodeTagNames((string) $versionRow['tag_names']),
            'edited_at' => UtcTimestamp::toIso((string) $versionRow['edited_at']),
            'edited_by' => $versionRow['edited_by_name'],
        ];
    }

    /**
     * True when this save only continues the caller's own recent burst of saves, so the state it
     * replaces need not be kept: the caller also made that state, and the state from before
     * their burst is already the newest kept version (stored by them, recently).
     *
     * @param array<string, mixed> $replacedNoteRow
     */
    private function continuesRecentBurstOfSaves(WorkspaceMembership $membership, array $replacedNoteRow): bool
    {
        if ((string) $replacedNoteRow['updated_by_user_id'] !== $membership->userId) {
            return false;
        }
        $latestVersionStatement = $this->database->prepare(
            'SELECT replaced_by_user_id, replaced_at >= UTC_TIMESTAMP() - INTERVAL ' . self::MERGE_WINDOW_MINUTES . ' MINUTE AS is_recent
             FROM note_versions WHERE note_id = :note_id ORDER BY revision DESC LIMIT 1'
        );
        $latestVersionStatement->execute(['note_id' => $replacedNoteRow['id']]);
        $latestVersionRow = $latestVersionStatement->fetch();

        return $latestVersionRow !== false
            && (string) $latestVersionRow['replaced_by_user_id'] === $membership->userId
            && (int) $latestVersionRow['is_recent'] === 1;
    }

    /** Deletes a note's oldest versions beyond MAXIMUM_VERSIONS. */
    private function dropVersionsBeyondLimit(string $noteId): void
    {
        // The derived table lets MariaDB read note_versions while deleting from it.
        $deleteStatement = $this->database->prepare(
            'DELETE FROM note_versions
             WHERE note_id = :note_id
               AND revision < (SELECT oldest_kept.revision FROM (
                       SELECT revision FROM note_versions WHERE note_id = :note_id_again
                       ORDER BY revision DESC LIMIT 1 OFFSET ' . (self::MAXIMUM_VERSIONS - 1) . '
                   ) AS oldest_kept)'
        );
        $deleteStatement->execute(['note_id' => $noteId, 'note_id_again' => $noteId]);
    }

    /**
     * @return list<string>
     */
    private static function decodeTagNames(string $tagNamesJson): array
    {
        $decodedTagNames = json_decode($tagNamesJson, true);

        return is_array($decodedTagNames) ? array_values(array_map('strval', $decodedTagNames)) : [];
    }
}
