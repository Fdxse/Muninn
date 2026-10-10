<?php

declare(strict_types=1);

namespace Muninn\Api\Workspaces;

/**
 * Workspace roles and what each one may do (decision D029). This is the ONLY place the role
 * permission matrix is defined (together with WorkspacePermission::minimumRole()); endpoints ask
 * WorkspaceAuthorizer, which asks this class.
 *
 *   Reader  read notes, their history and Trash; search
 *   Editor  + create, edit, archive and delete (trash) notes; restore from Trash and history
 *   Admin   + add, change and remove Editors and Readers; delete notes from Trash for good
 *   Owner   + manage Admins and Owners, rename and delete the workspace
 *
 * A workspace always keeps at least one Owner.
 */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Reader = 'reader';

    /** Higher number = more rights. Each role includes everything the roles below it can do. */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 4,
            self::Admin => 3,
            self::Editor => 2,
            self::Reader => 1,
        };
    }

    /** True when this role is at least as strong as $minimumRole. */
    public function includes(self $minimumRole): bool
    {
        return $this->rank() >= $minimumRole->rank();
    }

    /** True when this role grants $permission. The matrix itself lives on WorkspacePermission. */
    public function allows(WorkspacePermission $permission): bool
    {
        return $this->includes($permission->minimumRole());
    }

    /**
     * True when this role may give someone $targetRole (when adding a member or changing a role).
     * Owners may hand out any role; Admins only Editor and Reader.
     */
    public function canAssign(self $targetRole): bool
    {
        if ($this === self::Owner) {
            return true;
        }
        if ($this === self::Admin) {
            return !$targetRole->includes(self::Admin);
        }

        return false;
    }

    /**
     * True when this role may change or remove a member who currently has $memberRole.
     * Owners may manage everyone; Admins only Editors and Readers.
     */
    public function canManageMember(self $memberRole): bool
    {
        return $this->canAssign($memberRole);
    }

    /** True for the roles an open Shared Workspace's members may have (D067): Editor and Reader. */
    public static function isOpenWorkspaceRole(self $role): bool
    {
        return $role === self::Editor || $role === self::Reader;
    }

    /** Parses a role sent by a client, or null when it is not a known role. */
    public static function tryFromInput(mixed $roleInput): ?self
    {
        return is_string($roleInput) ? self::tryFrom($roleInput) : null;
    }
}
