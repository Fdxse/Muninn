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
        // bin/check-setup.php: on an empty database every migration is pending, and listing them changes nothing.
        $pendingBeforeFirstRun = $migrator->pendingVersions();
        self::assertContains('0001_initial_auth', $pendingBeforeFirstRun);
        self::assertNotContains('schema_migrations', TestDatabase::tableNames());

        $firstRunVersions = $migrator->migrate();
        self::assertSame($pendingBeforeFirstRun, $firstRunVersions);
        self::assertContains('0001_initial_auth', $firstRunVersions);
        self::assertContains('0002_workspaces_and_notes', $firstRunVersions);
        foreach (['users', 'sessions', 'invitations', 'auth_attempts', 'audit_log', 'workspaces', 'workspace_members', 'notes', 'schema_migrations'] as $expectedTable) {
            self::assertContains($expectedTable, TestDatabase::tableNames());
        }

        self::assertSame([], $migrator->migrate(), 'A second run must be a no-op.');
        self::assertSame([], $migrator->pendingVersions());
    }

    public function testConnectionUsesUtc(): void
    {
        $sessionTimeZone = TestDatabase::connection()->query('SELECT @@session.time_zone')->fetchColumn();
        self::assertSame('+00:00', $sessionTimeZone);
    }
}
