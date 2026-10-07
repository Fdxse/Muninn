<?php

declare(strict_types=1);

namespace Muninn\Api\Notes;

use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Workspaces\WorkspaceMembership;
use PDO;

/**
 * Note data operations. Like WorkspaceService it never decides on its own who may act:
 * every method takes the WorkspaceMembership that WorkspaceAuthorizer produced, and every
 * query is scoped to that membership's workspace, so a note in another workspace can never
 * be read or changed even if its ID is known.
 */
final class NoteService
{
    /** Most notes a list returns. Paging arrives with search and folders if it is needed. */
    private const LIST_LIMIT = 500;

    /** Length of the plain-text preview in note lists. */
    private const EXCERPT_LENGTH = 160;

    public function __construct(private readonly PDO $database)
    {
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
     * @return list<array<string, mixed>>
     */
    public function listInWorkspace(WorkspaceMembership $membership): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT notes.id, notes.title, LEFT(notes.content, ' . self::EXCERPT_LENGTH . ') AS excerpt,
                    notes.revision, notes.created_at, notes.updated_at, updater.display_name AS updated_by_name
             FROM notes
             JOIN users AS updater ON updater.id = notes.updated_by_user_id
             WHERE notes.workspace_id = :workspace_id AND notes.trashed_at IS NULL
             ORDER BY notes.updated_at DESC, notes.id
             LIMIT ' . self::LIST_LIMIT
        );
        $selectStatement->execute(['workspace_id' => $membership->workspaceId]);

        return array_map(static fn (array $noteRow): array => [
            'id' => $noteRow['id'],
            'title' => $noteRow['title'],
            // Collapse whitespace so the preview is one readable line.
            'excerpt' => trim((string) preg_replace('/\s+/u', ' ', (string) $noteRow['excerpt'])),
            'revision' => (int) $noteRow['revision'],
            'created_at' => UtcTimestamp::toIso((string) $noteRow['created_at']),
            'updated_at' => UtcTimestamp::toIso((string) $noteRow['updated_at']),
            'updated_by' => $noteRow['updated_by_name'],
        ], $selectStatement->fetchAll());
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
            'SELECT notes.*, creator.display_name AS created_by_name, updater.display_name AS updated_by_name
             FROM notes
             JOIN users AS creator ON creator.id = notes.created_by_user_id
             JOIN users AS updater ON updater.id = notes.updated_by_user_id
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
            'title' => $noteRow['title'],
            'content' => $noteRow['content'],
            'revision' => (int) $noteRow['revision'],
            'created_at' => UtcTimestamp::toIso((string) $noteRow['created_at']),
            'created_by' => $noteRow['created_by_name'],
            'updated_at' => UtcTimestamp::toIso((string) $noteRow['updated_at']),
            'updated_by' => $noteRow['updated_by_name'],
        ];
    }

    /** Creates a note in the membership's workspace and returns its ID. */
    public function create(WorkspaceMembership $membership, string $title, string $content): string
    {
        $newNoteId = UuidGenerator::generate();
        $insertStatement = $this->database->prepare(
            'INSERT INTO notes (id, workspace_id, title, content, revision, created_by_user_id, updated_by_user_id, created_at, updated_at)
             VALUES (:id, :workspace_id, :title, :content, 1, :user_id, :user_id_again, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $insertStatement->execute([
            'id' => $newNoteId,
            'workspace_id' => $membership->workspaceId,
            'title' => $title,
            'content' => $content,
            'user_id' => $membership->userId,
            'user_id_again' => $membership->userId,
        ]);

        return $newNoteId;
    }

    /**
     * Updates a note when it is still at $expectedRevision (optimistic concurrency, D010).
     * A null title or content leaves that field unchanged.
     *
     * @throws HttpException 404 when the note is gone, 409 when someone saved in the meantime.
     */
    public function update(WorkspaceMembership $membership, string $noteId, int $expectedRevision, ?string $newTitle, ?string $newContent): void
    {
        $updateStatement = $this->database->prepare(
            'UPDATE notes
             SET title = COALESCE(:title, title),
                 content = COALESCE(:content, content),
                 revision = revision + 1,
                 updated_by_user_id = :user_id,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND workspace_id = :workspace_id AND trashed_at IS NULL AND revision = :expected_revision'
        );
        $updateStatement->execute([
            'title' => $newTitle,
            'content' => $newContent,
            'user_id' => $membership->userId,
            'id' => $noteId,
            'workspace_id' => $membership->workspaceId,
            'expected_revision' => $expectedRevision,
        ]);

        if ($updateStatement->rowCount() === 1) {
            return;
        }

        // Nothing changed: either the note vanished or another save got there first.
        $this->find($membership, $noteId);
        throw HttpException::conflict(
            'revision_conflict',
            'This note was changed by someone else since you opened it. Reload it to see the latest version.',
        );
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
}
