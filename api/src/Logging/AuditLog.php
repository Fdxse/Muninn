<?php

declare(strict_types=1);

namespace Muninn\Api\Logging;

use Muninn\Api\Security\UuidGenerator;
use PDO;

/**
 * Writes security-relevant events to the audit_log table.
 *
 * Details pass through the same redaction as the application log; callers must still
 * never pass passwords or raw tokens.
 */
final class AuditLog
{
    public const LOGIN_SUCCEEDED = 'auth.login_succeeded';
    public const LOGIN_FAILED = 'auth.login_failed';
    public const LOGIN_RATE_LIMITED = 'auth.login_rate_limited';
    public const LOGOUT = 'auth.logout';
    public const INVITATION_CREATED = 'invitation.created';
    public const INVITATION_REVOKED = 'invitation.revoked';
    public const INVITATION_ACCEPTED = 'invitation.accepted';
    public const USER_CREATED_BY_CLI = 'user.created_by_cli';
    public const USER_DISABLED = 'user.disabled';
    public const USER_ENABLED = 'user.enabled';
    public const PASSWORD_CHANGED = 'user.password_changed';
    public const PASSWORD_RESET_CREATED = 'password_reset.created';
    public const PASSWORD_RESET_COMPLETED = 'password_reset.completed';
    public const INVITATION_REQUEST_CREATED = 'invitation_request.created';
    public const INVITATION_REQUEST_APPROVED = 'invitation_request.approved';
    public const INVITATION_REQUEST_DECLINED = 'invitation_request.declined';
    public const INVITATION_REQUEST_CANCELLED = 'invitation_request.cancelled';
    public const INVITATION_REQUEST_LINK_CREATED = 'invitation_request.link_created';
    public const WORKSPACE_CREATED = 'workspace.created';
    public const WORKSPACE_RENAMED = 'workspace.renamed';
    public const WORKSPACE_DELETED = 'workspace.deleted';
    public const WORKSPACE_MEMBER_ADDED = 'workspace.member_added';
    public const WORKSPACE_MEMBER_ROLE_CHANGED = 'workspace.member_role_changed';
    public const WORKSPACE_MEMBER_REMOVED = 'workspace.member_removed';
    public const NOTE_TRASHED = 'note.trashed';
    public const NOTE_RESTORED_FROM_TRASH = 'note.restored_from_trash';
    public const NOTE_PURGED = 'note.purged';
    public const TRASH_EMPTIED = 'trash.emptied';
    public const TRASH_EXPIRED_PURGED = 'trash.expired_purged';
    public const NOTE_VERSION_RESTORED = 'note.version_restored';
    public const FOLDER_DELETED = 'folder.deleted';
    public const ATTACHMENT_UPLOADED = 'attachment.uploaded';
    public const ATTACHMENT_DELETED = 'attachment.deleted';
    public const ADMIN_NOTIFICATION_QUEUED = 'notification.admin_queued';
    public const ADMIN_MESSAGE_SENT = 'admin_message.sent';
    public const SYSTEM_DATA_RESET = 'system.data_reset';
    public const MAGIC_LINK_CREATED = 'magic_link.created';
    public const MAGIC_LINK_REVOKED = 'magic_link.revoked';
    public const MAGIC_LINK_OPENED = 'magic_link.opened';
    public const MAGIC_LINK_NOTE_CREATED = 'magic_link.note_created';
    public const MAGIC_LINK_NOTE_UPDATED = 'magic_link.note_updated';
    public const MAGIC_LINK_ATTACHMENT_UPLOADED = 'magic_link.attachment_uploaded';

    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * @param array<string, mixed> $details Extra non-secret information.
     */
    public function record(
        string $eventType,
        ?string $actorUserId,
        ?string $targetType,
        ?string $targetId,
        ?string $ipAddress,
        array $details = [],
    ): void {
        $insertStatement = $this->database->prepare(
            'INSERT INTO audit_log (id, event_type, actor_user_id, target_type, target_id, ip_address, details, created_at)
             VALUES (:id, :event_type, :actor_user_id, :target_type, :target_id, :ip_address, :details, UTC_TIMESTAMP())'
        );
        $insertStatement->execute([
            'id' => UuidGenerator::generate(),
            'event_type' => $eventType,
            'actor_user_id' => $actorUserId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip_address' => $ipAddress,
            'details' => json_encode(AppLogger::redact($details), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
