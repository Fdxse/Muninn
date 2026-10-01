<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Http\Request;
use Muninn\Api\Http\Router;
use Muninn\Api\Tests\Support\IntegrationTestCase;
use RuntimeException;

final class HttpConventionsTest extends IntegrationTestCase
{
    public function testUnknownRouteIs404InEnvelope(): void
    {
        $this->assertError($this->send('GET', '/api/v1/does-not-exist'), 404, 'not_found');
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        $wrongMethodResponse = $this->send('GET', '/api/v1/auth/login');

        $this->assertError($wrongMethodResponse, 405, 'method_not_allowed');
        self::assertSame('POST', $wrongMethodResponse->header('Allow'));
    }

    public function testMalformedJsonIs400(): void
    {
        $malformedRequest = new Request('POST', '/api/v1/auth/login', ['Content-Type' => 'application/json'], [], '{"username": ');

        $this->assertError($this->application->handle($malformedRequest), 400, 'malformed_json');
    }

    public function testNonJsonBodyIs415(): void
    {
        // A cross-site HTML form can only send form encodings; those never reach handlers.
        $formRequest = new Request('POST', '/api/v1/auth/login', ['Content-Type' => 'application/x-www-form-urlencoded'], [], 'username=a&password=b');

        $this->assertError($this->application->handle($formRequest), 415, 'unsupported_media_type');
    }

    public function testInternalErrorsHideDetailsAndCarryRequestId(): void
    {
        $this->application->router()->add('GET', '/api/v1/test/explode', function (): never {
            throw new RuntimeException('SQLSTATE[42S02] secret_table at /volume1/web/muninn/src/Secret.php');
        }, Router::ACCESS_PUBLIC);

        $errorResponse = $this->send('GET', '/api/v1/test/explode');

        $this->assertError($errorResponse, 500, 'internal_error');
        $requestId = (string) $errorResponse->header('X-Request-Id');
        self::assertNotSame('', $requestId);
        self::assertStringContainsString($requestId, $errorResponse->body());
        foreach (['SQLSTATE', 'secret_table', '/volume1', 'Secret.php', 'RuntimeException', '#0'] as $forbiddenFragment) {
            self::assertStringNotContainsString($forbiddenFragment, $errorResponse->body());
        }

        // The details are in the server log, findable by request ID.
        $logContents = (string) file_get_contents($this->logFilePath);
        self::assertStringContainsString($requestId, $logContents);
        self::assertStringContainsString('secret_table', $logContents);
    }

    public function testSecurityHeadersArePresent(): void
    {
        $healthResponse = $this->send('GET', '/api/v1/health');

        self::assertSame('nosniff', $healthResponse->header('X-Content-Type-Options'));
        self::assertSame('no-store', $healthResponse->header('Cache-Control'));
        self::assertStringContainsString("default-src 'none'", (string) $healthResponse->header('Content-Security-Policy'));
    }
}
