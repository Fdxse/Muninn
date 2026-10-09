<?php

declare(strict_types=1);

namespace Muninn\Api\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Muninn\Api\Database\Migrator;
use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Logging\AuditLog;
use PDO;

/**
 * Gathers the numbers for the administrator's overview page (D060).
 *
 * Everything here is a count, a size or a time grid for the whole installation. Nothing names a
 * note, folder, tag or Magic Link, and nothing is broken down per user, because the system
 * administrator has no access to note data (D025, D050) and the overview must not become a way
 * to watch individual people.
 */
final class SystemOverviewService
{
    /** Thresholds for the "Needs attention" list. */
    private const DISK_WARNING_FREE_PERCENT = 10;
    private const DISK_WARNING_FREE_BYTES = 5_000_000_000;
    private const DISK_DANGER_FREE_PERCENT = 5;
    private const DISK_DANGER_FREE_BYTES = 1_000_000_000;
    private const FAILED_SIGN_IN_WARNING_PER_DAY = 20;
    private const LOG_FILE_WARNING_BYTES = 50_000_000;
    private const INACTIVE_ACCOUNT_DAYS = 90;
    private const MAGIC_LINK_EXPIRY_NOTICE_DAYS = 7;
    private const NOTE_WEEKS_SHOWN = 12;

    /** How much of the end of the application log is read to count recent errors. */
    private const LOG_TAIL_BYTES = 2_000_000;

    /** The tables whose exact row counts are shown, because they grow on their own. */
    private const GROWING_TABLES = ['audit_log', 'note_versions', 'sessions', 'auth_attempts', 'attachments'];

    public function __construct(
        private readonly PDO $database,
        private readonly DateTimeZone $displayTimeZone,
        private readonly AuditLogArchiver $auditLogArchiver,
        private readonly string $attachmentFolder,
        private readonly string $logFilePath,
        private readonly string $migrationsFolder,
        private readonly int $trashRetentionDays,
        private readonly int $sessionIdleHours,
        private readonly bool $isProduction,
    ) {
    }

