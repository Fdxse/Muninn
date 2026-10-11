<?php

declare(strict_types=1);

namespace Muninn\Api\Auth;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\InputReader;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Notifications\AdminNotifier;
use Muninn\Api\Users\UserRepository;

/**
 * Signing in on another device with a 6-digit code (decision D068).
 *
 * Signed-in users (not system administrators):
 *   POST   /api/v1/auth/device-codes        → 201 {id, code, expires_at}; replaces an older code
 *   GET    /api/v1/auth/device-codes/{id}   → {id, status, expires_at, used_at}
 *   DELETE /api/v1/auth/device-codes/{id}   → 204
 * Public (rate limited):
 *   POST   /api/v1/auth/device-code-login  {"code": "123456"} → user + csrf_token, like /auth/login
 */
final class DeviceSignInController
{
    /** One message for wrong, used, expired and cancelled codes, so they are indistinguishable. */
    private const INVALID_CODE_MESSAGE = 'That code is wrong or no longer works. Show a new code on your signed-in device.';

    public function __construct(
        private readonly DeviceSignInCodeService $codeService,
        private readonly UserRepository $userRepository,
        private readonly SessionService $sessionService,
        private readonly SessionCookie $sessionCookie,
        private readonly RateLimiter $rateLimiter,
        private readonly AuditLog $auditLog,
        private readonly AdminNotifier $adminNotifier,
    ) {
    }

    /**
     * POST /api/v1/auth/device-codes
     *
     * The digits are in this response only; they are never stored or shown again.
     */
    public function create(Request $request, RequestContext $context): Response
    {
        $currentSession = $context->requireSession();
        self::assertNotSystemAdmin($currentSession);

        $createdCode = $this->codeService->create($currentSession->user->id, $currentSession->sessionId);
        $this->auditLog->record(
            AuditLog::DEVICE_CODE_CREATED,
            $currentSession->user->id,
            'device_code',
            $createdCode['id'],
            $context->clientIp,
        );

        return Response::data([
            'id' => $createdCode['id'],
            'code' => $createdCode['code'],
            'expires_at' => $createdCode['expires_at'],
            'lifetime_seconds' => DeviceSignInCodeService::CODE_LIFETIME_MINUTES * 60,
        ], 201);
    }

    /** GET /api/v1/auth/device-codes/{id} — lets the showing device notice the code was used. */
    public function show(Request $request, RequestContext $context): Response
    {
        $currentSession = $context->requireSession();
        self::assertNotSystemAdmin($currentSession);

        return Response::data($this->codeService->describeOwn((string) $request->routeParameter('id'), $currentSession->user->id));
    }

    /** DELETE /api/v1/auth/device-codes/{id} — "Cancel" on the showing device. */
    public function cancel(Request $request, RequestContext $context): Response
    {
        $currentSession = $context->requireSession();
        self::assertNotSystemAdmin($currentSession);

        $codeId = (string) $request->routeParameter('id');
        $this->codeService->cancelOwn($codeId, $currentSession->user->id);
        $this->auditLog->record(AuditLog::DEVICE_CODE_CANCELLED, $currentSession->user->id, 'device_code', $codeId, $context->clientIp);

        return Response::noContent();
    }

    /**
     * POST /api/v1/auth/device-code-login  {"code": "123456"}
     *
     * Wrong codes are counted per IP and from everywhere together (RateLimiter, D068). Input
     * that is not 6 digits is refused as a typo without counting, since it cannot match.
     */
    public function signIn(Request $request, RequestContext $context): Response
    {
        $typedCode = InputReader::requiredString($request->jsonBody(), 'code');

        try {
            $this->rateLimiter->assertDeviceCodeAllowed($context->clientIp);
        } catch (HttpException $rateLimitException) {
            $this->auditLog->record(AuditLog::DEVICE_CODE_RATE_LIMITED, null, null, null, $context->clientIp);
            throw $rateLimitException;
        }

        $normalisedCode = DeviceSignInCodeService::normaliseTypedCode($typedCode);
        if ($normalisedCode === null) {
            throw HttpException::validation(['code' => 'Type the 6 digits shown on your signed-in device.']);
        }

        $redeemedCode = $this->codeService->redeem($normalisedCode);
        $codeUser = $redeemedCode === null ? null : $this->userRepository->findById($redeemedCode['user_id']);
        if ($redeemedCode === null || $codeUser === null) {
            $this->rateLimiter->recordAttempt(RateLimiter::TYPE_DEVICE_CODE, null, $context->clientIp, false);
            $this->auditLog->record(AuditLog::DEVICE_CODE_FAILED, null, null, null, $context->clientIp);
            // Tell the administrator once, when this wrong code is the one that pauses code sign-in.
            if ($this->rateLimiter->deviceCodeBrakeJustEngaged()) {
                $this->adminNotifier->deviceCodeSignInPaused(
                    $context->clientIp,
                    RateLimiter::DEVICE_CODE_MAX_FAILURES_EVERYWHERE,
                    RateLimiter::DEVICE_CODE_EVERYWHERE_WINDOW_MINUTES,
                );
            }
            throw new HttpException(401, 'invalid_code', self::INVALID_CODE_MESSAGE);
        }

        // From here on it is an ordinary sign-in, exactly like AuthController::login.
        // A previous session in this browser is replaced, never reused (prevents session fixation).
        $this->sessionService->revokeByRawToken($request->cookie($this->sessionCookie->name()));
        $newSession = $this->sessionService->create($codeUser->id, $context->clientIp, $request->header('User-Agent'));
        $this->codeService->recordUsedBySession($redeemedCode['code_id'], $newSession->sessionId);

        $this->userRepository->recordLogin($codeUser->id);
        $this->rateLimiter->recordAttempt(RateLimiter::TYPE_DEVICE_CODE, $codeUser->username, $context->clientIp, true);
        $this->auditLog->record(
            AuditLog::DEVICE_CODE_USED,
            $codeUser->id,
            'session',
            $newSession->sessionId,
            $context->clientIp,
            ['device_code_id' => $redeemedCode['code_id']],
        );
        $this->adminNotifier->deviceCodeUsed($codeUser->displayName, $codeUser->username, $context->clientIp);

        return Response::data([
            'user' => $codeUser->toPublicArray(),
            'csrf_token' => $newSession->csrfToken,
        ])->withSetCookie($this->sessionCookie->issue($newSession->rawToken));
    }

    /**
     * System administrator accounts sign in with their password only (D068). The answer is
     * 403 rather than 404: the administrator knows the feature exists.
     *
     * @throws HttpException
     */
    private static function assertNotSystemAdmin(AuthenticatedSession $currentSession): void
    {
        if ($currentSession->user->isSystemAdmin) {
            throw HttpException::forbidden('not_for_admins', 'Administrator accounts sign in with their password only.');
        }
    }
}
