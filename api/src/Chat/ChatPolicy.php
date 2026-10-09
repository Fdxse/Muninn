<?php

declare(strict_types=1);

namespace Muninn\Api\Chat;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Users\User;
use Muninn\Api\Workspaces\WorkspaceAuthorizer;
use Muninn\Api\Workspaces\WorkspaceMembership;
use Muninn\Api\Workspaces\WorkspacePermission;
use Muninn\Api\Workspaces\WorkspaceRole;

/**
 * The one place that decides who may use which chat (decision D062). Every chat endpoint asks
 * this class; nothing the client sends grants anything by itself.
 *
 * Rules:
 * - System administrator accounts never read or write any chat (like notes, D025).
 * - A user's chat level (ChatAccessLevel) can only take access away.
 * - Workspace chats exist only for SHARED workspaces; a personal workspace has nobody to talk to.
 * - Workspace access comes from WorkspaceAuthorizer: a non-member gets 404, exactly as for notes.
 *   Every member may read; Editors and up may write; Admins and Owners may delete anyone's message.
 * - The global channel: every level except Off may read it, only Global may write in it, and
 *   only authors may delete their own messages there.
 */
final class ChatPolicy
{
    public function __construct(private readonly WorkspaceAuthorizer $workspaceAuthorizer)
    {
    }

    /** True when the user may read the global channel. */
    public function canReadGlobal(User $user): bool
    {
        return $this->usesChat($user);
    }

    /** True when the user may write in the global channel. */
    public function canWriteGlobal(User $user): bool
    {
        return $this->usesChat($user) && $user->chatAccess === ChatAccessLevel::Global;
    }

    /**
     * True when the user's chat level lets them use this workspace's chat at all (reading).
     * The membership itself already proves they belong to the workspace.
     */
    public function canReadWorkspace(User $user, WorkspaceMembership $membership): bool
    {
        if (!$this->usesChat($user) || $membership->isPersonal()) {
            return false;
        }
        if ($user->chatAccess === ChatAccessLevel::OwnWorkspaces) {
            return $membership->role === WorkspaceRole::Owner;
        }

        return true;
    }

    /** True when the user may write in this workspace's chat: readable, and Editor or up. */
    public function canWriteWorkspace(User $user, WorkspaceMembership $membership): bool
    {
        return $this->canReadWorkspace($user, $membership)
            && $membership->role->allows(WorkspacePermission::WriteNotes);
    }

    /** True when the user may delete OTHER people's messages in this workspace's chat (Admin and up). */
    public function canModerateWorkspace(User $user, WorkspaceMembership $membership): bool
    {
        return $this->canReadWorkspace($user, $membership)
            && $membership->role->allows(WorkspacePermission::ManageMembers);
    }

    /**
     * Returns the membership when the user may read this workspace's chat.
     *
     * @throws HttpException 404 when the user is not a member (or the workspace does not exist),
     *                       403 when they are a member but their chat level or the kind of
     *                       workspace does not allow chat.
     */
    public function requireWorkspaceReader(User $user, string $workspaceId): WorkspaceMembership
    {
        $membership = $this->workspaceAuthorizer->requireWorkspacePermission($user, $workspaceId, WorkspacePermission::ReadNotes);
        if (!$this->canReadWorkspace($user, $membership)) {
            throw self::chatNotAllowed();
        }

        return $membership;
    }

    /** @throws HttpException 403 when the user may not read the global channel. */
    public function requireGlobalReader(User $user): void
    {
        if (!$this->canReadGlobal($user)) {
            throw self::chatNotAllowed();
        }
    }

    /** The 403 used whenever chat is not open to the caller. */
    public static function chatNotAllowed(): HttpException
    {
        return HttpException::forbidden('chat_not_allowed', 'Chat is not available to you here.');
    }

    /** Administrators and inactive accounts never use chat; everyone else unless their level is Off. */
    private function usesChat(User $user): bool
    {
        return !$user->isSystemAdmin && $user->isActive() && $user->chatAccess !== ChatAccessLevel::Off;
    }
}
