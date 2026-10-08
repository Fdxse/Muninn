<?php

/**
 * Deletes notes that have been in Trash longer than trash.retention_days (default 30) for good,
 * together with their version history, tag links, attachment rows and image files (D039).
 * Notes that are not in Trash are never touched.
 *
 * The API already does this by itself, at most once an hour (ExpiredTrashCleanup, D039). This
 * script is for checking or cleaning up by hand on the NAS:
 *   sudo php84 bin/purge-trash.php [--dry-run] [path/to/config.php]
 *
 *   --dry-run   Only report how many notes would be deleted; change nothing.
 *
 * Prints one summary line, so the scheduler's e-mail or log shows what happened.
 * Exit code 0 on success, 1 on failure.
 */

declare(strict_types=1);

require __DIR__ . '/cli-guard.php';

use Muninn\Api\Application;
use Muninn\Api\Attachments\AttachmentStorage;
use Muninn\Api\Bootstrap;
use Muninn\Api\Config\Config;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Notes\NotePurger;
use Muninn\Api\Tags\TagService;

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

$retentionDays = $config->getInt('trash.retention_days');
$notePurger = new NotePurger(
    $database,
    new TagService($database),
    new AttachmentStorage(Application::attachmentStorageFolder($config)),
);

if ($isDryRun) {
    echo 'Dry run: ' . $notePurger->countExpired($retentionDays) . ' note(s) have been in Trash for more than '
        . $retentionDays . ' days and would be deleted. Nothing was changed.' . PHP_EOL;
    exit(0);
}

try {
    $purgedNoteCount = $notePurger->purgeExpired($retentionDays);
} catch (Throwable $purgeFailure) {
    fwrite(STDERR, 'Trash cleanup failed: ' . $purgeFailure->getMessage() . PHP_EOL);
    exit(1);
}

// Record the run in the audit log; the actor is whoever ran this script, not an API user.
if ($purgedNoteCount > 0) {
    (new AuditLog($database))->record(AuditLog::TRASH_EXPIRED_PURGED, null, null, null, null, [
        'deleted_notes' => $purgedNoteCount,
        'retention_days' => $retentionDays,
    ]);
}

echo gmdate('Y-m-d H:i:s') . ' UTC: deleted ' . $purgedNoteCount . ' note(s) that had been in Trash for more than '
    . $retentionDays . ' days.' . PHP_EOL;
exit(0);
