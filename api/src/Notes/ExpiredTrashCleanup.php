<?php

declare(strict_types=1);

namespace Muninn\Api\Notes;

use Muninn\Api\Logging\AppLogger;
use Muninn\Api\Logging\AuditLog;
use PDO;
use Throwable;

/**
 * Lets the API itself delete notes that have been in Trash longer than trash.retention_days
 * (decision D039), so no scheduled task is needed on the NAS.
 *
 * The application calls runIfDue() after it has answered a signed-in request. The cleanup then
 * runs at most once per hour across all requests: the first request after the hour has passed
 * claims the run in maintenance_runs with one atomic statement, so two simultaneous requests can
 * never both run it. Each run deletes a bounded number of notes, so it never takes long; anything
 * left over is picked up by the next run.
 *
 * A failure is logged and swallowed: housekeeping must never break the user's request.
 */
final class ExpiredTrashCleanup
{
    /** Name of this task's row in maintenance_runs. */
    private const TASK_NAME = 'expired_trash_cleanup';

    /** Minimum time between two runs. */
    private const MINUTES_BETWEEN_RUNS = 60;

    /** Upper bound on the notes one run deletes (whole purge batches of 100). */
    private const MAXIMUM_NOTES_PER_RUN = 500;

    public function __construct(
        private readonly PDO $database,
        private readonly NotePurger $notePurger,
        private readonly AuditLog $auditLog,
        private readonly AppLogger $logger,
        private readonly int $retentionDays,
    ) {
    }

    /**
     * Runs the cleanup if the last run started more than an hour ago.
     *
     * @return int|null Number of notes deleted, or null when it was not this request's turn
     *                  (or the run failed).
     */
    public function runIfDue(): ?int
    {
        try {
            if (!$this->claimRun()) {
                return null;
            }

            $purgedNoteCount = $this->notePurger->purgeExpired($this->retentionDays, self::MAXIMUM_NOTES_PER_RUN);
            if ($purgedNoteCount > 0) {
                // No actor: the API did this on its own, not on behalf of the signed-in user.
                $this->auditLog->record(AuditLog::TRASH_EXPIRED_PURGED, null, null, null, null, [
                    'deleted_notes' => $purgedNoteCount,
                    'retention_days' => $this->retentionDays,
                    'run_by' => 'api',
                ]);
            }

            return $purgedNoteCount;
        } catch (Throwable $cleanupFailure) {
            $this->logger->error('Expired Trash cleanup failed.', [
                'exception' => $cleanupFailure::class,
                'message' => $cleanupFailure->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Atomically records that a run starts now, but only when the previous run started more than
     * MINUTES_BETWEEN_RUNS ago (or never ran).
     *
     * MariaDB reports 1 affected row for a new row, 2 for a changed row and 0 when the existing
     * row was left as it is, so any non-zero count means this request won the run.
     */
    private function claimRun(): bool
    {
        $claimStatement = $this->database->prepare(
            'INSERT INTO maintenance_runs (task_name, last_started_at) VALUES (:task_name, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE last_started_at = IF(
                 last_started_at <= UTC_TIMESTAMP() - INTERVAL ' . self::MINUTES_BETWEEN_RUNS . ' MINUTE,
                 UTC_TIMESTAMP(),
                 last_started_at
             )'
        );
        $claimStatement->execute(['task_name' => self::TASK_NAME]);

        return $claimStatement->rowCount() > 0;
    }
}
