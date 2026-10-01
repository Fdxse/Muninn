<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Database\Migrator;
use Muninn\Api\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class MigrationTest extends TestCase
{
    public function testFreshDatabaseMigratesAndSecondRunAppliesNothing(): void
    {
        TestDatabase::dropAllTables();
        $migrator = new Migrator(TestDatabase::connection(), dirname(__DIR__, 2) . '/migrations');

        $firstRunVersions = $migrator->migrate();
        self::assertContains('0001_initial_auth', $firstRunVersions);
        foreach (['users', 'sessions', 'invitations', 'auth_attempts', 'audit_log', 'schema_migrations'] as $expectedTable) {
            self::assertContains($expectedTable, TestDatabase::tableNames());
        }

        self::assertSame([], $migrator->migrate(), 'A second run must be a no-op.');
    }

    public function testConnectionUsesUtc(): void
    {
        $sessionTimeZone = TestDatabase::connection()->query('SELECT @@session.time_zone')->fetchColumn();
        self::assertSame('+00:00', $sessionTimeZone);
    }
}
