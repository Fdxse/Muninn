<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Attachments\AttachmentService;
use Muninn\Api\Attachments\ImageInspector;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\Response;
use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * Image attachments (D008, D036): upload validation, authorized download, role checks and
 * isolation. "User A cannot fetch User B's attachment" is a CLAUDE.md minimum test.
 */
final class AttachmentTest extends WorkspaceTestCase
{
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $alice;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $bob;
    /** @var array<string, mixed> */
    private array $aliceNote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->signedInUser('alice');
        $this->bob = $this->signedInUser('bob');
        $this->aliceNote = $this->createNote($this->alice, $this->personalWorkspaceId($this->alice));
    }

    public function testUploadListAndDownload(): void
    {
        $uploadResponse = $this->uploadAttachment($this->alice, $this->aliceNote['id'], self::tinyPng(), 'image/png', 'Skärmbild 1.PNG');
        self::assertSame(201, $uploadResponse->statusCode(), $uploadResponse->body());
        $attachment = $uploadResponse->json()['data']['attachment'];
        self::assertSame('Skärmbild 1.png', $attachment['filename']);
        self::assertSame('image/png', $attachment['media_type']);
        self::assertSame(1, $attachment['width']);
        self::assertSame('![Skärmbild 1](attachment:' . $attachment['id'] . ')', $attachment['markdown']);
        self::assertSame(1, $this->attachmentStorage()->countFiles());
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE event_type = 'attachment.uploaded'"));

        $listResponse = $this->sendAs($this->alice, 'GET', '/api/v1/notes/' . $this->aliceNote['id'] . '/attachments');
        self::assertSame([$attachment['id']], array_column($listResponse->json()['data']['attachments'], 'id'));

        $downloadResponse = $this->downloadAs($this->alice, $attachment['id']);
        self::assertSame(200, $downloadResponse->statusCode());
        self::assertSame(self::tinyPng(), $downloadResponse->body());
        self::assertSame('image/png', $downloadResponse->header('Content-Type'));
        self::assertSame('nosniff', $downloadResponse->header('X-Content-Type-Options'));
        self::assertSame('private, max-age=300', $downloadResponse->header('Cache-Control'));
        self::assertSame('same-site', $downloadResponse->header('Cross-Origin-Resource-Policy'));
        self::assertStringStartsWith('inline; filename="Sk_rmbild_1.png"', (string) $downloadResponse->header('Content-Disposition'));

        // A matching ETag gives 304 without the bytes.
        $conditionalResponse = $this->downloadAs($this->alice, $attachment['id'], ['If-None-Match' => (string) $downloadResponse->header('ETag')]);
        self::assertSame(304, $conditionalResponse->statusCode());
        self::assertSame('', $conditionalResponse->body());
    }

    public function testOtherUsersCannotFetchListOrUploadAttachments(): void
    {
        $attachment = $this->uploadAttachment($this->alice, $this->aliceNote['id'], self::tinyPng())->json()['data']['attachment'];

        // Bob gets exactly the answer a made-up ID gets, so he learns nothing.
        $bobDownload = $this->downloadAs($this->bob, $attachment['id']);
        $this->assertError($bobDownload, 404, 'not_found');
        self::assertSame($this->downloadAs($this->bob, '00000000-0000-4000-8000-000000000000')->body(), $bobDownload->body());
        $this->assertError($this->sendAs($this->bob, 'GET', '/api/v1/notes/' . $this->aliceNote['id'] . '/attachments'), 404, 'not_found');
        $this->assertError($this->uploadAttachment($this->bob, $this->aliceNote['id'], self::tinyPng()), 404, 'not_found');

        // Without a session there is nothing at all, even with a valid ID.
        $this->assertError($this->send('GET', '/api/v1/attachments/' . $attachment['id'] . '/content'), 401, 'unauthenticated');

        // System administrators never see note content (D025, D044).
        $admin = $this->signedInUser('sysadmin', true);
        $this->assertError($this->downloadAs($admin, $attachment['id']), 404, 'not_found');
        self::assertSame(1, $this->attachmentStorage()->countFiles());
    }

    public function testReadersCanViewButNotUpload(): void
    {
        $sharedWorkspaceId = $this->createSharedWorkspace($this->alice);
        self::assertSame(201, $this->addMember($this->alice, $sharedWorkspaceId, 'bob', 'reader')->statusCode());
        $sharedNote = $this->createNote($this->alice, $sharedWorkspaceId);
        $attachment = $this->uploadAttachment($this->alice, $sharedNote['id'], self::tinyPng())->json()['data']['attachment'];

        self::assertSame(200, $this->downloadAs($this->bob, $attachment['id'])->statusCode());
        $this->assertError($this->uploadAttachment($this->bob, $sharedNote['id'], self::tinyPng()), 403, 'insufficient_role');

        // Once Bob leaves the workspace the image is gone for him too.
        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/workspaces/' . $sharedWorkspaceId . '/members/' . $this->bob['user_id'])->statusCode());
        $this->assertError($this->downloadAs($this->bob, $attachment['id']), 404, 'not_found');
    }

    public function testAttachmentsOfTrashedNotesAreUnavailable(): void
    {
        $attachment = $this->uploadAttachment($this->alice, $this->aliceNote['id'], self::tinyPng())->json()['data']['attachment'];
        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/notes/' . $this->aliceNote['id'])->statusCode());

        $this->assertError($this->downloadAs($this->alice, $attachment['id']), 404, 'not_found');
        $this->assertError($this->uploadAttachment($this->alice, $this->aliceNote['id'], self::tinyPng()), 404, 'not_found');
        // The file is kept for restore (Week 4); only purge will delete it.
        self::assertSame(1, $this->attachmentStorage()->countFiles());
    }

    public function testNonImagesAndDisguisedFilesAreRefused(): void
    {
        $notePath = $this->aliceNote['id'];

        $svgImage = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $this->assertError($this->uploadAttachment($this->alice, $notePath, $svgImage, 'image/svg+xml', 'x.svg'), 422, 'validation_failed');
        $this->assertError($this->uploadAttachment($this->alice, $notePath, '<?php echo 1;', 'image/png', 'shell.php.png'), 422, 'validation_failed');
        // Right magic bytes, broken image: getimagesizefromstring() must agree.
        $this->assertError($this->uploadAttachment($this->alice, $notePath, "\x89PNG\r\n\x1A\n" . 'garbage', 'image/png'), 422, 'validation_failed');
        $this->assertError($this->uploadAttachment($this->alice, $notePath, '', 'image/png'), 422, 'validation_failed');
        // A form-style content type is refused before anything else, like JSON endpoints refuse forms.
        $this->assertError($this->uploadAttachment($this->alice, $notePath, self::tinyPng(), 'multipart/form-data'), 415, 'unsupported_media_type');
        $this->assertError($this->uploadAttachment($this->alice, $notePath, self::tinyPng(), 'text/plain'), 415, 'unsupported_media_type');

        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM attachments'));
        self::assertSame(0, $this->attachmentStorage()->countFiles());
    }

    public function testBrowserTypeIsIgnoredInFavourOfTheRealType(): void
    {
        // A PNG sent as "image/jpeg" with a .gif name is stored and served as what it really is.
        $uploadResponse = $this->uploadAttachment($this->alice, $this->aliceNote['id'], self::tinyPng(), 'image/jpeg', 'trick.gif');
        $attachment = $uploadResponse->json()['data']['attachment'];
        self::assertSame('image/png', $attachment['media_type']);
        self::assertSame('trick.png', $attachment['filename']);
    }

    public function testUploadSizeLimitAndCsrfAreEnforced(): void
    {
        $this->application = $this->buildApplication(['attachments' => ['max_upload_bytes' => 50]]);
        $this->assertError($this->uploadAttachment($this->alice, $this->aliceNote['id'], self::tinyPng() . str_repeat("\0", 60)), 413, 'file_too_large');

        $withoutCsrf = $this->application->handle(new Request(
            'POST',
            '/api/v1/notes/' . $this->aliceNote['id'] . '/attachments',
            ['Content-Type' => 'image/png'],
            [self::COOKIE_NAME => $this->alice['session_token']],
            self::tinyPng(),
        ));
        $this->assertError($withoutCsrf, 403, 'csrf_failed');
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM attachments'));
    }

    public function testFilenamesAreSanitised(): void
    {
        self::assertSame('passwd.png', AttachmentService::displayFilename('../../etc/passwd', 'png'));
        self::assertSame('evil.png', AttachmentService::displayFilename('C:\\temp\\evil.exe', 'png'));
        self::assertSame('image.webp', AttachmentService::displayFilename(null, 'webp'));
        self::assertSame('image.jpg', AttachmentService::displayFilename("\x00\x1F", 'jpg'));
        self::assertSame('ab.gif', AttachmentService::displayFilename('a"<b>.gif', 'gif'));
    }

    public function testImageInspectorRecognisesOnlyAcceptedFormats(): void
    {
        self::assertSame('image/png', ImageInspector::inspect(self::tinyPng())['media_type'] ?? null);
        $tinyGif = (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
        self::assertSame('image/gif', ImageInspector::inspect($tinyGif)['media_type'] ?? null);
        self::assertNull(ImageInspector::inspect('%PDF-1.7'));
        self::assertNull(ImageInspector::inspect('RIFF0000WAVEfmt '));
    }

    /** @param array<string, string> $extraHeaders */
    private function downloadAs(array $credentials, string $attachmentId, array $extraHeaders = []): Response
    {
        return $this->send('GET', '/api/v1/attachments/' . $attachmentId . '/content', null, $extraHeaders, [self::COOKIE_NAME => $credentials['session_token']]);
    }
}
