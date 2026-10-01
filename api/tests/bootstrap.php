<?php

/**
 * PHPUnit bootstrap: loads the autoloader and rebuilds the test database schema from the
 * migrations, so every test run starts from exactly what a fresh deployment would get.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Muninn\Api\Tests\Support\TestDatabase;

TestDatabase::rebuildSchema();
