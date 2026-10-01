<?php

/**
 * Muninn API front controller. Every request under /api/v1/ is rewritten to this file.
 *
 * Only this public/ folder is exposed by the web server. Source code, config, logs and
 * storage live in the "application folder", outside the web root.
 *
 * By default the application folder is the parent of this folder (the layout in the repository
 * and the release zip). On a server where the two are kept apart, for example
 *   web root:            /volume1/web/muninn
 *   application folder:  /volume1/secrets/muninn
 * put a file named app-location.php next to this one that returns the application folder:
 *   <?php return '/volume1/secrets/muninn';
 * Deploy-Api.ps1 writes that file for you.
 */

declare(strict_types=1);

$applicationFolder = dirname(__DIR__);
$applicationLocationFile = __DIR__ . '/app-location.php';
if (is_file($applicationLocationFile)) {
    $configuredFolder = require $applicationLocationFile;
    if (!is_string($configuredFolder) || !is_dir($configuredFolder)) {
        // Fail closed with a generic message; the server path is never echoed to the client.
        http_response_code(500);
        header('Content-Type: application/json');
        echo '{"error":{"code":"server_misconfigured","message":"The service is temporarily unavailable."}}';
        exit;
    }
    $applicationFolder = rtrim($configuredFolder, '/\\');
}

require $applicationFolder . '/vendor/autoload.php';

Muninn\Api\Bootstrap::run($applicationFolder . '/config/config.php');
