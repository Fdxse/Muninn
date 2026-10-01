<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Tests\Support\IntegrationTestCase;

final class CorsTest extends IntegrationTestCase
{
    public function testAllowedOriginGetsExactOriginAndCredentials(): void
    {
        $healthResponse = $this->send('GET', '/api/v1/health', null, ['Origin' => self::ALLOWED_ORIGIN]);

        self::assertSame(self::ALLOWED_ORIGIN, $healthResponse->header('Access-Control-Allow-Origin'));
        self::assertSame('true', $healthResponse->header('Access-Control-Allow-Credentials'));
        self::assertSame('Origin', $healthResponse->header('Vary'));
    }

    public function testOtherOriginGetsNoCorsHeaders(): void
    {
        $healthResponse = $this->send('GET', '/api/v1/health', null, ['Origin' => 'https://evil.example']);

        self::assertNull($healthResponse->header('Access-Control-Allow-Origin'));
        self::assertNull($healthResponse->header('Access-Control-Allow-Credentials'));
    }

    public function testPreflightFromAllowedOriginListsMethodsAndHeaders(): void
    {
        $preflightResponse = $this->send('OPTIONS', '/api/v1/auth/login', null, [
            'Origin' => self::ALLOWED_ORIGIN,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ]);

        self::assertSame(204, $preflightResponse->statusCode());
        self::assertSame(self::ALLOWED_ORIGIN, $preflightResponse->header('Access-Control-Allow-Origin'));
        self::assertStringContainsString('POST', (string) $preflightResponse->header('Access-Control-Allow-Methods'));
        self::assertStringContainsString('X-CSRF-Token', (string) $preflightResponse->header('Access-Control-Allow-Headers'));
    }

    public function testPreflightFromOtherOriginIsNotApproved(): void
    {
        $preflightResponse = $this->send('OPTIONS', '/api/v1/auth/login', null, [
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'POST',
        ]);

        self::assertNull($preflightResponse->header('Access-Control-Allow-Origin'));
        self::assertNull($preflightResponse->header('Access-Control-Allow-Methods'));
    }

    public function testWildcardIsNeverSent(): void
    {
        foreach (['https://www.dx.se', 'https://evil.example', 'null'] as $origin) {
            $healthResponse = $this->send('GET', '/api/v1/health', null, ['Origin' => $origin]);
            self::assertNotSame('*', $healthResponse->header('Access-Control-Allow-Origin'));
        }
    }

    public function testStateChangingRequestFromForeignOriginIsRefused(): void
    {
        $this->createUser('alice');

        $crossSiteLogin = $this->send(
            'POST',
            '/api/v1/auth/login',
            ['username' => 'alice', 'password' => self::DEFAULT_PASSWORD],
            ['Origin' => 'https://evil.example'],
        );

        $this->assertError($crossSiteLogin, 403, 'origin_not_allowed');
        self::assertSame([], $crossSiteLogin->setCookieHeaders());
    }
}
