<?php

declare(strict_types=1);

namespace Muninn\Api\AdminAttention;

use Muninn\Api\AdminInbox\AdminConversationService;
use Muninn\Api\Invitations\InvitationRequestService;
use PDO;

/**
 * "Something is waiting on the admin side" (decision D066).
 *
 * The administrator picks one everyday account (the recipient). That account sees a small icon
 * while admin work is waiting, so the administrator does not have to sign in to the admin
 * account just to look. The answer is a plain yes or no: never what is waiting, nor how much.
 *
 * Waiting means something an administrator has to act on:
 *   - invitation requests waiting for a decision (D049), and
 *   - inbox conversations with something the administrators have not read (D065).
 */
final class AdminAttentionService
{
    /** The settings table only ever has this one row. */
    private const SETTINGS_ROW_ID = 1;

    public function __construct(
        private readonly PDO $database,
        private readonly InvitationRequestService $invitationRequestService,
        private readonly AdminConversationService $conversationService,
    ) {
    }

    /** The ID of the account that is told, or null when nobody is. */
    public function recipientUserId(): ?string
    {
        $selectStatement = $this->database->prepare(
            'SELECT recipient_user_id FROM admin_attention_settings WHERE id = :id'
        );
        $selectStatement->execute(['id' => self::SETTINGS_ROW_ID]);
        $recipientUserId = $selectStatement->fetchColumn();

        return is_string($recipientUserId) && $recipientUserId !== '' ? $recipientUserId : null;
    }

    /** Makes the given account the recipient, or nobody when null. The caller has validated it. */
    public function setRecipientUserId(?string $recipientUserId): void
    {
        $upsertStatement = $this->database->prepare(
            'INSERT INTO admin_attention_settings (id, recipient_user_id, updated_at)
             VALUES (:id, :recipient_user_id, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE recipient_user_id = VALUES(recipient_user_id), updated_at = VALUES(updated_at)'
        );
        $upsertStatement->execute(['id' => self::SETTINGS_ROW_ID, 'recipient_user_id' => $recipientUserId]);
    }

    /** True when anything waits for an administrator to act on it. */
    public function isAdminWorkWaiting(): bool
    {
        return $this->invitationRequestService->countPending() > 0
            || $this->conversationService->countUnreadForAdmin() > 0;
    }
}
