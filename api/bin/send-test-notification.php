<?php

/**
 * Sends one test push notification through the configured ntfy server (D057), to check the
 * ntfy settings in config/config.php:
 *   sudo php84 bin/send-test-notification.php [path/to/config.php]
 *
 * Prints whether ntfy accepted it. The access token is never printed.
 * Exit code 0 when ntfy accepted the message, 1 otherwise.
 */

declare(strict_types=1);

require __DIR__ . '/cli-guard.php';

use Muninn\Api\Bootstrap;
use Muninn\Api\Config\Config;
use Muninn\Api\Logging\AppLogger;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Notifications\AdminNotifier;
use Muninn\Api\Notifications\HttpNtfyTransport;

$configFilePath = $argv[1] ?? dirname(__DIR__) . '/config/config.php';

try {
    $config = Config::fromFile($configFilePath);
    $database = Bootstrap::connect($config);
} catch (Throwable $startupFailure) {
    fwrite(STDERR, 'Cannot start: ' . $startupFailure->getMessage() . PHP_EOL);
    exit(1);
}

if (!$config->getBool('ntfy.enabled')) {
    fwrite(STDERR, 'ntfy is switched off: set ntfy.enabled to true and fill in server_url and topic in ' . $configFilePath . PHP_EOL);
    exit(1);
}

$serverUrl = rtrim($config->getString('ntfy.server_url'), '/');
$adminNotifier = new AdminNotifier(
    $database,
    new AuditLog($database),
    new AppLogger($config->getString('logging.file_path')),
    new HttpNtfyTransport(),
    true,
    $serverUrl,
    $config->getString('ntfy.topic'),
    $config->getString('ntfy.access_token'),
    $config->getInt('ntfy.timeout_seconds'),
    rtrim($config->getString('frontend.base_url'), '/'),
);

echo 'Sending a test notification to ' . $serverUrl . ', topic ' . $config->getString('ntfy.topic') . ' ...' . PHP_EOL;
$statusCode = $adminNotifier->sendTestMessage();

// Explain the usual answers in plain words.
$explanation = match (true) {
    $statusCode >= 200 && $statusCode < 300 => 'Delivered. It should appear in the ntfy app for this topic.',
    $statusCode === 0 => 'No answer: check server_url, that ntfy is running, and the port.',
    $statusCode === 401, $statusCode === 403 => 'Refused: the server needs a valid access_token with write access to this topic.',
    $statusCode === 429 => 'ntfy is rate limiting this sender; try again later.',
    default => 'ntfy answered HTTP ' . $statusCode . '.',
};
echo $explanation . PHP_EOL;

exit($statusCode >= 200 && $statusCode < 300 ? 0 : 1);
