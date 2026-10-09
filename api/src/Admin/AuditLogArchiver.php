<?php

declare(strict_types=1);

namespace Muninn\Api\Admin;

use Muninn\Api\Database\UtcTimestamp;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Moves old audit log entries into zip files on the NAS (D060).
 *
 * The audit log is kept forever, but entries older than audit_log.archive_after_months (13 by
 * default) can be "extracted, zipped and deleted" on the administrator's request:
 *
 *   1. every entry older than the cut-off is written, one JSON object per line, to a work file;
 *   2. the work file and a small manifest go into audit-log-<date>-<time>.zip in the archive folder;
 *   3. the zip is opened again and read back line by line; only when it holds exactly as many
 *      entries as were exported are those entries deleted from the database, in one transaction.
 *
 * Any failure before step 3 finishes leaves the database untouched and removes the half-made
 * files. The zips stay on the NAS (outside the web root, inside the folder that is backed up);
 * the administrator downloads them through the API, never as public files.
 */
final class AuditLogArchiver
{
    /** Entries read from the database per query while exporting, to keep memory use flat. */
    private const EXPORT_BATCH_SIZE = 1000;

    /** Only files named like this are ever listed or served. */
    private const ARCHIVE_FILE_PATTERN = '/^audit-log-\d{8}-\d{6}\.zip$/';

    /** Names of the files inside each zip. */
    private const ENTRIES_FILE_NAME = 'audit-log.jsonl';
    private const MANIFEST_FILE_NAME = 'manifest.json';

    /** MariaDB named lock that stops two administrators archiving at the same moment. */
    private const LOCK_NAME = 'muninn_audit_log_archive';

    public function __construct(
        private readonly PDO $database,
        private readonly string $archiveFolder,
        private readonly int $archiveAfterMonths,
    ) {
    }

    /** Zip support is a PHP extension; check-setup and the overview warn when it is missing. */
    public static function zipSupportAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function archiveAfterMonths(): int
    {
        return $this->archiveAfterMonths;
    }

    /** How many entries are old enough to be archived right now. */
    public function countArchivableEntries(): int
    {
        $countStatement = $this->database->prepare('SELECT COUNT(*) FROM audit_log WHERE created_at < :cutoff');
        $countStatement->execute(['cutoff' => $this->cutoff()]);

        return (int) $countStatement->fetchColumn();
    }

