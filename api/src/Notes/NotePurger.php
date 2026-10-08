<?php

declare(strict_types=1);

namespace Muninn\Api\Notes;

use Muninn\Api\Attachments\AttachmentStorage;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Tags\TagService;
use Muninn\Api\Workspaces\WorkspaceMembership;
use PDO;
use Throwable;

/**
 * Deletes trashed notes for good (decision D039): the note row, its version history, its tag
 * links, its attachment rows and the attachment files, plus tags no note uses any more.
 *
 * Only notes that are already in Trash can ever be purged; every query below requires
 * trashed_at IS NOT NULL. Used by the Trash endpoints (one note, or a workspace's whole Trash,
 * for members allowed to purge) and by ExpiredTrashCleanup and bin/purge-trash.php (notes past
 * the retention period).
 */
final class NotePurger
{
    /** Notes deleted per transaction, so a large purge never holds locks for long. */
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly PDO $database,
        private readonly TagService $tagService,
        private readonly AttachmentStorage $attachmentStorage,
    ) {
    }

    /**
     * Deletes one trashed note of the membership's workspace for good.
     *
     * @throws HttpException 404 when it is not a trashed note of that workspace.
     */
    public function purgeOne(WorkspaceMembership $membership, string $noteId): void
    {
        $purgedNoteCount = $this->purgeMatching(
            'workspace_id = :workspace_id AND id = :note_id',
            ['workspace_id' => $membership->workspaceId, 'note_id' => $noteId],
        );
        if ($purgedNoteCount === 0) {
            throw HttpException::notFound();
        }
    }

    /**
     * Deletes every note in the membership's workspace's Trash for good.
     *
     * @return int Number of notes deleted.
     */
    public function emptyTrash(WorkspaceMembership $membership): int
    {
        return $this->purgeMatching('workspace_id = :workspace_id', ['workspace_id' => $membership->workspaceId]);
    }

    /**
     * Deletes, in every workspace, the notes that have been in Trash for more than $retentionDays
     * days (run by the API itself through ExpiredTrashCleanup, or by bin/purge-trash.php).
     *
     * @param int|null $maximumNoteCount Stop after about this many notes (rounded up to whole
     *                                   batches); null deletes every expired note.
     * @return int Number of notes deleted.
     */
    public function purgeExpired(int $retentionDays, ?int $maximumNoteCount = null): int
    {
        return $this->purgeMatching(
            'trashed_at < UTC_TIMESTAMP() - INTERVAL :retention_days DAY',
            ['retention_days' => max(0, $retentionDays)],
            $maximumNoteCount,
        );
    }

    /**
     * Counts the notes purgeExpired() would delete now, without deleting anything.
     */
    public function countExpired(int $retentionDays): int
    {
        $countStatement = $this->database->prepare(
            'SELECT COUNT(*) FROM notes WHERE trashed_at IS NOT NULL AND trashed_at < UTC_TIMESTAMP() - INTERVAL :retention_days DAY'
        );
        $countStatement->execute(['retention_days' => max(0, $retentionDays)]);

        return (int) $countStatement->fetchColumn();
    }

    /**
     * Purges the trashed notes matching $extraCondition, batch by batch.
     *
     * @param string $extraCondition SQL condition on `notes` written in this class (never input).
     * @param array<string, mixed> $conditionParameters Values for its placeholders.
     * @param int|null $maximumNoteCount Stop starting new batches once this many are deleted.
     * @return int Number of notes deleted.
     */
    private function purgeMatching(string $extraCondition, array $conditionParameters, ?int $maximumNoteCount = null): int
    {
        $totalPurgedNoteCount = 0;
        do {
            $purgedInBatch = $this->purgeOneBatch($extraCondition, $conditionParameters);
            $totalPurgedNoteCount += $purgedInBatch;
            $limitReached = $maximumNoteCount !== null && $totalPurgedNoteCount >= $maximumNoteCount;
        } while ($purgedInBatch === self::BATCH_SIZE && !$limitReached);

        return $totalPurgedNoteCount;
    }

    /**
     * Deletes up to BATCH_SIZE matching trashed notes in one transaction, then their files.
     *
     * @param array<string, mixed> $conditionParameters
     * @return int Number of notes deleted in this batch.
     */
    private function purgeOneBatch(string $extraCondition, array $conditionParameters): int
    {
        $attachmentIds = [];

        $this->database->beginTransaction();
        try {
            // Lock the notes, so nobody can restore one half-way through its deletion.
            $selectStatement = $this->database->prepare(
                'SELECT id, workspace_id FROM notes
                 WHERE trashed_at IS NOT NULL AND ' . $extraCondition . '
                 ORDER BY trashed_at, id
                 LIMIT ' . self::BATCH_SIZE . ' FOR UPDATE'
            );
            $selectStatement->execute($conditionParameters);
            $noteRows = $selectStatement->fetchAll();
            if ($noteRows === []) {
                $this->database->commit();

                return 0;
            }

            $noteIds = array_map(static fn (array $noteRow): string => (string) $noteRow['id'], $noteRows);
            $notePlaceholders = implode(', ', array_fill(0, count($noteIds), '?'));

            $attachmentStatement = $this->database->prepare('SELECT id FROM attachments WHERE note_id IN (' . $notePlaceholders . ')');
            $attachmentStatement->execute($noteIds);
            $attachmentIds = array_map('strval', $attachmentStatement->fetchAll(PDO::FETCH_COLUMN));

            $this->database->prepare('DELETE FROM attachments WHERE note_id IN (' . $notePlaceholders . ')')->execute($noteIds);
            // Version history and tag links go with the notes (ON DELETE CASCADE).
            $this->database->prepare('DELETE FROM notes WHERE id IN (' . $notePlaceholders . ')')->execute($noteIds);

            $affectedWorkspaceIds = array_unique(array_map(static fn (array $noteRow): string => (string) $noteRow['workspace_id'], $noteRows));
            foreach ($affectedWorkspaceIds as $workspaceId) {
                $this->tagService->deleteUnusedTags($workspaceId);
            }

            $this->database->commit();
        } catch (Throwable $purgeFailure) {
            $this->database->rollBack();
            throw $purgeFailure;
        }

        // Files go only once their rows are gone for good. A file that cannot be deleted is an
        // orphan nobody can reach, since no attachment row points at it any more.
        foreach ($attachmentIds as $attachmentId) {
            $this->attachmentStorage->delete($attachmentId);
        }

        return count($noteRows);
    }
}
