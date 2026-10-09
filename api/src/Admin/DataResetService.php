<?php

declare(strict_types=1);

namespace Muninn\Api\Admin;

use Muninn\Api\Attachments\AttachmentStorage;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Resets an installation to "system administrators only" (decision D046).
 *
 * Used by bin/reset-data.php to clear out test accounts and test content. It deletes every
 * non-admin account together with everything that belongs to users: notes, note history, folders, tags,
 * attachments (rows and image files), Magic Links, workspaces, workspace memberships, sessions and all
 * invitations. It keeps:
 *   - system administrator accounts and their sessions (so the admin stays signed in),
 *   - the audit log (audit history must survive changes to the rows it describes),
 *   - the schema and the migration history.
 *
 * There is deliberately no API endpoint for this: it is run only from the server's command line.
 */
final class DataResetService
{
    /** Label used for the attachment files in the counts. */
    public const ATTACHMENT_FILES_LABEL = 'attachment image files';

    public function __construct(
        private readonly PDO $database,
        private readonly AttachmentStorage $attachmentStorage,
    ) {
    }

    /** Number of system administrator accounts that will be kept. */
    public function countAdministrators(): int
    {
        return (int) $this->database->query('SELECT COUNT(*) FROM users WHERE is_system_admin = 1')->fetchColumn();
    }

    /**
     * Counts what a reset would delete, without changing anything.
     *
     * @return array<string, int> Label => number of rows.
     */
    public function countRowsToDelete(): array
    {
        $rowCountQueries = [
            'user accounts (non-admin)' => 'SELECT COUNT(*) FROM users WHERE is_system_admin = 0',
            'sessions of those accounts' => 'SELECT COUNT(*) FROM sessions
                                             JOIN users ON users.id = sessions.user_id
                                             WHERE users.is_system_admin = 0',
            'workspaces' => 'SELECT COUNT(*) FROM workspaces',
            'magic links (D059)' => 'SELECT COUNT(*) FROM magic_links',
            'magic link visits' => 'SELECT COUNT(*) FROM magic_link_sessions',
            'workspace memberships' => 'SELECT COUNT(*) FROM workspace_members',
            'notes (including Trash)' => 'SELECT COUNT(*) FROM notes',
            'note history versions' => 'SELECT COUNT(*) FROM note_versions',
            'folders' => 'SELECT COUNT(*) FROM folders',
            'tags' => 'SELECT COUNT(*) FROM tags',
            'attachments' => 'SELECT COUNT(*) FROM attachments',
            'invitations (all states)' => 'SELECT COUNT(*) FROM invitations',
            'invitation requests' => 'SELECT COUNT(*) FROM invitation_requests',
            'password reset links' => 'SELECT COUNT(*) FROM password_resets',
            'sign-in attempt records' => 'SELECT COUNT(*) FROM auth_attempts',
            'broadcast votes of those accounts' => 'SELECT COUNT(*) FROM broadcast_votes
                                                   JOIN users ON users.id = broadcast_votes.user_id
                                                   WHERE users.is_system_admin = 0',
            'broadcast receipts of those accounts' => 'SELECT COUNT(*) FROM broadcast_receipts
                                                      JOIN users ON users.id = broadcast_receipts.user_id
                                                      WHERE users.is_system_admin = 0',
        ];

        $rowCounts = [];
        foreach ($rowCountQueries as $countLabel => $countQuery) {
            $rowCounts[$countLabel] = (int) $this->database->query($countQuery)->fetchColumn();
        }
        $rowCounts[self::ATTACHMENT_FILES_LABEL] = $this->attachmentStorage->countFiles();

        return $rowCounts;
    }

    /**
     * Deletes all user data except system administrator accounts, in one transaction.
     * Either everything is deleted or nothing is.
     *
     * @return array<string, int> Label => number of rows actually deleted.
     * @throws RuntimeException when no administrator account exists (nobody could sign in afterwards).
     */
    public function resetToAdministratorsOnly(): array
    {
        if ($this->countAdministrators() === 0) {
            throw new RuntimeException('No system administrator account exists. Refusing to reset.');
        }

        // Delete children before parents so every foreign key stays satisfied without disabling
        // FOREIGN_KEY_CHECKS. Administrators own no workspaces or notes (D044), so all content goes.
        $deleteStatements = [
            // Magic Links point at workspaces and at the accounts that created them (D059).
            'magic link visits' => 'DELETE FROM magic_link_sessions',
            'magic links (D059)' => 'DELETE FROM magic_links',
            // Broadcast answers point at the accounts being deleted (D061); the broadcasts
            // themselves belong to administrators and stay.
            'broadcast votes of those accounts' => 'DELETE broadcast_votes FROM broadcast_votes
                                                   JOIN users ON users.id = broadcast_votes.user_id
                                                   WHERE users.is_system_admin = 0',
            'broadcast receipts of those accounts' => 'DELETE broadcast_receipts FROM broadcast_receipts
                                                      JOIN users ON users.id = broadcast_receipts.user_id
                                                      WHERE users.is_system_admin = 0',
            'attachments' => 'DELETE FROM attachments',
            // note_tags rows go with their notes and tags (ON DELETE CASCADE).
            'tags' => 'DELETE FROM tags',
            'note history versions' => 'DELETE FROM note_versions',
            'notes (including Trash)' => 'DELETE FROM notes',
            'folders' => 'DELETE FROM folders',
            'workspace memberships' => 'DELETE FROM workspace_members',
            'workspaces' => 'DELETE FROM workspaces',
            // Requests point at invitations and at the accounts being deleted.
            'invitation requests' => 'DELETE FROM invitation_requests',
            // Accepted invitations point at the accounts being deleted; pending ones are test links.
            'invitations (all states)' => 'DELETE FROM invitations',
            // Unused links stop mattering once the accounts are gone; administrators' links go too.
            'password reset links' => 'DELETE FROM password_resets',
            'sessions of those accounts' => 'DELETE sessions FROM sessions
                                             JOIN users ON users.id = sessions.user_id
                                             WHERE users.is_system_admin = 0',
            'sign-in attempt records' => 'DELETE FROM auth_attempts',
            'user accounts (non-admin)' => 'DELETE FROM users WHERE is_system_admin = 0',
        ];

        $deletedRowCounts = [];
        $this->database->beginTransaction();
        try {
            foreach ($deleteStatements as $deleteLabel => $deleteQuery) {
                $deletedRowCounts[$deleteLabel] = (int) $this->database->exec($deleteQuery);
            }
            $this->database->commit();
        } catch (Throwable $resetFailure) {
            // Leave the database exactly as it was.
            $this->database->rollBack();
            throw $resetFailure;
        }

        // Files go only after the rows are gone for good. A file that cannot be deleted is merely
        // an orphan nobody can reach, since no attachment row points at it any more.
        $deletedRowCounts[self::ATTACHMENT_FILES_LABEL] = $this->attachmentStorage->deleteAllFiles();

        return $deletedRowCounts;
    }
}
