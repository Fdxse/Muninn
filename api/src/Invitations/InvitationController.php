<?php

declare(strict_types=1);

namespace Muninn\Api\Invitations;

use Muninn\Api\Auth\PasswordService;
use Muninn\Api\Auth\RateLimiter;
use Muninn\Api\Auth\SessionCookie;
use Muninn\Api\Auth\SessionService;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\InputReader;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Security\SecretToken;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Users\UserInputRules;
use Muninn\Api\Users\UserRepository;
use Muninn\Api\Workspaces\WorkspaceService;

/**
 * Admin endpoints (system admins only):
 *   GET    /api/v1/admin/invitations
 *   POST   /api/v1/admin/invitations
 *   DELETE /api/v1/admin/invitations/{id}
 * Public endpoints (rate limited):
 *   POST   /api/v1/invitations/inspect
 *   POST   /api/v1/invitations/accept
 */
final class InvitationController
{
    private const NOTE_MAX_LENGTH = 200;

    public function __construct(
        private readonly InvitationService $invitationService,
        private readonly PasswordService $passwordService,
        private readonly SessionService $sessionService,
        private readonly SessionCookie $sessionCookie,
        private readonly RateLimiter $rateLimiter,
        private readonly AuditLog $auditLog,
        private readonly string $frontendBaseUrl,
        private readonly int $defaultExpiryHours,
        private readonly int $maxExpiryHours,
        private readonly WorkspaceService $workspaceService,
        private readonly UserRepository $userRepository,
    ) {
    }

    /** GET /api/v1/admin/invitations */
    public function list(Request $request, RequestContext $context): Response
    {
        return Response::data(['invitations' => $this->invitationService->listAll()]);
    }

    /**
     * POST /api/v1/admin/invitations  {"note": "...", "expires_in_hours": 72}
     *
     * The response contains the invitation link exactly once. The token sits in the URL
     * fragment (#token=...), which browsers never send to servers or in Referer headers.
     */
    public function create(Request $request, RequestContext $context): Response
    {
        $adminSession = $context->requireSession();
        $requestBody = $request->jsonBody();

        $note = InputReader::optionalString($requestBody, 'note');
        $note = $note === null || trim($note) === '' ? null : trim($note);
        $expiresInHours = InputReader::optionalInt($requestBody, 'expires_in_hours') ?? $this->defaultExpiryHours;

        $fieldErrors = [];
        if ($note !== null && mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            $fieldErrors['note'] = 'Use at most ' . self::NOTE_MAX_LENGTH . ' characters.';
        }
        if ($expiresInHours < 1 || $expiresInHours > $this->maxExpiryHours) {
            $fieldErrors['expires_in_hours'] = 'Choose between 1 and ' . $this->maxExpiryHours . ' hours.';
        }
        if ($fieldErrors !== []) {
            throw HttpException::validation($fieldErrors);
        }

        $createdInvitation = $this->invitationService->create($adminSession->user->id, $note, $expiresInHours);
        $this->auditLog->record(
            AuditLog::INVITATION_CREATED,
            $adminSession->user->id,
            'invitation',
            $createdInvitation['id'],
            $context->clientIp,
            ['expires_in_hours' => $expiresInHours],
        );

        return Response::data([
            'invitation' => $this->invitationService->findPublic($createdInvitation['id']),
            'invitation_url' => rtrim($this->frontendBaseUrl, '/') . '/invite.php#token=' . $createdInvitation['raw_token'],
        ], 201);
    }

    /** DELETE /api/v1/admin/invitations/{id} */
    public function revoke(Request $request, RequestContext $context): Response
    {
        $adminSession = $context->requireSession();
        $invitationId = (string) $request->routeParameter('id');
        if (!UuidGenerator::isValid($invitationId)) {
            throw HttpException::notFound();
        }

        $this->invitationService->revoke($invitationId);
        $this->auditLog->record(AuditLog::INVITATION_REVOKED, $adminSession->user->id, 'invitation', $invitationId, $context->clientIp);

        return Response::noContent();
    }

    /** POST /api/v1/invitations/inspect  {"token": "..."} */
    public function inspect(Request $request, RequestContext $context): Response
    {
        $rawToken = InputReader::requiredString($request->jsonBody(), 'token');
        $this->rateLimiter->assertInvitationAllowed($context->clientIp);

        try {
            $this->assertTokenFormat($rawToken);
            $invitationDetails = $this->invitationService->inspect($rawToken);
        } catch (HttpException $inspectFailure) {
            $this->rateLimiter->recordAttempt(RateLimiter::TYPE_INVITATION, null, $context->clientIp, false);
            throw $inspectFailure;
        }

        return Response::data(['valid' => true, 'expires_at' => $invitationDetails['expires_at']]);
    }

    /**
     * POST /api/v1/invitations/accept
     *   {"token": "...", "username": "...", "display_name": "...", "password": "..."}
     *
     * Creates the account, consumes the invitation and signs the new user in.
     */
    public function accept(Request $request, RequestContext $context): Response
    {
        $requestBody = $request->jsonBody();
        $rawToken = InputReader::requiredString($requestBody, 'token');
        $this->rateLimiter->assertInvitationAllowed($context->clientIp);

        $username = InputReader::optionalString($requestBody, 'username') ?? '';
        $displayName = InputReader::optionalString($requestBody, 'display_name') ?? '';
        $plainPassword = InputReader::optionalString($requestBody, 'password') ?? '';

        $fieldErrors = array_filter([
            'username' => UserInputRules::usernameError($username),
            'display_name' => UserInputRules::displayNameError($displayName),
            'password' => $this->passwordService->policyError($plainPassword),
        ]);
        if ($fieldErrors !== []) {
            throw HttpException::validation($fieldErrors);
        }

        // Hash before opening the transaction so the invitation row is locked only briefly.
        $passwordHash = $this->passwordService->hash($plainPassword);

        try {
            $this->assertTokenFormat($rawToken);
            $acceptedInvitation = $this->invitationService->accept($rawToken, $username, trim($displayName), $passwordHash);
        } catch (HttpException $acceptFailure) {
            if ($acceptFailure->errorCode === 'invitation_invalid') {
                $this->rateLimiter->recordAttempt(RateLimiter::TYPE_INVITATION, null, $context->clientIp, false);
            }
            throw $acceptFailure;
        }

        $this->auditLog->record(
            AuditLog::INVITATION_ACCEPTED,
            $acceptedInvitation['user_id'],
            'invitation',
            $acceptedInvitation['invitation_id'],
            $context->clientIp,
        );

        // Every new user starts with their own personal workspace (Course MVP item 2).
        $acceptedUser = $this->userRepository->findById($acceptedInvitation['user_id']);
        if ($acceptedUser !== null) {
            $this->workspaceService->ensurePersonalWorkspace($acceptedUser);
        }

        $newSession = $this->sessionService->create($acceptedInvitation['user_id'], $context->clientIp, $request->header('User-Agent'));
        $newUser = [
            'id' => $acceptedInvitation['user_id'],
            'username' => UserInputRules::normaliseUsername($username),
            'display_name' => trim($displayName),
            'is_system_admin' => false,
        ];

        return Response::data(['user' => $newUser, 'csrf_token' => $newSession->csrfToken], 201)
            ->withSetCookie($this->sessionCookie->issue($newSession->rawToken));
    }

    /** @throws HttpException */
    private function assertTokenFormat(string $rawToken): void
    {
        if (!SecretToken::looksValid($rawToken)) {
            throw InvitationService::invalidInvitation();
        }
    }
}