    /**
     * The whole overview except the sign-in attempts, which SignInAttemptReport builds.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $heatMap = new ActivityHeatMap($this->database, $this->displayTimeZone);
        $overview = [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'timezone' => $this->displayTimeZone->getName(),
            'users' => $this->userStatistics(),
            'content' => $this->contentStatistics(),
            'magic_links' => $this->magicLinkStatistics(),
            'activity' => [
                'last_4_weeks' => [
                    'sign_ins' => $heatMap->grid(AuditLog::LOGIN_SUCCEEDED, 4),
                    'magic_link_visits' => $heatMap->grid(AuditLog::MAGIC_LINK_OPENED, 4),
                ],
                'last_12_weeks' => [
                    'sign_ins' => $heatMap->grid(AuditLog::LOGIN_SUCCEEDED, 12),
                    'magic_link_visits' => $heatMap->grid(AuditLog::MAGIC_LINK_OPENED, 12),
                ],
            ],
            'database' => $this->databaseStatistics(),
            'storage' => $this->storageStatistics(),
            'audit_log' => $this->auditLogStatistics(),
            'maintenance' => $this->maintenanceStatistics(),
            'software' => [
                'php_version' => PHP_VERSION,
                'database_version' => (string) $this->database->query('SELECT VERSION()')->fetchColumn(),
                'zip_available' => AuditLogArchiver::zipSupportAvailable(),
            ],
        ];
        $overview['warnings'] = $this->warnings($overview);

        return $overview;
    }

    /** @return array<string, int> */
    private function userStatistics(): array
    {
        $userRow = $this->database->query(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(status = 'active'), 0) AS active,
                COALESCE(SUM(status = 'disabled'), 0) AS disabled,
                COALESCE(SUM(is_system_admin = 1), 0) AS administrators,
                COALESCE(SUM(last_login_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY), 0) AS signed_in_last_7_days,
                COALESCE(SUM(last_login_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY), 0) AS signed_in_last_30_days,
                COALESCE(SUM(status = 'active' AND last_login_at IS NULL), 0) AS never_signed_in,
                COALESCE(SUM(status = 'active' AND created_at < UTC_TIMESTAMP() - INTERVAL " . self::INACTIVE_ACCOUNT_DAYS . " DAY
                    AND (last_login_at IS NULL OR last_login_at < UTC_TIMESTAMP() - INTERVAL " . self::INACTIVE_ACCOUNT_DAYS . ' DAY)), 0) AS inactive_90_days
             FROM users'
        )->fetch(PDO::FETCH_ASSOC);

        $openSessionsStatement = $this->database->prepare(
            'SELECT COUNT(*) FROM sessions
             WHERE revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
               AND last_seen_at > UTC_TIMESTAMP() - INTERVAL :idle_hours HOUR'
        );
        $openSessionsStatement->bindValue('idle_hours', $this->sessionIdleHours, PDO::PARAM_INT);
        $openSessionsStatement->execute();

        return array_map('intval', $userRow) + [
            'open_sessions' => (int) $openSessionsStatement->fetchColumn(),
            'open_invitations' => $this->count(
                'SELECT COUNT(*) FROM invitations WHERE accepted_at IS NULL AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()'
            ),
            'pending_invitation_requests' => $this->count("SELECT COUNT(*) FROM invitation_requests WHERE status = 'pending'"),
        ];
    }

    /** @return array<string, mixed> */
    private function contentStatistics(): array
    {
        $noteRow = $this->database->query(
            'SELECT
                COALESCE(SUM(trashed_at IS NULL AND archived_at IS NULL), 0) AS notes_active,
                COALESCE(SUM(trashed_at IS NULL AND archived_at IS NOT NULL), 0) AS notes_archived,
                COALESCE(SUM(trashed_at IS NOT NULL), 0) AS notes_in_trash
             FROM notes'
        )->fetch(PDO::FETCH_ASSOC);
        $imageRow = $this->database->query('SELECT COUNT(*) AS image_count, COALESCE(SUM(byte_size), 0) AS image_bytes FROM attachments')
            ->fetch(PDO::FETCH_ASSOC);

        return array_map('intval', $noteRow) + [
            'notes_created_per_week' => $this->notesCreatedPerWeek(),
            'workspaces' => $this->count('SELECT COUNT(*) FROM workspaces'),
            'folders' => $this->count('SELECT COUNT(*) FROM folders'),
            'tags' => $this->count('SELECT COUNT(*) FROM tags'),
            'note_versions' => $this->count('SELECT COUNT(*) FROM note_versions'),
            'images' => (int) $imageRow['image_count'],
            'image_bytes' => (int) $imageRow['image_bytes'],
        ];
    }

    /**
     * Notes created in each of the last 12 weeks (Monday to Sunday in the display time zone),
     * oldest week first. Counts every note created, wherever it is now.
     *
     * @return list<array{week_start: string, count: int}>
     */
    private function notesCreatedPerWeek(): array
    {
        $thisMonday = (new DateTimeImmutable('now', $this->displayTimeZone))->modify('monday this week')->setTime(0, 0);
        $firstMonday = $thisMonday->modify('-' . (self::NOTE_WEEKS_SHOWN - 1) . ' weeks');

        $weeks = [];
        for ($weekIndex = 0; $weekIndex < self::NOTE_WEEKS_SHOWN; $weekIndex++) {
            $weeks[$firstMonday->modify('+' . $weekIndex . ' weeks')->format('Y-m-d')] = 0;
        }

        // Grouped per UTC hour in the database, then moved into local weeks (see ActivityHeatMap).
        $countStatement = $this->database->prepare(
            "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H') AS utc_hour, COUNT(*) AS note_count
             FROM notes WHERE created_at >= :since GROUP BY utc_hour"
        );
        $countStatement->execute([
            'since' => $firstMonday->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
        foreach ($countStatement->fetchAll(PDO::FETCH_ASSOC) as $hourRow) {
            $localTime = (new DateTimeImmutable($hourRow['utc_hour'] . ':00:00', new DateTimeZone('UTC')))->setTimezone($this->displayTimeZone);
            $weekStart = $localTime->modify('monday this week')->format('Y-m-d');
            if (isset($weeks[$weekStart])) {
                $weeks[$weekStart] += (int) $hourRow['note_count'];
            }
        }

        $weekList = [];
        foreach ($weeks as $weekStart => $noteCount) {
            $weekList[] = ['week_start' => $weekStart, 'count' => $noteCount];
        }

        return $weekList;
    }

    /** Counts only: Magic Link labels and targets stay hidden from the system administrator (D059). */
    private function magicLinkStatistics(): array
    {
        $linkRow = $this->database->query(
            'SELECT
                COALESCE(SUM(revoked_at IS NULL AND valid_from <= UTC_TIMESTAMP() AND valid_until > UTC_TIMESTAMP()), 0) AS active,
                COALESCE(SUM(revoked_at IS NULL AND valid_until > UTC_TIMESTAMP()
                    AND valid_until <= UTC_TIMESTAMP() + INTERVAL ' . self::MAGIC_LINK_EXPIRY_NOTICE_DAYS . ' DAY), 0) AS expiring_within_7_days
             FROM magic_links'
        )->fetch(PDO::FETCH_ASSOC);
        $visitsStatement = $this->database->prepare(
            'SELECT COUNT(*) FROM audit_log WHERE event_type = :event_type AND created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY'
        );
        $visitsStatement->execute(['event_type' => AuditLog::MAGIC_LINK_OPENED]);

        return array_map('intval', $linkRow) + ['visits_last_30_days' => (int) $visitsStatement->fetchColumn()];
    }

    /** @return array<string, mixed> */
    private function databaseStatistics(): array
    {
        // information_schema only describes this database's own tables to this account.
        $tableRows = $this->database->query(
            'SELECT TABLE_NAME AS table_name, COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS byte_size
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
             ORDER BY byte_size DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $rowCounts = [];
        foreach (self::GROWING_TABLES as $tableName) {
            // Table names come from the constant above, never from a request.
            $rowCounts[$tableName] = $this->count('SELECT COUNT(*) FROM ' . $tableName);
        }

        $migrator = new Migrator($this->database, $this->migrationsFolder);

        return [
            'total_bytes' => array_sum(array_map(static fn (array $tableRow): int => (int) $tableRow['byte_size'], $tableRows)),
            'largest_tables' => array_map(static fn (array $tableRow): array => [
                'name' => (string) $tableRow['table_name'],
                'byte_size' => (int) $tableRow['byte_size'],
            ], array_slice($tableRows, 0, 5)),
            'row_counts' => $rowCounts,
            'latest_migration' => $this->database->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() ?: null,
            'pending_migrations' => $migrator->pendingVersions(),
        ];
    }

    /** @return array<string, int|null> */
    private function storageStatistics(): array
    {
        // The image folder may not exist before the first upload; measure the disk it will be on.
        $measuredFolder = is_dir($this->attachmentFolder) ? $this->attachmentFolder : dirname($this->attachmentFolder);
        $freeBytes = is_dir($measuredFolder) ? @disk_free_space($measuredFolder) : false;
        $totalBytes = is_dir($measuredFolder) ? @disk_total_space($measuredFolder) : false;

        $archiveBytes = 0;
        foreach ($this->auditLogArchiver->listArchives() as $archive) {
            $archiveBytes += $archive['byte_size'];
        }

        return [
            'disk_free_bytes' => $freeBytes === false ? null : (int) $freeBytes,
            'disk_total_bytes' => $totalBytes === false ? null : (int) $totalBytes,
            'log_file_bytes' => is_file($this->logFilePath) ? (int) filesize($this->logFilePath) : 0,
            'log_errors_last_24_hours' => $this->recentLogErrorCount(),
            'audit_archive_bytes' => $archiveBytes,
        ];
    }

    /** @return array<string, mixed> */
    private function auditLogStatistics(): array
    {
        return [
            'entries' => $this->count('SELECT COUNT(*) FROM audit_log'),
            'oldest_entry_at' => UtcTimestamp::toIsoOrNull($this->database->query('SELECT MIN(created_at) FROM audit_log')->fetchColumn() ?: null),
            'archive_after_months' => $this->auditLogArchiver->archiveAfterMonths(),
            'archivable_entries' => $this->auditLogArchiver->countArchivableEntries(),
            'archives' => $this->auditLogArchiver->listArchives(),
        ];
    }

    /** @return array<string, mixed> */
    private function maintenanceStatistics(): array
    {
        $lastRunStatement = $this->database->query("SELECT last_started_at FROM maintenance_runs WHERE task_name = 'expired_trash_cleanup'");
        $overdueStatement = $this->database->prepare(
            'SELECT COUNT(*) FROM notes WHERE trashed_at IS NOT NULL AND trashed_at < UTC_TIMESTAMP() - INTERVAL :overdue_days DAY'
        );
        // One day of slack: the cleanup runs at most hourly, and only after a signed-in request.
        $overdueStatement->bindValue('overdue_days', $this->trashRetentionDays + 1, PDO::PARAM_INT);
        $overdueStatement->execute();

        return [
            'trash_cleanup_last_run_at' => UtcTimestamp::toIsoOrNull($lastRunStatement->fetchColumn() ?: null),
            'trash_retention_days' => $this->trashRetentionDays,
            'overdue_trash_notes' => (int) $overdueStatement->fetchColumn(),
        ];
    }

    /**
     * Counts "error" lines from the last 24 hours at the end of the application log. Only the
     * last part of the file is read, so a huge log never slows the page down.
     */
    private function recentLogErrorCount(): int
    {
        if (!is_file($this->logFilePath) || !is_readable($this->logFilePath)) {
            return 0;
        }
        $logFile = @fopen($this->logFilePath, 'rb');
        if ($logFile === false) {
            return 0;
        }
        $fileSize = (int) filesize($this->logFilePath);
        fseek($logFile, max(0, $fileSize - self::LOG_TAIL_BYTES));
        $logTail = (string) stream_get_contents($logFile);
        fclose($logFile);

        $since = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
        $errorCount = 0;
        foreach (explode("\n", $logTail) as $logLine) {
            $logEntry = json_decode($logLine, true);
            // ISO timestamps in UTC compare correctly as plain strings.
            if (is_array($logEntry) && ($logEntry['level'] ?? null) === 'error' && (string) ($logEntry['time'] ?? '') >= $since) {
                $errorCount++;
            }
        }

        return $errorCount;
    }

    /**
     * The "Needs attention" list: only what is wrong or about to go wrong, most serious first.
     *
     * @param array<string, mixed> $overview
     * @return list<array{level: string, code: string, message: string}>
     */
    private function warnings(array $overview): array
    {
        $warnings = [];
        $addWarning = static function (string $level, string $code, string $message) use (&$warnings): void {
            $warnings[] = ['level' => $level, 'code' => $code, 'message' => $message];
        };

        $storage = $overview['storage'];
        if ($storage['disk_free_bytes'] !== null && $storage['disk_total_bytes']) {
            $freePercent = 100 * $storage['disk_free_bytes'] / $storage['disk_total_bytes'];
            if ($freePercent < self::DISK_DANGER_FREE_PERCENT || $storage['disk_free_bytes'] < self::DISK_DANGER_FREE_BYTES) {
                $addWarning('danger', 'disk_almost_full', 'The disk is almost full: uploads and saves will soon fail.');
            } elseif ($freePercent < self::DISK_WARNING_FREE_PERCENT || $storage['disk_free_bytes'] < self::DISK_WARNING_FREE_BYTES) {
                $addWarning('warning', 'disk_low', 'Disk space is getting low.');
            }
        }

        $pendingMigrations = $overview['database']['pending_migrations'];
        if ($pendingMigrations !== []) {
            $addWarning('danger', 'migrations_pending', 'Database updates are waiting: run sudo php84 bin/migrate.php on the NAS (' . implode(', ', $pendingMigrations) . ').');
        }

        if (!$this->isProduction) {
            $addWarning('warning', 'not_production', 'The API runs in development mode. Set app.environment to production in config.php.');
        }

        $signInsToday = $this->signInTotalsLastDay();
        if ($signInsToday['blocked'] > 0) {
            $addWarning('warning', 'sign_ins_blocked', $signInsToday['blocked'] . ' sign-in attempts were blocked in the last 24 hours after repeated wrong passwords.');
        }
        if ($signInsToday['failed'] > self::FAILED_SIGN_IN_WARNING_PER_DAY) {
            $addWarning('warning', 'many_failed_sign_ins', $signInsToday['failed'] . ' failed sign-ins in the last 24 hours.');
        }

        if ($overview['maintenance']['overdue_trash_notes'] > 0) {
            $addWarning('warning', 'trash_cleanup_behind', $overview['maintenance']['overdue_trash_notes'] . ' notes have stayed in Trash longer than ' . $this->trashRetentionDays . ' days: the automatic cleanup is not keeping up. Check the application log.');
        }

        if ($storage['log_errors_last_24_hours'] > 0) {
            $addWarning('warning', 'log_errors', $storage['log_errors_last_24_hours'] . ' errors in the application log in the last 24 hours.');
        }
        if ($storage['log_file_bytes'] > self::LOG_FILE_WARNING_BYTES) {
            $addWarning('warning', 'log_file_large', 'The application log file is larger than 50 MB.');
        }

        if (!$overview['software']['zip_available']) {
            $addWarning('warning', 'zip_unavailable', 'The PHP zip extension is off, so the audit log cannot be archived. Turn on "zip" in the Web Station PHP profile.');
        }
        if ($overview['audit_log']['archivable_entries'] > 0) {
            $addWarning('info', 'audit_log_archivable', $overview['audit_log']['archivable_entries'] . ' audit log entries are older than ' . $overview['audit_log']['archive_after_months'] . ' months and can be archived.');
        }
        if ($overview['users']['pending_invitation_requests'] > 0) {
            $addWarning('info', 'invitation_requests_pending', $overview['users']['pending_invitation_requests'] . ' invitation requests are waiting for your decision.');
        }
        if ($overview['magic_links']['expiring_within_7_days'] > 0) {
            $addWarning('info', 'magic_links_expiring', $overview['magic_links']['expiring_within_7_days'] . ' Magic Links stop working within 7 days.');
        }

        // Most serious first: danger, then warning, then info; otherwise in the order added.
        $levelOrder = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($warnings, static fn (array $firstWarning, array $secondWarning): int => $levelOrder[$firstWarning['level']] <=> $levelOrder[$secondWarning['level']]);

        return $warnings;
    }

    /** @return array{failed: int, blocked: int} */
    private function signInTotalsLastDay(): array
    {
        $totalsStatement = $this->database->prepare(
            'SELECT event_type, COUNT(*) FROM audit_log
             WHERE event_type IN (:failed, :blocked) AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY
             GROUP BY event_type'
        );
        $totalsStatement->execute(['failed' => AuditLog::LOGIN_FAILED, 'blocked' => AuditLog::LOGIN_RATE_LIMITED]);
        $countsByEventType = $totalsStatement->fetchAll(PDO::FETCH_KEY_PAIR);

        return [
            'failed' => (int) ($countsByEventType[AuditLog::LOGIN_FAILED] ?? 0),
            'blocked' => (int) ($countsByEventType[AuditLog::LOGIN_RATE_LIMITED] ?? 0),
        ];
    }

    /** Runs a fixed COUNT(*) query (never built from request input). */
    private function count(string $countQuery): int
    {
        return (int) $this->database->query($countQuery)->fetchColumn();
    }
}