    /**
     * Archives and deletes every entry older than the cut-off.
     *
     * @return array<string, mixed>|null The new archive, or null when nothing was old enough.
     * @throws AuditLogArchiveException When zip support is missing, another archive is running,
     *                                  or the archive could not be written and verified.
     */
    public function archiveOldEntries(): ?array
    {
        if (!self::zipSupportAvailable()) {
            throw new AuditLogArchiveException('zip_unavailable', 'The PHP zip extension is not enabled on the server.');
        }

        $lockAcquired = (int) $this->database->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn() === 1;
        if (!$lockAcquired) {
            throw new AuditLogArchiveException('archive_running', 'An archive is already being made. Try again in a minute.');
        }

        try {
            return $this->archiveWhileLocked();
        } finally {
            $this->database->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    /**
     * The archives in the archive folder, newest first.
     *
     * @return list<array{id: string, file_name: string, byte_size: int, created_at: string}>
     */
    public function listArchives(): array
    {
        if (!is_dir($this->archiveFolder)) {
            return [];
        }

        $archives = [];
        foreach (scandir($this->archiveFolder) ?: [] as $folderEntry) {
            $archivePath = $this->archiveFolder . '/' . $folderEntry;
            if (!preg_match(self::ARCHIVE_FILE_PATTERN, $folderEntry) || !is_file($archivePath)) {
                continue;
            }
            $archives[] = [
                // The name without ".zip" addresses the archive in download URLs: a dot in the last
                // path segment makes some servers (PHP's own development server) treat it as a file.
                'id' => basename($folderEntry, '.zip'),
                'file_name' => $folderEntry,
                'byte_size' => (int) filesize($archivePath),
                'created_at' => gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($archivePath)),
            ];
        }
        // The file names contain the date and time, so a reverse name sort is newest first.
        usort($archives, static fn (array $firstArchive, array $secondArchive): int => strcmp($secondArchive['file_name'], $firstArchive['file_name']));

        return $archives;
    }

    /**
     * The full path of an existing archive, or null when the name is not one of ours. The strict
     * name pattern rules out paths, "..", and every other file in the folder.
     */
    public function archivePath(string $requestedFileName): ?string
    {
        if (!preg_match(self::ARCHIVE_FILE_PATTERN, $requestedFileName)) {
            return null;
        }
        $archivePath = $this->archiveFolder . '/' . $requestedFileName;

        return is_file($archivePath) ? $archivePath : null;
    }

    /** @return array<string, mixed>|null */
    private function archiveWhileLocked(): ?array
    {
        $cutoff = $this->cutoff();
        $this->ensureArchiveFolder();

        $archiveFileName = 'audit-log-' . gmdate('Ymd-His') . '.zip';
        $archivePath = $this->archiveFolder . '/' . $archiveFileName;
        // Work files start with a dot so they never match the archive pattern.
        $workFilePath = $this->archiveFolder . '/.audit-log-export-' . bin2hex(random_bytes(6)) . '.jsonl';
        $partialArchivePath = $archivePath . '.partial';

        try {
            $export = $this->exportEntries($cutoff, $workFilePath);
            if ($export['entry_count'] === 0) {
                return null;
            }
            // Names have one-second precision; never overwrite an archive made a moment ago.
            if (file_exists($archivePath)) {
                throw new AuditLogArchiveException('archive_running', 'An archive was made a moment ago. Try again in a minute.');
            }

            $this->writeZip($partialArchivePath, $workFilePath, [
                'description' => 'Muninn audit log entries older than ' . $this->archiveAfterMonths . ' months, moved out of the database.',
                'format' => 'audit-log.jsonl holds one JSON object per line, one per audit_log row; times are UTC.',
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'cutoff' => UtcTimestamp::toIso($cutoff),
                'entry_count' => $export['entry_count'],
                'oldest_entry_at' => $export['oldest_entry_at'],
                'newest_entry_at' => $export['newest_entry_at'],
            ]);
            $this->verifyZip($partialArchivePath, $export['entry_count'], (int) filesize($workFilePath));

            if (!rename($partialArchivePath, $archivePath)) {
                throw new AuditLogArchiveException('archive_failed', 'The archive could not be saved.');
            }
            @chmod($archivePath, 0640);

            $this->deleteArchivedEntries($cutoff, $export['entry_count'], $archivePath);
        } finally {
            // Never leave half-made files behind, whatever happened.
            if (is_file($workFilePath)) {
                @unlink($workFilePath);
            }
            if (is_file($partialArchivePath)) {
                @unlink($partialArchivePath);
            }
        }

        return [
            'id' => basename($archiveFileName, '.zip'),
            'file_name' => $archiveFileName,
            'byte_size' => (int) filesize($archivePath),
            'entry_count' => $export['entry_count'],
            'oldest_entry_at' => $export['oldest_entry_at'],
            'newest_entry_at' => $export['newest_entry_at'],
            'cutoff' => UtcTimestamp::toIso($cutoff),
        ];
    }

    /** The UTC moment before which entries are archived, as a database DATETIME string. */
    private function cutoff(): string
    {
        $cutoffStatement = $this->database->prepare('SELECT UTC_TIMESTAMP() - INTERVAL :month_count MONTH');
        $cutoffStatement->bindValue('month_count', $this->archiveAfterMonths, PDO::PARAM_INT);
        $cutoffStatement->execute();

        return (string) $cutoffStatement->fetchColumn();
    }

    private function ensureArchiveFolder(): void
    {
        if (!is_dir($this->archiveFolder) && !@mkdir($this->archiveFolder, 0750, true) && !is_dir($this->archiveFolder)) {
            throw new AuditLogArchiveException('archive_failed', 'The archive folder could not be created on the server.');
        }
        if (!is_writable($this->archiveFolder)) {
            throw new AuditLogArchiveException('archive_failed', 'The server cannot write to the archive folder.');
        }
    }

    /**
     * Writes every entry older than the cut-off to the work file, oldest first, in batches.
     *
     * @return array{entry_count: int, oldest_entry_at: string|null, newest_entry_at: string|null}
     */
    private function exportEntries(string $cutoff, string $workFilePath): array
    {
        $workFile = fopen($workFilePath, 'wb');
        if ($workFile === false) {
            throw new AuditLogArchiveException('archive_failed', 'The server cannot write to the archive folder.');
        }

        $entryCount = 0;
        $oldestEntryAt = null;
        $newestEntryAt = null;
        // Keyset pagination: continue after the last (created_at, id) pair of the previous batch.
        $lastCreatedAt = '1000-01-01 00:00:00';
        $lastEntryId = '';
        $batchStatement = $this->database->prepare(
            'SELECT id, event_type, actor_user_id, target_type, target_id, ip_address, details, created_at
             FROM audit_log
             WHERE created_at < :cutoff
               AND (created_at > :last_created_at OR (created_at = :same_created_at AND id > :last_id))
             ORDER BY created_at, id
             LIMIT ' . self::EXPORT_BATCH_SIZE
        );

        try {
            do {
                $batchStatement->execute([
                    'cutoff' => $cutoff,
                    'last_created_at' => $lastCreatedAt,
                    'same_created_at' => $lastCreatedAt,
                    'last_id' => $lastEntryId,
                ]);
                $entryRows = $batchStatement->fetchAll(PDO::FETCH_ASSOC);

                foreach ($entryRows as $entryRow) {
                    $exportLine = json_encode(self::exportedEntry($entryRow), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if (fwrite($workFile, $exportLine . "\n") === false) {
                        throw new AuditLogArchiveException('archive_failed', 'The server could not write the archive (is the disk full?).');
                    }
                    $entryCount++;
                    $oldestEntryAt ??= UtcTimestamp::toIso((string) $entryRow['created_at']);
                    $newestEntryAt = UtcTimestamp::toIso((string) $entryRow['created_at']);
                    $lastCreatedAt = (string) $entryRow['created_at'];
                    $lastEntryId = (string) $entryRow['id'];
                }
            } while (count($entryRows) === self::EXPORT_BATCH_SIZE);
        } finally {
            fclose($workFile);
        }

        return ['entry_count' => $entryCount, 'oldest_entry_at' => $oldestEntryAt, 'newest_entry_at' => $newestEntryAt];
    }

    /**
     * One audit_log row as it is written to the archive. The stored details are JSON already, so
     * they are decoded and kept as an object instead of a string inside a string.
     *
     * @param array<string, mixed> $entryRow
     * @return array<string, mixed>
     */
    private static function exportedEntry(array $entryRow): array
    {
        $decodedDetails = json_decode((string) $entryRow['details'], true);

        return [
            'id' => $entryRow['id'],
            'created_at' => UtcTimestamp::toIso((string) $entryRow['created_at']),
            'event_type' => $entryRow['event_type'],
            'actor_user_id' => $entryRow['actor_user_id'],
            'target_type' => $entryRow['target_type'],
            'target_id' => $entryRow['target_id'],
            'ip_address' => $entryRow['ip_address'],
            'details' => is_array($decodedDetails) ? $decodedDetails : $entryRow['details'],
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function writeZip(string $zipPath, string $workFilePath, array $manifest): void
    {
        $zipArchive = new ZipArchive();
        if ($zipArchive->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new AuditLogArchiveException('archive_failed', 'The archive could not be created.');
        }
        $filesAdded = $zipArchive->addFile($workFilePath, self::ENTRIES_FILE_NAME)
            && $zipArchive->addFromString(self::MANIFEST_FILE_NAME, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        // close() is where ZipArchive actually compresses and writes the file.
        if (!$filesAdded || !$zipArchive->close()) {
            throw new AuditLogArchiveException('archive_failed', 'The archive could not be written (is the disk full?).');
        }
    }

    /**
     * Opens the finished zip again and reads the entries back, so nothing is deleted from the
     * database unless the archive really holds every exported entry.
     */
    private function verifyZip(string $zipPath, int $expectedEntryCount, int $expectedByteSize): void
    {
        $zipArchive = new ZipArchive();
        if ($zipArchive->open($zipPath, ZipArchive::CHECKCONS) !== true) {
            throw new AuditLogArchiveException('archive_failed', 'The archive could not be read back, so nothing was deleted.');
        }

        try {
            $entriesFileStats = $zipArchive->statName(self::ENTRIES_FILE_NAME);
            $entriesStream = $zipArchive->getStream(self::ENTRIES_FILE_NAME);
            if ($entriesFileStats === false || $entriesFileStats['size'] !== $expectedByteSize || $entriesStream === false) {
                throw new AuditLogArchiveException('archive_failed', 'The archive could not be read back, so nothing was deleted.');
            }

            $readBackCount = 0;
            while (($entryLine = fgets($entriesStream)) !== false) {
                if (trim($entryLine) !== '') {
                    $readBackCount++;
                }
            }
            fclose($entriesStream);
            if ($readBackCount !== $expectedEntryCount) {
                throw new AuditLogArchiveException('archive_failed', 'The archive did not hold every entry, so nothing was deleted.');
            }
        } finally {
            $zipArchive->close();
        }
    }

    /**
     * Deletes the archived entries in one transaction. If the database would delete a different
     * number than was archived, nothing is deleted and the new zip is removed again.
     */
    private function deleteArchivedEntries(string $cutoff, int $archivedEntryCount, string $archivePath): void
    {
        $this->database->beginTransaction();
        try {
            $deleteStatement = $this->database->prepare('DELETE FROM audit_log WHERE created_at < :cutoff');
            $deleteStatement->execute(['cutoff' => $cutoff]);
            if ($deleteStatement->rowCount() !== $archivedEntryCount) {
                throw new AuditLogArchiveException('archive_failed', 'The audit log changed while it was archived, so nothing was deleted. Try again.');
            }
            $this->database->commit();
        } catch (Throwable $deleteFailure) {
            $this->database->rollBack();
            @unlink($archivePath);
            if ($deleteFailure instanceof AuditLogArchiveException) {
                throw $deleteFailure;
            }
            throw new RuntimeException('Deleting archived audit log entries failed.', 0, $deleteFailure);
        }
    }
}
