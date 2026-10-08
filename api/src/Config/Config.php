<?php

declare(strict_types=1);

namespace Muninn\Api\Config;

/**
 * Read-only access to the API configuration array.
 *
 * Values are addressed with dot paths such as "database.host". The constructor validates
 * that every required key exists, so a misconfigured server fails fast at startup instead
 * of failing half-way through a request.
 */
final class Config
{
    /** Keys that must be present (and non-empty) for the API to start. */
    private const REQUIRED_KEYS = [
        'app.environment',
        'database.host',
        'database.name',
        'database.username',
        'database.password',
        'cors.allowed_origins',
        'frontend.base_url',
        'logging.file_path',
    ];

    /** Defaults for optional keys, applied when the config file leaves them out. */
    private const DEFAULTS = [
        'database' => ['port' => 3306],
        'session' => [
            'cookie_name' => '__Host-muninn_session',
            'cookie_secure' => true,
            'idle_timeout_hours' => 168,
            'absolute_timeout_hours' => 720,
        ],
        'security' => [
            'trusted_proxies' => [],
            'login_max_failures_per_username' => 5,
            'login_max_failures_per_ip' => 20,
            'invitation_max_failures_per_ip' => 20,
            'rate_limit_window_minutes' => 15,
        ],
        'invitations' => [
            'default_expiry_hours' => 72,
            'max_expiry_hours' => 720,
        ],
        'attachments' => [
            // Empty means "<application folder>/storage/attachments" (see Application).
            'storage_path' => '',
            'max_upload_bytes' => 10_000_000,
        ],
        'trash' => [
            // Days a note stays in Trash before the API deletes it for good (D012, D039).
            'retention_days' => 30,
        ],
    ];

    /** @var array<string, mixed> */
    private array $values;

    /**
     * @param array<string, mixed> $values Raw configuration array.
     * @throws ConfigException When a required key is missing or a value is unusable.
     */
    public function __construct(array $values)
    {
        $this->values = array_replace_recursive(self::DEFAULTS, $values);
        $this->validate();
    }

    /**
     * Loads configuration from a PHP file that returns an array.
     *
     * @throws ConfigException When the file is missing or does not return an array.
     */
    public static function fromFile(string $configFilePath): self
    {
        if (!is_file($configFilePath)) {
            throw new ConfigException('Configuration file not found: ' . basename($configFilePath));
        }

        $loadedValues = require $configFilePath;
        if (!is_array($loadedValues)) {
            throw new ConfigException('Configuration file must return an array.');
        }

        return new self($loadedValues);
    }

    /**
     * Returns the value at a dot path, or the given default when the path does not exist.
     */
    public function get(string $dotPath, mixed $default = null): mixed
    {
        $currentValue = $this->values;
        foreach (explode('.', $dotPath) as $pathSegment) {
            if (!is_array($currentValue) || !array_key_exists($pathSegment, $currentValue)) {
                return $default;
            }
            $currentValue = $currentValue[$pathSegment];
        }

        return $currentValue;
    }

    /** Returns a value that must be a string. */
    public function getString(string $dotPath): string
    {
        return (string) $this->get($dotPath, '');
    }

    /** Returns a value that must be an integer. */
    public function getInt(string $dotPath): int
    {
        return (int) $this->get($dotPath, 0);
    }

    /** Returns a value that must be a boolean. */
    public function getBool(string $dotPath): bool
    {
        return (bool) $this->get($dotPath, false);
    }

    /**
     * Returns a list of strings, e.g. the CORS allowlist.
     *
     * @return list<string>
     */
    public function getStringList(string $dotPath): array
    {
        $listValue = $this->get($dotPath, []);
        if (!is_array($listValue)) {
            return [];
        }

        return array_values(array_map('strval', $listValue));
    }

    /** True when the API runs in production mode (no internal details anywhere near clients). */
    public function isProduction(): bool
    {
        return $this->getString('app.environment') === 'production';
    }

    /**
     * Checks required keys and rejects dangerous values.
     *
     * @throws ConfigException
     */
    private function validate(): void
    {
        $missingKeys = [];
        foreach (self::REQUIRED_KEYS as $requiredKey) {
            $requiredValue = $this->get($requiredKey);
            if ($requiredValue === null || $requiredValue === '' || $requiredValue === []) {
                $missingKeys[] = $requiredKey;
            }
        }

        if ($missingKeys !== []) {
            throw new ConfigException('Missing required configuration keys: ' . implode(', ', $missingKeys));
        }

        // A wildcard origin combined with credentials would let any website act as the user.
        foreach ($this->getStringList('cors.allowed_origins') as $allowedOrigin) {
            if ($allowedOrigin === '*' || !preg_match('#^https?://[^/\s]+$#', $allowedOrigin)) {
                throw new ConfigException('cors.allowed_origins must contain exact origins like https://www.dx.se (no paths, no "*").');
            }
        }

        // A retention of 0 days would let the daily cleanup destroy notes the moment they are trashed.
        $retentionDays = $this->get('trash.retention_days');
        if (!is_int($retentionDays) || $retentionDays < 1 || $retentionDays > 3650) {
            throw new ConfigException('trash.retention_days must be a whole number of days from 1 to 3650.');
        }

        if ($this->isProduction() && !$this->getBool('session.cookie_secure')) {
            throw new ConfigException('session.cookie_secure must be true in production.');
        }
    }
}
