<?php

declare(strict_types=1);

namespace Muninn\Api\Auth;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\InputReader;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Users\UserInputRules;
use Muninn\Api\Users\UserRepository;

/**
 * Endpoints: POST /auth/login, POST /auth/logout, GET /auth/me.
 */
final class AuthController
{
    /** Identical body for every failed login, so responses never reveal which part was wrong. */
    private const INVALID_CREDENTIALS_MESSAGE = 'Invalid username or password.';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PasswordService $passwordService,
        private readonly SessionService $sessionService,
        private readonly SessionCookie $sessionCookie,
        private readonly RateLimiter $rateLimiter,
        private readonly AuditLog $auditLog,
    ) {
    }

    /**
     * POST /api/v1/auth/login  {"username": "...", "password": "..."}
     */
    public function login(Request $request, RequestContext $context): Response
    {
        $requestBody = $request->jsonBody();
        $submittedUsername = InputReader::requiredString($requestBody, 'username');
        $submittedPassword = InputReader::requiredString($requestBody, 'password');
        $normalisedUsername = mb_substr(UserInputRules::normaliseUsername($submittedUsername), 0, 64);

        try {
            $this->rateLimiter->assertLoginAllowed($normalisedUsername, $context->clientIp);
        } catch (HttpException $rateLimitException) {
            $this->auditLog->record(AuditLog::LOGIN_RATE_LIMITED, null, null, null, $context->clientIp, ['username' => $normalisedUsername]);
            throw $rateLimitException;
        }

        $candidateUser = $this->userRepository->findByUsername($normalisedUsername);
        if ($candidateUser === null) {
            // Spend comparable time so response timing does not reveal unknown usernames.
            $this->passwordService->verifyAgainstDummy($submittedPassword);
        }

        $credentialsAreValid = $candidateUser !== null
            && $this->passwordService->verify($submittedPassword, $candidateUser->passwordHash)
            && $candidateUser->isActive();

        if (!$credentialsAreValid) {
            $this->rateLimiter->recordAttempt(RateLimiter::TYPE_LOGIN, $normalisedUsername, $context->clientIp, false);
            $this->auditLog->record(AuditLog::LOGIN_FAILED, $candidateUser?->id, null, null, $context->clientIp, ['username' => $normalisedUsername]);
            throw new HttpException(401, 'invalid_credentials', self::INVALID_CREDENTIALS_MESSAGE);
        }

        // Transparently upgrade old hashes (e.g. bcrypt → Argon2id) while we know the password.
        if ($this->passwordService->needsRehash($candidateUser->passwordHash)) {
            $this->userRepository->updatePasswordHash($candidateUser->id, $this->passwordService->hash($submittedPassword));
        }

        // A previous session in this browser is replaced, never reused (prevents session fixation).
        $this->sessionService->revokeByRawToken($request->cookie($this->sessionCookie->name()));
        $newSession = $this->sessionService->create($candidateUser->id, $context->clientIp, $request->header('User-Agent'));

        $this->userRepository->recordLogin($candidateUser->id);
        $this->rateLimiter->recordAttempt(RateLimiter::TYPE_LOGIN, $normalisedUsername, $context->clientIp, true);
        $this->auditLog->record(AuditLog::LOGIN_SUCCEEDED, $candidateUser->id, 'session', $newSession->sessionId, $context->clientIp);

        return Response::data([
            'user' => $candidateUser->toPublicArray(),
            'csrf_token' => $newSession->csrfToken,
        ])->withSetCookie($this->sessionCookie->issue($newSession->rawToken));
    }

    /**
     * POST /api/v1/auth/logout  (requires session + CSRF token)
     */
    public function logout(Request $request, RequestContext $context): Response
    {
        $currentSession = $context->requireSession();
        $this->sessionService->revoke($currentSession->sessionId);
        $this->auditLog->record(AuditLog::LOGOUT, $currentSession->user->id, 'session', $currentSession->sessionId, $context->clientIp);

        return Response::noContent()->withSetCookie($this->sessionCookie->clear());
    }

    /**
     * GET /api/v1/auth/me — the current user and the CSRF token for state-changing calls.
     */
    public function me(Request $request, RequestContext $context): Response
    {
        $currentSession = $context->requireSession();

        return Response::data([
            'user' => $currentSession->user->toPublicArray(),
            'csrf_token' => $currentSession->csrfToken,
        ]);
    }
}
