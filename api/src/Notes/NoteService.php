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

    /** Folder filter value meaning "notes that are in no folder". */
    public const FOLDER_FILTER_NONE = 'none';

    public function __construct(
        private readonly PDO $database,
        private readonly TagService $tagService,
    ) {
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
     *
     * @param string|null $folderFilter A folder ID, FOLDER_FILTER_NONE, or null for all folders.
     * @param string|null $tagFilter    Only notes carrying this tag (case-insensitive), or null.
     * @return list<array<string, mixed>>
     */
    public function listInWorkspace(WorkspaceMembership $membership, ?string $folderFilter = null, ?string $tagFilter = null): array
    {
        $whereConditions = ['notes.workspace_id = :workspace_id', 'notes.trashed_at IS NULL'];
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
            'SELECT notes.id, notes.folder_id, notes.title, LEFT(notes.content, ' . self::EXCERPT_LENGTH . ') AS excerpt,
                    notes.revision, notes.created_at, notes.updated_at, updater.display_name AS updated_by_name
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
            // Collapse whitespace so the preview is one readable line.
            'excerpt' => trim((string) preg_replace('/\s+/u', ' ', (string) $noteRow['excerpt'])),
            'tags' => $tagNamesByNote[$noteRow['id']] ?? [],
            'revision' => (int) $noteRow['revision'],
            'created_at' => UtcTimestamp::toIso((string) $noteRow['created_at']),
            'updated_at' => UtcTimestamp::toIso((string) $noteRow['updated_at']),
            'updated_by' => $noteRow['updated_by_name'],
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
     * revision check and transaction, so a refused save changes nothing at all.
     *
     * @throws HttpException 404 when the note is gone, 409 when someone saved in the meantime,
     *                       422 when the folder is not a folder of the same workspace.
     */
    public function update(WorkspaceMembership $membership, string $noteId, int $expectedRevision, NoteInput $noteInput): void
    {
        $this->inTransaction(function () use ($membership, $noteId, $expectedRevision, $noteInput): void {
            $this->requireFolderInWorkspace($membership, $noteInput);
            $updateStatement = $this->database->prepare(
                'UPDATE notes
                 SET title = COALESCE(:title, title),
                     content = COALESCE(:content, content),
                     folder_id = IF(:change_folder = 1, :folder_id, folder_id),
                     revision = revision + 1,
                     updated_by_user_id = :user_id,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND workspace_id = :workspace_id AND trashed_at IS NULL AND revision = :expected_revision'
            );
            $updateStatement->execute([
                'title' => $noteInput->title,
                'content' => $noteInput->content,
                'change_folder' => $noteInput->folderIsSet ? 1 : 0,
                'folder_id' => $noteInput->folderId,
                'user_id' => $membership->userId,
                'id' => $noteId,
                'workspace_id' => $membership->workspaceId,
                'expected_revision' => $expectedRevision,
            ]);

            if ($updateStatement->rowCount() !== 1) {
                // Nothing changed: either the note vanished (404 from find) or another save got there first.
                $this->find($membership, $noteId);
                throw HttpException::conflict(
                    'revision_conflict',
                    'This note was changed by someone else since you opened it. Reload it to see the latest version.',
                );
            }

            if ($noteInput->tagNames !== null) {
                $this->tagService->replaceNoteTags($membership, $noteId, $noteInput->tagNames);
            }
        });
    }

    /**
     * Moves a note to Trash. Trashed notes disappear from every endpoint; Trash listing,
     * restore and permanent deletion arrive in Week 4.
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
}
