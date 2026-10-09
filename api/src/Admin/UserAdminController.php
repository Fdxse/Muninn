<?php

declare(strict_types=1);

namespace Muninn\Api\Admin;

use Muninn\Api\Auth\PasswordResetService;
use Muninn\Api\Chat\ChatAccessLevel;
use Muninn\Api\Auth\SessionService;
use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Users\UserRepository;

/**
 * Account administration for system administrators (decision D031):
 *   GET  /api/v1/admin/users
 *   POST /api/v1/admin/users/{id}/disable
 *   POST /api/v1/admin/users/{id}/enable
 *   PATCH /api/v1/admin/users/{id}/chat-access  {"chat_access": "off|own_workspaces|member_workspaces|global"} (D062)
 *
 * Accounts are disabled, never deleted, in the MVP. A disabled user's sessions are revoked
 * at once, their password reset links stop working, and they cannot sign in; their workspaces
 * and notes stay untouched.
 */
final class UserAdminController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly SessionService $sessionService,
        private readonly PasswordResetService $passwordResetService,
        private readonly AuditLog $auditLog,
    ) {
    }

    /** GET /api/v1/admin/users */
    public function list(Request $request, RequestContext $context): Response
    {
        $userRows = $this->userRepository->listForAdmin();

        return Response::data(['users' => array_map(static fn (array $userRow): array => [
            'id' => $userRow['id'],
            'username' => $userRow['username'],
            'display_name' => $userRow['display_name'],
            'is_system_admin' => (bool) $userRow['is_system_admin'],
            'status' => $userRow['status'],
            // How much chat the account may use (D062). Administrator accounts never chat.
            'chat_access' => (bool) $userRow['is_system_admin'] ? ChatAccessLevel::Off->value : $userRow['chat_access'],
            'created_at' => UtcTimestamp::toIso((string) $userRow['created_at']),
            'last_login_at' => UtcTimestamp::toIsoOrNull($userRow['last_login_at']),
        ], $userRows)]);
    }

    /** POST /api/v1/admin/users/{id}/disable */
    public function disable(Request $request, RequestContext $context): Response
    {
        $adminUser = $context->requireSession()->user;
        $targetUserId = $this->existingUserId($request);
        if ($targetUserId === $adminUser->id) {
            throw HttpException::conflict('cannot_disable_self', 'You cannot disable the account you are signed in with.');
        }

        $this->userRepository->setStatus($targetUserId, 'disabled');
        $this->sessionService->revokeAllForUser($targetUserId);
        // An open password reset link must not survive the account being disabled (D040).
        $this->passwordResetService->revokeUnusedForUser($targetUserId);
        $this->auditLog->record(AuditLog::USER_DISABLED, $adminUser->id, 'user', $targetUserId, $context->clientIp);

        return Response::noContent();
    }

    /** POST /api/v1/admin/users/{id}/enable */
    public function enable(Request $request, RequestContext $context): Response
    {
        $adminUser = $context->requireSession()->user;
        $targetUserId = $this->existingUserId($request);

        $this->userRepository->setStatus($targetUserId, 'active');
        $this->auditLog->record(AuditLog::USER_ENABLED, $adminUser->id, 'user', $targetUserId, $context->clientIp);

        return Response::noContent();
    }

    /**
     * PATCH /api/v1/admin/users/{id}/chat-access  {"chat_access": "..."}
     *
     * Takes effect on the user's next request: every chat endpoint reads the level afresh.
     * Administrator accounts cannot chat at all (D062), so their level cannot be changed.
     */
    public function changeChatAccess(Request $request, RequestContext $context): Response
    {
        $adminUser = $context->requireSession()->user;
        $targetUserId = $this->existingUserId($request);
        $targetUser = $this->userRepository->findById($targetUserId);
        if ($targetUser === null || $targetUser->isSystemAdmin) {
            throw HttpException::validation(['chat_access' => 'Administrator accounts cannot use chat.']);
        }

        $newChatAccess = ChatAccessLevel::tryFromInput($request->jsonBody()['chat_access'] ?? null);
        if ($newChatAccess === null) {
            throw HttpException::validation(['chat_access' => 'Choose one of: ' . implode(', ', ChatAccessLevel::inputValues()) . '.']);
        }

        $this->userRepository->setChatAccess($targetUserId, $newChatAccess);
        $this->auditLog->record(AuditLog::USER_CHAT_ACCESS_CHANGED, $adminUser->id, 'user', $targetUserId, $context->clientIp, [
            'from' => $targetUser->chatAccess->value,
            'to' => $newChatAccess->value,
        ]);

        return Response::data(['chat_access' => $newChatAccess->value]);
    }

    /** @throws HttpException 404 when {id} is not an existing user. */
    private function existingUserId(Request $request): string
    {
        $targetUserId = (string) $request->routeParameter('id');
        if (!UuidGenerator::isValid($targetUserId) || $this->userRepository->findById($targetUserId) === null) {
            throw HttpException::notFound();
        }

        return $targetUserId;
    }
}
