<?php

/**
 * Creates a system administrator account (decision D028). This is the ONLY way to create an
 * admin: there is no web setup endpoint that an attacker could reach first.
 *
 * Usage (over SSH on the server):  php bin/create-admin.php [path/to/config.php]
 *
 * The password is read from a hidden prompt, never from a command-line argument, so it does
 * not end up in shell history or process listings. Keep the admin account separate from the
 * account you use for everyday notes.
 */

declare(strict_types=1);

require __DIR__ . '/cli-guard.php';

use Muninn\Api\Auth\PasswordService;
use Muninn\Api\Bootstrap;
use Muninn\Api\Config\Config;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Users\UserInputRules;
use Muninn\Api\Users\UserRepository;

/** Reads one line from the terminal; hides typing when $hideInput is true. */
function promptLine(string $promptText, bool $hideInput = false): string
{
    fwrite(STDOUT, $promptText);
    $terminalIsInteractive = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($hideInput && $terminalIsInteractive) {
        shell_exec('stty -echo');
    }
    $enteredLine = fgets(STDIN);
    if ($hideInput && $terminalIsInteractive) {
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }

    return $enteredLine === false ? '' : rtrim($enteredLine, "\r\n");
}

$configFilePath = $argv[1] ?? dirname(__DIR__) . '/config/config.php';

try {
    $config = Config::fromFile($configFilePath);
    $database = Bootstrap::connect($config);
} catch (Throwable $startupFailure) {
    fwrite(STDERR, 'Cannot start: ' . $startupFailure->getMessage() . PHP_EOL);
    exit(1);
}

$userRepository = new UserRepository($database);
$passwordService = new PasswordService();

$username = UserInputRules::normaliseUsername(promptLine('Admin username: '));
$usernameProblem = UserInputRules::usernameError($username);
if ($usernameProblem !== null) {
    fwrite(STDERR, 'Invalid username. ' . $usernameProblem . PHP_EOL);
    exit(1);
}
if ($userRepository->usernameExists($username)) {
    fwrite(STDERR, 'That username already exists. Nothing was changed.' . PHP_EOL);
    exit(1);
}

$displayName = trim(promptLine('Display name: '));
$displayNameProblem = UserInputRules::displayNameError($displayName);
if ($displayNameProblem !== null) {
    fwrite(STDERR, 'Invalid display name. ' . $displayNameProblem . PHP_EOL);
    exit(1);
}

$password = promptLine('Password (hidden): ', true);
$passwordProblem = $passwordService->policyError($password);
if ($passwordProblem !== null) {
    fwrite(STDERR, 'Password rejected. ' . $passwordProblem . PHP_EOL);
    exit(1);
}
if (promptLine('Repeat password: ', true) !== $password) {
    fwrite(STDERR, 'Passwords do not match. Nothing was changed.' . PHP_EOL);
    exit(1);
}

$newAdminId = $userRepository->create($username, $displayName, $passwordService->hash($password), true);
(new AuditLog($database))->record(AuditLog::USER_CREATED_BY_CLI, null, 'user', $newAdminId, null, ['is_system_admin' => true]);

echo 'System administrator "' . $username . '" created.' . PHP_EOL;
exit(0);
