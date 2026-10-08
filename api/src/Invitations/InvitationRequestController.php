<?php

declare(strict_types=1);

namespace Muninn\Api\Invitations;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\InputReader;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Notifications\AdminNotifier;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Users\User;
use Muninn\Api\Validation\TextRules;

/**
 * Invitation requests (decision D049).
 *
 * Signed-in everyday users (their own requests only):
 *   GET    /api/v1/invitation-requests
 *   POST   /api/v1/invitation-requests            {"note": "Who and why"}
 *   DELETE /api/v1/invitation-requests/{id}       cancel
 *   POST   /api/v1/invitation-requests/{id}/link  create (or replace) the invitation link
 * System administrators:
 *   GET    /api/v1/admin/invitation-requests
 *   GET    /api/v1/admin/invitation-requests/pending-count
 *   POST   /api/v1/admin/invitation-requests/{id}/approve
 *   POST   /api/v1/admin/invitation-requests/{id}/decline
 */
final class InvitationRequestController
{
    private const NOTE_MAX_LENGTH = 200;

    public function __construct(
        private readonly InvitationRequestService $invitationRequestService,
        private readonly AuditLog $auditLog,
        private readonly string $frontendBaseUrl,
        private readonly int $linkExpiryHours,
        private readonly AdminNotifier $adminNotifier,
    ) {
    }

    /** GET /api/v1/invitation-requests — the caller's own requests. */
    public function listOwn(Request $request, RequestContext $context): Response
    {
        $currentUser = $this->requireEverydayUser($context);

        return Response::data([
            'invitation_requests' => $this->invitationRequestService->listForRequester($currentUser->id),
            'max_open_requests' => InvitationRequestService::MAX_OPEN_REQUESTS_PER_USER,
        ]);
    }

    /** POST /api/v1/invitation-requests  {"note": "..."} */
    public function create(Request $request, RequestContext $context): Response
    {
        $currentUser = $this->requireEverydayUser($context);
        $note = trim(InputReader::optionalString($request->jsonBody(), 'note') ?? '');
        $noteError = TextRules::singleLineError($note, self::NOTE_MAX_LENGTH, true);
        if ($noteError !== null) {
            throw HttpException::validation(['note' => $noteError]);
        }

        $newRequestId = $this->invitationRequestService->create($currentUser->id, $note);
        $this->auditLog->record(AuditLog::INVITATION_REQUEST_CREATED, $currentUser->id, 'invitation_request', $newRequestId, $context->clientIp);
        // The administrator gets a push notification after this response is sent (D057).
        $this->adminNotifier->invitationRequested($currentUser->displayName, $newRequestId);

        return Response::data(['invitation_request' => $this->invitationRequestService->find($newRequestId, $currentUser->id)], 201);
    }

    /** DELETE /api/v1/invitation-requests/{id} — cancels the caller's own open request. */
    public function cancel(Request $request, RequestContext $context): Response
    {
        $currentUser = $this->requireEverydayUser($context);
        $requestId = (string) $request->routeParameter('id');

        $this->invitationRequestService->cancel($requestId, $currentUser->id);
        $this->auditLog->record(AuditLog::INVITATION_REQUEST_CANCELLED, $currentUser->id, 'invitation_request', $requestId, $context->clientIp);

        return Response::noContent();
    }

    /**
     * POST /api/v1/invitation-requests/{id}/link
     *
     * Returns the invitation link exactly once, like an administrator's invitation. Calling it
     * again replaces the link: the earlier one stops working.
     */
    public function createLink(Request $request, RequestContext $context): Response
    {
        $currentUser = $this->requireEverydayUser($context);
        $requestId = (string) $request->routeParameter('id');
        if (!UuidGenerator::isValid($requestId)) {
            throw HttpException::notFound();
        }

        // The administrator's invitation list shows who the link is for and who asked.
        $ownRequest = $this->invitationRequestService->find($requestId, $currentUser->id);
        $invitationNote = mb_substr('Requested by ' . $currentUser->username . ': ' . $ownRequest['note'], 0, self::NOTE_MAX_LENGTH);

        $createdLink = $this->invitationRequestService->createLink($requestId, $currentUser->id, $invitationNote, $this->linkExpiryHours);
        $this->auditLog->record(
            AuditLog::INVITATION_REQUEST_LINK_CREATED,
            $currentUser->id,
            'invitation_request',
            $requestId,
            $context->clientIp,
            ['invitation_id' => $createdLink['invitation_id']],
        );

        return Response::data([
            'invitation_request' => $this->invitationRequestService->find($requestId, $currentUser->id),
            'invitation_url' => rtrim($this->frontendBaseUrl, '/') . '/invite.php#token=' . $createdLink['raw_token'],
        ], 201);
    }

    /** GET /api/v1/admin/invitation-requests */
    public function listAll(Request $request, RequestContext $context): Response
    {
        return Response::data(['invitation_requests' => $this->invitationRequestService->listAll()]);
    }

    /** GET /api/v1/admin/invitation-requests/pending-count — for the navigation badge. */
    public function pendingCount(Request $request, RequestContext $context): Response
    {
        return Response::data(['pending_count' => $this->invitationRequestService->countPending()]);
    }

    /** POST /api/v1/admin/invitation-requests/{id}/approve */
    public function approve(Request $request, RequestContext $context): Response
    {
        $adminUser = $context->requireSession()->user;
        $requestId = (string) $request->routeParameter('id');

        $this->invitationRequestService->approve($requestId, $adminUser->id);
        $this->auditLog->record(AuditLog::INVITATION_REQUEST_APPROVED, $adminUser->id, 'invitation_request', $requestId, $context->clientIp);

        return Response::data(['invitation_request' => $this->invitationRequestService->find($requestId)]);
    }

    /** POST /api/v1/admin/invitation-requests/{id}/decline — also withdraws an unused approval. */
    public function decline(Request $request, RequestContext $context): Response
    {
        $adminUser = $context->requireSession()->user;
        $requestId = (string) $request->routeParameter('id');

        $this->invitationRequestService->decline($requestId, $adminUser->id);
        $this->auditLog->record(AuditLog::INVITATION_REQUEST_DECLINED, $adminUser->id, 'invitation_request', $requestId, $context->clientIp);

        return Response::data(['invitation_request' => $this->invitationRequestService->find($requestId)]);
    }

    /**
     * Administrators create invitations directly, so requests are for everyday accounts only.
     *
     * @throws HttpException 403 for administrator accounts.
     */
    private function requireEverydayUser(RequestContext $context): User
    {
        $currentUser = $context->requireSession()->user;
        if ($currentUser->isSystemAdmin) {
            throw HttpException::forbidden('admin_account', 'Administrator accounts create invitations directly under Admin.');
        }

        return $currentUser;
    }
}
