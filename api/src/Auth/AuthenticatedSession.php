<?php

declare(strict_types=1);

namespace Muninn\Api\Auth;

use Muninn\Api\Users\User;

/**
 * The signed-in user and their session, resolved from the session cookie for one request.
 */
final class AuthenticatedSession
{
    public function __construct(
        public readonly string $sessionId,
        public readonly User $user,
        public readonly string $csrfToken,
    ) {
    }
}
