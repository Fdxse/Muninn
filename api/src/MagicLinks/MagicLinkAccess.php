<?php

declare(strict_types=1);

namespace Muninn\Api\MagicLinks;

use Muninn\Api\Workspaces\WorkspaceMembership;
use Muninn\Api\Workspaces\WorkspaceRole;

/**
 * What one browser that opened a Magic Link may reach during one request (D059).
 *
 * Only MagicLinkService creates instances, after checking the link again from the database:
 * not revoked, inside its dates and daily window, its creator still an active Admin or Owner of
 * the workspace, and its target still there. Nothing the browser sends adds to it.
 */
final class MagicLinkAccess
{
    public const TARGET_WORKSPACE = 'workspace';
    public const TARGET_FOLDER = 'folder';
    public const TARGET_NOTE = 'note';

    public const PERMISSION_READ = 'read';
    public const PERMISSION_WRITE = 'write';

    public function __construct(
        public readonly string $visitId,
        public readonly string $csrfToken,
        public readonly string $linkId,
        public readonly string $label,
        public readonly string $workspaceId,
        public readonly string $workspaceName,
        public readonly string $workspaceKind,
        public readonly string $targetType,
        /** Folder or note ID; null for a workspace link. */
        public readonly ?string $targetId,
        public readonly string $permission,
        /** The link's creator: notes saved through the link are recorded under this account. */
        public readonly string $creatorUserId,
        /** ISO 8601 UTC end of the link's validity, shown to the visitor. */
        public readonly string $validUntilIso,
    ) {
    }

    public function canWrite(): bool
    {
        return $this->permission === self::PERMISSION_WRITE;
    }

    /**
     * A membership-shaped value for the existing note, folder and attachment services, which
     * scope every query to its workspace. It carries the link's own permission as a role
     * (Editor for write, Reader for read) and the creator's user ID, so saves are attributed to
     * the creator. The visitor endpoints still decide WHICH notes and folders inside the
     * workspace are reachable (MagicLinkScope); this value alone is never handed to an endpoint
     * that would allow more, such as history, Trash, search or members.
     */
    public function serviceMembership(): WorkspaceMembership
    {
        return new WorkspaceMembership(
            workspaceId: $this->workspaceId,
            workspaceName: $this->workspaceName,
            workspaceKind: $this->workspaceKind,
            userId: $this->creatorUserId,
            role: $this->canWrite() ? WorkspaceRole::Editor : WorkspaceRole::Reader,
        );
    }
}
