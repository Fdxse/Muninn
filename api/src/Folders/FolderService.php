<?php

declare(strict_types=1);

namespace Muninn\Api\Folders;

use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Workspaces\WorkspaceMembership;
use PDO;
use PDOException;

/**
 * Folder data operations (decision D033: flat folders, one level per workspace).
 *
 * Like NoteService it never decides on its own who may act: every method takes the
 * WorkspaceMembership produced by WorkspaceAuthorizer and scopes every query to that workspace,
 * so a folder of another workspace can never be seen, used or changed, even by its ID.
 */
final class FolderService
{
    /** MariaDB/MySQL SQLSTATE for a unique-key violation. */
    private const SQLSTATE_INTEGRITY_VIOLATION = '23000';

    /** More folders than this in one flat list stops being useful; it also bounds the response. */
    private const MAXIMUM_FOLDERS_PER_WORKSPACE = 200;

    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * Finds which workspace a folder belongs to, so the caller's access to that workspace can be
     * checked. Returns null for unknown or malformed IDs.
     */
    public function findWorkspaceIdOfFolder(string $folderId): ?string
    {
        if (!UuidGenerator::isValid($folderId)) {
            return null;
        }
        $selectStatement = $this->database->prepare('SELECT workspace_id FROM folders WHERE id = :id');
        $selectStatement->execute(['id' => $folderId]);
        $workspaceId = $selectStatement->fetchColumn();

        return $workspaceId === false ? null : (string) $workspaceId;
    }

    /**
     * Lists the workspace's folders alphabetically, each with the number of active (not trashed)
     * notes in it. Trashed notes are not counted: they are invisible until Week 4's Trash view.
     *
     * @return list<array<string, mixed>>
     */
    public function listInWorkspace(WorkspaceMembership $membership): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT folders.id, folders.name, folders.created_at, folders.updated_at,
                    COUNT(notes.id) AS note_count
             FROM folders
             LEFT JOIN notes ON notes.folder_id = folders.id AND notes.trashed_at IS NULL
             WHERE folders.workspace_id = :workspace_id
             GROUP BY folders.id, folders.name, folders.created_at, folders.updated_at
             ORDER BY folders.name, folders.id'
        );
        $selectStatement->execute(['workspace_id' => $membership->workspaceId]);

        return array_map(self::toPublicArray(...), $selectStatement->fetchAll());
    }

    /**
     * Returns one folder of the membership's workspace.
     *
     * @return array<string, mixed>
     * @throws HttpException 404 when it is not a folder of that workspace.
     */
    public function find(WorkspaceMembership $membership, string $folderId): array
    {
        foreach ($this->listInWorkspace($membership) as $folder) {
            if ($folder['id'] === $folderId) {
                return $folder;
            }
        }
        throw HttpException::notFound();
    }

    /**
     * Creates a folder and returns its ID.
     *
     * @throws HttpException 409 when the name is taken or the workspace has too many folders.
     */
    public function create(WorkspaceMembership $membership, string $folderName): string
    {
        $countStatement = $this->database->prepare('SELECT COUNT(*) FROM folders WHERE workspace_id = :workspace_id');
        $countStatement->execute(['workspace_id' => $membership->workspaceId]);
        if ((int) $countStatement->fetchColumn() >= self::MAXIMUM_FOLDERS_PER_WORKSPACE) {
            throw HttpException::conflict('folder_limit', 'This workspace already has the maximum of ' . self::MAXIMUM_FOLDERS_PER_WORKSPACE . ' folders.');
        }

        $newFolderId = UuidGenerator::generate();
        $insertStatement = $this->database->prepare(
            'INSERT INTO folders (id, workspace_id, name, created_by_user_id, created_at, updated_at)
             VALUES (:id, :workspace_id, :name, :user_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $this->runRefusingDuplicateNames(fn () => $insertStatement->execute([
            'id' => $newFolderId,
            'workspace_id' => $membership->workspaceId,
            'name' => $folderName,
            'user_id' => $membership->userId,
        ]));

        return $newFolderId;
    }

    /**
     * Renames a folder of the membership's workspace.
     *
     * @throws HttpException 404 when it is not a folder of that workspace, 409 when the name is taken.
     */
    public function rename(WorkspaceMembership $membership, string $folderId, string $newName): void
    {
        $this->find($membership, $folderId);
        $updateStatement = $this->database->prepare(
            'UPDATE folders SET name = :name, updated_at = UTC_TIMESTAMP() WHERE id = :id AND workspace_id = :workspace_id'
        );
        $this->runRefusingDuplicateNames(fn () => $updateStatement->execute([
            'name' => $newName,
            'id' => $folderId,
            'workspace_id' => $membership->workspaceId,
        ]));
    }

    /**
     * Deletes a folder. Its notes (Trash included) are not touched except that they move to
     * "No folder", which the foreign key's ON DELETE SET NULL does in the same statement (D033).
     *
     * @throws HttpException 404 when it is not a folder of that workspace.
     */
    public function delete(WorkspaceMembership $membership, string $folderId): void
    {
        $deleteStatement = $this->database->prepare('DELETE FROM folders WHERE id = :id AND workspace_id = :workspace_id');
        $deleteStatement->execute(['id' => $folderId, 'workspace_id' => $membership->workspaceId]);
        if ($deleteStatement->rowCount() !== 1) {
            throw HttpException::notFound();
        }
    }

    /**
     * Runs an insert/update and turns a unique-key clash on (workspace, name) into a 409.
     *
     * @param callable(): mixed $databaseWrite
     */
    private function runRefusingDuplicateNames(callable $databaseWrite): void
    {
        try {
            $databaseWrite();
        } catch (PDOException $writeException) {
            if ($writeException->getCode() === self::SQLSTATE_INTEGRITY_VIOLATION) {
                throw HttpException::conflict('folder_name_taken', 'A folder with this name already exists in this workspace.');
            }
            throw $writeException;
        }
    }

    /**
     * @param array<string, mixed> $folderRow
     * @return array<string, mixed>
     */
    private static function toPublicArray(array $folderRow): array
    {
        return [
            'id' => $folderRow['id'],
            'name' => $folderRow['name'],
            'note_count' => (int) $folderRow['note_count'],
            'created_at' => UtcTimestamp::toIso((string) $folderRow['created_at']),
            'updated_at' => UtcTimestamp::toIso((string) $folderRow['updated_at']),
        ];
    }
}
