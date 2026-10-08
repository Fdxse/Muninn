<?php

declare(strict_types=1);

namespace Muninn\Api\MagicLinks;

use Muninn\Api\Security\UuidGenerator;
use PDO;

/**
 * Which folders and notes a Magic Link reaches inside its workspace (D059):
 *   workspace link  every active note and every folder of the workspace
 *   folder link     the folder, its sub-folders (D055) and the active notes in them
 *   note link       that one active note, and no folders at all
 *
 * Every visitor endpoint asks this class before touching a note, folder or attachment, so the
 * rest of the workspace stays exactly as invisible as it is to a stranger.
 */
final class MagicLinkScope
{
    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * The IDs of the folders the link reaches, or null when it reaches every folder of the
     * workspace (a workspace link).
     *
     * @return list<string>|null
     */
    public function reachableFolderIds(MagicLinkAccess $access): ?array
    {
        return match ($access->targetType) {
            MagicLinkAccess::TARGET_WORKSPACE => null,
            MagicLinkAccess::TARGET_FOLDER => $this->folderBranch($access->workspaceId, (string) $access->targetId),
            default => [],
        };
    }

    /** True when $folderId is a folder of the link's workspace that the link reaches. */
    public function reachesFolder(MagicLinkAccess $access, string $folderId): bool
    {
        if (!UuidGenerator::isValid($folderId)) {
            return false;
        }
        $reachableFolderIds = $this->reachableFolderIds($access);
        if ($reachableFolderIds !== null) {
            return in_array($folderId, $reachableFolderIds, true);
        }

        // Workspace link: any folder, as long as it is one of THIS workspace's folders.
        $selectStatement = $this->database->prepare('SELECT 1 FROM folders WHERE id = :id AND workspace_id = :workspace_id');
        $selectStatement->execute(['id' => $folderId, 'workspace_id' => $access->workspaceId]);

        return $selectStatement->fetchColumn() !== false;
    }

    /** True when $noteId is an active (not trashed) note of the link's workspace that the link reaches. */
    public function reachesNote(MagicLinkAccess $access, string $noteId): bool
    {
        if (!UuidGenerator::isValid($noteId)) {
            return false;
        }
        $selectStatement = $this->database->prepare(
            'SELECT folder_id FROM notes WHERE id = :id AND workspace_id = :workspace_id AND trashed_at IS NULL'
        );
        $selectStatement->execute(['id' => $noteId, 'workspace_id' => $access->workspaceId]);
        $noteRow = $selectStatement->fetch();
        if ($noteRow === false) {
            return false;
        }

        return match ($access->targetType) {
            MagicLinkAccess::TARGET_WORKSPACE => true,
            MagicLinkAccess::TARGET_NOTE => $noteId === $access->targetId,
            MagicLinkAccess::TARGET_FOLDER => $noteRow['folder_id'] !== null
                && in_array((string) $noteRow['folder_id'], $this->folderBranch($access->workspaceId, (string) $access->targetId), true),
            default => false,
        };
    }

    /**
     * A folder and all its sub-folders, walked inside one workspace only (same walk as the note
     * list's folder filter). Sub-folders are at most 3 levels deep; the level guard only stops a
     * damaged tree from recursing endlessly.
     *
     * @return list<string>
     */
    private function folderBranch(string $workspaceId, string $topFolderId): array
    {
        $selectStatement = $this->database->prepare(
            'WITH RECURSIVE folder_branch (id, branch_level) AS (
                 SELECT id, 1 FROM folders WHERE id = :folder_id AND workspace_id = :workspace_id
                 UNION ALL
                 SELECT folders.id, folder_branch.branch_level + 1
                 FROM folders JOIN folder_branch ON folders.parent_folder_id = folder_branch.id
                 WHERE folders.workspace_id = :branch_workspace_id AND folder_branch.branch_level < 10
             )
             SELECT id FROM folder_branch'
        );
        $selectStatement->execute([
            'folder_id' => $topFolderId,
            'workspace_id' => $workspaceId,
            'branch_workspace_id' => $workspaceId,
        ]);

        return array_map('strval', $selectStatement->fetchAll(PDO::FETCH_COLUMN));
    }
}
