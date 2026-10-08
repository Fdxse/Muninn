<?php

/**
 * Checks a Muninn API installation before (and after) it goes live: configuration, database,
 * migrations, administrator account, storage folders and PHP. It only reads; it changes nothing.
 *
 * Usage (over SSH on the server, from the API folder):
 *   php bin/check-setup.php [path/to/config.php]
 *
 * Each line says OK, WARN (works, but check it), FAIL (fix before going live) or INFO (something
 * this script cannot see, to check by hand). The exit code
 * is 1 when anything failed. Note: this runs with the command-line PHP, whose settings can differ
 * from the Web Station PHP profile that serves the API (post_max_size, open_basedir).
 */

declare(strict_types=1);

require __DIR__ . '/cli-guard.php';

use Muninn\Api\Application;
use Muninn\Api\Bootstrap;
use Muninn\Api\Config\Config;
use Muninn\Api\Database\Migrator;

/** Oldest PHP version the API supports. */
const MINIMUM_PHP_VERSION = '8.2.0';

/** PHP extensions the API needs; sodium is optional (bcrypt is used without it). */
const REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'openssl'];

/** Counts of each result, for the summary and the exit code. */
$resultCounts = ['OK' => 0, 'WARN' => 0, 'FAIL' => 0, 'INFO' => 0];

/** Prints one check result and counts it. */
function report(string $resultLevel, string $message): void
{
    global $resultCounts;
    $resultCounts[$resultLevel]++;
    echo str_pad($resultLevel, 5) . ' ' . $message . PHP_EOL;
}

/** True when $path is $folder itself or anything inside it. */
function isInsideFolder(string $path, string $folder): bool
{
    $normalisedFolder = rtrim($folder, '/') . '/';

    return str_starts_with(rtrim($path, '/') . '/', $normalisedFolder);
}

/** Reports whether a storage folder exists, is outside the web root, and who owns it. */
function checkStorageFolder(string $folderLabel, string $folderPath, string $webRootFolder, bool $mayNotExistYet): void
{
    if (isInsideFolder($folderPath, $webRootFolder)) {
        report('FAIL', $folderLabel . ' ' . $folderPath . ' is inside the web root ' . $webRootFolder . '; move it outside.');

        return;
    }
    if (!is_dir($folderPath)) {
        report($mayNotExistYet ? 'OK' : 'WARN', $folderLabel . ' ' . $folderPath . ' does not exist yet'
            . ($mayNotExistYet ? ' (the API creates it on first use).' : '; create it and let the web server user write to it.'));

        return;
    }

    // Run with sudo, this script can write anywhere, so show the owner instead of testing writes.
    $ownerName = (string) fileowner($folderPath);
    if (function_exists('posix_getpwuid')) {
        $ownerName = posix_getpwuid((int) fileowner($folderPath))['name'] ?? $ownerName;
    }
    report('OK', $folderLabel . ' ' . $folderPath . ' exists (owner: ' . $ownerName . '). The web server user (http on a Synology) must be able to write to it.');
}

$applicationFolder = dirname(__DIR__);
$configFilePath = $argv[1] ?? $applicationFolder . '/config/config.php';
// The API's own public/ folder is the web root in the repository and NAS layout.
$webRootFolder = $applicationFolder . '/public';

echo 'Muninn setup check for ' . $applicationFolder . PHP_EOL . PHP_EOL;

// 1. PHP itself.
if (version_compare(PHP_VERSION, MINIMUM_PHP_VERSION, '>=')) {
    report('OK', 'PHP ' . PHP_VERSION);
} else {
    report('FAIL', 'PHP ' . PHP_VERSION . ' is too old; use ' . MINIMUM_PHP_VERSION . ' or newer.');
}
foreach (REQUIRED_EXTENSIONS as $extensionName) {
    report(extension_loaded($extensionName) ? 'OK' : 'FAIL', 'PHP extension ' . $extensionName . (extension_loaded($extensionName) ? '' : ' is missing.'));
}
report(
    extension_loaded('sodium') ? 'OK' : 'WARN',
    extension_loaded('sodium') ? 'PHP extension sodium (Argon2id password hashing)' : 'PHP extension sodium is missing; passwords use bcrypt instead of Argon2id.',
);

// 2. Configuration. Config::fromFile() already refuses missing keys and unsafe values.
try {
    $config = Config::fromFile($configFilePath);
    report('OK', 'Configuration ' . $configFilePath . ' loads.');
} catch (Throwable $configFailure) {
    report('FAIL', 'Configuration: ' . $configFailure->getMessage());
    echo PHP_EOL . 'Stopped: the other checks need a working configuration.' . PHP_EOL;
    exit(1);
}

if ($config->isProduction()) {
    report('OK', 'app.environment is production.');
} else {
    report('WARN', 'app.environment is "' . $config->getString('app.environment') . '"; set it to production on the live server.');
}

