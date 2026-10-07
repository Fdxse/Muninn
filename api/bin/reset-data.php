<?php

/**
 * Resets Muninn to "system administrators only" (decision D046): deletes every non-admin
 * account with its sessions, workspaces and notes, plus all invitations. Admin accounts, the
 * audit log and the schema are kept. Meant for clearing out test data. THIS CANNOT BE UNDONE.
 *
 * Usage (over SSH on the server):
 *   php bin/reset-data.php [--dry-run] [path/to/config.php]
 *
 *   --dry-run   Only show what would be deleted; change nothing.
 *
 * The script always shows the row counts first and only deletes after the confirmation
 * phrase has been typed exactly. It cannot be run non-interactively by design.
 */

declare(strict_types=1);

require __DIR__ . '/cli-guard.php';

use Muninn\Api\Admin\DataResetService;
use Muninn\Api\Bootstrap;
use Muninn\Api\Config\Config;
use Muninn\Api\Logging\AuditLog;

/** The exact text the operator has to type before anything is deleted. */
const CONFIRMATION_PHRASE = 'DELETE ALL USER DATA';

// Separate the optional --dry-run flag from the optional config file path.
$commandLineArguments = array_slice($argv, 1);
$isDryRun = in_array('--dry-run', $commandLineArguments, true);
$positionalArguments = array_values(array_filter(
    $commandLineArguments,
    static fn (string $argument): bool => !str_starts_with($argument, '--'),
));
$configFilePath = $positionalArguments[0] ?? dirname(__DIR__) . '/config/config.php';

try {
    $config = Config::fromFile($configFilePath);
    $database = Bootstrap::connect($config);
} catch (Throwable $startupFailure) {
    fwrite(STDERR, 'Cannot start: ' . $startupFailure->getMessage() . PHP_EOL);
    exit(1);
}

$resetService = new DataResetService($database);

// Without an admin nobody could sign in afterwards, so refuse before showing anything else.
$administratorCount = $resetService->countAdministrators();
if ($administratorCount === 0) {
    fwrite(STDERR, 'No system administrator account exists. Create one with bin/create-admin.php first. Nothing was changed.' . PHP_EOL);
    exit(1);
}

echo 'Database: ' . $config->getString('database.name') . ' on ' . $config->getString('database.host') . PHP_EOL;
echo 'Kept: ' . $administratorCount . ' system administrator account(s), their sessions, and the audit log.' . PHP_EOL;
echo PHP_EOL . 'This will permanently delete:' . PHP_EOL;
foreach ($resetService->countRowsToDelete() as $countLabel => $rowCount) {
    echo sprintf('  %6d  %s', $rowCount, $countLabel) . PHP_EOL;
}
echo PHP_EOL;

if ($isDryRun) {
    echo 'Dry run: nothing was changed.' . PHP_EOL;
    exit(0);
}

echo 'There is no undo. Take a database backup first if you might want any of this back.' . PHP_EOL;
echo 'Type "' . CONFIRMATION_PHRASE . '" to continue, or anything else to cancel: ';
$typedConfirmation = fgets(STDIN);
if ($typedConfirmation === false || rtrim($typedConfirmation, "\r\n") !== CONFIRMATION_PHRASE) {
    echo 'Cancelled. Nothing was changed.' . PHP_EOL;
    exit(1);
}

try {
    $deletedRowCounts = $resetService->resetToAdministratorsOnly();
} catch (Throwable $resetFailure) {
    fwrite(STDERR, 'Reset failed and was rolled back: ' . $resetFailure->getMessage() . PHP_EOL);
    exit(1);
}

// Record who-did-what in the audit log; the actor is the server operator, not an API user.
(new AuditLog($database))->record(AuditLog::SYSTEM_DATA_RESET, null, null, null, null, ['deleted' => $deletedRowCounts]);

echo 'Done. Deleted:' . PHP_EOL;
foreach ($deletedRowCounts as $deleteLabel => $deletedRowCount) {
    echo sprintf('  %6d  %s', $deletedRowCount, $deleteLabel) . PHP_EOL;
}
exit(0);
