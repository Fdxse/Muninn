<?php

declare(strict_types=1);

namespace Muninn\Api;

use Muninn\Api\Auth\AuthController;
use Muninn\Api\Auth\PasswordService;
use Muninn\Api\Auth\RateLimiter;
use Muninn\Api\Auth\SessionCookie;
use Muninn\Api\Auth\SessionService;
use Muninn\Api\Config\Config;
use Muninn\Api\Http\ClientIpResolver;
use Muninn\Api\Http\Cors;
use Muninn\Api\Http\ErrorHandler;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Http\Router;
use Muninn\Api\Http\SecurityHeaders;
use Muninn\Api\Invitations\InvitationController;
use Muninn\Api\Invitations\InvitationService;
use Muninn\Api\Logging\AppLogger;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Users\UserRepository;
use PDO;
use Throwable;

/**
 * Wires services together, declares routes, and runs the request pipeline:
 *
 *   CORS preflight → route match → Origin check → authentication → authorization
 *   → CSRF check → handler → error mapping → CORS + security headers.
 *
 * Access rules are attached to routes and enforced here, before any handler runs, so an
 * endpoint cannot accidentally skip authentication, admin checks, or CSRF protection.
 */
final class Application
{
    private Router $router;
    private Cors $cors;
    private ErrorHandler $errorHandler;
    private ClientIpResolver $clientIpResolver;
    private SessionService $sessionService;
    private SessionCookie $sessionCookie;

    public function __construct(
        private readonly Config $config,
        private readonly PDO $database,
        private readonly AppLogger $logger,
    ) {
        $this->cors = new Cors($config->getStringList('cors.allowed_origins'));
        $this->errorHandler = new ErrorHandler($logger);
        $this->clientIpResolver = new ClientIpResolver($config->getStringList('security.trusted_proxies'));
        $this->sessionService = new SessionService(
            $database,
            $config->getInt('session.idle_timeout_hours'),
            $config->getInt('session.absolute_timeout_hours'),
        );
        $this->sessionCookie = new SessionCookie(
            $config->getString('session.cookie_name'),
            $config->getBool('session.cookie_secure'),
            $config->getInt('session.absolute_timeout_hours'),
        );
        $this->router = new Router();
        $this->registerRoutes();
    }

    /** Exposed so tests can register extra routes (e.g. one that throws). */
    public function router(): Router
    {
        return $this->router;
    }

    /** Handles one request and always returns a response; it never throws. */
    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(8));

        try {
            $response = $this->dispatch($request, $requestId);
        } catch (Throwable $throwable) {
            $response = $this->errorHandler->toResponse($throwable, $requestId);
        }

        $response = $this->cors->withCorsHeaders($request, $response);

        return SecurityHeaders::apply($response)->withHeader('X-Request-Id', $requestId);
    }

    /** @throws Throwable */
    private function dispatch(Request $request, string $requestId): Response
    {
        if ($this->cors->isPreflight($request)) {
            return $this->cors->preflightResponse($request);
        }

        $matchedRoute = $this->router->match($request->method(), $request->path());
        $routedRequest = $request->withRouteParameters($matchedRoute['parameters']);

        // Browsers always send Origin on cross-site state-changing requests. A foreign origin
        // is refused outright; requests without Origin (CLI tools, tests) are allowed and are
        // still subject to the session + CSRF checks below.
        $requestOrigin = $request->header('Origin');
        if ($request->isStateChanging() && $requestOrigin !== null && !$this->cors->isAllowedOrigin($requestOrigin)) {
            throw HttpException::forbidden('origin_not_allowed', 'This origin is not allowed.');
        }

        $currentSession = null;
        if ($matchedRoute['access'] !== Router::ACCESS_PUBLIC) {
            $currentSession = $this->sessionService->findActive($request->cookie($this->sessionCookie->name()));
            if ($currentSession === null) {
                throw HttpException::unauthenticated();
            }

            // Admin routes are hidden from everyone else: 404 rather than 403.
            if ($matchedRoute['access'] === Router::ACCESS_SYSTEM_ADMIN && !$currentSession->user->isSystemAdmin) {
                throw HttpException::notFound();
            }

            // Synchronizer-token CSRF defence (decision D023) for every authenticated state change.
            if ($request->isStateChanging()) {
                $submittedCsrfToken = (string) $request->header('X-CSRF-Token');
                if (!hash_equals($currentSession->csrfToken, $submittedCsrfToken)) {
                    throw HttpException::forbidden('csrf_failed', 'The security token is missing or invalid. Reload and try again.');
                }
            }
        }

        $context = new RequestContext(
            requestId: $requestId,
            clientIp: $this->clientIpResolver->resolve($request),
            session: $currentSession,
        );

        return ($matchedRoute['handler'])($routedRequest, $context);
    }

    private function registerRoutes(): void
    {
        $passwordService = new PasswordService();
        $userRepository = new UserRepository($this->database);
        $auditLog = new AuditLog($this->database);
        $rateLimiter = new RateLimiter(
            $this->database,
            $this->config->getInt('security.rate_limit_window_minutes'),
            $this->config->getInt('security.login_max_failures_per_username'),
            $this->config->getInt('security.login_max_failures_per_ip'),
            $this->config->getInt('security.invitation_max_failures_per_ip'),
        );

        $authController = new AuthController(
            $userRepository,
            $passwordService,
            $this->sessionService,
            $this->sessionCookie,
            $rateLimiter,
            $auditLog,
        );
        $invitationController = new InvitationController(
            new InvitationService($this->database, $userRepository),
            $passwordService,
            $this->sessionService,
            $this->sessionCookie,
            $rateLimiter,
            $auditLog,
            $this->config->getString('frontend.base_url'),
            $this->config->getInt('invitations.default_expiry_hours'),
            $this->config->getInt('invitations.max_expiry_hours'),
        );

        // Health check: reveals nothing about versions or the database.
        $this->router->add('GET', '/api/v1/health', fn (): Response => Response::data(['status' => 'ok']), Router::ACCESS_PUBLIC);

        // Authentication.
        $this->router->add('POST', '/api/v1/auth/login', $authController->login(...), Router::ACCESS_PUBLIC);
        $this->router->add('POST', '/api/v1/auth/logout', $authController->logout(...), Router::ACCESS_USER);
        $this->router->add('GET', '/api/v1/auth/me', $authController->me(...), Router::ACCESS_USER);

        // Invitation acceptance (public, rate limited, token in the JSON body — never in a URL).
        $this->router->add('POST', '/api/v1/invitations/inspect', $invitationController->inspect(...), Router::ACCESS_PUBLIC);
        $this->router->add('POST', '/api/v1/invitations/accept', $invitationController->accept(...), Router::ACCESS_PUBLIC);

        // Invitation administration (system admins only).
        $this->router->add('GET', '/api/v1/admin/invitations', $invitationController->list(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('POST', '/api/v1/admin/invitations', $invitationController->create(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('DELETE', '/api/v1/admin/invitations/{id}', $invitationController->revoke(...), Router::ACCESS_SYSTEM_ADMIN);
    }
}
