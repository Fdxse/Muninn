<?php

declare(strict_types=1);

namespace Muninn\Api\Auth;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\InputReader;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Security\SecretToken;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Users\UserRepository;

/**
 * Password reset links (decision D040) and changing one's own password.
 *
 * Admin endpoint (system admins only):
 *   POST /api/v1/admin/users/{id}/password-reset  {"expires_in_hours": 24}
 * Public endpoints (rate limited, token in the JSON body, never in a URL):
 *   POST /api/v1/password-resets/inspect   {"token": "..."}
 *   POST /api/v1/password-resets/complete  {"token": "...", "password": "..."}
 * Signed-in users:
 *   POST /api/v1/auth/password  {"current_password": "...", "new_password": "..."}
 */
final class PasswordResetController
{
    /** Default and maximum lifetime of a reset link, in hours. */
    private const DEFAULT_EXPIRY_HOURS = 24;
    private const MAX_EXPIRY_HOURS = 72;

    public function __construct(
        private readonly PasswordResetService $passwordResetService,
        private readonly PasswordService $passwordService,
        private readonly SessionService $sessionService,
        private readonly UserRepository $userRepository,
        private readonly RateLimiter $rateLimiter,
        private readonly AuditLog $auditLog,
        private readonly string $frontendBaseUrl,
    ) {
    }

    /**
     * POST /api/v1/admin/users/{id}/password-reset
     *
     * The response contains the reset link exactly once. Like invitation links, the token sits
     * in the URL fragment (#token=...), which browsers never send to servers or in Referer headers.
     */
    public function create(Request $request, RequestContext $context): Response
    {
        $adminUser = $context->requireSession()->user;
        $targetUserId = (string) $request->routeParameter('id');
        $targetUser = UuidGenerator::isValid($targetUserId) ? $this->userRepository->findById($targetUserId) : null;
        if ($targetUser === null) {
            throw HttpException::notFound();
        }
        if ($targetUser->id === $adminUser->id) {
            throw HttpException::conflict('cannot_reset_self', 'Change your own password from your account page instead.');
        }
        if (!$targetUser->isActive()) {
            throw HttpException::conflict('user_disabled', 'Enable the account before creating a reset link.');
        }

        $expiresInHours = InputReader::optionalInt($request->jsonBody(), 'expires_in_hours') ?? self::DEFAULT_EXPIRY_HOURS;
        if ($expiresInHours < 1 || $expiresInHours > self::MAX_EXPIRY_HOURS) {
            throw HttpException::validation(['expires_in_hours' => 'Choose between 1 and ' . self::MAX_EXPIRY_HOURS . ' hours.']);
        }

        $createdReset = $this->passwordResetService->create($targetUser->id, $adminUser->id, $expiresInHours);
        $this->auditLog->record(
            AuditLog::PASSWORD_RESET_CREATED,
            $adminUser->id,
            'user',
            $targetUser->id,
            $context->clientIp,
            ['expires_in_hours' => $expiresInHours],
        );

        return Response::data([
            'username' => $targetUser->username,
            'expires_at' => $createdReset['expires_at'],
            'reset_url' => rtrim($this->frontendBaseUrl, '/') . '/reset-password.php#token=' . $createdReset['raw_token'],
        ], 201);
    }

    /** POST /api/v1/password-resets/inspect  {"token": "..."} */
    public function inspect(Request $request, RequestContext $context): Response
    {
        $rawToken = InputReader::requiredString($request->jsonBody(), 'token');
        $this->rateLimiter->assertPasswordResetAllowed($context->clientIp);

        try {
            $this->assertTokenFormat($rawToken);
            $resetDetails = $this->passwordResetService->inspect($rawToken);
        } catch (HttpException $inspectFailure) {
            $this->rateLimiter->recordAttempt(RateLimiter::TYPE_PASSWORD_RESET, null, $context->clientIp, false);
            throw $inspectFailure;
        }

        return Response::data(['valid' => true] + $resetDetails);
    }

    /**
     * POST /api/v1/password-resets/complete  {"token": "...", "password": "..."}
     *
     * Sets the new password, uses up the link and signs the account out everywhere. The user
     * then signs in normally; no session is created here.
     */
    public function complete(Request $request, RequestContext $context): Response
    {
        $requestBody = $request->jsonBody();
        $rawToken = InputReader::requiredString($requestBody, 'token');
        $this->rateLimiter->assertPasswordResetAllowed($context->clientIp);

        $newPassword = InputReader::optionalString($requestBody, 'password') ?? '';
        $passwordError = $this->passwordService->policyError($newPassword);
        if ($passwordError !== null) {
            throw HttpException::validation(['password' => $passwordError]);
        }

        // Hash before opening the transaction so the link row is locked only briefly.
        $newPasswordHash = $this->passwordService->hash($newPassword);

        try {
            $this->assertTokenFormat($rawToken);
            $completedReset = $this->passwordResetService->complete($rawToken, $newPasswordHash);
        } catch (HttpException $completeFailure) {
            if ($completeFailure->errorCode === 'password_reset_invalid') {
                $this->rateLimiter->recordAttempt(RateLimiter::TYPE_PASSWORD_RESET, null, $context->clientIp, false);
            }
            throw $completeFailure;
        }

        // Whoever knew the old password is signed out everywhere.
        $this->sessionService->revokeAllForUser($completedReset['user_id']);
        $this->auditLog->record(
            AuditLog::PASSWORD_RESET_COMPLETED,
            $completedReset['user_id'],
            'user',
            $completedReset['user_id'],
            $context->clientIp,
        );

        return Response::noContent();
    }

    /**
     * POST /api/v1/auth/password  {"current_password": "...", "new_password": "..."}
     *
     * Wrong current passwords count as failed sign-ins, so this cannot be used to guess
     * passwords faster than the login form. Every other session of the account is signed out.
     */
    public function changeOwn(Request $request, RequestContext $context): Response
    {
        $currentSession = $context->requireSession();
        $currentUser = $currentSession->user;
        $requestBody = $request->jsonBody();
        $currentPassword = InputReader::requiredString($requestBody, 'current_password');
        $newPassword = InputReader::optionalString($requestBody, 'new_password') ?? '';

        $this->rateLimiter->assertLoginAllowed($currentUser->username, $context->clientIp);
        if (!$this->passwordService->verify($currentPassword, $currentUser->passwordHash)) {
            $this->rateLimiter->recordAttempt(RateLimiter::TYPE_LOGIN, $currentUser->username, $context->clientIp, false);
            throw HttpException::validation(['current_password' => 'That is not your current password.']);
        }

        $passwordError = $this->passwordService->policyError($newPassword);
        if ($passwordError !== null) {
            throw HttpException::validation(['new_password' => $passwordError]);
        }

        $this->userRepository->updatePasswordHash($currentUser->id, $this->passwordService->hash($newPassword));
        $this->sessionService->revokeAllForUserExcept($currentUser->id, $currentSession->sessionId);
        // A reset link the administrator created earlier is no longer needed.
        $this->passwordResetService->revokeUnusedForUser($currentUser->id);
        $this->auditLog->record(AuditLog::PASSWORD_CHANGED, $currentUser->id, 'user', $currentUser->id, $context->clientIp);

        return Response::noContent();
    }

    /** @throws HttpException */
    private function assertTokenFormat(string $rawToken): void
    {
        if (!SecretToken::looksValid($rawToken)) {
            throw PasswordResetService::invalidLink();
        }
    }
}
