<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\Response;
use Muninn\Api\Security\SecretToken;
use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * Magic Links (D019, D059): who may create them, what a visitor can reach, and every way a link
 * stops working (revoked, expired, not yet valid, outside its hours, creator demoted or disabled,
 * target gone).
 */
final class MagicLinkTest extends WorkspaceTestCase
{
    private const VISIT_COOKIE_NAME = '__Host-muninn_link';

    /** @var array{session_token: string, csrf_token: string, user_id: string} Owner of the Team workspace. */
    private array $alice;
    /** @var array{session_token: string, csrf_token: string, user_id: string} Someone with their own workspace. */
    private array $bob;
    private string $teamWorkspaceId;
    /** @var array<string, mixed> Folder "Projects" with sub-folder "Garden". */
    private array $projectsFolder;
    private array $gardenFolder;
    private array $privateFolder;
    private array $projectNote;
    private array $gardenNote;
    private array $privateNote;
    private array $looseNote;
    private array $bobNote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->signedInUser('alice');
        $this->bob = $this->signedInUser('bob');
        $this->teamWorkspaceId = $this->createSharedWorkspace($this->alice, 'Team');

        $this->projectsFolder = $this->createFolder($this->alice, $this->teamWorkspaceId, 'Projects');
        $gardenResponse = $this->sendAs($this->alice, 'POST', '/api/v1/workspaces/' . $this->teamWorkspaceId . '/folders', [
            'name' => 'Garden',
            'parent_id' => $this->projectsFolder['id'],
        ]);
        self::assertSame(201, $gardenResponse->statusCode(), $gardenResponse->body());
        $this->gardenFolder = $gardenResponse->json()['data']['folder'];
        $this->privateFolder = $this->createFolder($this->alice, $this->teamWorkspaceId, 'Private');

