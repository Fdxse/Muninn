<?php

declare(strict_types=1);

namespace Muninn\Api\Auth;

/**
 * Builds Set-Cookie header values for the session cookie.
 *
 * Secure + HttpOnly + SameSite=Lax + Path=/ with no Domain attribute: the cookie stays on the
 * API host (api.dx.se), JavaScript cannot read it, and it is first-party for www.dx.se because
 * both share the dx.se site (decision D022).
 */
final class SessionCookie
{
    public function __construct(
        private readonly string $cookieName,
        private readonly bool $secure,
        private readonly int $absoluteTimeoutHours,
    ) {
    }

    public function name(): string
    {
        return $this->cookieName;
    }

    /** Cookie that stores a new session token. */
    public function issue(string $rawToken): string
    {
        return $this->build($rawToken, $this->absoluteTimeoutHours * 3600);
    }

    /** Cookie that tells the browser to delete the session token. */
    public function clear(): string
    {
        return $this->build('', 0);
    }

    private function build(string $cookieValue, int $maxAgeSeconds): string
    {
        $cookieAttributes = [
            $this->cookieName . '=' . $cookieValue,
            'Max-Age=' . $maxAgeSeconds,
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
        ];
        if ($this->secure) {
            $cookieAttributes[] = 'Secure';
        }

        return implode('; ', $cookieAttributes);
    }
}
