<?php

declare(strict_types=1);

namespace Muninn\Api\Auth;

/**
 * A freshly created session. $rawToken goes into the cookie and is never stored or logged.
 */
final class NewSession
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $rawToken,
        public readonly string $csrfToken,
    ) {
    }
}
