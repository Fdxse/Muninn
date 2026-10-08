<?php

declare(strict_types=1);

namespace Muninn\Api\Database;

use PDO;
use RuntimeException;

/**
 * Applies numbered, forward-only SQL migrations from the migrations/ folder.
 *
 * Files are named NNNN_description.sql and applied in name order. Each applied version is
 * recorded in schema_migrations, so running the migrator again applies nothing.
 *
 * MariaDB commits DDL implicitly, so a migration cannot be rolled back as a whole; keep each
 * file small, and every statement must end with a semicolon at the end of a line.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $database,
        private readonly string $migrationsDirectory,
    ) {
    }

    /**
     * Applies all pending migrations.
     *
     * @return list<string> Versions applied in this run (empty when already up to date).
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();
        $alreadyAppliedVersions = $this->appliedVersions();
        $appliedThisRun = [];

        foreach ($this->migrationFiles() as $migrationVersion => $migrationFilePath) {
            if (in_array($migrationVersion, $alreadyAppliedVersions, true)) {
                continue;
            }

            foreach ($this->splitStatements((string) file_get_contents($migrationFilePath)) as $sqlStatement) {
                $this->database->exec($sqlStatement);
            }

            $recordStatement = $this->database->prepare(
                'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, UTC_TIMESTAMP())'
            );
            $recordStatement->execute(['version' => $migrationVersion]);
            $appliedThisRun[] = $migrationVersion;
        }

        return $appliedThisRun;
    }

    /**
     * Lists the migrations that have not been applied yet, without changing anything. Used by
     * bin/check-setup.php.
     *
     * @return list<string>
     */
    public function pendingVersions(): array
    {
        $tableExists = $this->database->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn() !== false;
        $alreadyAppliedVersions = $tableExists ? $this->appliedVersions() : [];

        return array_values(array_diff(array_keys($this->migrationFiles()), $alreadyAppliedVersions));
    }

    private function ensureMigrationsTable(): void
    {
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(191) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return list<string> */
    private function appliedVersions(): array
    {
        return array_map('strval', $this->database->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array<string, string> Version (file name without .sql) => absolute path, sorted.
     */
    private function migrationFiles(): array
    {
        $migrationFilePaths = glob(rtrim($this->migrationsDirectory, '/') . '/*.sql');
        if ($migrationFilePaths === false) {
            throw new RuntimeException('Cannot read the migrations directory.');
        }
        sort($migrationFilePaths, SORT_STRING);

        $migrationsByVersion = [];
        foreach ($migrationFilePaths as $migrationFilePath) {
            $migrationsByVersion[basename($migrationFilePath, '.sql')] = $migrationFilePath;
        }

        return $migrationsByVersion;
    }

    /**
     * Splits a migration file into statements. Lines starting with "--" are comments.
     *
     * @return list<string>
     */
    private function splitStatements(string $migrationSql): array
    {
        $statements = [];
        $currentStatementLines = [];

        foreach (preg_split('/\R/', $migrationSql) ?: [] as $sqlLine) {
            if (str_starts_with(ltrim($sqlLine), '--') || trim($sqlLine) === '') {
                continue;
            }
            $currentStatementLines[] = $sqlLine;

            if (str_ends_with(rtrim($sqlLine), ';')) {
                $statements[] = rtrim(trim(implode("\n", $currentStatementLines)), ';');
                $currentStatementLines = [];
            }
        }

        if (trim(implode('', $currentStatementLines)) !== '') {
            throw new RuntimeException('Migration has a statement without a terminating semicolon.');
        }

        return $statements;
    }
}
