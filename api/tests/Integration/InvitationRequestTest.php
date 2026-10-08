<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Http\Response;
use Muninn\Api\Tests\Support\WorkspaceTestCase;

/**
 * Invitation requests (D049): a user asks, an administrator approves or declines, and the
 * requesting user creates the invitation link and sends it themselves.
 */
final class InvitationRequestTest extends WorkspaceTestCase
{
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $admin;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $alice;
    /** @var array{session_token: string, csrf_token: string, user_id: string} */
    private array $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->signedInUser('sysadmin', true);
        $this->alice = $this->signedInUser('alice');
        $this->bob = $this->signedInUser('bob');
    }

    /** Creates a request as $credentials and returns its ID. */
    private function createRequest(array $credentials, string $note = 'My colleague Carl, for the project'): string
    {
        $createResponse = $this->sendAs($credentials, 'POST', '/api/v1/invitation-requests', ['note' => $note]);
        self::assertSame(201, $createResponse->statusCode(), $createResponse->body());

        return (string) $createResponse->json()['data']['invitation_request']['id'];
    }

    private function approve(string $requestId): Response
    {
        return $this->sendAs($this->admin, 'POST', '/api/v1/admin/invitation-requests/' . $requestId . '/approve');
    }

    /** Creates the link as $credentials and returns the raw token from it. */
    private function createLinkToken(array $credentials, string $requestId): string
    {
        $linkResponse = $this->sendAs($credentials, 'POST', '/api/v1/invitation-requests/' . $requestId . '/link');
        self::assertSame(201, $linkResponse->statusCode(), $linkResponse->body());
        $invitationUrl = (string) $linkResponse->json()['data']['invitation_url'];
        self::assertStringStartsWith('https://www.dx.se/invite.php#token=', $invitationUrl);

        return substr($invitationUrl, strpos($invitationUrl, '#token=') + strlen('#token='));
    }

    private function accept(string $rawToken, string $username = 'carl'): Response
    {
        return $this->send('POST', '/api/v1/invitations/accept', [
            'token' => $rawToken,
            'username' => $username,
            'display_name' => 'Carl',
            'password' => 'a long enough password',
        ]);
    }

    public function testFullFlowFromRequestToNewAccount(): void
    {
        $requestId = $this->createRequest($this->alice);

        // The admin sees it as pending, with who asked.
        $adminList = $this->sendAs($this->admin, 'GET', '/api/v1/admin/invitation-requests')->json()['data']['invitation_requests'];
        self::assertSame('pending', $adminList[0]['status']);
        self::assertSame('alice', $adminList[0]['requested_by']);

        // No link before approval.
        $this->assertError($this->sendAs($this->alice, 'POST', '/api/v1/invitation-requests/' . $requestId . '/link'), 409, 'request_not_approved');

        self::assertSame(200, $this->approve($requestId)->statusCode());
        $rawToken = $this->createLinkToken($this->alice, $requestId);

        // The invitation is an ordinary one, created by Alice, visible to the admin.
        $adminInvitations = $this->sendAs($this->admin, 'GET', '/api/v1/admin/invitations')->json()['data']['invitations'];
        self::assertSame('alice', $adminInvitations[0]['created_by']);
        self::assertStringContainsString('Requested by alice', (string) $adminInvitations[0]['note']);

        self::assertSame(201, $this->accept($rawToken)->statusCode());

        $ownList = $this->sendAs($this->alice, 'GET', '/api/v1/invitation-requests')->json()['data']['invitation_requests'];
        self::assertSame('completed', $ownList[0]['status']);
        self::assertSame('carl', $ownList[0]['accepted_username']);

        // A completed request can neither get a new link nor be cancelled or declined.
        $this->assertError($this->sendAs($this->alice, 'POST', '/api/v1/invitation-requests/' . $requestId . '/link'), 409, 'request_completed');
        $this->assertError($this->sendAs($this->alice, 'DELETE', '/api/v1/invitation-requests/' . $requestId), 409, 'request_closed');
        $this->assertError($this->sendAs($this->admin, 'POST', '/api/v1/admin/invitation-requests/' . $requestId . '/decline'), 409, 'request_closed');
    }

    public function testNewLinkReplacesTheOldOne(): void
    {
        $requestId = $this->createRequest($this->alice);
        $this->approve($requestId);

        $lostToken = $this->createLinkToken($this->alice, $requestId);
        $replacementToken = $this->createLinkToken($this->alice, $requestId);

        $this->assertError($this->accept($lostToken), 404, 'invitation_invalid');
        self::assertSame(201, $this->accept($replacementToken)->statusCode());
    }

    public function testDeclineAfterApprovalStopsTheLink(): void
    {
        $requestId = $this->createRequest($this->alice);
        $this->approve($requestId);
        $rawToken = $this->createLinkToken($this->alice, $requestId);

        self::assertSame(200, $this->sendAs($this->admin, 'POST', '/api/v1/admin/invitation-requests/' . $requestId . '/decline')->statusCode());

        $this->assertError($this->accept($rawToken), 404, 'invitation_invalid');
        $this->assertError($this->sendAs($this->alice, 'POST', '/api/v1/invitation-requests/' . $requestId . '/link'), 409, 'request_not_approved');
    }

    public function testCancellingStopsTheLink(): void
    {
        $requestId = $this->createRequest($this->alice);
        $this->approve($requestId);
        $rawToken = $this->createLinkToken($this->alice, $requestId);

        self::assertSame(204, $this->sendAs($this->alice, 'DELETE', '/api/v1/invitation-requests/' . $requestId)->statusCode());

        $this->assertError($this->accept($rawToken), 404, 'invitation_invalid');
        $this->assertError($this->approve($requestId), 409, 'request_not_pending');
    }

    public function testDisablingTheRequesterStopsTheirLinks(): void
    {
        $requestId = $this->createRequest($this->alice);
        $this->approve($requestId);
        $rawToken = $this->createLinkToken($this->alice, $requestId);

        $this->sendAs($this->admin, 'POST', '/api/v1/admin/users/' . $this->alice['user_id'] . '/disable');

        $this->assertError($this->send('POST', '/api/v1/invitations/inspect', ['token' => $rawToken]), 404, 'invitation_invalid');
        $this->assertError($this->accept($rawToken), 404, 'invitation_invalid');
    }

    public function testUsersSeeAndTouchOnlyTheirOwnRequests(): void
    {
        $aliceRequestId = $this->createRequest($this->alice, 'Private note about Carl');
        $this->approve($aliceRequestId);

        // Bob's list does not contain Alice's request or its note.
        $bobListResponse = $this->sendAs($this->bob, 'GET', '/api/v1/invitation-requests');
        self::assertSame([], $bobListResponse->json()['data']['invitation_requests']);
        self::assertStringNotContainsString('Carl', $bobListResponse->body());

        // Bob cannot create a link for, or cancel, Alice's request: it looks like it does not exist.
        $this->assertError($this->sendAs($this->bob, 'POST', '/api/v1/invitation-requests/' . $aliceRequestId . '/link'), 404, 'not_found');
        $this->assertError($this->sendAs($this->bob, 'DELETE', '/api/v1/invitation-requests/' . $aliceRequestId), 404, 'not_found');
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM invitations'));
    }

    public function testEverydayUsersCannotApproveOrSeeAllRequests(): void
    {
        $requestId = $this->createRequest($this->alice);

        $this->assertError($this->sendAs($this->alice, 'GET', '/api/v1/admin/invitation-requests'), 404, 'not_found');
        $this->assertError($this->sendAs($this->alice, 'POST', '/api/v1/admin/invitation-requests/' . $requestId . '/approve'), 404, 'not_found');
        $this->assertError($this->sendAs($this->bob, 'POST', '/api/v1/admin/invitation-requests/' . $requestId . '/decline'), 404, 'not_found');
    }

    public function testOpenRequestsAreLimitedAndNoteIsRequired(): void
    {
        for ($requestNumber = 1; $requestNumber <= 5; $requestNumber++) {
            $this->createRequest($this->alice, 'Person number ' . $requestNumber);
        }
        $this->assertError($this->sendAs($this->alice, 'POST', '/api/v1/invitation-requests', ['note' => 'One too many']), 409, 'too_many_open_requests');

        $this->assertError($this->sendAs($this->bob, 'POST', '/api/v1/invitation-requests', ['note' => '   ']), 422, 'validation_failed');
        $this->assertError($this->sendAs($this->bob, 'POST', '/api/v1/invitation-requests', ['note' => str_repeat('x', 201)]), 422, 'validation_failed');
    }

    public function testAdministratorAccountsCannotFileRequests(): void
    {
        $this->assertError($this->sendAs($this->admin, 'POST', '/api/v1/invitation-requests', ['note' => 'Someone']), 403, 'admin_account');
    }
}
