<?php

declare(strict_types=1);

namespace Muninn\Api\Admin;

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
 *
 * Accounts are disabled, never deleted, in the MVP. A disabled user's sessions are revoked
 * at once and they cannot sign in; their workspaces and notes stay untouched.
 */
final class UserAdminController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly SessionService $sessionService,
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
