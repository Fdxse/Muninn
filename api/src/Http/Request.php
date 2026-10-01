<?php

declare(strict_types=1);

namespace Muninn\Api\Http;

/**
 * Immutable view of one incoming HTTP request.
 *
 * Built from PHP globals in production and constructed directly in tests, so the whole
 * application can be exercised in-process without a web server.
 */
final class Request
{
    /** @var array<string, string> Header names are stored lowercase. */
    private array $headers;

    /** @var array<string, string> Values captured from the route pattern, e.g. {id}. */
    private array $routeParameters = [];

    /** Parsed JSON body; null until parsed, false when the body is empty. */
    private array|false|null $parsedJsonBody = null;

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, string> $queryParameters
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        array $headers = [],
        private readonly array $cookies = [],
        private readonly string $rawBody = '',
        private readonly string $remoteAddress = '127.0.0.1',
        private readonly array $queryParameters = [],
    ) {
        $this->headers = [];
        foreach ($headers as $headerName => $headerValue) {
            $this->headers[strtolower($headerName)] = $headerValue;
        }
    }

    /**
     * Removes the folder the API is installed in from the request path, so routes always
     * start with /api/v1/ whether the API is served from a host root (https://api.dx.se/)
     * or from a sub-folder (https://fehre.synology.me/muninn/).
     *
     * The install folder is taken from SCRIPT_NAME, which the web server sets to the URL of
     * the front controller (e.g. /muninn/index.php). It is never taken from client input.
     */
    public static function stripInstallFolder(string $requestPath, string $scriptName): string
    {
        $installFolder = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if ($installFolder === '' || $installFolder === '.') {
            return $requestPath;
        }
        if (str_starts_with($requestPath, $installFolder . '/')) {
            return substr($requestPath, strlen($installFolder));
        }
        return $requestPath;
    }

    /** Creates a Request from PHP's superglobals (production entry point). */
    public static function fromGlobals(): self
    {
        $requestHeaders = [];
        foreach ($_SERVER as $serverKey => $serverValue) {
            if (str_starts_with($serverKey, 'HTTP_')) {
                $headerName = str_replace('_', '-', substr($serverKey, 5));
                $requestHeaders[$headerName] = (string) $serverValue;
            }
        }
        // CONTENT_TYPE and CONTENT_LENGTH are not prefixed with HTTP_ by PHP.
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $requestHeaders['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $routablePath = self::stripInstallFolder(
            is_string($requestPath) ? $requestPath : '/',
            (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
        );

        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path: $routablePath,
            headers: $requestHeaders,
            cookies: array_map('strval', $_COOKIE),
            rawBody: (string) file_get_contents('php://input'),
            remoteAddress: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            queryParameters: array_map('strval', array_filter($_GET, 'is_scalar')),
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Returns a header value (case-insensitive name) or null when absent. */
    public function header(string $headerName): ?string
    {
        return $this->headers[strtolower($headerName)] ?? null;
    }

    public function cookie(string $cookieName): ?string
    {
        return $this->cookies[$cookieName] ?? null;
    }

    public function remoteAddress(): string
    {
        return $this->remoteAddress;
    }

    public function queryParameter(string $parameterName): ?string
    {
        return $this->queryParameters[$parameterName] ?? null;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /** True for methods that change state and therefore need CSRF/Origin protection. */
    public function isStateChanging(): bool
    {
        return !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * Returns a copy of this request carrying the parameters captured by the router.
     *
     * @param array<string, string> $routeParameters
     */
    public function withRouteParameters(array $routeParameters): self
    {
        $requestCopy = clone $this;
        $requestCopy->routeParameters = $routeParameters;

        return $requestCopy;
    }

    public function routeParameter(string $parameterName): ?string
    {
        return $this->routeParameters[$parameterName] ?? null;
    }

    /**
     * Decodes the JSON body into an associative array.
     *
     * An empty body yields an empty array. Anything that is not a JSON object is rejected
     * with 400, and a body sent with a non-JSON content type is rejected with 415 so that
     * plain HTML forms on other sites can never reach state-changing endpoints.
     *
     * @return array<string, mixed>
     * @throws HttpException
     */
    public function jsonBody(): array
    {
        if ($this->parsedJsonBody === null) {
            $this->parsedJsonBody = $this->parseJsonBody();
        }

        return $this->parsedJsonBody === false ? [] : $this->parsedJsonBody;
    }

    /**
     * @return array<string, mixed>|false
     * @throws HttpException
     */
    private function parseJsonBody(): array|false
    {
        if (trim($this->rawBody) === '') {
            return false;
        }

        $contentType = strtolower((string) $this->header('Content-Type'));
        if (!str_starts_with($contentType, 'application/json')) {
            throw HttpException::unsupportedMediaType();
        }

        try {
            $decodedBody = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw HttpException::badRequest('malformed_json', 'The request body is not valid JSON.');
        }

        if (!is_array($decodedBody) || array_is_list($decodedBody) && $decodedBody !== []) {
            throw HttpException::badRequest('malformed_json', 'The request body must be a JSON object.');
        }

        return $decodedBody;
    }
}
