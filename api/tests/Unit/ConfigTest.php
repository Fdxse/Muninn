<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Unit;

use Muninn\Api\Config\Config;
use Muninn\Api\Config\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private function validValues(): array
    {
        return [
            'app' => ['environment' => 'production'],
            'database' => ['host' => 'localhost', 'name' => 'muninn', 'username' => 'app', 'password' => 'secret-value'],
            'cors' => ['allowed_origins' => ['https://www.dx.se']],
            'frontend' => ['base_url' => 'https://www.dx.se'],
            'logging' => ['file_path' => '/tmp/muninn.log'],
        ];
    }

    public function testValidConfigLoadsAndAppliesDefaults(): void
    {
        $config = new Config($this->validValues());

        self::assertTrue($config->isProduction());
        self::assertSame('__Host-muninn_session', $config->getString('session.cookie_name'));
        self::assertSame(3306, $config->getInt('database.port'));
    }

    public function testMissingRequiredKeyIsNamedButValueIsNot(): void
    {
        $incompleteValues = $this->validValues();
        unset($incompleteValues['database']['name']);

        try {
            new Config($incompleteValues);
            self::fail('Expected a ConfigException.');
        } catch (ConfigException $configException) {
            self::assertStringContainsString('database.name', $configException->getMessage());
            self::assertStringNotContainsString('secret-value', $configException->getMessage());
        }
    }

    public function testWildcardOriginIsRejected(): void
    {
        $values = $this->validValues();
        $values['cors']['allowed_origins'] = ['*'];

        $this->expectException(ConfigException::class);
        new Config($values);
    }

    public function testInsecureCookieIsRejectedInProduction(): void
    {
        $values = $this->validValues();
        $values['session'] = ['cookie_secure' => false];

        $this->expectException(ConfigException::class);
        new Config($values);
    }

    public function testAuditLogArchiveAgeMustBeAtLeastOneMonth(): void
    {
        $values = $this->validValues();
        self::assertSame(13, (new Config($values))->getInt('audit_log.archive_after_months'));
        $values['audit_log'] = ['archive_after_months' => 0];

        $this->expectException(ConfigException::class);
        new Config($values);
    }

    public function testChatRetentionDefaultsToNinetyDaysAndMustBePositive(): void
    {
        self::assertSame(90, (new Config($this->validValues()))->getInt('chat.retention_days'));

        $invalidValues = $this->validValues();
        $invalidValues['chat'] = ['retention_days' => 0];
        $this->expectException(ConfigException::class);
        new Config($invalidValues);
    }

    public function testMissingConfigFileThrows(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromFile('/nonexistent/config.php');
    }
}