        $this->projectNote = $this->noteInFolder('Project plan', $this->projectsFolder['id']);
        $this->gardenNote = $this->noteInFolder('Tomatoes', $this->gardenFolder['id']);
        $this->privateNote = $this->noteInFolder('Salary review', $this->privateFolder['id']);
        $this->looseNote = $this->createNote($this->alice, $this->teamWorkspaceId, 'Loose note', 'No folder');
        $this->bobNote = $this->createNote($this->bob, $this->personalWorkspaceId($this->bob), 'Bob secret', 'Bob only');
    }

    // ----- Creating and managing links ---------------------------------------------------------

    public function testOwnerCreatesAReadLinkWhoseTokenIsShownOnceAndStoredOnlyAsAHash(): void
    {
        $createResponse = $this->createLinkResponse($this->alice, ['label' => 'Kitchen tablet', 'target_type' => 'workspace']);
        self::assertSame(201, $createResponse->statusCode(), $createResponse->body());
        $createdData = $createResponse->json()['data'];

        self::assertStringStartsWith('https://www.dx.se/link.php#token=', $createdData['link_url']);
        $rawToken = substr($createdData['link_url'], strlen('https://www.dx.se/link.php#token='));
        self::assertTrue(SecretToken::looksValid($rawToken));

        // Read is the default, and the default lifetime is 30 days.
        $createdLink = $createdData['magic_link'];
        self::assertSame('read', $createdLink['permission']);
        self::assertSame('active', $createdLink['status']);
        self::assertSame('Team', $createdLink['target_name']);
        self::assertSame('Europe/Stockholm', $createdLink['timezone']);
        $lifetimeSeconds = strtotime($createdLink['valid_until']) - strtotime($createdLink['valid_from']);
        self::assertSame(30 * 86400, $lifetimeSeconds);

        // Only the hash is stored; neither the raw token nor its hash ever leaves the API again.
        self::assertSame(SecretToken::hash($rawToken), $this->scalar('SELECT token_hash FROM magic_links'));
        $listResponse = $this->sendAs($this->alice, 'GET', '/api/v1/workspaces/' . $this->teamWorkspaceId . '/magic-links');
        self::assertSame(200, $listResponse->statusCode());
        self::assertStringNotContainsString($rawToken, $listResponse->body());
        self::assertStringNotContainsString(SecretToken::hash($rawToken), $listResponse->body());
        self::assertArrayNotHasKey('token_hash', $listResponse->json()['data']['magic_links'][0]);

        // The raw token is never written to the audit log.
        $auditDetails = (string) $this->scalar('SELECT GROUP_CONCAT(details) FROM audit_log');
        self::assertStringNotContainsString($rawToken, $auditDetails);
    }

    public function testOnlyAdminsAndOwnersOfTheWorkspaceManageLinks(): void
    {
        $carol = $this->signedInUser('carol');
        $dave = $this->signedInUser('dave');
        $administrator = $this->signedInUser('root', true);
        self::assertSame(201, $this->addMember($this->alice, $this->teamWorkspaceId, 'carol', 'editor')->statusCode());
        self::assertSame(201, $this->addMember($this->alice, $this->teamWorkspaceId, 'dave', 'admin')->statusCode());

        $this->assertError($this->createLinkResponse($carol, ['label' => 'x', 'target_type' => 'workspace']), 403, 'insufficient_role');
        $this->assertError($this->createLinkResponse($this->bob, ['label' => 'x', 'target_type' => 'workspace']), 404, 'not_found');
        $this->assertError($this->createLinkResponse($administrator, ['label' => 'x', 'target_type' => 'workspace']), 404, 'not_found');
        self::assertSame(201, $this->createLinkResponse($dave, ['label' => 'Admin link', 'target_type' => 'workspace'])->statusCode());

        [$aliceLink] = $this->createLink(['label' => 'Alice link', 'target_type' => 'workspace']);
        $this->assertError($this->sendAs($carol, 'DELETE', '/api/v1/magic-links/' . $aliceLink['id']), 403, 'insufficient_role');
        $this->assertError($this->sendAs($this->bob, 'DELETE', '/api/v1/magic-links/' . $aliceLink['id']), 404, 'not_found');
        $this->assertError($this->sendAs($carol, 'GET', '/api/v1/workspaces/' . $this->teamWorkspaceId . '/magic-links'), 403, 'insufficient_role');

        // Another Admin may revoke Alice's link.
        self::assertSame(204, $this->sendAs($dave, 'DELETE', '/api/v1/magic-links/' . $aliceLink['id'])->statusCode());
        self::assertSame('revoked', $this->linkStatus($aliceLink['id']));
    }

    public function testInvalidLinkRequestsAreRefused(): void
    {
        $workspacePath = '/api/v1/workspaces/' . $this->teamWorkspaceId . '/magic-links';
        $farFuture = (new DateTimeImmutable('+400 days'))->format('Y-m-d\TH:i:s\Z');

        $invalidRequests = [
            'label' => ['label' => '', 'target_type' => 'workspace'],
            'target_type' => ['label' => 'x', 'target_type' => 'everything'],
            'target_id' => ['label' => 'x', 'target_type' => 'folder'],
            'permission' => ['label' => 'x', 'target_type' => 'workspace', 'permission' => 'delete'],
            'valid_until' => ['label' => 'x', 'target_type' => 'workspace', 'valid_until' => $farFuture],
            'daily_end_time' => ['label' => 'x', 'target_type' => 'workspace', 'daily_start_time' => '07:00', 'daily_end_time' => '07:00'],
        ];
        foreach ($invalidRequests as $expectedField => $requestBody) {
            $invalidResponse = $this->sendAs($this->alice, 'POST', $workspacePath, $requestBody);
            $this->assertError($invalidResponse, 422, 'validation_failed');
            self::assertArrayHasKey($expectedField, $invalidResponse->json()['error']['fields'], $expectedField);
        }

        // A timestamp without an offset is refused instead of guessed in some server time zone.
        $noOffsetResponse = $this->sendAs($this->alice, 'POST', $workspacePath, ['label' => 'x', 'target_type' => 'workspace', 'valid_until' => '2026-12-01T10:00:00']);
        self::assertArrayHasKey('valid_until', $noOffsetResponse->json()['error']['fields']);

        // A folder or note of another workspace is refused like one that does not exist.
        $foreignNoteResponse = $this->sendAs($this->alice, 'POST', $workspacePath, ['label' => 'x', 'target_type' => 'note', 'target_id' => $this->bobNote['id']]);
        self::assertArrayHasKey('target_id', $foreignNoteResponse->json()['error']['fields']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM magic_links'));
    }

    // ----- What a visitor can reach -------------------------------------------------------------

    public function testWorkspaceReadLinkShowsTheWorkspaceButCannotWrite(): void
    {
        [, $rawToken] = $this->createLink(['label' => 'Kiosk', 'target_type' => 'workspace']);
        $visit = $this->openLink($rawToken);

        $describedLink = $this->assertOkData($this->visitorRequest($visit, 'GET', '/api/v1/link/me'))['link'];
        self::assertSame('workspace', $describedLink['target_type']);
        self::assertSame('Team', $describedLink['target_name']);
        self::assertSame('read', $describedLink['permission']);

        $listedTitles = $this->listedTitles($visit);
        sort($listedTitles);
        self::assertSame(['Loose note', 'Project plan', 'Salary review', 'Tomatoes'], $listedTitles);
        self::assertCount(3, $this->assertOkData($this->visitorRequest($visit, 'GET', '/api/v1/link/folders'))['folders']);

        $shownNote = $this->assertOkData($this->visitorRequest($visit, 'GET', '/api/v1/link/notes/' . $this->projectNote['id']))['note'];
        self::assertSame('Project plan', $shownNote['title']);
        // Visitors learn nothing about the members.
        self::assertArrayNotHasKey('created_by', $shownNote);
        self::assertArrayNotHasKey('updated_by', $shownNote);
        self::assertArrayNotHasKey('history_count', $shownNote);

        // Read means read: no saving, no creating, no uploading.
        $this->assertError($this->visitorRequest($visit, 'PATCH', '/api/v1/link/notes/' . $this->projectNote['id'], ['revision' => 1, 'title' => 'Hacked']), 403, 'link_read_only');
        $this->assertError($this->visitorRequest($visit, 'POST', '/api/v1/link/notes', ['title' => 'New']), 403, 'link_read_only');
        $this->assertError($this->visitorUpload($visit, $this->projectNote['id']), 403, 'link_read_only');

        // Another workspace's note is invisible, exactly like a note that does not exist.
        $this->assertError($this->visitorRequest($visit, 'GET', '/api/v1/link/notes/' . $this->bobNote['id']), 404, 'not_found');
    }

    public function testVisitCookieAndSignInCookieNeverStandInForEachOther(): void
    {
        [, $rawToken] = $this->createLink(['label' => 'Kiosk', 'target_type' => 'workspace']);
        $visit = $this->openLink($rawToken);

        // The visit cookie opens nothing in the signed-in API (history, Trash, search, members...).
        foreach (['/api/v1/notes/' . $this->projectNote['id'], '/api/v1/notes/' . $this->projectNote['id'] . '/versions',
                  '/api/v1/workspaces/' . $this->teamWorkspaceId . '/trash', '/api/v1/workspaces/' . $this->teamWorkspaceId . '/members',
                  '/api/v1/auth/me'] as $signedInPath) {
            $this->assertError($this->send('GET', $signedInPath, null, [], [self::VISIT_COOKIE_NAME => $visit['visit_token']]), 401, 'unauthenticated');
        }

        // And being signed in does not open the visitor endpoints.
        $this->assertError($this->sendAs($this->alice, 'GET', '/api/v1/link/notes'), 401, 'link_unavailable');
    }

    public function testFolderLinkReachesTheFolderAndItsSubFoldersOnly(): void
    {
        [, $rawToken] = $this->createLink(['label' => 'Projects', 'target_type' => 'folder', 'target_id' => $this->projectsFolder['id']]);
        $visit = $this->openLink($rawToken);

        $listedTitles = $this->listedTitles($visit);
        sort($listedTitles);
        self::assertSame(['Project plan', 'Tomatoes'], $listedTitles);

        $folderNames = array_column($this->assertOkData($this->visitorRequest($visit, 'GET', '/api/v1/link/folders'))['folders'], 'name');
        self::assertSame(['Projects', 'Garden'], $folderNames);

        self::assertSame(['Tomatoes'], $this->listedTitles($visit, $this->gardenFolder['id']));
        // Folders outside the link, "no folder", and other people's IDs are all just "not found".
        $this->assertError($this->visitorGet($visit, '/api/v1/link/notes', ['folder' => $this->privateFolder['id']]), 404, 'not_found');
        $this->assertError($this->visitorGet($visit, '/api/v1/link/notes', ['folder' => 'none']), 404, 'not_found');
        $this->assertError($this->visitorRequest($visit, 'GET', '/api/v1/link/notes/' . $this->privateNote['id']), 404, 'not_found');
        $this->assertError($this->visitorRequest($visit, 'GET', '/api/v1/link/notes/' . $this->looseNote['id']), 404, 'not_found');
    }

    public function testNoteLinkReachesOnlyItsNote(): void
    {
        [, $rawToken] = $this->createLink(['label' => 'One note', 'target_type' => 'note', 'target_id' => $this->gardenNote['id'], 'permission' => 'write']);
        $visit = $this->openLink($rawToken);

        self::assertSame(['Tomatoes'], $this->listedTitles($visit));
        self::assertSame([], $this->assertOkData($this->visitorRequest($visit, 'GET', '/api/v1/link/folders'))['folders']);
        $shownNote = $this->assertOkData($this->visitorRequest($visit, 'GET', '/api/v1/link/notes/' . $this->gardenNote['id']))['note'];
        // Not even the name of the folder the note sits in.
        self::assertNull($shownNote['folder_name']);
        $this->assertError($this->visitorRequest($visit, 'GET', '/api/v1/link/notes/' . $this->projectNote['id']), 404, 'not_found');
        $this->assertError($this->visitorRequest($visit, 'POST', '/api/v1/link/notes', ['title' => 'New']), 403, 'link_cannot_create');
    }

    public function testWriteLinkSavesWithRevisionCheckAndCreatesInsideItsFolder(): void
    {
        [, $rawToken] = $this->createLink(['label' => 'Garden crew', 'target_type' => 'folder', 'target_id' => $this->projectsFolder['id'], 'permission' => 'write']);
        $visit = $this->openLink($rawToken);
        $notePath = '/api/v1/link/notes/' . $this->gardenNote['id'];

        $startRevision = (int) $this->gardenNote['revision'];
        $savedNote = $this->assertOkData($this->visitorRequest($visit, 'PATCH', $notePath, ['revision' => $startRevision, 'content' => 'Water daily']))['note'];
        self::assertSame($startRevision + 1, $savedNote['revision']);
        self::assertSame('Water daily', $savedNote['content']);
        // The replaced text is kept in the note's history like after any other save (D037).
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM note_versions WHERE note_id = ? AND content = ?',
            [$this->gardenNote['id'], 'Tomatoes content'],
        ));

        // A stale revision never silently overwrites (D010).
        $this->assertError($this->visitorRequest($visit, 'PATCH', $notePath, ['revision' => $startRevision, 'content' => 'Old copy']), 409, 'revision_conflict');

        // New notes land in the link's folder by default, or in one of its sub-folders.
        $createdNote = $this->assertOkData201($this->visitorRequest($visit, 'POST', '/api/v1/link/notes', ['title' => 'Seeds']))['note'];
        self::assertSame($this->projectsFolder['id'], $createdNote['folder_id']);
        $gardenChild = $this->assertOkData201($this->visitorRequest($visit, 'POST', '/api/v1/link/notes', ['title' => 'Compost', 'folder_id' => $this->gardenFolder['id']]))['note'];
        self::assertSame($this->gardenFolder['id'], $gardenChild['folder_id']);
        $outsideResponse = $this->visitorRequest($visit, 'POST', '/api/v1/link/notes', ['title' => 'Escape', 'folder_id' => $this->privateFolder['id']]);
        $this->assertError($outsideResponse, 422, 'validation_failed');

        // Write never includes deleting or anything outside the link.
        $this->assertError($this->visitorRequest($visit, 'PATCH', '/api/v1/link/notes/' . $this->privateNote['id'], ['revision' => 1, 'title' => 'x']), 404, 'not_found');
        $this->assertError($this->visitorRequest($visit, 'DELETE', $notePath), 405, 'method_not_allowed');
    }

    public function testWriteLinkUploadsImagesAndServesOnlyReachableOnes(): void
    {
        [, $rawToken] = $this->createLink(['label' => 'Photos', 'target_type' => 'folder', 'target_id' => $this->gardenFolder['id'], 'permission' => 'write']);
        $visit = $this->openLink($rawToken);

        $uploadResponse = $this->visitorUpload($visit, $this->gardenNote['id']);
        self::assertSame(201, $uploadResponse->statusCode(), $uploadResponse->body());
        $attachmentId = $uploadResponse->json()['data']['attachment']['id'];
        $contentResponse = $this->visitorRequest($visit, 'GET', '/api/v1/link/attachments/' . $attachmentId . '/content');
        self::assertSame(200, $contentResponse->statusCode());
        self::assertSame(self::tinyPng(), $contentResponse->body());

        // An image of a note outside the link is not found, even with its exact ID.
        $privateUpload = $this->uploadAttachment($this->alice, $this->privateNote['id'], self::tinyPng());
        $privateAttachmentId = $privateUpload->json()['data']['attachment']['id'];
        $this->assertError($this->visitorRequest($visit, 'GET', '/api/v1/link/attachments/' . $privateAttachmentId . '/content'), 404, 'not_found');
        $this->assertError($this->visitorUpload($visit, $this->privateNote['id']), 404, 'not_found');
    }

    public function testStateChangesNeedTheVisitCsrfToken(): void
    {
        [, $rawToken] = $this->createLink(['label' => 'Writers', 'target_type' => 'workspace', 'permission' => 'write']);
        $visit = $this->openLink($rawToken);

        $withoutToken = $this->send('PATCH', '/api/v1/link/notes/' . $this->projectNote['id'], ['revision' => 1, 'title' => 'x'], [], [self::VISIT_COOKIE_NAME => $visit['visit_token']]);
        $this->assertError($withoutToken, 403, 'csrf_failed');
    }

    // ----- Every way a link stops working --------------------------------------------------------

    public function testRevokingStopsTheLinkAndOpenVisitsAtOnce(): void
    {
        [$createdLink, $rawToken] = $this->createLink(['label' => 'Kiosk', 'target_type' => 'workspace']);
        $visit = $this->openLink($rawToken);

        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/magic-links/' . $createdLink['id'])->statusCode());

        $this->assertError($this->visitorRequest($visit, 'GET', '/api/v1/link/notes'), 401, 'link_unavailable');
        $this->assertError($this->send('POST', '/api/v1/link/open', ['token' => $rawToken]), 404, 'link_unavailable');
        self::assertSame('revoked', $this->linkStatus($createdLink['id']));
    }

    public function testExpiredAndNotYetValidLinksFail(): void
    {
        [$expiringLink, $expiringToken] = $this->createLink(['label' => 'Old', 'target_type' => 'workspace']);
        $visit = $this->openLink($expiringToken);
        $this->database->prepare('UPDATE magic_links SET valid_until = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ?')->execute([$expiringLink['id']]);
        $this->assertError($this->visitorRequest($visit, 'GET', '/api/v1/link/notes'), 401, 'link_unavailable');
        $this->assertError($this->send('POST', '/api/v1/link/open', ['token' => $expiringToken]), 404, 'link_unavailable');
        self::assertSame('expired', $this->linkStatus($expiringLink['id']));

        $tomorrow = (new DateTimeImmutable('+1 day', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        [$futureLink, $futureToken] = $this->createLink(['label' => 'Later', 'target_type' => 'workspace', 'valid_from' => $tomorrow]);
        $this->assertError($this->send('POST', '/api/v1/link/open', ['token' => $futureToken]), 404, 'link_unavailable');
        self::assertSame('scheduled', $this->linkStatus($futureLink['id']));
    }

    public function testDailyWindowIsEnforcedOnOpeningAndOnEveryRequest(): void
    {
        $stockholmNow = new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm'));
        $currentHour = (int) $stockholmNow->format('G');
        $hourText = static fn (int $hourOffset): string => sprintf('%02d:00', ($currentHour + $hourOffset + 24) % 24);

        // A window two to three hours from now does not include the present moment.
        [, $closedToken] = $this->createLink([
            'label' => 'Night shift', 'target_type' => 'workspace',
            'daily_start_time' => $hourText(2), 'daily_end_time' => $hourText(3),
        ]);
        $closedResponse = $this->send('POST', '/api/v1/link/open', ['token' => $closedToken]);
        $this->assertError($closedResponse, 403, 'link_outside_hours');
        self::assertStringContainsString('Europe/Stockholm', $closedResponse->json()['error']['message']);

        // A window from this hour to two hours later does; it then closes while the visit is open.
        [$openLink, $openToken] = $this->createLink([
            'label' => 'Day shift', 'target_type' => 'workspace',
            'daily_start_time' => $hourText(0), 'daily_end_time' => $hourText(2),
        ]);
        $visit = $this->openLink($openToken);
        self::assertSame(200, $this->visitorRequest($visit, 'GET', '/api/v1/link/notes')->statusCode());
        $this->database->prepare('UPDATE magic_links SET daily_start_time = ?, daily_end_time = ? WHERE id = ?')
            ->execute([$hourText(2) . ':00', $hourText(3) . ':00', $openLink['id']]);
        $this->assertError($this->visitorRequest($visit, 'GET', '/api/v1/link/notes'), 401, 'link_unavailable');
    }

    public function testLinkStopsWhenItsCreatorIsDemotedOrDisabled(): void
    {
        $dave = $this->signedInUser('dave');
        $administrator = $this->signedInUser('root', true);
        self::assertSame(201, $this->addMember($this->alice, $this->teamWorkspaceId, 'dave', 'admin')->statusCode());

        [$daveLink, $daveToken] = $this->createLink(['label' => 'Dave link', 'target_type' => 'workspace'], $dave);
        $visit = $this->openLink($daveToken);
        $memberPath = '/api/v1/workspaces/' . $this->teamWorkspaceId . '/members/' . $dave['user_id'];
        self::assertSame(200, $this->sendAs($this->alice, 'PATCH', $memberPath, ['role' => 'editor'])->statusCode());
        $this->assertError($this->visitorRequest($visit, 'GET', '/api/v1/link/notes'), 401, 'link_unavailable');
        self::assertSame('creator_lost_access', $this->linkStatus($daveLink['id']));

        // Disabled creators stop their links too.
        [, $aliceToken] = $this->createLink(['label' => 'Alice link', 'target_type' => 'workspace']);
        $aliceVisit = $this->openLink($aliceToken);
        self::assertSame(204, $this->sendAs($administrator, 'POST', '/api/v1/admin/users/' . $this->alice['user_id'] . '/disable')->statusCode());
        $this->assertError($this->visitorRequest($aliceVisit, 'GET', '/api/v1/link/notes'), 401, 'link_unavailable');
    }

    public function testLinkStopsWhenItsNoteIsTrashedOrFolderDeleted(): void
    {
        [, $noteToken] = $this->createLink(['label' => 'Note', 'target_type' => 'note', 'target_id' => $this->looseNote['id']]);
        $noteVisit = $this->openLink($noteToken);
        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/notes/' . $this->looseNote['id'])->statusCode());
        $this->assertError($this->visitorRequest($noteVisit, 'GET', '/api/v1/link/notes'), 401, 'link_unavailable');

        [$folderLink, $folderToken] = $this->createLink(['label' => 'Folder', 'target_type' => 'folder', 'target_id' => $this->privateFolder['id']]);
        $folderVisit = $this->openLink($folderToken);
        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/folders/' . $this->privateFolder['id'])->statusCode());
        $this->assertError($this->visitorRequest($folderVisit, 'GET', '/api/v1/link/notes'), 401, 'link_unavailable');
        self::assertSame('target_gone', $this->linkStatus($folderLink['id']));
    }

    public function testClosingEndsTheVisitButNotTheLink(): void
    {
        [, $rawToken] = $this->createLink(['label' => 'Kiosk', 'target_type' => 'workspace']);
        $visit = $this->openLink($rawToken);

        self::assertSame(204, $this->visitorRequest($visit, 'POST', '/api/v1/link/close')->statusCode());
        $this->assertError($this->visitorRequest($visit, 'GET', '/api/v1/link/notes'), 401, 'link_unavailable');

        // The link itself is reusable: opening it again works and counts the use.
        $this->openLink($rawToken);
        self::assertSame(2, (int) $this->scalar('SELECT use_count FROM magic_links'));
    }

    public function testGuessingTokensIsRateLimited(): void
    {
        $this->application = $this->buildApplication(['security' => ['invitation_max_failures_per_ip' => 3]]);

        for ($guessNumber = 0; $guessNumber < 3; $guessNumber++) {
            $this->assertError($this->send('POST', '/api/v1/link/open', ['token' => SecretToken::generate()]), 404, 'link_unavailable');
        }
        $this->assertError($this->send('POST', '/api/v1/link/open', ['token' => SecretToken::generate()]), 429, 'rate_limited');
    }

    // ----- System administrator overview ---------------------------------------------------------

    public function testAdministratorSeesAndRevokesLinksWithoutNoteData(): void
    {
        $administrator = $this->signedInUser('root', true);
        [$noteLink] = $this->createLink(['label' => 'Secret label', 'target_type' => 'note', 'target_id' => $this->privateNote['id']]);

        $overviewResponse = $this->sendAs($administrator, 'GET', '/api/v1/admin/magic-links');
        $overviewLinks = $this->assertOkData($overviewResponse)['magic_links'];
        self::assertCount(1, $overviewLinks);
        self::assertSame('alice', $overviewLinks[0]['created_by_username']);
        self::assertSame('Team', $overviewLinks[0]['workspace_name']);
        // No labels, folder names or note titles (like D050).
        self::assertStringNotContainsString('Secret label', $overviewResponse->body());
        self::assertStringNotContainsString('Salary review', $overviewResponse->body());

        self::assertSame(204, $this->sendAs($administrator, 'DELETE', '/api/v1/admin/magic-links/' . $noteLink['id'])->statusCode());
        self::assertSame('revoked', $this->linkStatus($noteLink['id']));
        $this->assertError($this->sendAs($this->alice, 'GET', '/api/v1/admin/magic-links'), 404, 'not_found');
    }

    // ----- Helpers -------------------------------------------------------------------------------

    /** Creates a note in the Team workspace and files it into a folder. */
    private function noteInFolder(string $title, string $folderId): array
    {
        $createdNote = $this->createNote($this->alice, $this->teamWorkspaceId, $title, $title . ' content');
        $movedResponse = $this->updateNote($this->alice, $createdNote['id'], 1, ['folder_id' => $folderId]);
        self::assertSame(200, $movedResponse->statusCode(), $movedResponse->body());

        return $movedResponse->json()['data']['note'];
    }

    /** @param array<string, mixed> $linkFields */
    private function createLinkResponse(array $credentials, array $linkFields): Response
    {
        return $this->sendAs($credentials, 'POST', '/api/v1/workspaces/' . $this->teamWorkspaceId . '/magic-links', $linkFields);
    }

    /**
     * Creates a link in the Team workspace (as Alice unless told otherwise).
     *
     * @param array<string, mixed> $linkFields
     * @return array{0: array<string, mixed>, 1: string} The link and its raw token.
     */
    private function createLink(array $linkFields, ?array $creatorCredentials = null): array
    {
        $createResponse = $this->createLinkResponse($creatorCredentials ?? $this->alice, $linkFields);
        self::assertSame(201, $createResponse->statusCode(), $createResponse->body());
        $createdData = $createResponse->json()['data'];
        $rawToken = explode('#token=', $createdData['link_url'])[1];

        return [$createdData['magic_link'], $rawToken];
    }

    /**
     * Opens a link as a fresh browser and returns its visit cookie and CSRF token.
     *
     * @return array{visit_token: string, csrf_token: string}
     */
    private function openLink(string $rawToken): array
    {
        $openResponse = $this->send('POST', '/api/v1/link/open', ['token' => $rawToken]);
        self::assertSame(200, $openResponse->statusCode(), $openResponse->body());

        $visitToken = null;
        foreach ($openResponse->setCookieHeaders() as $setCookieHeader) {
            if (str_starts_with($setCookieHeader, self::VISIT_COOKIE_NAME . '=')) {
                $visitToken = explode(';', substr($setCookieHeader, strlen(self::VISIT_COOKIE_NAME) + 1))[0];
                self::assertStringContainsString('HttpOnly', $setCookieHeader);
                self::assertStringContainsString('Secure', $setCookieHeader);
            }
        }
        self::assertNotNull($visitToken, 'Opening the link did not set the visit cookie.');
        // The sign-in cookie is never set by opening a link.
        foreach ($openResponse->setCookieHeaders() as $setCookieHeader) {
            self::assertStringStartsNotWith(self::COOKIE_NAME . '=', $setCookieHeader);
        }

        return ['visit_token' => $visitToken, 'csrf_token' => (string) $openResponse->json()['data']['csrf_token']];
    }

    /**
     * @param array{visit_token: string, csrf_token: string} $visit
     * @param array<string, mixed>|null $jsonBody
     */
    private function visitorRequest(array $visit, string $method, string $path, ?array $jsonBody = null): Response
    {
        return $this->send($method, $path, $jsonBody, ['X-CSRF-Token' => $visit['csrf_token']], [self::VISIT_COOKIE_NAME => $visit['visit_token']]);
    }

    /**
     * @param array{visit_token: string, csrf_token: string} $visit
     * @param array<string, string> $queryParameters
     */
    private function visitorGet(array $visit, string $path, array $queryParameters): Response
    {
        return $this->application->handle(new Request('GET', $path, [], [self::VISIT_COOKIE_NAME => $visit['visit_token']], '', '203.0.113.10', $queryParameters));
    }

    /** @param array{visit_token: string, csrf_token: string} $visit */
    private function visitorUpload(array $visit, string $noteId): Response
    {
        return $this->application->handle(new Request(
            'POST',
            '/api/v1/link/notes/' . $noteId . '/attachments',
            ['X-CSRF-Token' => $visit['csrf_token'], 'Content-Type' => 'image/png', 'X-Filename' => 'photo.png'],
            [self::VISIT_COOKIE_NAME => $visit['visit_token']],
            self::tinyPng(),
            '203.0.113.10',
        ));
    }

    /**
     * Titles the visitor's note list shows, optionally for one folder.
     *
     * @param array{visit_token: string, csrf_token: string} $visit
     * @return list<string>
     */
    private function listedTitles(array $visit, ?string $folderId = null): array
    {
        $listResponse = $folderId === null
            ? $this->visitorRequest($visit, 'GET', '/api/v1/link/notes')
            : $this->visitorGet($visit, '/api/v1/link/notes', ['folder' => $folderId]);

        return array_column($this->assertOkData($listResponse)['notes'], 'title');
    }

    /** The link's status as its workspace's Owner sees it. */
    private function linkStatus(string $linkId): string
    {
        $listResponse = $this->sendAs($this->alice, 'GET', '/api/v1/workspaces/' . $this->teamWorkspaceId . '/magic-links');
        foreach ($this->assertOkData($listResponse)['magic_links'] as $listedLink) {
            if ($listedLink['id'] === $linkId) {
                return (string) $listedLink['status'];
            }
        }
        self::fail('The link is not listed.');
    }

    /** Asserts a 201 response and returns its "data" part. */
    private function assertOkData201(Response $response): array
    {
        self::assertSame(201, $response->statusCode(), $response->body());

        return $response->json()['data'];
    }
}
