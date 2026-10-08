<?php

declare(strict_types=1);

namespace Muninn\Api\Http;

/**
 * Hardening headers added to every API response.
 *
 * The API returns JSON and authorized images, never documents, so the content security policy
 * forbids everything. Responses must not be cached because they can contain private data; the
 * only exception is an attachment, which sets its own short "private" Cache-Control.
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
            ->withHeader('Cache-Control', $response->header('Cache-Control') ?? 'no-store')
            ->withHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
