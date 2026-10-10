<?php

declare(strict_types=1);

namespace Muninn\Api\AdminAttention;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Users\UserRepository;

/**
 * "Something is waiting on the admin side" (decision D066).
 *
 * Signed-in users (ACCESS_USER):
 *   GET   /api/v1/admin-attention                 {is_recipient, needs_attention}
 *
 * System administrators (ACCESS_SYSTEM_ADMIN):
 *   GET   /api/v1/admin/attention-recipient       {recipient: {id, username, display_name} | null}
 *   PATCH /api/v1/admin/attention-recipient       {"user_id": "<uuid>" | null}
 *
 * Only the recipient ever gets needs_attention = true; everyone else, administrators included,
 * always gets false, so the answer tells nobody else anything.
 */
final class AdminAttentionController
{
    public function __construct(
        private readonly AdminAttentionService $attentionService,
        private readonly UserRepository $userRepository,
        private readonly AuditLog $auditLog,
    ) {
    }

    /** GET /api/v1/admin-attention */
    public function status(Request $request, RequestContext $context): Response
    {
        $signedInUser = $context->requireSession()->user;
        // Administrators see the admin pages themselves, so they are never the recipient.
        $isRecipient = !$signedInUser->isSystemAdmin && $this->attentionService->recipientUserId() === $signedInUser->id;

        return Response::data([
            'is_recipient' => $isRecipient,
            // Only counted for the recipient: nobody else may learn whether admin work waits.
            'needs_attention' => $isRecipient && $this->attentionService->isAdminWorkWaiting(),
        ]);
    }

    /** GET /api/v1/admin/attention-recipient */
    public function showRecipient(Request $request, RequestContext $context): Response
    {
        return Response::data(['recipient' => $this->recipientSummary($this->attentionService->recipientUserId())]);
    }

    /**
     * PATCH /api/v1/admin/attention-recipient  {"user_id": "<uuid>" | null}
     *
     * The recipient must be an active everyday account; null means nobody is told.
     */
    public function changeRecipient(Request $request, RequestContext $context): Response
    {
        $adminUser = $context->requireSession()->user;
        $requestBody = $request->jsonBody();
        if (!array_key_exists('user_id', $requestBody)) {
            throw HttpException::validation(['user_id' => 'Choose an account, or null for nobody.']);
        }

        $newRecipientUserId = $requestBody['user_id'];
        if ($newRecipientUserId !== null) {
            if (!is_string($newRecipientUserId) || !UuidGenerator::isValid($newRecipientUserId)) {
                throw HttpException::validation(['user_id' => 'Choose an existing account.']);
            }
            $newRecipient = $this->userRepository->findById($newRecipientUserId);
            if ($newRecipient === null) {
                throw HttpException::validation(['user_id' => 'Choose an existing account.']);
            }
            if ($newRecipient->isSystemAdmin) {
                throw HttpException::validation(['user_id' => 'Choose an everyday account, not an administrator account.']);
            }
            if (!$newRecipient->isActive()) {
                throw HttpException::validation(['user_id' => 'This account is disabled. Enable it first, or choose another one.']);
            }
        }

        $previousRecipientUserId = $this->attentionService->recipientUserId();
        $this->attentionService->setRecipientUserId($newRecipientUserId);
        if ($previousRecipientUserId !== $newRecipientUserId) {
            $this->auditLog->record(AuditLog::ADMIN_ATTENTION_RECIPIENT_CHANGED, $adminUser->id, 'user', $newRecipientUserId, $context->clientIp, [
                'from' => $previousRecipientUserId,
                'to' => $newRecipientUserId,
            ]);
        }

        return Response::data(['recipient' => $this->recipientSummary($newRecipientUserId)]);
    }

    /**
     * Who is told, for the admin page.
     *
     * @return array{id: string, username: string, display_name: string, status: string}|null
     */
    private function recipientSummary(?string $recipientUserId): ?array
    {
        if ($recipientUserId === null) {
            return null;
        }
        $recipient = $this->userRepository->findById($recipientUserId);
        if ($recipient === null) {
            return null;
        }

        return [
            'id' => $recipient->id,
            'username' => $recipient->username,
            'display_name' => $recipient->displayName,
            // A recipient disabled later keeps the setting but cannot sign in to see the icon.
            'status' => $recipient->status,
        ];
    }
}