$cookieName = (string) $config->get('session.cookie_name', '__Host-muninn_session');
$cookieIsSecure = (bool) $config->get('session.cookie_secure', true);
report($cookieIsSecure ? 'OK' : ($config->isProduction() ? 'FAIL' : 'WARN'), 'Session cookie ' . $cookieName . ($cookieIsSecure ? ' is Secure (HTTPS only).' : ' is NOT Secure; only acceptable for local development.'));

foreach ($config->getStringList('cors.allowed_origins') as $allowedOrigin) {
    $originIsHttps = str_starts_with($allowedOrigin, 'https://');
    report($originIsHttps ? 'OK' : 'WARN', 'Allowed frontend origin ' . $allowedOrigin . ($originIsHttps ? '' : ' is not HTTPS.'));
}
$frontendBaseUrl = $config->getString('frontend.base_url');
report(str_starts_with($frontendBaseUrl, 'https://') ? 'OK' : 'WARN', 'Frontend address for invitation and reset links: ' . $frontendBaseUrl);

// Administrator push notifications (D057) are optional. Only the address is shown, never the token.
if (!$config->getBool('ntfy.enabled')) {
    report('INFO', 'ntfy notifications are off (ntfy.enabled). Send a test with bin/send-test-notification.php once configured.');
} else {
    $ntfyServerUrl = $config->getString('ntfy.server_url');
    $ntfyHost = (string) parse_url($ntfyServerUrl, PHP_URL_HOST);
    $ntfyIsLocal = in_array($ntfyHost, ['127.0.0.1', 'localhost', '::1'], true);
    // A token sent over plain HTTP to another machine could be read on the way.
    $tokenTravelsInClear = $config->getString('ntfy.access_token') !== '' && str_starts_with($ntfyServerUrl, 'http://') && !$ntfyIsLocal;
    report($tokenTravelsInClear ? 'WARN' : 'OK', 'ntfy notifications go to ' . $ntfyServerUrl . ', topic ' . $config->getString('ntfy.topic')
        . ($tokenTravelsInClear ? '; the access token travels unencrypted, use https:// or the local address.' : '.'));
    report(function_exists('curl_init') || ini_get('allow_url_fopen') ? 'OK' : 'FAIL', 'PHP can make HTTP requests to ntfy (curl or allow_url_fopen).');
}

// 3. Database: the app account connects, the schema is current, an administrator exists.
try {
    $database = Bootstrap::connect($config);
    report('OK', 'Database ' . $config->getString('database.name') . ' on ' . $config->getString('database.host') . ' accepts the app account.');
} catch (Throwable $connectionFailure) {
    report('FAIL', 'Database connection: ' . $connectionFailure->getMessage());
    $database = null;
}

if ($database !== null) {
    $pendingMigrations = (new Migrator($database, $applicationFolder . '/migrations'))->pendingVersions();
    if ($pendingMigrations === []) {
        report('OK', 'Database schema is up to date.');
    } else {
        report('FAIL', 'Migrations not applied yet: ' . implode(', ', $pendingMigrations) . '. Run bin/migrate.php.');
    }

    if ($pendingMigrations === []) {
        $activeAdministratorCount = (int) $database->query("SELECT COUNT(*) FROM users WHERE is_system_admin = 1 AND status = 'active'")->fetchColumn();
        report(
            $activeAdministratorCount > 0 ? 'OK' : 'FAIL',
            $activeAdministratorCount > 0 ? $activeAdministratorCount . ' active system administrator account(s).' : 'No active system administrator; run bin/create-admin.php.',
        );
    }
}

// 4. Storage folders: outside the web root, so logs and images are never served directly.
checkStorageFolder('Log folder', dirname($config->getString('logging.file_path')), $webRootFolder, false);
checkStorageFolder('Image folder', Application::attachmentStorageFolder($config), $webRootFolder, true);

// 5. The private configuration file must not be readable by every account on the server.
$configPermissions = fileperms($configFilePath) & 0o777;
report(
    ($configPermissions & 0o004) === 0 ? 'OK' : 'WARN',
    sprintf('Configuration file permissions are %o', $configPermissions)
        . (($configPermissions & 0o004) === 0 ? '.' : '; it holds the database password, so remove read access for others (e.g. chmod 640).'),
);

// 6. Upload size. Only the command-line value can be read here; check the Web Station profile too.
$maximumUploadBytes = (int) $config->get('attachments.max_upload_bytes', 10000000);
report('INFO', 'Images may be up to ' . round($maximumUploadBytes / 1000000, 1) . ' MB: make sure post_max_size in the Web Station PHP profile is at least 12M (this check cannot see it).');

echo PHP_EOL . sprintf('%d OK, %d WARN, %d FAIL', $resultCounts['OK'], $resultCounts['WARN'], $resultCounts['FAIL']) . PHP_EOL;
exit($resultCounts['FAIL'] > 0 ? 1 : 0);
