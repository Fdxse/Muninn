<?php

/**
 * Applies pending database migrations.
 *
 * Usage:  php bin/migrate.php [path/to/config.php]
 *
 * Uses the optional 'migrations' credentials (a more privileged account) when configured,
 * otherwise the normal 'database' credentials. Safe to run repeatedly.
 */

declare(strict_types=1);

require __DIR__ . '/cli-guard.php';

use Muninn\Api\Config\Config;
use Muninn\Api\Database\ConnectionFactory;
use Muninn\Api\Database\Migrator;

$configFilePath = $argv[1] ?? dirname(__DIR__) . '/config/config.php';

try {
    $config = Config::fromFile($configFilePath);
    $database = ConnectionFactory::create(
        $config->getString('database.host'),
        $config->getInt('database.port'),
        $config->getString('database.name'),
        (string) $config->get('migrations.username', $config->getString('database.username')),
        (string) $config->get('migrations.password', $config->getString('database.password')),
    );

    $migrator = new Migrator($database, dirname(__DIR__) . '/migrations');
    $appliedVersions = $migrator->migrate();
} catch (Throwable $migrationFailure) {
    fwrite(STDERR, 'Migration failed: ' . $migrationFailure->getMessage() . PHP_EOL);
    exit(1);
}

if ($appliedVersions === []) {
    echo 'Database is up to date. Nothing to apply.' . PHP_EOL;
} else {
    echo 'Applied migrations:' . PHP_EOL;
    foreach ($appliedVersions as $appliedVersion) {
        echo '  - ' . $appliedVersion . PHP_EOL;
    }
}
exit(0);
