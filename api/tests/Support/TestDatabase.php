<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Support;

use Muninn\Api\Database\ConnectionFactory;
use Muninn\Api\Database\Migrator;
use PDO;

/**
 * Access to the disposable integration-test database.
 *
 * Configure with MUNINN_TEST_DB_HOST, _PORT, _NAME, _USER, _PASSWORD. NEVER point these at a
 * real database: every table is dropped at the start of the run and truncated between tests.
 */
final class TestDatabase
{
    private static ?PDO $sharedConnection = null;

    /** @return array{host: string, port: int, name: string, username: string, password: string} */
    public static function settings(): array
    {
        return [
            'host' => getenv('MUNINN_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('MUNINN_TEST_DB_PORT') ?: 3306),
            'name' => getenv('MUNINN_TEST_DB_NAME') ?: 'muninn_test',
            'username' => getenv('MUNINN_TEST_DB_USER') ?: 'muninn_test',
            'password' => getenv('MUNINN_TEST_DB_PASSWORD') ?: 'muninn_test',
        ];
    }

    /** A fresh, independent connection (used e.g. to simulate a second client). */
    public static function newConnection(): PDO
    {
        $databaseSettings = self::settings();

        return ConnectionFactory::create(
            $databaseSettings['host'],
            $databaseSettings['port'],
            $databaseSettings['name'],
            $databaseSettings['username'],
            $databaseSettings['password'],
        );
    }

    public static function connection(): PDO
    {
        return self::$sharedConnection ??= self::newConnection();
    }

    /** Drops every table and re-applies all migrations. */
    public static function rebuildSchema(): void
    {
        self::dropAllTables();
        (new Migrator(self::connection(), dirname(__DIR__, 2) . '/migrations'))->migrate();
    }

    public static function dropAllTables(): void
    {
        $database = self::connection();
        $database->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::tableNames() as $tableName) {
            $database->exec('DROP TABLE `' . $tableName . '`');
        }
        $database->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Empties all data tables but keeps the schema and the migration history. */
    public static function truncateDataTables(): void
    {
        $database = self::connection();
        $database->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::tableNames() as $tableName) {
            if ($tableName !== 'schema_migrations') {
                $database->exec('TRUNCATE TABLE `' . $tableName . '`');
            }
        }
        $database->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return list<string> */
    public static function tableNames(): array
    {
        return array_map('strval', self::connection()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    }
}
