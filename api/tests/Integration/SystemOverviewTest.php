<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Muninn\Api\Admin\SignInAttemptReport;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Tests\Support\WorkspaceTestCase;
use ZipArchive;

/**
 * The administrator's overview page (D060): counts and heat maps without note data, masked
 * sign-in usernames with an audited reveal, and the audit log archive (zip, verify, delete).
 */
final class SystemOverviewTest extends WorkspaceTestCase
{
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $admin;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $alice;
    /** A throwaway archive folder per test. */
    private string $archiveFolder;

    protected function setUp(): void
    {
        $this->archiveFolder = sys_get_temp_dir() . '/muninn-test-audit-archives-' . bin2hex(random_bytes(6));
        parent::setUp();
        $this->admin = $this->signedInUser('sysadmin', true);
        $this->alice = $this->signedInUser('alice');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->archiveFolder)) {
            foreach (scandir($this->archiveFolder) ?: [] as $folderEntry) {
                if (is_file($this->archiveFolder . '/' . $folderEntry)) {
                    unlink($this->archiveFolder . '/' . $folderEntry);
                }
            }
            rmdir($this->archiveFolder);
        }
        parent::tearDown();
    }

    protected function configValues(array $overrides = []): array
    {
        return parent::configValues(array_replace_recursive(['audit_log' => ['archive_path' => $this->archiveFolder]], $overrides));
    }

    public function testNonAdminsGet404OnEveryOverviewEndpoint(): void
    {
        $this->assertError($this->sendAs($this->alice, 'GET', '/api/v1/admin/overview'), 404, 'not_found');
        $this->assertError($this->getAs($this->alice, '/api/v1/admin/sign-in-attempts', ['reveal' => 'true']), 404, 'not_found');
        $this->assertError($this->sendAs($this->alice, 'POST', '/api/v1/admin/audit-log/archive'), 404, 'not_found');
        $this->assertError($this->sendAs($this->alice, 'GET', '/api/v1/admin/audit-log/archives/audit-log-20250101-000000'), 404, 'not_found');
        // Signed out: 401 as everywhere else.
        $this->assertError($this->send('GET', '/api/v1/admin/overview'), 401, 'unauthenticated');
        // The refused reveal left no trace of having looked.
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type = 'admin.sign_in_usernames_revealed'"));
    }

    public function testOverviewCountsWithoutRevealingNoteData(): void
    {
        $workspaceId = $this->personalWorkspaceId($this->alice);
        $this->createNote($this->alice, $workspaceId, 'Confidential merger', 'Very private body text');
        $trashedNote = $this->createNote($this->alice, $workspaceId, 'Old idea', 'Gone soon');
        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/notes/' . $trashedNote['id'])->statusCode());
        $this->createFolder($this->alice, $workspaceId, 'Secret folder');

        $overviewResponse = $this->sendAs($this->admin, 'GET', '/api/v1/admin/overview');
        $overview = $this->assertOkData($overviewResponse);

        self::assertSame(2, $overview['users']['total']);
        self::assertSame(2, $overview['users']['active']);
        self::assertSame(1, $overview['users']['administrators']);
        self::assertSame(2, $overview['users']['signed_in_last_7_days']);
        self::assertSame(2, $overview['users']['open_sessions']);
        self::assertSame(1, $overview['content']['notes_active']);
        self::assertSame(1, $overview['content']['notes_in_trash']);
        self::assertSame(1, $overview['content']['folders']);
        self::assertCount(12, $overview['content']['notes_created_per_week']);
        self::assertSame(2, array_sum(array_column($overview['content']['notes_created_per_week'], 'count')));
        self::assertGreaterThan(0, $overview['database']['total_bytes']);
        self::assertSame([], $overview['database']['pending_migrations']);
        self::assertSame('Europe/Stockholm', $overview['timezone']);

        // Counts only: no titles, contents or folder names anywhere in the answer.
        foreach (['Confidential merger', 'Very private body text', 'Old idea', 'Secret folder'] as $privateText) {
            self::assertStringNotContainsString($privateText, $overviewResponse->body());
        }
    }

    public function testHeatMapPlacesSignInsInLocalWeekdayAndHour(): void
    {
        // A sign-in three days ago, and one five weeks ago that only the 12-week map includes.
        $threeDaysAgo = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-3 days')->setTime(11, 30);
        $fiveWeeksAgo = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-5 weeks');
        $this->insertAuditEntry('auth.login_succeeded', $threeDaysAgo->format('Y-m-d H:i:s'));
        $this->insertAuditEntry('auth.login_succeeded', $fiveWeeksAgo->format('Y-m-d H:i:s'));

        $overview = $this->assertOkData($this->sendAs($this->admin, 'GET', '/api/v1/admin/overview'));
        $fourWeekGrid = $overview['activity']['last_4_weeks']['sign_ins'];
        $twelveWeekGrid = $overview['activity']['last_12_weeks']['sign_ins'];

        // setUp signed in two users just now, plus the entry from three days ago.
        self::assertSame(3, array_sum(array_map('array_sum', $fourWeekGrid)));
        self::assertSame(4, array_sum(array_map('array_sum', $twelveWeekGrid)));

        // 11:30 UTC is 12:30 or 13:30 in Stockholm, depending on summer time.
        $localTime = $threeDaysAgo->setTimezone(new DateTimeZone('Europe/Stockholm'));
        self::assertGreaterThanOrEqual(1, $fourWeekGrid[(int) $localTime->format('N') - 1][(int) $localTime->format('G')]);
        self::assertCount(7, $fourWeekGrid);
        self::assertCount(24, $fourWeekGrid[0]);
    }

    public function testFailedSignInUsernamesAreMaskedUntilRevealedAndTheRevealIsAudited(): void
    {
        $this->send('POST', '/api/v1/auth/login', ['username' => 'Alice', 'password' => 'wrong password']);
        // Someone typed a password into the username field.
        $this->send('POST', '/api/v1/auth/login', ['username' => 'hunter2secret', 'password' => 'whatever']);

        $overviewResponse = $this->sendAs($this->admin, 'GET', '/api/v1/admin/overview');
        $attempts = $this->assertOkData($overviewResponse)['sign_in_attempts'];
        self::assertStringNotContainsString('hunter2secret', $overviewResponse->body());
        self::assertFalse($attempts['usernames_revealed']);
        self::assertSame(2, $attempts['totals']['last_24_hours']['failed']);
        self::assertSame(2, $attempts['totals']['last_24_hours']['succeeded']);

        $failuresByUsername = array_column($attempts['recent_failures'], null, 'username');
        self::assertTrue($failuresByUsername['a•••e']['account_exists']);
        self::assertFalse($failuresByUsername['h•••t']['account_exists']);
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type = 'admin.sign_in_usernames_revealed'"));

        $revealedAttempts = $this->assertOkData($this->getAs($this->admin, '/api/v1/admin/sign-in-attempts', ['reveal' => 'true']))['sign_in_attempts'];
        self::assertTrue($revealedAttempts['usernames_revealed']);
        self::assertContains('hunter2secret', array_column($revealedAttempts['recent_failures'], 'username'));
        self::assertContains('alice', array_column($revealedAttempts['top_usernames'], 'username'));
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM audit_log WHERE event_type = 'admin.sign_in_usernames_revealed' AND actor_user_id = :admin_id",
            ['admin_id' => $this->admin['user_id']],
        ));
    }

    public function testMaskingHidesAllButTheEdgesAndNeverTheLength(): void
    {
        self::assertSame('A•••n', SignInAttemptReport::maskUsername('Admin'));
        self::assertSame('a•••n', SignInAttemptReport::maskUsername('averyveryverylongadmin'));
        self::assertSame('b•••', SignInAttemptReport::maskUsername('bob'));
        self::assertSame('•••', SignInAttemptReport::maskUsername('xy'));
        self::assertSame('•••', SignInAttemptReport::maskUsername(''));
        self::assertSame('å•••ö', SignInAttemptReport::maskUsername('åäö-ö'));
    }

    public function testArchiveZipsVerifiesAndDeletesOnlyOldEntries(): void
    {
        $oldMoment = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-14 months');
        foreach ([1, 2, 3] as $minuteOffset) {
            $this->insertAuditEntry('auth.login_failed', $oldMoment->modify('+' . $minuteOffset . ' minutes')->format('Y-m-d H:i:s'), ['username' => 'old-user-' . $minuteOffset]);
        }
        $recentEntryCount = (int) $this->scalar('SELECT COUNT(*) FROM audit_log');
        self::assertSame(3 + 2, $recentEntryCount);

        $overviewBefore = $this->assertOkData($this->sendAs($this->admin, 'GET', '/api/v1/admin/overview'));
        self::assertSame(3, $overviewBefore['audit_log']['archivable_entries']);
        self::assertContains('audit_log_archivable', array_column($overviewBefore['warnings'], 'code'));

        $archiveResponse = $this->sendAs($this->admin, 'POST', '/api/v1/admin/audit-log/archive');
        self::assertSame(201, $archiveResponse->statusCode(), $archiveResponse->body());
        $newArchive = $archiveResponse->json()['data']['archive'];
        self::assertSame(3, $newArchive['entry_count']);
        self::assertMatchesRegularExpression('/^audit-log-\d{8}-\d{6}\.zip$/', $newArchive['file_name']);

        // The old entries are gone from the database; the recent ones (and the archive record) stay.
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE details LIKE '%old-user-%'"));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type = 'audit_log.archived'"));
        self::assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type = 'auth.login_succeeded'"));

        // The zip holds the three entries, oldest first, plus a manifest.
        $zipArchive = new ZipArchive();
        self::assertTrue($zipArchive->open($this->archiveFolder . '/' . $newArchive['file_name']) === true);
        $exportedLines = array_values(array_filter(explode("\n", (string) $zipArchive->getFromName('audit-log.jsonl'))));
        $manifest = json_decode((string) $zipArchive->getFromName('manifest.json'), true);
        $zipArchive->close();
        self::assertCount(3, $exportedLines);
        self::assertSame('old-user-1', json_decode($exportedLines[0], true)['details']['username']);
        self::assertSame(3, $manifest['entry_count']);
        // No work files are left behind.
        self::assertSame([$newArchive['file_name']], array_values(array_diff(scandir($this->archiveFolder) ?: [], ['.', '..'])));

        // Listed on the overview and downloadable by the administrator.
        $overviewAfter = $this->assertOkData($this->sendAs($this->admin, 'GET', '/api/v1/admin/overview'));
        self::assertSame($newArchive['file_name'], $overviewAfter['audit_log']['archives'][0]['file_name']);
        $downloadResponse = $this->sendAs($this->admin, 'GET', '/api/v1/admin/audit-log/archives/' . $newArchive['id']);
        self::assertSame(200, $downloadResponse->statusCode());
        self::assertSame('application/zip', $downloadResponse->header('Content-Type'));
        self::assertSame(file_get_contents($this->archiveFolder . '/' . $newArchive['file_name']), $downloadResponse->body());

        // Nothing else is old enough now.
        $this->assertError($this->sendAs($this->admin, 'POST', '/api/v1/admin/audit-log/archive'), 409, 'nothing_to_archive');
    }

    public function testArchiveDownloadOnlyServesArchiveNames(): void
    {
        mkdir($this->archiveFolder, 0750, true);
        file_put_contents($this->archiveFolder . '/notes.txt', 'not an archive');

        file_put_contents($this->archiveFolder . '/audit-log-20250101-000000.zip.zip', 'not an archive either');

        foreach (['notes', 'notes.txt', '..%2Fconfig', 'audit-log-x', 'audit-log-20250101-000000', 'audit-log-20250101-000000.zip'] as $requestedName) {
            $this->assertError($this->sendAs($this->admin, 'GET', '/api/v1/admin/audit-log/archives/' . $requestedName), 404, 'not_found');
        }
    }

    public function testFailedArchiveDeletesNothing(): void
    {
        // A file where the archive folder should be: the folder cannot be created.
        file_put_contents($this->archiveFolder, 'in the way');
        $this->insertAuditEntry('auth.logout', (new DateTimeImmutable('-2 years'))->format('Y-m-d H:i:s'));

        try {
            $this->assertError($this->sendAs($this->admin, 'POST', '/api/v1/admin/audit-log/archive'), 500, 'archive_failed');
            self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type = 'auth.logout'"));
        } finally {
            unlink($this->archiveFolder);
        }
    }

    /**
     * Writes an audit entry at a chosen moment (the API itself always writes "now").
     *
     * @param array<string, mixed> $details
     */
    private function insertAuditEntry(string $eventType, string $createdAt, array $details = []): void
    {
        $insertStatement = $this->database->prepare(
            'INSERT INTO audit_log (id, event_type, actor_user_id, target_type, target_id, ip_address, details, created_at)
             VALUES (:id, :event_type, NULL, NULL, NULL, :ip_address, :details, :created_at)'
        );
        $insertStatement->execute([
            'id' => UuidGenerator::generate(),
            'event_type' => $eventType,
            'ip_address' => '198.51.100.7',
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
        ]);
    }
}
