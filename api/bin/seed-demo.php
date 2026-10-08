<?php

/**
 * Creates demo data for showing Muninn (decision D052): two everyday accounts, a shared
 * workspace where one is Owner and the other Editor, folders, tags, Markdown notes with
 * checklists, code and links, an archived note and a note with history. Follow
 * docs/demo.md afterwards.
 *
 * Usage (over SSH on the server, from the API folder):
 *   php bin/seed-demo.php [--dry-run] [path/to/config.php]
 *
 *   --dry-run   Only check that the demo accounts can be created; change nothing.
 *
 * The passwords are random and shown ONCE at the end; they are not stored anywhere else.
 * Nothing existing is touched: the script refuses to run when a demo username already exists.
 * To get rid of the demo afterwards, disable the two accounts on the admin Users page (their
 * notes then become unreachable), or clear a test server with bin/reset-data.php.
 */

declare(strict_types=1);

require __DIR__ . '/cli-guard.php';

use Muninn\Api\Auth\PasswordService;
use Muninn\Api\Bootstrap;
use Muninn\Api\Config\Config;
use Muninn\Api\Folders\FolderService;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Notes\NoteHistory;
use Muninn\Api\Notes\NoteInput;
use Muninn\Api\Notes\NoteService;
use Muninn\Api\Tags\TagService;
use Muninn\Api\Users\User;
use Muninn\Api\Users\UserRepository;
use Muninn\Api\Workspaces\WorkspaceAuthorizer;
use Muninn\Api\Workspaces\WorkspaceMembership;
use Muninn\Api\Workspaces\WorkspaceRole;
use Muninn\Api\Workspaces\WorkspaceService;

/** The two demo accounts: username => display name. The first one owns the shared workspace. */
const DEMO_ACCOUNTS = [
    'demo.anna' => 'Anna (demo)',
    'demo.erik' => 'Erik (demo)',
];

/** Name of the shared workspace the demo accounts work in together. */
const DEMO_SHARED_WORKSPACE_NAME = 'Demo: Team handbook';

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

$userRepository = new UserRepository($database);

// Never touch existing accounts: refuse before creating anything.
foreach (array_keys(DEMO_ACCOUNTS) as $demoUsername) {
    if ($userRepository->usernameExists($demoUsername)) {
        fwrite(STDERR, 'The username "' . $demoUsername . '" already exists, so the demo data seems to be there already. Nothing was changed.' . PHP_EOL);
        exit(1);
    }
}

echo 'Database: ' . $config->getString('database.name') . ' on ' . $config->getString('database.host') . PHP_EOL;
if ($isDryRun) {
    echo 'Dry run: the demo accounts ' . implode(' and ', array_keys(DEMO_ACCOUNTS)) . ' can be created. Nothing was changed.' . PHP_EOL;
    exit(0);
}

$passwordService = new PasswordService();
$auditLog = new AuditLog($database);
$workspaceService = new WorkspaceService($database, $userRepository);
$workspaceAuthorizer = new WorkspaceAuthorizer($database);
$folderService = new FolderService($database);
$tagService = new TagService($database);
$noteService = new NoteService($database, $tagService, new NoteHistory($database));

/**
 * The membership of $user in the workspace with $workspaceId; the demo just created it, so it
 * must exist.
 */
function demoMembership(WorkspaceAuthorizer $workspaceAuthorizer, User $user, string $workspaceId): WorkspaceMembership
{
    $membership = $workspaceAuthorizer->findMembership($user, $workspaceId);
    if ($membership === null) {
        throw new RuntimeException('The demo user ' . $user->username . ' is unexpectedly not a member of workspace ' . $workspaceId . '.');
    }

    return $membership;
}

/** The ID of the user's personal workspace (created on first use, Course MVP item 2). */
function personalWorkspaceId(WorkspaceService $workspaceService, User $user): string
{
    foreach ($workspaceService->listForUser($user) as $workspace) {
        if ($workspace['kind'] === WorkspaceMembership::KIND_PERSONAL) {
            return (string) $workspace['id'];
        }
    }
    throw new RuntimeException('The demo user ' . $user->username . ' has no personal workspace.');
}

