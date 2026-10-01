<?php

declare(strict_types=1);

namespace Muninn\Api\Http;

/**
 * An outgoing HTTP response. All API bodies are JSON:
 *   success: {"data": ...}
 *   error:   {"error": {"code": "...", "message": "...", "fields": {...}?}}
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var list<string> Full Set-Cookie header values. */
    private array $setCookieHeaders = [];

    private function __construct(
        private readonly int $statusCode,
        private readonly string $body,
    ) {
    }

    /** A successful response wrapping $data in the standard envelope. */
    public static function data(mixed $data, int $statusCode = 200): self
    {
        $response = new self($statusCode, self::encode(['data' => $data]));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * An error response in the standard envelope.
     *
     * @param array<string, string> $fieldErrors
     */
    public static function error(int $statusCode, string $errorCode, string $message, array $fieldErrors = []): self
    {
        $errorBody = ['code' => $errorCode, 'message' => $message];
        if ($fieldErrors !== []) {
            $errorBody['fields'] = $fieldErrors;
        }
        $response = new self($statusCode, self::encode(['error' => $errorBody]));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /** 204 No Content. */
    public static function noContent(): self
    {
        return new self(204, '');
    }

    public function withHeader(string $headerName, string $headerValue): self
    {
        $responseCopy = clone $this;
        $responseCopy->headers[$headerName] = $headerValue;

        return $responseCopy;
    }

    public function withSetCookie(string $setCookieValue): self
    {
        $responseCopy = clone $this;
        $responseCopy->setCookieHeaders[] = $setCookieValue;

        return $responseCopy;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $headerName): ?string
    {
        foreach ($this->headers as $existingName => $existingValue) {
            if (strcasecmp($existingName, $headerName) === 0) {
                return $existingValue;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return list<string> */
    public function setCookieHeaders(): array
    {
        return $this->setCookieHeaders;
    }

    /**
     * Decodes the body back into an array (used by tests and never by production code).
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        return (array) json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Writes status, headers and body to the client. */
    public function send(): void
    {
        http_response_code($this->statusCode);
        foreach ($this->headers as $headerName => $headerValue) {
            header($headerName . ': ' . $headerValue);
        }
        foreach ($this->setCookieHeaders as $setCookieValue) {
            header('Set-Cookie: ' . $setCookieValue, false);
        }
        echo $this->body;
    }

    private static function encode(mixed $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
