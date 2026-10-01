<?php

/**
 * Muninn API front controller. Every request under /api/v1/ is rewritten to this file.
 *
 * Only this public/ folder is exposed by the web server; source code, config, logs and
 * storage live one level up, outside the web root.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

Muninn\Api\Bootstrap::run(dirname(__DIR__) . '/config/config.php');
