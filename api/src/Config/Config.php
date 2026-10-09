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
        'magic_links' => [
            // Magic Links (D059). The cookie a browser gets after opening a link; separate from
            // the sign-in cookie. Needs a name without the __Host- prefix on plain-http development.
            'cookie_name' => '__Host-muninn_link',
            // Time zone of the optional daily window ("07:00-18:00"). Never the server's own zone.
            'timezone' => 'Europe/Stockholm',
            // Lifetime of a new link when the creator picks no end date, and the longest allowed.
            'default_valid_days' => 30,
            'max_valid_days' => 365,
            // How long one opening of a link lasts in a browser before the link must be opened again.
            'visit_hours' => 12,
        ],
        'audit_log' => [
            // The audit log is kept forever; entries older than this many months can be zipped
            // and removed from the database from the admin Overview page (D060).
            'archive_after_months' => 13,
            // Empty means "<application folder>/storage/audit-archives" (see Application).
            'archive_path' => '',
        ],
        'ntfy' => [
            // Push notifications to the administrator (D057). Off until configured.
            'enabled' => false,
            'server_url' => '',
            'topic' => '',
            'access_token' => '',
            'timeout_seconds' => 3,
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

        $this->validateNtfy();
        $this->validateMagicLinks();

        // Archiving entries younger than a month would empty the audit log of current events.
        $archiveAfterMonths = $this->get('audit_log.archive_after_months');
        if (!is_int($archiveAfterMonths) || $archiveAfterMonths < 1 || $archiveAfterMonths > 1200) {
            throw new ConfigException('audit_log.archive_after_months must be a whole number of months from 1 to 1200.');
        }

        if ($this->isProduction() && !$this->getBool('session.cookie_secure')) {
            throw new ConfigException('session.cookie_secure must be true in production.');
        }
    }

    /**
     * Checks the Magic Link section (D059): a real time zone and sensible lifetimes.
     *
     * @throws ConfigException
     */
    private function validateMagicLinks(): void
    {
        if (!in_array($this->getString('magic_links.timezone'), \DateTimeZone::listIdentifiers(), true)) {
            throw new ConfigException('magic_links.timezone must be a time zone name like Europe/Stockholm.');
        }
        $maximumValidDays = $this->get('magic_links.max_valid_days');
        if (!is_int($maximumValidDays) || $maximumValidDays < 1 || $maximumValidDays > 3650) {
            throw new ConfigException('magic_links.max_valid_days must be a whole number from 1 to 3650.');
        }
        $defaultValidDays = $this->get('magic_links.default_valid_days');
        if (!is_int($defaultValidDays) || $defaultValidDays < 1 || $defaultValidDays > $maximumValidDays) {
            throw new ConfigException('magic_links.default_valid_days must be a whole number from 1 to magic_links.max_valid_days.');
        }
        $visitHours = $this->get('magic_links.visit_hours');
        if (!is_int($visitHours) || $visitHours < 1 || $visitHours > 168) {
            throw new ConfigException('magic_links.visit_hours must be a whole number from 1 to 168.');
        }
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $this->getString('magic_links.cookie_name'))) {
            throw new ConfigException('magic_links.cookie_name must be 1-64 letters, digits, "-" or "_".');
        }
    }

    /**
     * Checks the ntfy section when notifications are switched on (D057).
     *
     * @throws ConfigException
     */
    private function validateNtfy(): void
    {
        if (!$this->getBool('ntfy.enabled')) {
            return;
        }
        // A plain base URL: no user name or password in it (use access_token), no query string.
        if (!preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?(/[A-Za-z0-9._~\-/]*)?$#', $this->getString('ntfy.server_url'))) {
            throw new ConfigException('ntfy.server_url must be a URL like http://127.0.0.1:2586 (no user name, password or query).');
        }
        // ntfy topic names: letters, digits, "-" and "_", at most 64 characters.
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $this->getString('ntfy.topic'))) {
            throw new ConfigException('ntfy.topic must be 1-64 letters, digits, "-" or "_".');
        }
        $timeoutSeconds = $this->get('ntfy.timeout_seconds');
        if (!is_int($timeoutSeconds) || $timeoutSeconds < 1 || $timeoutSeconds > 10) {
            throw new ConfigException('ntfy.timeout_seconds must be a whole number from 1 to 10.');
        }
    }
}