// The services commit each step in their own transaction, so a failure part-way leaves the
// steps before it in place; the usernames were checked above, so that is very unlikely.
$generatedPasswords = [];
try {
    // 1. Accounts, each with a random password and (on first listing) a personal workspace.
    $demoUsers = [];
    foreach (DEMO_ACCOUNTS as $demoUsername => $demoDisplayName) {
        // 18 random bytes give a 24-character password, well above the 12-character minimum.
        $generatedPasswords[$demoUsername] = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $newUserId = $userRepository->create($demoUsername, $demoDisplayName, $passwordService->hash($generatedPasswords[$demoUsername]), false);
        $auditLog->record(AuditLog::USER_CREATED_BY_CLI, null, 'user', $newUserId, null, ['demo' => true]);
        $demoUsers[$demoUsername] = $userRepository->findById($newUserId)
            ?? throw new RuntimeException('The demo user ' . $demoUsername . ' could not be read back.');
    }
    $anna = $demoUsers['demo.anna'];
    $erik = $demoUsers['demo.erik'];

    // 2. Anna's personal notes: private, so Erik never sees them (data isolation in the demo).
    $annaPersonal = demoMembership($workspaceAuthorizer, $anna, personalWorkspaceId($workspaceService, $anna));
    $annaRecipesFolderId = $folderService->create($annaPersonal, 'Recipes');
    $noteService->create($annaPersonal, new NoteInput(
        title: 'Weekend shopping',
        content: "# Weekend shopping\n\n- [x] Coffee beans\n- [ ] Oat milk\n- [ ] Cardamom buns\n- [ ] Lingonberry jam\n\n**Remember** the cloth bags.",
        tagNames: ['shopping', 'todo'],
    ));
    $noteService->create($annaPersonal, new NoteInput(
        title: 'Cardamom buns',
        content: "## Cardamom buns\n\n1. Warm 250 ml milk with 75 g butter.\n2. Add 25 g yeast, 1 egg, 60 g sugar and 2 tsp ground cardamom.\n3. Knead in about 450 g flour, rise for 30 minutes.\n4. Shape, rise again, bake at **225 °C** for 8 minutes.\n\n> Tip: crush the cardamom seeds yourself; it makes all the difference.",
        folderIsSet: true,
        folderId: $annaRecipesFolderId,
        tagNames: ['baking'],
    ));
    $noteService->create($annaPersonal, new NoteInput(
        title: 'Private: gift ideas for Erik',
        content: "Only Anna can see this note, also in search.\n\n- [ ] Book about ravens\n- [ ] Coffee grinder",
        tagNames: ['private'],
    ));
    $noteService->setArchived($annaPersonal, $noteService->create($annaPersonal, new NoteInput(
        title: 'Summer trip 2025',
        content: "# Summer trip 2025\n\nArchived: still searchable with *Include archived notes*.\n\n- [x] Book the cabin\n- [x] Rent the canoe",
        tagNames: ['travel'],
    )), true);

    // 3. Erik's personal workspace exists too, with one private note.
    $erikPersonal = demoMembership($workspaceAuthorizer, $erik, personalWorkspaceId($workspaceService, $erik));
    $noteService->create($erikPersonal, new NoteInput(
        title: 'Running log',
        content: "## October\n\n| Day | Distance |\n|---|---|\n| Mon | 5 km |\n| Thu | 8 km |",
        tagNames: ['training'],
    ));

    // 4. The shared workspace: Anna is Owner (as its creator), Erik is Editor.
    $sharedWorkspaceId = $workspaceService->createShared($anna, DEMO_SHARED_WORKSPACE_NAME);
    $auditLog->record(AuditLog::WORKSPACE_CREATED, $anna->id, 'workspace', $sharedWorkspaceId, null, ['demo' => true]);
    $annaShared = demoMembership($workspaceAuthorizer, $anna, $sharedWorkspaceId);
    $workspaceService->addMember($annaShared, $erik->username, WorkspaceRole::Editor);
    $auditLog->record(AuditLog::WORKSPACE_MEMBER_ADDED, $anna->id, 'workspace', $sharedWorkspaceId, null, ['user_id' => $erik->id, 'role' => WorkspaceRole::Editor->value, 'demo' => true]);
    $erikShared = demoMembership($workspaceAuthorizer, $erik, $sharedWorkspaceId);

    $routinesFolderId = $folderService->create($annaShared, 'Routines');
    $folderService->create($annaShared, 'Meetings');
    $noteService->create($annaShared, new NoteInput(
        title: 'Welcome to the team handbook',
        content: "# Welcome\n\nThis workspace is shared by **Anna** (Owner) and **Erik** (Editor).\n\n- Notes are written in *Markdown*.\n- Use folders for structure and tags for themes.\n- See [the Muninn repository](https://github.com/Fdxse/Muninn) for how it works.",
        tagNames: ['handbook'],
    ));
    $noteService->create($annaShared, new NoteInput(
        title: 'Deploying a new release',
        content: "## Deploying a new release\n\nOn the NAS, from the API folder:\n\n```sh\nsudo php84 bin/migrate.php\n```\n\nThen upload the frontend over FTP with *overwrite* on.",
        folderIsSet: true,
        folderId: $routinesFolderId,
        tagNames: ['handbook', 'it'],
    ));

    // A note both people edit, so its history shows two authors and can be restored.
    $meetingNoteId = $noteService->create($annaShared, new NoteInput(
        title: 'Planning meeting',
        content: "# Planning meeting\n\n## Agenda\n\n- [ ] Status of the handbook\n- [ ] Next demo date",
        tagNames: ['meeting'],
    ));
    $noteService->update($erikShared, $meetingNoteId, 1, new NoteInput(
        content: "# Planning meeting\n\n## Agenda\n\n- [x] Status of the handbook\n- [ ] Next demo date\n\n## Notes\n\nErik: the handbook is nearly done; demo on Friday.",
    ));
    $noteService->update($annaShared, $meetingNoteId, 2, new NoteInput(
        content: "# Planning meeting\n\n## Agenda\n\n- [x] Status of the handbook\n- [x] Next demo date\n\n## Notes\n\nErik: the handbook is nearly done; demo on Friday.\n\nAnna: Friday 10:00 it is.",
    ));
} catch (Throwable $seedFailure) {
    fwrite(STDERR, 'Creating the demo data failed part-way: ' . $seedFailure->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Disable any demo account that was created on the admin Users page before trying again.' . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Demo data created. Sign in on the frontend with:' . PHP_EOL;
foreach ($generatedPasswords as $demoUsername => $generatedPassword) {
    echo sprintf('  %-10s  password: %s', $demoUsername, $generatedPassword) . PHP_EOL;
}
echo PHP_EOL . 'These passwords are shown only now. Write them down, or create reset links on the admin Users page later.' . PHP_EOL;
echo 'Demo path: docs/demo.md. Remove afterwards by disabling the two accounts on the admin Users page.' . PHP_EOL;
exit(0);
