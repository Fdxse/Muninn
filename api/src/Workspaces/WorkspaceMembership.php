<?php

declare(strict_types=1);

namespace Muninn\Api\Workspaces;

/**
 * One user's membership of one workspace, as established by WorkspaceAuthorizer.
 *
 * Holding an instance means "this user is an active member of this workspace with this role";
 * nothing else in the code creates one from client input. The one exception is
 * WorkspaceAuthorizer::requireAdministratorMemberManagement(), which gives a system administrator
 * Owner rights over the members of a shared workspace without an active Owner, or of an open
 * Shared Workspace (D067), never over its notes.
 */
final class WorkspaceMembership
{
    public const KIND_PERSONAL = 'personal';
    public const KIND_SHARED = 'shared';
    /** A Shared Workspace users ask to join; a system administrator manages its members (D067). */
    public const KIND_OPEN = 'open';

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $workspaceName,
        public readonly string $workspaceKind,
        public readonly string $userId,
        public readonly WorkspaceRole $role,
    ) {
    }

    public function isPersonal(): bool
    {
        return $this->workspaceKind === self::KIND_PERSONAL;
    }

    /** True for a Shared Workspace that users ask to join (D067). */
    public function isOpen(): bool
    {
        return $this->workspaceKind === self::KIND_OPEN;
    }
}
