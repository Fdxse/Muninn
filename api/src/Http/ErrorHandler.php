<?php

declare(strict_types=1);

namespace Muninn\Api\Http;

use Muninn\Api\Logging\AppLogger;
use Throwable;

/**
 * Turns any exception into a JSON error response.
 *
 * HttpExceptions are expected errors and are returned as-is. Every other Throwable is an
 * internal error: it is logged with the request ID and the client only receives a generic
 * message plus that request ID, never a stack trace, SQL, or file path.
 */
final class ErrorHandler
{
    public function __construct(private readonly AppLogger $logger)
    {
    }

    public function toResponse(Throwable $throwable, string $requestId): Response
    {
        if ($throwable instanceof HttpException) {
            $errorResponse = Response::error(
                $throwable->statusCode,
                $throwable->errorCode,
                $throwable->getMessage(),
                $throwable->fieldErrors,
            );
            foreach ($throwable->extraHeaders as $headerName => $headerValue) {
                $errorResponse = $errorResponse->withHeader($headerName, $headerValue);
            }

            return $errorResponse;
        }

        // Full details go to the server log only.
        $this->logger->error('Unhandled exception', [
            'request_id' => $requestId,
            'exception_class' => $throwable::class,
            'exception_message' => $throwable->getMessage(),
            'location' => $throwable->getFile() . ':' . $throwable->getLine(),
        ]);

        return Response::error(500, 'internal_error', 'An unexpected error occurred. Reference: ' . $requestId);
    }
}
