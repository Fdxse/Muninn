<?php

declare(strict_types=1);

namespace Muninn\Api\Http;

use RuntimeException;

/**
 * An expected, client-facing error. The message and code are safe to show to users.
 *
 * Anything that is NOT an HttpException is treated as an internal error and its details
 * never leave the server (see ErrorHandler).
 */
final class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $fieldErrors Per-field validation messages.
     * @param array<string, string> $extraHeaders Headers such as Retry-After or Allow.
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message,
        public readonly array $fieldErrors = [],
        public readonly array $extraHeaders = [],
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $errorCode, string $message): self
    {
        return new self(400, $errorCode, $message);
    }

    public static function unauthenticated(): self
    {
        return new self(401, 'unauthenticated', 'Authentication is required.');
    }

    public static function forbidden(string $errorCode, string $message): self
    {
        return new self(403, $errorCode, $message);
    }

    /** Used both for missing resources and for resources the caller may not know exist. */
    public static function notFound(string $errorCode = 'not_found', string $message = 'The requested resource was not found.'): self
    {
        return new self(404, $errorCode, $message);
    }

    /** @param list<string> $allowedMethods */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(405, 'method_not_allowed', 'This method is not allowed for this resource.', [], ['Allow' => implode(', ', $allowedMethods)]);
    }

    public static function conflict(string $errorCode, string $message): self
    {
        return new self(409, $errorCode, $message);
    }

    public static function unsupportedMediaType(): self
    {
        return new self(415, 'unsupported_media_type', 'Request bodies must be sent as application/json.');
    }

    /** @param array<string, string> $fieldErrors */
    public static function validation(array $fieldErrors): self
    {
        return new self(422, 'validation_failed', 'Some fields are invalid.', $fieldErrors);
    }

    public static function tooManyRequests(int $retryAfterSeconds): self
    {
        return new self(429, 'rate_limited', 'Too many attempts. Please wait and try again.', [], ['Retry-After' => (string) $retryAfterSeconds]);
    }
}
