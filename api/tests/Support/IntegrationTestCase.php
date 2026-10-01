<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Support;

use Muninn\Api\Application;
use Muninn\Api\Auth\PasswordService;
use Muninn\Api\Config\Config;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AppLogger;
use Muninn\Api\Users\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that drive the real Application against the real test database,
 * in-process, exactly as an HTTP request would.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected const ALLOWED_ORIGIN = 'https://www.dx.se';
    protected const COOKIE_NAME = '__Host-muninn_session';
    protected const DEFAULT_PASSWORD = 'correct horse battery staple';

    protected PDO $database;
    protected Application $application;
    protected string $logFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        TestDatabase::truncateDataTables();
        $this->database = TestDatabase::connection();
        $this->logFilePath = sys_get_temp_dir() . '/muninn-test-' . bin2hex(random_bytes(6)) . '.log';
        $this->application = $this->buildApplication();
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFilePath)) {
            unlink($this->logFilePath);
        }
        parent::tearDown();
    }

    /**
     * The configuration every integration test runs with (production mode, so error hiding is tested).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function configValues(array $overrides = []): array
    {
        $databaseSettings = TestDatabase::settings();

        return array_replace_recursive([
            'app' => ['environment' => 'production'],
            'database' => [
                'host' => $databaseSettings['host'],
                'port' => $databaseSettings['port'],
                'name' => $databaseSettings['name'],
                'username' => $databaseSettings['username'],
                'password' => $databaseSettings['password'],
            ],
            'cors' => ['allowed_origins' => [self::ALLOWED_ORIGIN]],
            'frontend' => ['base_url' => 'https://www.dx.se'],
            'logging' => ['file_path' => $this->logFilePath],
        ], $overrides);
    }

    /** @param array<string, mixed> $configOverrides */
    protected function buildApplication(array $configOverrides = []): Application
    {
        return new Application(
            new Config($this->configValues($configOverrides)),
            $this->database,
            new AppLogger($this->logFilePath),
        );
    }

    /**
     * Sends a request through the application.
     *
     * @param array<string, mixed>|null $jsonBody Encoded as JSON with Content-Type application/json.
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    protected function send(
        string $method,
        string $path,
        ?array $jsonBody = null,
        array $headers = [],
        array $cookies = [],
        string $remoteAddress = '203.0.113.10',
    ): Response {
        $rawBody = '';
        if ($jsonBody !== null) {
            $rawBody = json_encode($jsonBody, JSON_THROW_ON_ERROR);
            $headers += ['Content-Type' => 'application/json'];
        }

        return $this->application->handle(new Request($method, $path, $headers, $cookies, $rawBody, $remoteAddress));
    }

    /** Creates a user directly in the database and returns its ID. */
    protected function createUser(string $username, string $password = self::DEFAULT_PASSWORD, bool $isSystemAdmin = false): string
    {
        return (new UserRepository($this->database))->create($username, ucfirst($username), (new PasswordService())->hash($password), $isSystemAdmin);
    }

    /**
     * Signs in through the API and returns the raw session token and CSRF token.
     *
     * @return array{session_token: string, csrf_token: string}
     */
    protected function login(string $username, string $password = self::DEFAULT_PASSWORD): array
    {
        $loginResponse = $this->send('POST', '/api/v1/auth/login', ['username' => $username, 'password' => $password]);
        self::assertSame(200, $loginResponse->statusCode(), $loginResponse->body());

        return [
            'session_token' => $this->sessionTokenFrom($loginResponse),
            'csrf_token' => (string) $loginResponse->json()['data']['csrf_token'],
        ];
    }

    /** Extracts the raw session token from a response's Set-Cookie header. */
    protected function sessionTokenFrom(Response $response): string
    {
        foreach ($response->setCookieHeaders() as $setCookieHeader) {
            if (str_starts_with($setCookieHeader, self::COOKIE_NAME . '=')) {
                return explode(';', substr($setCookieHeader, strlen(self::COOKIE_NAME) + 1))[0];
            }
        }
        self::fail('Response did not set the session cookie.');
    }

    /**
     * Sends an authenticated request carrying the session cookie and CSRF header.
     *
     * @param array{session_token: string, csrf_token: string} $credentials
     * @param array<string, mixed>|null $jsonBody
     */
    protected function sendAs(array $credentials, string $method, string $path, ?array $jsonBody = null): Response
    {
        return $this->send(
            $method,
            $path,
            $jsonBody,
            ['X-CSRF-Token' => $credentials['csrf_token']],
            [self::COOKIE_NAME => $credentials['session_token']],
        );
    }

    /** Returns the single value from a query (test helper; never used with user input). */
    protected function scalar(string $sql, array $parameters = []): mixed
    {
        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }

    /** Asserts the standard error envelope with the given status and code. */
    protected function assertError(Response $response, int $expectedStatus, string $expectedCode): void
    {
        self::assertSame($expectedStatus, $response->statusCode(), $response->body());
        self::assertSame($expectedCode, $response->json()['error']['code'] ?? null, $response->body());
    }
}
