<?php

declare(strict_types=1);

namespace Muninn\Api\Workspaces;

/**
 * One user's membership of one workspace, as established by WorkspaceAuthorizer.
 *
 * Holding an instance means "this user is an active member of this workspace with this role";
 * nothing else in the code creates one from client input.
 */
final class WorkspaceMembership
{
    public const KIND_PERSONAL = 'personal';
    public const KIND_SHARED = 'shared';

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
}
