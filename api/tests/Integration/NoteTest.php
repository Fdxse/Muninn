<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * Note CRUD, optimistic concurrency and input validation.
 */
final class NoteTest extends WorkspaceTestCase
{
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $alice;
    private string $workspaceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->signedInUser('alice');
        $this->workspaceId = $this->personalWorkspaceId($this->alice);
    }

    public function testCreateReadListUpdateAndTrash(): void
    {
        $createdNote = $this->createNote($this->alice, $this->workspaceId, '  Shopping  ', "- [ ] milk\n- [ ] bread\n");
        self::assertTrue(\Muninn\Api\Security\UuidGenerator::isValid($createdNote['id']));
        self::assertSame('Shopping', $createdNote['title'], 'Titles are trimmed.');
        self::assertSame("- [ ] milk\n- [ ] bread\n", $createdNote['content'], 'Content is stored exactly as sent.');
        self::assertSame(1, $createdNote['revision']);
        self::assertSame($this->workspaceId, $createdNote['workspace_id']);
        self::assertSame('Alice', $createdNote['created_by']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $createdNote['updated_at']);

        $listedNotes = $this->sendAs($this->alice, 'GET', '/api/v1/workspaces/' . $this->workspaceId . '/notes')->json()['data']['notes'];
        self::assertCount(1, $listedNotes);
        self::assertSame('- [ ] milk - [ ] bread', $listedNotes[0]['excerpt']);
        self::assertArrayNotHasKey('content', $listedNotes[0], 'Listings carry a short excerpt, not the full content.');

        $updateResponse = $this->sendAs($this->alice, 'PATCH', '/api/v1/notes/' . $createdNote['id'], [
            'revision' => 1,
            'title' => 'Groceries',
            'content' => '- [x] milk',
        ]);
        self::assertSame(200, $updateResponse->statusCode(), $updateResponse->body());
        self::assertSame('Groceries', $updateResponse->json()['data']['note']['title']);
        self::assertSame(2, $updateResponse->json()['data']['note']['revision']);

        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/notes/' . $createdNote['id'])->statusCode());
        $this->assertError($this->sendAs($this->alice, 'DELETE', '/api/v1/notes/' . $createdNote['id']), 404, 'not_found');
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE event_type = \'note.trashed\''));
    }

    public function testEmptyNoteIsAllowed(): void
    {
        $createResponse = $this->sendAs($this->alice, 'POST', '/api/v1/workspaces/' . $this->workspaceId . '/notes', []);

        self::assertSame(201, $createResponse->statusCode(), $createResponse->body());
        self::assertSame('', $createResponse->json()['data']['note']['title']);
        self::assertSame('', $createResponse->json()['data']['note']['content']);
    }

    public function testStaleRevisionIsRefusedAndNothingIsOverwritten(): void
    {
        $note = $this->createNote($this->alice, $this->workspaceId, 'Plan', 'v1');
        $notePath = '/api/v1/notes/' . $note['id'];

        // Two editors opened revision 1. The first save wins...
        self::assertSame(200, $this->sendAs($this->alice, 'PATCH', $notePath, ['revision' => 1, 'content' => 'first save'])->statusCode());
        // ...and the second, still based on revision 1, is refused instead of silently overwriting.
        $this->assertError($this->sendAs($this->alice, 'PATCH', $notePath, ['revision' => 1, 'content' => 'second save']), 409, 'revision_conflict');

        $currentNote = $this->sendAs($this->alice, 'GET', $notePath)->json()['data']['note'];
        self::assertSame('first save', $currentNote['content']);
        self::assertSame(2, $currentNote['revision']);
    }

    public function testUpdateRequiresARevision(): void
    {
        $note = $this->createNote($this->alice, $this->workspaceId);

        $this->assertError($this->sendAs($this->alice, 'PATCH', '/api/v1/notes/' . $note['id'], ['content' => 'no revision']), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->alice, 'PATCH', '/api/v1/notes/' . $note['id'], ['revision' => '1', 'content' => 'string revision']), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->alice, 'PATCH', '/api/v1/notes/' . $note['id'], ['revision' => 0, 'content' => 'zero']), 422, 'validation_failed');
    }

    public function testInvalidTitlesAndContentAreRejected(): void
    {
        $notesPath = '/api/v1/workspaces/' . $this->workspaceId . '/notes';

        $this->assertError($this->sendAs($this->alice, 'POST', $notesPath, ['title' => str_repeat('a', 201)]), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->alice, 'POST', $notesPath, ['title' => "two\nlines"]), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->alice, 'POST', $notesPath, ['title' => ['not', 'a', 'string']]), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->alice, 'POST', $notesPath, ['content' => "nul\0byte"]), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->alice, 'POST', $notesPath, ['content' => str_repeat('x', 1_000_001)]), 422, 'validation_failed');
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM notes'));
    }

    public function testUnicodeAndMarkdownRoundTrip(): void
    {
        $markdownContent = "# Rubrik åäö\n\n```php\necho '<script>';\n```\n\n[länk](https://example.com) 🐦‍⬛\n";
        $note = $this->createNote($this->alice, $this->workspaceId, 'Hugin & Munin 🐦‍⬛', $markdownContent);

        $readBack = $this->sendAs($this->alice, 'GET', '/api/v1/notes/' . $note['id'])->json()['data']['note'];
        self::assertSame('Hugin & Munin 🐦‍⬛', $readBack['title']);
        self::assertSame($markdownContent, $readBack['content']);
    }

    public function testWorkspaceNameValidation(): void
    {
        $this->assertError($this->sendAs($this->alice, 'POST', '/api/v1/workspaces', ['name' => '   ']), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->alice, 'POST', '/api/v1/workspaces', ['name' => str_repeat('n', 101)]), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->alice, 'POST', '/api/v1/workspaces', []), 422, 'validation_failed');
    }

    public function testInvitationAcceptanceCreatesThePersonalWorkspace(): void
    {
        $admin = $this->signedInUser('sysadmin', true);
        $invitationUrl = (string) $this->sendAs($admin, 'POST', '/api/v1/admin/invitations', [])->json()['data']['invitation_url'];
        $rawToken = substr($invitationUrl, strpos($invitationUrl, '#token=') + strlen('#token='));

        $acceptResponse = $this->send('POST', '/api/v1/invitations/accept', [
            'token' => $rawToken,
            'username' => 'newbie',
            'display_name' => 'New Person',
            'password' => 'a long enough password',
        ]);
        self::assertSame(201, $acceptResponse->statusCode(), $acceptResponse->body());

        $newUserId = (string) $acceptResponse->json()['data']['user']['id'];
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM workspaces WHERE personal_owner_user_id = :id', ['id' => $newUserId]));
    }
}
