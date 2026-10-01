<?php

declare(strict_types=1);

namespace Muninn\Api\Http;

/**
 * Hardening headers added to every API response.
 *
 * The API only ever returns JSON, so the content security policy forbids everything,
 * and responses must never be cached because they can contain private data.
 */
final class SecurityHeaders
{
    public static function apply(Response $response): Response
    {
        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
