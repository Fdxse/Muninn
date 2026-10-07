<?php

declare(strict_types=1);

namespace Muninn\Api\Workspaces;

/**
 * Things a workspace member may be allowed to do. Each permission names the weakest role that
 * has it (decision D029); stronger roles always have it too (see WorkspaceRole::includes()).
 */
enum WorkspacePermission
{
    /** See the workspace, its member list and its notes. */
    case ReadNotes;
    /** Create, edit and delete (trash) notes. */
    case WriteNotes;
    /** Add, change and remove members (which members: WorkspaceRole::canManageMember()). */
    case ManageMembers;
    /** Rename and delete the workspace. */
    case ManageWorkspace;

    public function minimumRole(): WorkspaceRole
    {
        return match ($this) {
            self::ReadNotes => WorkspaceRole::Reader,
            self::WriteNotes => WorkspaceRole::Editor,
            self::ManageMembers => WorkspaceRole::Admin,
            self::ManageWorkspace => WorkspaceRole::Owner,
        };
    }
}
