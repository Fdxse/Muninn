<?php

declare(strict_types=1);

namespace Muninn\Api\Http;

/**
 * CORS handling with an exact-match origin allowlist.
 *
 * Only allowlisted origins ever receive Access-Control-Allow-Origin, and the value is the
 * caller's exact origin (never "*"), because the API uses credentialed requests (cookies).
 * CORS is NOT our CSRF defence; see CsrfGuard and docs/api-conventions.md.
 */
final class Cors
{
    /** Methods the frontend may use. */
    private const ALLOWED_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';

    /** Request headers the frontend may send. */
    private const ALLOWED_HEADERS = 'Content-Type, X-CSRF-Token, X-Filename';

    /** How long browsers may cache a preflight answer, in seconds. */
    private const PREFLIGHT_MAX_AGE_SECONDS = '600';

    /** @param list<string> $allowedOrigins */
    public function __construct(private readonly array $allowedOrigins)
    {
    }

    /** True when the Origin header is present and on the allowlist. */
    public function isAllowedOrigin(?string $origin): bool
    {
        return $origin !== null && in_array($origin, $this->allowedOrigins, true);
    }

    /** True for a browser CORS preflight request. */
    public function isPreflight(Request $request): bool
    {
        return $request->method() === 'OPTIONS' && $request->header('Access-Control-Request-Method') !== null;
    }

    /** Answers a preflight. Disallowed origins get a bare 204 without any CORS headers. */
    public function preflightResponse(Request $request): Response
    {
        $response = Response::noContent();
        if (!$this->isAllowedOrigin($request->header('Origin'))) {
            return $response;
        }

        return $this->withCorsHeaders($request, $response)
            ->withHeader('Access-Control-Allow-Methods', self::ALLOWED_METHODS)
            ->withHeader('Access-Control-Allow-Headers', self::ALLOWED_HEADERS)
            ->withHeader('Access-Control-Max-Age', self::PREFLIGHT_MAX_AGE_SECONDS);
    }

    /** Adds CORS headers to a normal response when the origin is allowlisted. */
    public function withCorsHeaders(Request $request, Response $response): Response
    {
        // Caches must keep separate copies per Origin because the header value varies.
        $response = $response->withHeader('Vary', 'Origin');

        $requestOrigin = $request->header('Origin');
        if (!$this->isAllowedOrigin($requestOrigin)) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', (string) $requestOrigin)
            ->withHeader('Access-Control-Allow-Credentials', 'true');
    }
}
