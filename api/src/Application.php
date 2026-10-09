<?php

declare(strict_types=1);

namespace Muninn\Api;

use Muninn\Api\Admin\AuditLogArchiver;
use Muninn\Api\Admin\SignInAttemptReport;
use Muninn\Api\Admin\SystemOverviewController;
use Muninn\Api\Admin\SystemOverviewService;
use Muninn\Api\Admin\UserAdminController;
use Muninn\Api\Admin\WorkspaceAdminController;
use Muninn\Api\Attachments\AttachmentController;
use Muninn\Api\Attachments\AttachmentService;
use Muninn\Api\Attachments\AttachmentStorage;
use Muninn\Api\Auth\AuthController;
use Muninn\Api\Broadcasts\BroadcastController;
use Muninn\Api\Broadcasts\BroadcastService;
use Muninn\Api\Chat\ChatController;
use Muninn\Api\Chat\ChatPolicy;
use Muninn\Api\Chat\ChatService;
use Muninn\Api\Chat\ExpiredChatCleanup;
use Muninn\Api\Auth\PasswordResetController;
use Muninn\Api\Auth\PasswordResetService;
use Muninn\Api\Auth\PasswordService;
use Muninn\Api\Auth\RateLimiter;
use Muninn\Api\Auth\SessionCookie;
use Muninn\Api\Auth\SessionService;
use Muninn\Api\Config\Config;
use Muninn\Api\Folders\FolderController;
use Muninn\Api\Folders\FolderService;
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
use Muninn\Api\Invitations\InvitationRequestController;
use Muninn\Api\Invitations\InvitationRequestService;
use Muninn\Api\Invitations\InvitationService;
use Muninn\Api\Logging\AppLogger;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\MagicLinks\LinkVisitorController;
use Muninn\Api\MagicLinks\MagicLinkAccess;
use Muninn\Api\MagicLinks\MagicLinkController;
use Muninn\Api\MagicLinks\MagicLinkScope;
use Muninn\Api\MagicLinks\MagicLinkService;
use Muninn\Api\Notes\ExpiredTrashCleanup;
use Muninn\Api\Notes\NoteController;
use Muninn\Api\Notes\NoteHistory;
use Muninn\Api\Notes\NotePurger;
use Muninn\Api\Notes\NoteService;
use Muninn\Api\Notes\TrashController;
use Muninn\Api\Notifications\AdminMessageController;
use Muninn\Api\Notifications\AdminNotifier;
use Muninn\Api\Notifications\HttpNtfyTransport;
use Muninn\Api\Notifications\NtfyTransport;
use Muninn\Api\Search\SearchController;
use Muninn\Api\Search\SearchService;
use Muninn\Api\Tags\TagController;
use Muninn\Api\Tags\TagService;
use Muninn\Api\Users\UserRepository;
use Muninn\Api\Workspaces\WorkspaceAuthorizer;
use Muninn\Api\Workspaces\WorkspaceController;
use Muninn\Api\Workspaces\WorkspaceService;
use DateTimeZone;
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
    private ExpiredTrashCleanup $expiredTrashCleanup;
    private ExpiredChatCleanup $expiredChatCleanup;
    private AdminNotifier $adminNotifier;
    private NtfyTransport $ntfyTransport;
    private MagicLinkService $magicLinkService;
    /** The cookie a browser gets after opening a Magic Link (D059); separate from the sign-in cookie. */
    private SessionCookie $magicLinkVisitCookie;

    /** Set once the current request is signed in; housekeeping only follows such requests. */
    private bool $housekeepingDue = false;

    public function __construct(
        private readonly Config $config,
        private readonly PDO $database,
        private readonly AppLogger $logger,
        ?NtfyTransport $ntfyTransport = null,
    ) {
        // Tests pass a transport that records messages instead of sending them.
        $this->ntfyTransport = $ntfyTransport ?? new HttpNtfyTransport();
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
        $this->magicLinkService = new MagicLinkService(
            $database,
            new DateTimeZone($config->getString('magic_links.timezone')),
            $config->getInt('magic_links.visit_hours'),
        );
        $this->magicLinkVisitCookie = new SessionCookie(
            $config->getString('magic_links.cookie_name'),
            $config->getBool('session.cookie_secure'),
            $config->getInt('magic_links.visit_hours'),
        );
        $this->router = new Router();
        $this->registerRoutes();
    }

    /**
     * The attachment storage folder: attachments.storage_path, or storage/attachments inside
     * the application folder (outside the web root on the NAS) when that is left empty.
     * Shared with bin/reset-data.php so both always use the same folder.
     */
    public static function attachmentStorageFolder(Config $config): string
    {
        $configuredPath = $config->getString('attachments.storage_path');

        return $configuredPath !== '' ? rtrim($configuredPath, '/') : dirname(__DIR__) . '/storage/attachments';
    }

    /**
     * The audit log archive folder (D060): audit_log.archive_path, or storage/audit-archives inside
     * the application folder (outside the web root on the NAS) when that is left empty.
     */
    public static function auditArchiveFolder(Config $config): string
    {
        $configuredPath = $config->getString('audit_log.archive_path');

        return $configuredPath !== '' ? rtrim($configuredPath, '/') : dirname(__DIR__) . '/storage/audit-archives';
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
        $this->housekeepingDue = false;

        try {
            $response = $this->dispatch($request, $requestId);
        } catch (Throwable $throwable) {
            $response = $this->errorHandler->toResponse($throwable, $requestId);
        }

        $response = $this->cors->withCorsHeaders($request, $response);

        return SecurityHeaders::apply($response)->withHeader('X-Request-Id', $requestId);
    }

    /**
     * Sends the administrator notifications queued while answering the request (D057). Runs
     * after the response has been sent, like housekeeping, so ntfy never delays a request.
     * Never throws.
     *
     * @return int Notifications ntfy accepted.
     */
    public function sendQueuedNotifications(): int
    {
        return $this->adminNotifier->sendQueued();
    }

    /**
     * Housekeeping that runs after the response to a signed-in request has been sent: deletes
     * notes whose time in Trash has run out (D039) and chat messages older than the chat
     * retention (D062), each at most once per hour. Anonymous requests never trigger it, so
     * nobody can make the server do this work without an account. Never throws.
     *
     * @return int|null Notes deleted, or null when nothing ran.
     */
    public function runHousekeeping(): ?int
    {
        if (!$this->housekeepingDue) {
            return null;
        }
        $this->housekeepingDue = false;

        // Runs on its own hourly schedule; its count is audited, not returned.
        $this->expiredChatCleanup->runIfDue();

        return $this->expiredTrashCleanup->runIfDue();
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

        // Magic Link visitor routes (D059) accept only the visit cookie, never a sign-in, and the
        // link behind it is checked again (revoked, dates, daily window, creator, target) right here.
        $magicLinkAccess = null;
        if ($matchedRoute['access'] === Router::ACCESS_MAGIC_LINK) {
            $magicLinkAccess = $this->requireMagicLinkVisit($request);
        }

        $currentSession = null;
        if ($matchedRoute['access'] === Router::ACCESS_USER || $matchedRoute['access'] === Router::ACCESS_SYSTEM_ADMIN) {
            $currentSession = $this->sessionService->findActive($request->cookie($this->sessionCookie->name()));
            if ($currentSession === null) {
                throw HttpException::unauthenticated();
            }
            $this->housekeepingDue = true;

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
            magicLinkAccess: $magicLinkAccess,
        );

        return ($matchedRoute['handler'])($routedRequest, $context);
    }

    /**
     * Resolves the Magic Link visit cookie and applies the CSRF check to state changes, exactly
     * like a signed-in session (D023), but with the visit's own token.
     *
     * @throws HttpException 401 when there is no usable visit (the link may have been revoked,
     *                       expired, left its daily window, or lost its creator or target).
     */
    private function requireMagicLinkVisit(Request $request): MagicLinkAccess
    {
        $magicLinkAccess = $this->magicLinkService->findActiveVisit($request->cookie($this->magicLinkVisitCookie->name()));
        if ($magicLinkAccess === null) {
            throw new HttpException(401, 'link_unavailable', 'This link no longer works here. Open it again, or ask for a new one.');
        }
        if ($request->isStateChanging()) {
            $submittedCsrfToken = (string) $request->header('X-CSRF-Token');
            if (!hash_equals($magicLinkAccess->csrfToken, $submittedCsrfToken)) {
                throw HttpException::forbidden('csrf_failed', 'The security token is missing or invalid. Reload and try again.');
            }
        }

        return $magicLinkAccess;
    }

    private function registerRoutes(): void
    {
        $passwordService = new PasswordService();
        $userRepository = new UserRepository($this->database);
        $auditLog = new AuditLog($this->database);
        $this->adminNotifier = new AdminNotifier(
            $this->database,
            $auditLog,
            $this->logger,
            $this->ntfyTransport,
            $this->config->getBool('ntfy.enabled'),
            rtrim($this->config->getString('ntfy.server_url'), '/'),
            $this->config->getString('ntfy.topic'),
            $this->config->getString('ntfy.access_token'),
            $this->config->getInt('ntfy.timeout_seconds'),
            rtrim($this->config->getString('frontend.base_url'), '/'),
        );
        $rateLimiter = new RateLimiter(
            $this->database,
            $this->config->getInt('security.rate_limit_window_minutes'),
            $this->config->getInt('security.login_max_failures_per_username'),
            $this->config->getInt('security.login_max_failures_per_ip'),
            $this->config->getInt('security.invitation_max_failures_per_ip'),
        );

        // Every workspace and note endpoint goes through this one authorizer (SECURITY.md).
        $workspaceAuthorizer = new WorkspaceAuthorizer($this->database);
        $workspaceService = new WorkspaceService($this->database, $userRepository);

        $authController = new AuthController(
            $userRepository,
            $passwordService,
            $this->sessionService,
            $this->sessionCookie,
            $rateLimiter,
            $auditLog,
            $this->adminNotifier,
        );
        $invitationService = new InvitationService($this->database, $userRepository);
        $invitationController = new InvitationController(
            $invitationService,
            $passwordService,
            $this->sessionService,
            $this->sessionCookie,
            $rateLimiter,
            $auditLog,
            $this->config->getString('frontend.base_url'),
            $this->config->getInt('invitations.default_expiry_hours'),
            $this->config->getInt('invitations.max_expiry_hours'),
            $workspaceService,
            $userRepository,
        );
        $invitationRequestController = new InvitationRequestController(
            new InvitationRequestService($this->database, $invitationService),
            $auditLog,
            $this->config->getString('frontend.base_url'),
            $this->config->getInt('invitations.default_expiry_hours'),
            $this->adminNotifier,
        );
        $adminMessageController = new AdminMessageController($this->database, $auditLog, $this->adminNotifier);
        $passwordResetService = new PasswordResetService($this->database);
        $passwordResetController = new PasswordResetController(
            $passwordResetService,
            $passwordService,
            $this->sessionService,
            $userRepository,
            $rateLimiter,
            $auditLog,
            $this->config->getString('frontend.base_url'),
        );
        $userAdminController = new UserAdminController($userRepository, $this->sessionService, $passwordResetService, $auditLog);
        $workspaceController = new WorkspaceController($workspaceService, $workspaceAuthorizer, $auditLog);
        $workspaceAdminController = new WorkspaceAdminController($workspaceService, $workspaceAuthorizer, $auditLog);
        $tagService = new TagService($this->database);
        $noteHistory = new NoteHistory($this->database);
        $noteService = new NoteService($this->database, $tagService, $noteHistory);
        $noteController = new NoteController($noteService, $noteHistory, $workspaceAuthorizer, $auditLog);
        $attachmentStorage = new AttachmentStorage(self::attachmentStorageFolder($this->config));
        $notePurger = new NotePurger($this->database, $tagService, $attachmentStorage);
        $this->expiredTrashCleanup = new ExpiredTrashCleanup(
            $this->database,
            $notePurger,
            $auditLog,
            $this->logger,
            $this->config->getInt('trash.retention_days'),
        );
        $trashController = new TrashController(
            $noteService,
            $notePurger,
            $workspaceAuthorizer,
            $auditLog,
            $this->config->getInt('trash.retention_days'),
        );
        $searchController = new SearchController(new SearchService($this->database, $tagService), $workspaceAuthorizer);
        $folderController = new FolderController(new FolderService($this->database), $workspaceAuthorizer, $auditLog);
        $tagController = new TagController($tagService, $workspaceAuthorizer);
        $attachmentController = new AttachmentController(
            new AttachmentService($this->database, $attachmentStorage),
            $noteService,
            $workspaceAuthorizer,
            $auditLog,
            $this->config->getInt('attachments.max_upload_bytes'),
        );

        // Magic Links (D059): managed by workspace Admins and Owners, overseen by system administrators,
        // and used by visitors through their own /link/ endpoints.
        $magicLinkController = new MagicLinkController(
            $this->magicLinkService,
            $workspaceAuthorizer,
            $auditLog,
            $this->config->getString('frontend.base_url'),
            $this->config->getInt('magic_links.default_valid_days'),
            $this->config->getInt('magic_links.max_valid_days'),
        );
        $linkVisitorController = new LinkVisitorController(
            $this->magicLinkService,
            new MagicLinkScope($this->database),
            $noteService,
            new FolderService($this->database),
            new AttachmentService($this->database, $attachmentStorage),
            $rateLimiter,
            $this->magicLinkVisitCookie,
            $auditLog,
            $this->config->getInt('attachments.max_upload_bytes'),
        );

        // The administrator's overview (D060): counts and sizes only, never note data.
        $displayTimeZone = new DateTimeZone($this->config->getString('magic_links.timezone'));
        $auditLogArchiver = new AuditLogArchiver(
            $this->database,
            self::auditArchiveFolder($this->config),
            $this->config->getInt('audit_log.archive_after_months'),
        );
        $systemOverviewController = new SystemOverviewController(
            new SystemOverviewService(
                $this->database,
                $displayTimeZone,
                $auditLogArchiver,
                self::attachmentStorageFolder($this->config),
                $this->config->getString('logging.file_path'),
                dirname(__DIR__) . '/migrations',
                $this->config->getInt('trash.retention_days'),
                $this->config->getInt('session.idle_timeout_hours'),
                $this->config->isProduction(),
            ),
            new SignInAttemptReport($this->database),
            $auditLogArchiver,
            $auditLog,
        );

        // Broadcast messages from the administrator (D061).
        $broadcastController = new BroadcastController(new BroadcastService($this->database), $auditLog, $this->adminNotifier);

        // Chat (D062): one channel per shared workspace plus a global one; ChatPolicy decides access.
        $chatService = new ChatService($this->database);
        $chatController = new ChatController(
            $chatService,
            new ChatPolicy($workspaceAuthorizer),
            $workspaceAuthorizer,
            $auditLog,
            $this->config->getInt('chat.retention_days'),
        );
        $this->expiredChatCleanup = new ExpiredChatCleanup(
            $this->database,
            $chatService,
            $auditLog,
            $this->logger,
            $this->config->getInt('chat.retention_days'),
        );

        // Health check: reveals nothing about versions or the database.
        $this->router->add('GET', '/api/v1/health', fn (): Response => Response::data(['status' => 'ok']), Router::ACCESS_PUBLIC);

        // Authentication.
        $this->router->add('POST', '/api/v1/auth/login', $authController->login(...), Router::ACCESS_PUBLIC);
        $this->router->add('POST', '/api/v1/auth/logout', $authController->logout(...), Router::ACCESS_USER);
        $this->router->add('GET', '/api/v1/auth/me', $authController->me(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/auth/password', $passwordResetController->changeOwn(...), Router::ACCESS_USER);

        // Password reset links (D040): public, rate limited, token in the JSON body.
        $this->router->add('POST', '/api/v1/password-resets/inspect', $passwordResetController->inspect(...), Router::ACCESS_PUBLIC);
        $this->router->add('POST', '/api/v1/password-resets/complete', $passwordResetController->complete(...), Router::ACCESS_PUBLIC);

        // Invitation acceptance (public, rate limited, token in the JSON body — never in a URL).
        $this->router->add('POST', '/api/v1/invitations/inspect', $invitationController->inspect(...), Router::ACCESS_PUBLIC);
        $this->router->add('POST', '/api/v1/invitations/accept', $invitationController->accept(...), Router::ACCESS_PUBLIC);

        // Invitation administration (system admins only).
        $this->router->add('GET', '/api/v1/admin/invitations', $invitationController->list(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('POST', '/api/v1/admin/invitations', $invitationController->create(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('DELETE', '/api/v1/admin/invitations/{id}', $invitationController->revoke(...), Router::ACCESS_SYSTEM_ADMIN);

        // Invitation requests (D049): everyday users ask, administrators decide, the user sends the link.
        $this->router->add('GET', '/api/v1/invitation-requests', $invitationRequestController->listOwn(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/invitation-requests', $invitationRequestController->create(...), Router::ACCESS_USER);
        $this->router->add('DELETE', '/api/v1/invitation-requests/{id}', $invitationRequestController->cancel(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/invitation-requests/{id}/link', $invitationRequestController->createLink(...), Router::ACCESS_USER);
        $this->router->add('GET', '/api/v1/admin/invitation-requests', $invitationRequestController->listAll(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('GET', '/api/v1/admin/invitation-requests/pending-count', $invitationRequestController->pendingCount(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('POST', '/api/v1/admin/invitation-requests/{id}/approve', $invitationRequestController->approve(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('POST', '/api/v1/admin/invitation-requests/{id}/decline', $invitationRequestController->decline(...), Router::ACCESS_SYSTEM_ADMIN);

        // "Contact admin" (D058): everyday users write to the administrator through ntfy.
        $this->router->add('GET', '/api/v1/admin-messages', $adminMessageController->status(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/admin-messages', $adminMessageController->send(...), Router::ACCESS_USER);

        // Broadcast messages (D061): the administrator schedules them, everyday users see and answer them.
        $this->router->add('GET', '/api/v1/broadcasts', $broadcastController->listShowing(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/broadcasts/{id}/seen', $broadcastController->markSeen(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/broadcasts/{id}/dismiss', $broadcastController->dismiss(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/broadcasts/{id}/vote', $broadcastController->vote(...), Router::ACCESS_USER);
        $this->router->add('GET', '/api/v1/admin/broadcasts', $broadcastController->listAll(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('POST', '/api/v1/admin/broadcasts', $broadcastController->create(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('PATCH', '/api/v1/admin/broadcasts/{id}', $broadcastController->update(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('DELETE', '/api/v1/admin/broadcasts/{id}', $broadcastController->delete(...), Router::ACCESS_SYSTEM_ADMIN);

        // Account administration (system admins only; accounts are disabled, never deleted).
        $this->router->add('GET', '/api/v1/admin/users', $userAdminController->list(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('POST', '/api/v1/admin/users/{id}/disable', $userAdminController->disable(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('POST', '/api/v1/admin/users/{id}/enable', $userAdminController->enable(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('POST', '/api/v1/admin/users/{id}/password-reset', $passwordResetController->create(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('PATCH', '/api/v1/admin/users/{id}/chat-access', $userAdminController->changeChatAccess(...), Router::ACCESS_SYSTEM_ADMIN);

        // The administrator's overview page (D060).
        $this->router->add('GET', '/api/v1/admin/overview', $systemOverviewController->overview(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('GET', '/api/v1/admin/sign-in-attempts', $systemOverviewController->signInAttempts(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('POST', '/api/v1/admin/audit-log/archive', $systemOverviewController->archive(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('GET', '/api/v1/admin/audit-log/archives/{archiveId}', $systemOverviewController->downloadArchive(...), Router::ACCESS_SYSTEM_ADMIN);

        // Shared workspace membership administration (D050): members only, never notes.
        $this->router->add('GET', '/api/v1/admin/workspaces', $workspaceAdminController->list(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('GET', '/api/v1/admin/workspaces/{id}/members', $workspaceAdminController->listMembers(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('POST', '/api/v1/admin/workspaces/{id}/members', $workspaceAdminController->addMember(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('PATCH', '/api/v1/admin/workspaces/{id}/members/{userId}', $workspaceAdminController->changeMemberRole(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('DELETE', '/api/v1/admin/workspaces/{id}/members/{userId}', $workspaceAdminController->removeMember(...), Router::ACCESS_SYSTEM_ADMIN);

        // Workspaces and members. Signed-in users only; WorkspaceAuthorizer checks membership and role.
        $this->router->add('GET', '/api/v1/workspaces', $workspaceController->list(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/workspaces', $workspaceController->create(...), Router::ACCESS_USER);
        $this->router->add('GET', '/api/v1/workspaces/{id}', $workspaceController->show(...), Router::ACCESS_USER);
        $this->router->add('PATCH', '/api/v1/workspaces/{id}', $workspaceController->rename(...), Router::ACCESS_USER);
        $this->router->add('DELETE', '/api/v1/workspaces/{id}', $workspaceController->delete(...), Router::ACCESS_USER);
        $this->router->add('GET', '/api/v1/workspaces/{id}/members', $workspaceController->listMembers(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/workspaces/{id}/members', $workspaceController->addMember(...), Router::ACCESS_USER);
        $this->router->add('PATCH', '/api/v1/workspaces/{id}/members/{userId}', $workspaceController->changeMemberRole(...), Router::ACCESS_USER);
        $this->router->add('DELETE', '/api/v1/workspaces/{id}/members/{userId}', $workspaceController->removeMember(...), Router::ACCESS_USER);

        // Notes. Access always comes from a membership of the note's workspace.
        $this->router->add('GET', '/api/v1/workspaces/{id}/notes', $noteController->list(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/workspaces/{id}/notes', $noteController->create(...), Router::ACCESS_USER);
        $this->router->add('GET', '/api/v1/notes/{id}', $noteController->show(...), Router::ACCESS_USER);
        $this->router->add('PATCH', '/api/v1/notes/{id}', $noteController->update(...), Router::ACCESS_USER);
        $this->router->add('DELETE', '/api/v1/notes/{id}', $noteController->trash(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/notes/{id}/archive', $noteController->archive(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/notes/{id}/unarchive', $noteController->unarchive(...), Router::ACCESS_USER);

        // Version history (D009, D037): earlier states of an active note.
        $this->router->add('GET', '/api/v1/notes/{id}/versions', $noteController->listVersions(...), Router::ACCESS_USER);
        $this->router->add('GET', '/api/v1/notes/{id}/versions/{versionId}', $noteController->showVersion(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/notes/{id}/versions/{versionId}/restore', $noteController->restoreVersion(...), Router::ACCESS_USER);

        // Trash (D012, D039). /trash/{noteId} only ever addresses notes that are in Trash.
        $this->router->add('GET', '/api/v1/workspaces/{id}/trash', $trashController->list(...), Router::ACCESS_USER);
        $this->router->add('DELETE', '/api/v1/workspaces/{id}/trash', $trashController->empty(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/trash/{noteId}/restore', $trashController->restore(...), Router::ACCESS_USER);
        $this->router->add('DELETE', '/api/v1/trash/{noteId}', $trashController->purge(...), Router::ACCESS_USER);

        // Chat (D062). Signed-in users only; ChatPolicy checks the chat level, membership and role.
        $this->router->add('GET', '/api/v1/chat', $chatController->overview(...), Router::ACCESS_USER);
        $this->router->add('GET', '/api/v1/chat/global/messages', $chatController->listGlobal(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/chat/global/messages', $chatController->postGlobal(...), Router::ACCESS_USER);
        $this->router->add('GET', '/api/v1/workspaces/{id}/chat/messages', $chatController->listWorkspace(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/workspaces/{id}/chat/messages', $chatController->postWorkspace(...), Router::ACCESS_USER);
        $this->router->add('DELETE', '/api/v1/chat/messages/{id}', $chatController->delete(...), Router::ACCESS_USER);

        // Search across every workspace the caller may read (D011, D038).
        $this->router->add('GET', '/api/v1/search', $searchController->search(...), Router::ACCESS_USER);

        // Folders and tags belong to one workspace each (D033, D034).
        $this->router->add('GET', '/api/v1/workspaces/{id}/folders', $folderController->list(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/workspaces/{id}/folders', $folderController->create(...), Router::ACCESS_USER);
        $this->router->add('PATCH', '/api/v1/folders/{id}', $folderController->update(...), Router::ACCESS_USER);
        $this->router->add('DELETE', '/api/v1/folders/{id}', $folderController->delete(...), Router::ACCESS_USER);
        $this->router->add('GET', '/api/v1/workspaces/{id}/tags', $tagController->list(...), Router::ACCESS_USER);

        // Image attachments. Access always comes from access to the attachment's active note.
        $this->router->add('GET', '/api/v1/notes/{id}/attachments', $attachmentController->list(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/notes/{id}/attachments', $attachmentController->upload(...), Router::ACCESS_USER);
        $this->router->add('GET', '/api/v1/attachments/{id}/content', $attachmentController->content(...), Router::ACCESS_USER);
        $this->router->add('DELETE', '/api/v1/attachments/{id}', $attachmentController->delete(...), Router::ACCESS_USER);

        // Magic Link management (D059): Admin+ of the workspace; WorkspaceAuthorizer checks the role.
        $this->router->add('GET', '/api/v1/workspaces/{id}/magic-links', $magicLinkController->list(...), Router::ACCESS_USER);
        $this->router->add('POST', '/api/v1/workspaces/{id}/magic-links', $magicLinkController->create(...), Router::ACCESS_USER);
        $this->router->add('DELETE', '/api/v1/magic-links/{id}', $magicLinkController->revoke(...), Router::ACCESS_USER);
        $this->router->add('GET', '/api/v1/admin/magic-links', $magicLinkController->listForAdministrator(...), Router::ACCESS_SYSTEM_ADMIN);
        $this->router->add('DELETE', '/api/v1/admin/magic-links/{id}', $magicLinkController->revokeAsAdministrator(...), Router::ACCESS_SYSTEM_ADMIN);

        // Magic Link visitors (D059): opening is public and rate limited (token in the JSON body,
        // never in a URL the server sees); everything else needs the visit cookie.
        $this->router->add('POST', '/api/v1/link/open', $linkVisitorController->open(...), Router::ACCESS_PUBLIC);
        $this->router->add('GET', '/api/v1/link/me', $linkVisitorController->me(...), Router::ACCESS_MAGIC_LINK);
        $this->router->add('POST', '/api/v1/link/close', $linkVisitorController->close(...), Router::ACCESS_MAGIC_LINK);
        $this->router->add('GET', '/api/v1/link/folders', $linkVisitorController->folders(...), Router::ACCESS_MAGIC_LINK);
        $this->router->add('GET', '/api/v1/link/notes', $linkVisitorController->notes(...), Router::ACCESS_MAGIC_LINK);
        $this->router->add('POST', '/api/v1/link/notes', $linkVisitorController->createNote(...), Router::ACCESS_MAGIC_LINK);
        $this->router->add('GET', '/api/v1/link/notes/{id}', $linkVisitorController->showNote(...), Router::ACCESS_MAGIC_LINK);
        $this->router->add('PATCH', '/api/v1/link/notes/{id}', $linkVisitorController->updateNote(...), Router::ACCESS_MAGIC_LINK);
        $this->router->add('POST', '/api/v1/link/notes/{id}/attachments', $linkVisitorController->uploadAttachment(...), Router::ACCESS_MAGIC_LINK);
        $this->router->add('GET', '/api/v1/link/attachments/{id}/content', $linkVisitorController->attachmentContent(...), Router::ACCESS_MAGIC_LINK);
    }
}
