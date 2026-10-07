<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Integration;

use Muninn\Api\Bootstrap;
use Muninn\Api\Http\Request;
use Muninn\Api\Tests\Support\IntegrationTestCase;

final class BootstrapTest extends IntegrationTestCase
{
    public function testMissingConfigSaysNotSetUpWithoutDetails(): void
    {
        // Silence PHP's error_log output for this expected failure.
        $previousErrorLog = ini_set('error_log', $this->logFilePath);
        $startupResponse = Bootstrap::handle('/srv/secret-location/config.php', new Request('GET', '/api/v1/health'));
        ini_set('error_log', (string) $previousErrorLog);

        $this->assertError($startupResponse, 503, 'not_set_up');
        self::assertStringNotContainsString('secret-location', $startupResponse->body());
        self::assertStringNotContainsString('config', strtolower($startupResponse->body()));
    }

    public function testInvalidConfigGivesGenericErrorWithoutDetails(): void
    {
        // A config file that exists but is unusable is a real fault: generic 500, details only in the log.
        $temporaryConfigPath = sys_get_temp_dir() . '/muninn-config-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($temporaryConfigPath, '<?php return "not an array";');

        $previousErrorLog = ini_set('error_log', $this->logFilePath);
        try {
            $startupResponse = Bootstrap::handle($temporaryConfigPath, new Request('GET', '/api/v1/health'));
        } finally {
            ini_set('error_log', (string) $previousErrorLog);
            unlink($temporaryConfigPath);
        }

        $this->assertError($startupResponse, 500, 'internal_error');
        self::assertStringNotContainsString('muninn-config', $startupResponse->body());
    }

    public function testValidConfigFileServesHealthCheck(): void
    {
        $temporaryConfigPath = sys_get_temp_dir() . '/muninn-config-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($temporaryConfigPath, '<?php return ' . var_export($this->configValues(), true) . ';');

        try {
            $healthResponse = Bootstrap::handle($temporaryConfigPath, new Request('GET', '/api/v1/health'));
        } finally {
            unlink($temporaryConfigPath);
        }

        self::assertSame(200, $healthResponse->statusCode());
        self::assertSame(['data' => ['status' => 'ok']], $healthResponse->json());
    }
}
