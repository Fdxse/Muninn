<?php

/**
 * Helper process for the concurrency test: accepts one invitation through a separate
 * PHP process and database connection, waiting until a shared start time so that two
 * workers hit the database at the same moment.
 *
 * Usage: php accept-invitation-worker.php <raw-token> <username> <start-unix-microtime>
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Muninn\Api\Auth\PasswordService;
use Muninn\Api\Invitations\InvitationService;
use Muninn\Api\Tests\Support\TestDatabase;
use Muninn\Api\Users\UserRepository;

[, $rawToken, $username, $startAtMicrotime] = $argv;

$database = TestDatabase::newConnection();
$invitationService = new InvitationService($database, new UserRepository($database));
$passwordHash = (new PasswordService(PASSWORD_BCRYPT))->hash('a long enough password');

// Busy-wait until the agreed start moment.
while (microtime(true) < (float) $startAtMicrotime) {
    usleep(500);
}

try {
    $invitationService->accept($rawToken, $username, 'Racer', $passwordHash);
    echo 'accepted';
} catch (Throwable $acceptFailure) {
    echo 'refused';
}
