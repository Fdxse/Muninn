<?php

declare(strict_types=1);

namespace Muninn\Api\Http;

use Muninn\Api\Auth\AuthenticatedSession;
use Muninn\Api\MagicLinks\MagicLinkAccess;

/**
 * Per-request facts resolved by the Application before a handler runs.
 */
final class RequestContext
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $clientIp,
        public readonly ?AuthenticatedSession $session,
        /** Set only on Magic Link visitor routes (D059); never together with $session. */
        public readonly ?MagicLinkAccess $magicLinkAccess = null,
    ) {
    }

    /**
     * Returns the session for handlers on routes that require sign-in.
     * The Application guarantees it is set for those routes; this guards against wiring mistakes.
     */
    public function requireSession(): AuthenticatedSession
    {
        if ($this->session === null) {
            throw HttpException::unauthenticated();
        }

        return $this->session;
    }

    /**
     * Returns the Magic Link visit for handlers on visitor routes. The Application guarantees it
     * is set for those routes; this guards against wiring mistakes.
     */
    public function requireMagicLinkAccess(): MagicLinkAccess
    {
        if ($this->magicLinkAccess === null) {
            throw HttpException::unauthenticated();
        }

        return $this->magicLinkAccess;
    }
}
