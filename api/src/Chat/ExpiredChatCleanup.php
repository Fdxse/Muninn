<?php

declare(strict_types=1);

namespace Muninn\Api\Chat;

use Muninn\Api\Logging\AppLogger;
use Muninn\Api\Logging\AuditLog;
use PDO;
use Throwable;

/**
 * Deletes chat messages older than chat.retention_days (90 by default, decision D062), so the
 * NAS needs no scheduled task. Works exactly like the Trash cleanup (ExpiredTrashCleanup, D039):
 * the application calls runIfDue() after it has answered a signed-in request, and the first such
 * request after an hour has passed claims the run in maintenance_runs with one atomic statement.
 * Each run deletes a bounded number of messages; anything left over goes in the next run.
 *
 * A failure is logged and swallowed: housekeeping must never break the user's request.
 */
final class ExpiredChatCleanup
{
    /** Name of this task's row in maintenance_runs. */
    private const TASK_NAME = 'expired_chat_cleanup';

    /** Minimum time between two runs. */
    private const MINUTES_BETWEEN_RUNS = 60;

    /** Upper bound on the messages one run deletes. */
    private const MAXIMUM_MESSAGES_PER_RUN = 5000;

    public function __construct(
        private readonly PDO $database,
        private readonly ChatService $chatService,
        private readonly AuditLog $auditLog,
        private readonly AppLogger $logger,
        private readonly int $retentionDays,
    ) {
    }

    /**
     * Runs the cleanup if the last run started more than an hour ago.
     *
     * @return int|null Messages deleted, or null when it was not this request's turn (or the run failed).
     */
    public function runIfDue(): ?int
    {
        try {
            if (!$this->claimRun()) {
                return null;
            }

            $deletedMessageCount = $this->chatService->deleteExpired($this->retentionDays, self::MAXIMUM_MESSAGES_PER_RUN);
            if ($deletedMessageCount > 0) {
                // No actor and no text: the API did this on its own, and chat stays private.
                $this->auditLog->record(AuditLog::CHAT_EXPIRED_DELETED, null, null, null, null, [
                    'deleted_messages' => $deletedMessageCount,
                    'retention_days' => $this->retentionDays,
                    'run_by' => 'api',
                ]);
            }

            return $deletedMessageCount;
        } catch (Throwable $cleanupFailure) {
            $this->logger->error('Expired chat cleanup failed.', [
                'exception' => $cleanupFailure::class,
                'message' => $cleanupFailure->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Atomically records that a run starts now, but only when the previous run started more than
     * MINUTES_BETWEEN_RUNS ago (or never ran). Any non-zero affected-row count means this request
     * won the run (see ExpiredTrashCleanup::claimRun()).
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
