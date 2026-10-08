<?php

declare(strict_types=1);

namespace Muninn\Api\Workspaces;

/**
 * Things a workspace member may be allowed to do. Each permission names the weakest role that
 * has it (decision D029); stronger roles always have it too (see WorkspaceRole::includes()).
 */
enum WorkspacePermission
{
    /** See the workspace, its member list, its notes, their history, its Trash, and search them. */
    case ReadNotes;
    /** Create, edit, archive and delete (trash) notes; restore them from Trash or history. */
    case WriteNotes;
    /** Delete notes from Trash for good, before the retention period ends (D039). */
    case PurgeNotes;
    /** Add, change and remove members (which members: WorkspaceRole::canManageMember()). */
    case ManageMembers;
    /** Rename and delete the workspace. */
    case ManageWorkspace;

    public function minimumRole(): WorkspaceRole
    {
        return match ($this) {
            self::ReadNotes => WorkspaceRole::Reader,
            self::WriteNotes => WorkspaceRole::Editor,
            self::PurgeNotes => WorkspaceRole::Admin,
            self::ManageMembers => WorkspaceRole::Admin,
            self::ManageWorkspace => WorkspaceRole::Owner,
        };
    }
}
