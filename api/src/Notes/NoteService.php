<?php

declare(strict_types=1);

namespace Muninn\Api\Notes;

use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Tags\TagService;
use Muninn\Api\Workspaces\WorkspaceMembership;
use PDO;
use Throwable;

/**
 * Note data operations. Like WorkspaceService it never decides on its own who may act:
 * every method takes the WorkspaceMembership that WorkspaceAuthorizer produced, and every
 * query is scoped to that membership's workspace, so a note in another workspace can never
 * be read or changed even if its ID is known.
 */
final class NoteService
{
    /** Most notes a list returns. Paging arrives with search if it is needed. */
    private const LIST_LIMIT = 500;

    /** Length of the plain-text preview in note lists. */
    private const EXCERPT_LENGTH = 160;

    /**
     * Characters of Markdown read for the preview. More than EXCERPT_LENGTH, because the
     * Markdown markers (link addresses especially) disappear from the preview (D051).
     */
    private const EXCERPT_SOURCE_LENGTH = 600;

    /** Folder filter value meaning "notes that are in no folder". */
    public const FOLDER_FILTER_NONE = 'none';

    public function __construct(
        private readonly PDO $database,
        private readonly TagService $tagService,
        private readonly NoteHistory $noteHistory,
    ) {
    }

    /**
     * Finds which workspace a trashed note belongs to, so the caller's access to that workspace
     * can be checked before the note is restored or deleted for good. Returns null for unknown,
     * malformed and active IDs.
     */
    public function findWorkspaceIdOfTrashedNote(string $noteId): ?string
    {
        if (!UuidGenerator::isValid($noteId)) {
            return null;
        }
        $selectStatement = $this->database->prepare('SELECT workspace_id FROM notes WHERE id = :id AND trashed_at IS NOT NULL');
        $selectStatement->execute(['id' => $noteId]);
        $workspaceId = $selectStatement->fetchColumn();

        return $workspaceId === false ? null : (string) $workspaceId;
    }

    /**
     * Finds which workspace an active (not trashed) note belongs to, so the caller's access to
     * that workspace can be checked. Returns null for unknown, malformed or trashed IDs.
     */
    public function findWorkspaceIdOfActiveNote(string $noteId): ?string
    {
        if (!UuidGenerator::isValid($noteId)) {
            return null;
        }
        $selectStatement = $this->database->prepare('SELECT workspace_id FROM notes WHERE id = :id AND trashed_at IS NULL');
        $selectStatement->execute(['id' => $noteId]);
        $workspaceId = $selectStatement->fetchColumn();

        return $workspaceId === false ? null : (string) $workspaceId;
    }

    /**
     * Lists the active notes of a workspace, most recently changed first, without content.
     * Archived notes are listed only when $listArchived is true, and then only they are listed.
     *
     * @param string|null $folderFilter A folder ID, FOLDER_FILTER_NONE, or null for all folders.
     * @param string|null $tagFilter    Only notes carrying this tag (case-insensitive), or null.
     * @return list<array<string, mixed>>
     */
    public function listInWorkspace(
        WorkspaceMembership $membership,
        ?string $folderFilter = null,
        ?string $tagFilter = null,
        bool $listArchived = false,
    ): array {
        $whereConditions = [
            'notes.workspace_id = :workspace_id',
            'notes.trashed_at IS NULL',
            $listArchived ? 'notes.archived_at IS NOT NULL' : 'notes.archived_at IS NULL',
        ];
        $queryParameters = ['workspace_id' => $membership->workspaceId];

        if ($folderFilter === self::FOLDER_FILTER_NONE) {
            $whereConditions[] = 'notes.folder_id IS NULL';
        } elseif ($folderFilter !== null) {
            $whereConditions[] = 'notes.folder_id = :folder_id';
            $queryParameters['folder_id'] = $folderFilter;
        }
        if ($tagFilter !== null) {
            // The tag must be one of THIS workspace's tags; tag names never cross workspaces (D034).
            $whereConditions[] = 'EXISTS (SELECT 1 FROM note_tags JOIN tags ON tags.id = note_tags.tag_id
                                          WHERE note_tags.note_id = notes.id AND tags.workspace_id = notes.workspace_id
                                            AND tags.name = :tag_name)';
            $queryParameters['tag_name'] = $tagFilter;
        }

