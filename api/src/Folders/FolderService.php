<?php

declare(strict_types=1);

namespace Muninn\Api\Folders;

use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Workspaces\WorkspaceMembership;
use PDO;
use PDOException;
use Throwable;

/**
 * Folder data operations (D033: folders belong to one workspace and have unique names there;
 * D055: folders can sit inside each other, at most MAXIMUM_DEPTH levels deep).
 *
 * Like NoteService it never decides on its own who may act: every method takes the
 * WorkspaceMembership produced by WorkspaceAuthorizer and scopes every query to that workspace,
 * so a folder of another workspace can never be seen, used, chosen as a parent or changed,
 * even by its ID.
 *
 * Every change to the folder tree (create, move, delete) locks the workspace row first. That
 * serialises tree changes per workspace, so two simultaneous moves can never build a loop or
 * together exceed the depth limit.
 */
final class FolderService
{
    /** MariaDB/MySQL SQLSTATE for a unique-key violation. */
    private const SQLSTATE_INTEGRITY_VIOLATION = '23000';

    /** More folders than this stops being useful; it also bounds the response. */
    private const MAXIMUM_FOLDERS_PER_WORKSPACE = 200;

    /** Top-level folders are level 1; a folder may sit at most this many levels deep (D055). */
    public const MAXIMUM_DEPTH = 3;

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
     * Lists the workspace's folders in tree order: each folder is followed by its sub-folders,
     * and folders with the same parent are sorted by name. Each folder carries its parent, its
     * level (1 = top level) and two counts of the notes the normal note list shows (archived and
     * trashed notes are not counted): note_count for the notes directly in it, and
     * total_note_count including the notes in all its sub-folders.
     *
     * @return list<array<string, mixed>>
     */
    public function listInWorkspace(WorkspaceMembership $membership): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT folders.id, folders.parent_folder_id, folders.name, folders.created_at, folders.updated_at,
                    COUNT(notes.id) AS note_count
             FROM folders
             LEFT JOIN notes ON notes.folder_id = folders.id AND notes.trashed_at IS NULL AND notes.archived_at IS NULL
             WHERE folders.workspace_id = :workspace_id
             GROUP BY folders.id, folders.parent_folder_id, folders.name, folders.created_at, folders.updated_at
             ORDER BY folders.name, folders.id'
        );
        $selectStatement->execute(['workspace_id' => $membership->workspaceId]);

        return self::inTreeOrder($selectStatement->fetchAll());
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
     * Creates a folder, at the top level or inside $parentFolderId, and returns its ID.
     *
     * @throws HttpException 409 when the name is taken or the workspace has too many folders,
     *                       422 when the parent is not a folder of this workspace or is already
     *                       at the deepest level.
     */
    public function create(WorkspaceMembership $membership, string $folderName, ?string $parentFolderId = null): string
    {
        $newFolderId = UuidGenerator::generate();

        $this->inTreeTransaction($membership, function (array $foldersById) use ($membership, $folderName, $parentFolderId, $newFolderId): void {
            if (count($foldersById) >= self::MAXIMUM_FOLDERS_PER_WORKSPACE) {
                throw HttpException::conflict('folder_limit', 'This workspace already has the maximum of ' . self::MAXIMUM_FOLDERS_PER_WORKSPACE . ' folders.');
            }
            if ($parentFolderId !== null) {
                self::requireParentInWorkspace($foldersById, $parentFolderId);
                if (self::levelOf($foldersById, $parentFolderId) >= self::MAXIMUM_DEPTH) {
                    throw HttpException::validation(['parent_id' => self::depthLimitMessage()]);
                }
            }

            $insertStatement = $this->database->prepare(
                'INSERT INTO folders (id, workspace_id, parent_folder_id, name, created_by_user_id, created_at, updated_at)
                 VALUES (:id, :workspace_id, :parent_folder_id, :name, :user_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $this->runRefusingDuplicateNames(fn () => $insertStatement->execute([
                'id' => $newFolderId,
                'workspace_id' => $membership->workspaceId,
                'parent_folder_id' => $parentFolderId,
                'name' => $folderName,
                'user_id' => $membership->userId,
            ]));
        });

        return $newFolderId;
    }

    /**
     * Renames and/or moves a folder of the membership's workspace. A null $newName keeps the
     * name; $moveRequested false keeps the parent, otherwise $newParentFolderId is the new parent
     * (null = top level). The folder's sub-folders and notes move with it.
     *
     * @throws HttpException 404 when it is not a folder of that workspace, 409 when the name is
     *                       taken, 422 when the new parent is not a folder of this workspace, is
     *                       the folder itself or one of its sub-folders, or would make the
     *                       folder or its sub-folders deeper than MAXIMUM_DEPTH.
     */
    public function update(
        WorkspaceMembership $membership,
        string $folderId,
        ?string $newName,
        bool $moveRequested,
        ?string $newParentFolderId,
    ): void {
        $this->inTreeTransaction($membership, function (array $foldersById) use ($membership, $folderId, $newName, $moveRequested, $newParentFolderId): void {
            if (!isset($foldersById[$folderId])) {
                throw HttpException::notFound();
            }
            $parentFolderId = $foldersById[$folderId]['parent_folder_id'];

            if ($moveRequested && $newParentFolderId !== $parentFolderId) {
                if ($newParentFolderId !== null) {
                    self::requireParentInWorkspace($foldersById, $newParentFolderId);
                    // Moving a folder into itself or below itself would detach a loop from the tree.
                    if ($newParentFolderId === $folderId || self::isInsideFolder($foldersById, $newParentFolderId, $folderId)) {
                        throw HttpException::validation(['parent_id' => 'A folder cannot be moved into itself or one of its own sub-folders.']);
                    }
                }
                $newLevel = $newParentFolderId === null ? 1 : self::levelOf($foldersById, $newParentFolderId) + 1;
                // The deepest sub-folder moves along, so the whole branch must still fit.
                if ($newLevel + self::branchHeight($foldersById, $folderId) - 1 > self::MAXIMUM_DEPTH) {
                    throw HttpException::validation(['parent_id' => self::depthLimitMessage()]);
                }
                $parentFolderId = $newParentFolderId;
            }

            $updateStatement = $this->database->prepare(
                'UPDATE folders
                 SET name = COALESCE(:name, name), parent_folder_id = :parent_folder_id, updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND workspace_id = :workspace_id'
            );
            $this->runRefusingDuplicateNames(fn () => $updateStatement->execute([
                'name' => $newName,
                'parent_folder_id' => $parentFolderId,
                'id' => $folderId,
                'workspace_id' => $membership->workspaceId,
            ]));
        });
    }

    /**
     * Deletes a folder (D055). Its notes (Archive and Trash included) and its sub-folders move up
     * one level, into the folder's own parent; for a top-level folder that is "No folder" and the
     * top level. Notes themselves are never changed otherwise and never deleted. Moving up can
     * never break the depth limit, because everything only gets shallower.
     *
     * @return string|null The parent the contents moved to (null = top level / "No folder").
     * @throws HttpException 404 when it is not a folder of that workspace.
     */
    public function delete(WorkspaceMembership $membership, string $folderId): ?string
    {
        $parentFolderId = null;

        $this->inTreeTransaction($membership, function (array $foldersById) use ($membership, $folderId, &$parentFolderId): void {
            if (!isset($foldersById[$folderId])) {
                throw HttpException::notFound();
            }
            $parentFolderId = $foldersById[$folderId]['parent_folder_id'];
            $moveParameters = ['parent_folder_id' => $parentFolderId, 'folder_id' => $folderId, 'workspace_id' => $membership->workspaceId];

            $moveNotesStatement = $this->database->prepare(
                'UPDATE notes SET folder_id = :parent_folder_id WHERE folder_id = :folder_id AND workspace_id = :workspace_id'
            );
            $moveNotesStatement->execute($moveParameters);

            $moveSubFoldersStatement = $this->database->prepare(
                'UPDATE folders SET parent_folder_id = :parent_folder_id, updated_at = UTC_TIMESTAMP()
                 WHERE parent_folder_id = :folder_id AND workspace_id = :workspace_id'
            );
            $moveSubFoldersStatement->execute($moveParameters);

            $deleteStatement = $this->database->prepare('DELETE FROM folders WHERE id = :id AND workspace_id = :workspace_id');
            $deleteStatement->execute(['id' => $folderId, 'workspace_id' => $membership->workspaceId]);
        });

        return $parentFolderId;
    }

    /**
     * Runs a change to the folder tree in a transaction that holds the workspace row lock, and
     * hands the change the workspace's current folders (id => ['parent_folder_id' => ...]), read
     * after the lock so they cannot change underneath it.
     *
     * @param callable(array<string, array{parent_folder_id: string|null}>): void $treeChange
     */
    private function inTreeTransaction(WorkspaceMembership $membership, callable $treeChange): void
    {
        $this->database->beginTransaction();
        try {
            $lockStatement = $this->database->prepare('SELECT id FROM workspaces WHERE id = :id FOR UPDATE');
            $lockStatement->execute(['id' => $membership->workspaceId]);

            $selectStatement = $this->database->prepare('SELECT id, parent_folder_id FROM folders WHERE workspace_id = :workspace_id');
            $selectStatement->execute(['workspace_id' => $membership->workspaceId]);
            $foldersById = [];
            foreach ($selectStatement->fetchAll() as $folderRow) {
                $foldersById[(string) $folderRow['id']] = [
                    'parent_folder_id' => $folderRow['parent_folder_id'] === null ? null : (string) $folderRow['parent_folder_id'],
                ];
            }

            $treeChange($foldersById);
            $this->database->commit();
        } catch (Throwable $changeFailure) {
            $this->database->rollBack();
            throw $changeFailure;
        }
    }

    /**
     * Refuses a parent that is not a folder of this workspace. Another workspace's folder gets
     * the same answer as a made-up ID, so its existence is never revealed.
     *
     * @param array<string, array{parent_folder_id: string|null}> $foldersById
     * @throws HttpException 422 on parent_id.
     */
    private static function requireParentInWorkspace(array $foldersById, string $parentFolderId): void
    {
        if (!isset($foldersById[$parentFolderId])) {
            throw HttpException::validation(['parent_id' => 'Choose a folder of this workspace.']);
        }
    }

    /**
     * The level of a folder: 1 for a top-level folder, 2 for one inside it, and so on. Walking up
     * stops after MAXIMUM_FOLDERS_PER_WORKSPACE steps, so even a damaged tree cannot loop forever.
     *
     * @param array<string, array{parent_folder_id: string|null}> $foldersById
     */
    private static function levelOf(array $foldersById, string $folderId): int
    {
        $level = 1;
        $currentFolderId = $foldersById[$folderId]['parent_folder_id'] ?? null;
        while ($currentFolderId !== null && isset($foldersById[$currentFolderId]) && $level <= self::MAXIMUM_FOLDERS_PER_WORKSPACE) {
            $level++;
            $currentFolderId = $foldersById[$currentFolderId]['parent_folder_id'];
        }

        return $level;
    }

    /**
     * True when $folderId sits somewhere below $possibleAncestorId.
     *
     * @param array<string, array{parent_folder_id: string|null}> $foldersById
     */
    private static function isInsideFolder(array $foldersById, string $folderId, string $possibleAncestorId): bool
    {
        $stepCount = 0;
        $currentFolderId = $foldersById[$folderId]['parent_folder_id'] ?? null;
        while ($currentFolderId !== null && $stepCount <= self::MAXIMUM_FOLDERS_PER_WORKSPACE) {
            if ($currentFolderId === $possibleAncestorId) {
                return true;
            }
            $currentFolderId = $foldersById[$currentFolderId]['parent_folder_id'] ?? null;
            $stepCount++;
        }

        return false;
    }

    /**
     * How many levels a folder's branch spans: 1 for a folder without sub-folders, 2 when it has
     * sub-folders but they have none, and so on.
     *
     * @param array<string, array{parent_folder_id: string|null}> $foldersById
     */
    private static function branchHeight(array $foldersById, string $folderId): int
    {
        $tallestBranch = 1;
        foreach ($foldersById as $candidateFolderId => $candidateFolder) {
            if ($candidateFolder['parent_folder_id'] === $folderId && (string) $candidateFolderId !== $folderId) {
                $tallestBranch = max($tallestBranch, 1 + self::branchHeight($foldersById, (string) $candidateFolderId));
            }
        }

        return $tallestBranch;
    }

    private static function depthLimitMessage(): string
    {
        return 'Folders can be at most ' . self::MAXIMUM_DEPTH . ' levels deep.';
    }

    /**
     * Turns the alphabetical folder rows into tree order with levels and total counts. A folder
     * whose parent is missing (never expected) is shown at the top level rather than hidden.
     *
     * @param list<array<string, mixed>> $folderRows Sorted by name.
     * @return list<array<string, mixed>>
     */
    private static function inTreeOrder(array $folderRows): array
    {
        $rowsById = [];
        $childIdsByParentId = [];
        foreach ($folderRows as $folderRow) {
            $rowsById[(string) $folderRow['id']] = $folderRow;
        }
        foreach ($folderRows as $folderRow) {
            $parentFolderId = $folderRow['parent_folder_id'];
            $parentKey = $parentFolderId !== null && isset($rowsById[(string) $parentFolderId]) ? (string) $parentFolderId : '';
            // Rows arrive sorted by name, so each child list is sorted too.
            $childIdsByParentId[$parentKey][] = (string) $folderRow['id'];
        }

        $orderedFolders = [];
        $visitedFolderIds = [];
        $appendBranch = function (string $folderId, int $level) use (&$appendBranch, &$orderedFolders, &$visitedFolderIds, $rowsById, $childIdsByParentId): int {
            $visitedFolderIds[$folderId] = true;
            $positionInList = count($orderedFolders);
            $orderedFolders[] = null;
            $totalNoteCount = (int) $rowsById[$folderId]['note_count'];
            foreach ($childIdsByParentId[$folderId] ?? [] as $childFolderId) {
                if (!isset($visitedFolderIds[$childFolderId])) {
                    $totalNoteCount += $appendBranch($childFolderId, $level + 1);
                }
            }
            $orderedFolders[$positionInList] = self::toPublicArray($rowsById[$folderId], $level, $totalNoteCount);

            return $totalNoteCount;
        };

        foreach ($childIdsByParentId[''] ?? [] as $topLevelFolderId) {
            $appendBranch($topLevelFolderId, 1);
        }
        // Only a loop in the stored data could leave folders unvisited; show them rather than hide them.
        foreach (array_keys($rowsById) as $folderId) {
            if (!isset($visitedFolderIds[(string) $folderId])) {
                $appendBranch((string) $folderId, 1);
            }
        }

        return $orderedFolders;
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
    private static function toPublicArray(array $folderRow, int $level, int $totalNoteCount): array
    {
        return [
            'id' => $folderRow['id'],
            'parent_id' => $folderRow['parent_folder_id'],
            'name' => $folderRow['name'],
            'level' => $level,
            'note_count' => (int) $folderRow['note_count'],
            'total_note_count' => $totalNoteCount,
            'created_at' => UtcTimestamp::toIso((string) $folderRow['created_at']),
            'updated_at' => UtcTimestamp::toIso((string) $folderRow['updated_at']),
        ];
    }
}