        $selectStatement = $this->database->prepare(
            'SELECT notes.id, notes.folder_id, notes.title, LEFT(notes.content, ' . self::EXCERPT_SOURCE_LENGTH . ') AS excerpt,
                    CHAR_LENGTH(notes.content) > ' . self::EXCERPT_SOURCE_LENGTH . ' AS excerpt_source_cut,
                    notes.revision, notes.created_at, notes.updated_at, notes.archived_at,
                    updater.display_name AS updated_by_name
             FROM notes
             JOIN users AS updater ON updater.id = notes.updated_by_user_id
             WHERE ' . implode(' AND ', $whereConditions) . '
             ORDER BY notes.updated_at DESC, notes.id
             LIMIT ' . self::LIST_LIMIT
        );
        $selectStatement->execute($queryParameters);
        $noteRows = $selectStatement->fetchAll();
        $tagNamesByNote = $this->tagService->tagNamesByNote(array_map(static fn (array $noteRow): string => (string) $noteRow['id'], $noteRows));

        return array_map(static fn (array $noteRow): array => [
            'id' => $noteRow['id'],
            'folder_id' => $noteRow['folder_id'],
            'title' => $noteRow['title'],
            // Markdown markers removed, so the preview is one readable line (D051).
            'excerpt' => self::excerpt($noteRow),
            'tags' => $tagNamesByNote[$noteRow['id']] ?? [],
            'revision' => (int) $noteRow['revision'],
            'created_at' => UtcTimestamp::toIso((string) $noteRow['created_at']),
            'updated_at' => UtcTimestamp::toIso((string) $noteRow['updated_at']),
            'updated_by' => $noteRow['updated_by_name'],
            'archived_at' => UtcTimestamp::toIsoOrNull($noteRow['archived_at']),
        ], $noteRows);
    }

    /**
     * Returns one active note of the membership's workspace.
     *
     * @return array<string, mixed>
     * @throws HttpException 404 when it is not an active note of that workspace.
     */
    public function find(WorkspaceMembership $membership, string $noteId): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT notes.*, folders.name AS folder_name,
                    creator.display_name AS created_by_name, updater.display_name AS updated_by_name
             FROM notes
             JOIN users AS creator ON creator.id = notes.created_by_user_id
             JOIN users AS updater ON updater.id = notes.updated_by_user_id
             LEFT JOIN folders ON folders.id = notes.folder_id
             WHERE notes.id = :id AND notes.workspace_id = :workspace_id AND notes.trashed_at IS NULL'
        );
        $selectStatement->execute(['id' => $noteId, 'workspace_id' => $membership->workspaceId]);
        $noteRow = $selectStatement->fetch();
        if ($noteRow === false) {
            throw HttpException::notFound();
        }

        return [
            'id' => $noteRow['id'],
            'workspace_id' => $noteRow['workspace_id'],
            'folder_id' => $noteRow['folder_id'],
            'folder_name' => $noteRow['folder_name'],
            'title' => $noteRow['title'],
            'content' => $noteRow['content'],
            'tags' => $this->tagService->tagNamesByNote([$noteId])[$noteId] ?? [],
            'revision' => (int) $noteRow['revision'],
            'created_at' => UtcTimestamp::toIso((string) $noteRow['created_at']),
            'created_by' => $noteRow['created_by_name'],
            'updated_at' => UtcTimestamp::toIso((string) $noteRow['updated_at']),
            'updated_by' => $noteRow['updated_by_name'],
            'archived_at' => UtcTimestamp::toIsoOrNull($noteRow['archived_at']),
            // Shown as e.g. "History 34 / 100" (D009).
            'history_count' => $this->noteHistory->countVersions($membership, $noteId),
            'history_limit' => NoteHistory::MAXIMUM_VERSIONS,
        ];
    }

    /**
     * Creates a note in the membership's workspace and returns its ID.
     *
     * @throws HttpException 422 when the folder is not a folder of the same workspace.
     */
    public function create(WorkspaceMembership $membership, NoteInput $noteInput): string
    {
        $newNoteId = UuidGenerator::generate();

        $this->inTransaction(function () use ($membership, $noteInput, $newNoteId): void {
            $this->requireFolderInWorkspace($membership, $noteInput);
            $insertStatement = $this->database->prepare(
                'INSERT INTO notes (id, workspace_id, folder_id, title, content, revision, created_by_user_id, updated_by_user_id, created_at, updated_at)
                 VALUES (:id, :workspace_id, :folder_id, :title, :content, 1, :user_id, :user_id_again, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $insertStatement->execute([
                'id' => $newNoteId,
                'workspace_id' => $membership->workspaceId,
                'folder_id' => $noteInput->folderIsSet ? $noteInput->folderId : null,
                'title' => $noteInput->title ?? '',
                'content' => $noteInput->content ?? '',
                'user_id' => $membership->userId,
                'user_id_again' => $membership->userId,
            ]);

            if ($noteInput->tagNames !== null && $noteInput->tagNames !== []) {
                $this->tagService->replaceNoteTags($membership, $newNoteId, $noteInput->tagNames);
            }
        });

        return $newNoteId;
    }

    /**
     * Updates a note when it is still at $expectedRevision (optimistic concurrency, D010).
     * Fields left out of $noteInput stay unchanged. Folder and tag changes are part of the same
     * revision check and transaction, so a refused save changes nothing at all. The state the
     * save replaces is kept in the note's history (D037), unless nothing actually changed.
     *
     * @param bool $neverMergeHistory True when restoring a version: the replaced state is then
     *                                always kept, so the restore itself can be undone.
     * @throws HttpException 404 when the note is gone, 409 when someone saved in the meantime,
     *                       422 when the folder is not a folder of the same workspace.
     */
    public function update(
        WorkspaceMembership $membership,
        string $noteId,
        int $expectedRevision,
        NoteInput $noteInput,
        bool $neverMergeHistory = false,
    ): void {
        $this->inTransaction(function () use ($membership, $noteId, $expectedRevision, $noteInput, $neverMergeHistory): void {
            $this->requireFolderInWorkspace($membership, $noteInput);

            // Lock the note so the revision check, the history entry and the update are one step.
            $lockStatement = $this->database->prepare(
                'SELECT * FROM notes WHERE id = :id AND workspace_id = :workspace_id AND trashed_at IS NULL FOR UPDATE'
            );
            $lockStatement->execute(['id' => $noteId, 'workspace_id' => $membership->workspaceId]);
            $currentNoteRow = $lockStatement->fetch();
            if ($currentNoteRow === false) {
                throw HttpException::notFound();
            }
            if ((int) $currentNoteRow['revision'] !== $expectedRevision) {
                // Another save got there first: refuse instead of silently overwriting it.
                throw HttpException::conflict(
                    'revision_conflict',
                    'This note was changed by someone else since you opened it. Reload it to see the latest version.',
                );
            }

            $currentTagNames = $this->tagService->tagNamesByNote([$noteId])[$noteId] ?? [];
            if (self::changesSomething($currentNoteRow, $currentTagNames, $noteInput)) {
                $this->noteHistory->keepReplacedState($membership, $currentNoteRow, $currentTagNames, $neverMergeHistory);
            }

            $updateStatement = $this->database->prepare(
                'UPDATE notes
                 SET title = COALESCE(:title, title),
                     content = COALESCE(:content, content),
                     folder_id = IF(:change_folder = 1, :folder_id, folder_id),
                     revision = revision + 1,
                     updated_by_user_id = :user_id,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $updateStatement->execute([
                'title' => $noteInput->title,
                'content' => $noteInput->content,
                'change_folder' => $noteInput->folderIsSet ? 1 : 0,
                'folder_id' => $noteInput->folderId,
                'user_id' => $membership->userId,
                'id' => $noteId,
            ]);

            if ($noteInput->tagNames !== null) {
                $this->tagService->replaceNoteTags($membership, $noteId, $noteInput->tagNames);
            }
        });
    }

    /**
     * Moves an active note to the Archive, or back out of it (D048). Archived notes stay readable
     * and editable; they only leave the normal note list and, unless asked for, search.
     * This is not an edit of the note, so it neither changes the revision nor adds history.
     *
     * @throws HttpException 404 when it is not an active note of the workspace.
     */
    public function setArchived(WorkspaceMembership $membership, string $noteId, bool $archive): void
    {
        if ($archive) {
            // Archiving an already archived note keeps its original archive date.
            $archiveStatement = $this->database->prepare(
                'UPDATE notes SET archived_at = COALESCE(archived_at, UTC_TIMESTAMP()),
                                  archived_by_user_id = COALESCE(archived_by_user_id, :user_id)
                 WHERE id = :id AND workspace_id = :workspace_id AND trashed_at IS NULL'
            );
            $archiveStatement->execute(['user_id' => $membership->userId, 'id' => $noteId, 'workspace_id' => $membership->workspaceId]);
        } else {
            $unarchiveStatement = $this->database->prepare(
                'UPDATE notes SET archived_at = NULL, archived_by_user_id = NULL
                 WHERE id = :id AND workspace_id = :workspace_id AND trashed_at IS NULL'
            );
            $unarchiveStatement->execute(['id' => $noteId, 'workspace_id' => $membership->workspaceId]);
        }
        // rowCount() is 0 both for a missing note and for "already in that state", so check again.
        $this->find($membership, $noteId);
    }

    /**
     * Moves a note to Trash. Trashed notes disappear from every note endpoint and from search;
     * they are only seen in the workspace's Trash until restored or deleted for good (D012, D039).
     *
     * @throws HttpException 404 when it is not an active note of the workspace.
     */
    public function trash(WorkspaceMembership $membership, string $noteId): void
    {
        $trashStatement = $this->database->prepare(
            'UPDATE notes SET trashed_at = UTC_TIMESTAMP(), trashed_by_user_id = :user_id
             WHERE id = :id AND workspace_id = :workspace_id AND trashed_at IS NULL'
        );
        $trashStatement->execute([
            'user_id' => $membership->userId,
            'id' => $noteId,
            'workspace_id' => $membership->workspaceId,
        ]);
        if ($trashStatement->rowCount() !== 1) {
            throw HttpException::notFound();
        }
    }

    /**
     * Brings a trashed note back. It returns to where it was (its folder, or the Archive).
     *
     * @throws HttpException 404 when it is not a trashed note of the workspace.
     */
    public function restoreFromTrash(WorkspaceMembership $membership, string $noteId): void
    {
        $restoreStatement = $this->database->prepare(
            'UPDATE notes SET trashed_at = NULL, trashed_by_user_id = NULL
             WHERE id = :id AND workspace_id = :workspace_id AND trashed_at IS NOT NULL'
        );
        $restoreStatement->execute(['id' => $noteId, 'workspace_id' => $membership->workspaceId]);
        if ($restoreStatement->rowCount() !== 1) {
            throw HttpException::notFound();
        }
    }

    /**
     * Lists the workspace's Trash, most recently deleted first, without content. Each note says
     * when it will be deleted for good, $retentionDays after it was moved to Trash.
     *
     * @return list<array<string, mixed>>
     */
    public function listTrash(WorkspaceMembership $membership, int $retentionDays): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT notes.id, notes.title, LEFT(notes.content, ' . self::EXCERPT_SOURCE_LENGTH . ') AS excerpt,
                    CHAR_LENGTH(notes.content) > ' . self::EXCERPT_SOURCE_LENGTH . ' AS excerpt_source_cut, notes.trashed_at,
                    notes.trashed_at + INTERVAL :retention_days DAY AS purge_after,
                    trasher.display_name AS trashed_by_name
             FROM notes
             LEFT JOIN users AS trasher ON trasher.id = notes.trashed_by_user_id
             WHERE notes.workspace_id = :workspace_id AND notes.trashed_at IS NOT NULL
             ORDER BY notes.trashed_at DESC, notes.id
             LIMIT ' . self::LIST_LIMIT
        );
        $selectStatement->execute(['retention_days' => $retentionDays, 'workspace_id' => $membership->workspaceId]);

        return array_map(static fn (array $noteRow): array => [
            'id' => $noteRow['id'],
            'title' => $noteRow['title'],
            'excerpt' => self::excerpt($noteRow),
            'trashed_at' => UtcTimestamp::toIso((string) $noteRow['trashed_at']),
            'trashed_by' => $noteRow['trashed_by_name'],
            'purge_after' => UtcTimestamp::toIso((string) $noteRow['purge_after']),
        ], $selectStatement->fetchAll());
    }

    /**
     * True when $noteInput would change the note's title, content, folder or tags.
     *
     * @param array<string, mixed> $currentNoteRow
     * @param list<string> $currentTagNames
     */
    private static function changesSomething(array $currentNoteRow, array $currentTagNames, NoteInput $noteInput): bool
    {
        if ($noteInput->title !== null && $noteInput->title !== (string) $currentNoteRow['title']) {
            return true;
        }
        if ($noteInput->content !== null && $noteInput->content !== (string) $currentNoteRow['content']) {
            return true;
        }
        if ($noteInput->folderIsSet && $noteInput->folderId !== $currentNoteRow['folder_id']) {
            return true;
        }
        if ($noteInput->tagNames !== null) {
            // Tag names are unique per workspace ignoring case, so compare them that way.
            $normaliseTagList = static function (array $tagNames): array {
                $lowercaseNames = array_map('mb_strtolower', $tagNames);
                sort($lowercaseNames);

                return $lowercaseNames;
            };

            return $normaliseTagList($noteInput->tagNames) !== $normaliseTagList($currentTagNames);
        }

        return false;
    }

    /**
     * Checks that a folder named in the input belongs to the note's own workspace, so a note can
     * never be filed into (or reveal the existence of) another workspace's folder. The row is
     * locked until the transaction ends, so the folder cannot be deleted half-way through a save.
     *
     * @throws HttpException 422 for any other folder ID, existing elsewhere or not at all.
     */
    private function requireFolderInWorkspace(WorkspaceMembership $membership, NoteInput $noteInput): void
    {
        if (!$noteInput->folderIsSet || $noteInput->folderId === null) {
            return;
        }
        $folderExists = false;
        if (UuidGenerator::isValid($noteInput->folderId)) {
            $selectStatement = $this->database->prepare(
                'SELECT 1 FROM folders WHERE id = :id AND workspace_id = :workspace_id LOCK IN SHARE MODE'
            );
            $selectStatement->execute(['id' => $noteInput->folderId, 'workspace_id' => $membership->workspaceId]);
            $folderExists = $selectStatement->fetchColumn() !== false;
        }
        if (!$folderExists) {
            throw HttpException::validation(['folder_id' => 'This folder does not exist in this workspace.']);
        }
    }

    /**
     * Runs $work in a transaction, rolling back on any failure.
     *
     * @param callable(): void $work
     */
    private function inTransaction(callable $work): void
    {
        $this->database->beginTransaction();
        try {
            $work();
            $this->database->commit();
        } catch (Throwable $workFailure) {
            $this->database->rollBack();
            throw $workFailure;
        }
    }

    /**
     * The plain-text preview of a list row that selected `title`, `excerpt` (the start of the
     * content) and `excerpt_source_cut` (whether the content goes on beyond it).
     *
     * @param array<string, mixed> $noteRow
     */
    private static function excerpt(array $noteRow): string
    {
        return MarkdownExcerpt::preview((string) $noteRow['excerpt'], self::EXCERPT_LENGTH, (bool) $noteRow['excerpt_source_cut'], (string) $noteRow['title']);
    }
}
