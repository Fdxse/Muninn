<?php

declare(strict_types=1);

namespace Muninn\Api\Logging;

/**
 * Append-only JSON-lines application log.
 *
 * The log file must live outside the web root. Context values whose keys look like
 * secrets (password, token, secret, cookie, authorization) are always replaced with
 * "[redacted]" so that a careless call can never write a credential to disk.
 */
final class AppLogger
{
    /** Context keys containing any of these words are redacted. */
    private const SECRET_KEY_FRAGMENTS = ['password', 'token', 'secret', 'cookie', 'authorization'];

    public function __construct(private readonly string $logFilePath)
    {
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /**
     * Returns a copy of $context with every secret-looking key replaced.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function redact(array $context): array
    {
        $redactedContext = [];
        foreach ($context as $contextKey => $contextValue) {
            $lowercaseKey = strtolower((string) $contextKey);
            $looksSecret = false;
            foreach (self::SECRET_KEY_FRAGMENTS as $secretFragment) {
                if (str_contains($lowercaseKey, $secretFragment)) {
                    $looksSecret = true;
                    break;
                }
            }

            if ($looksSecret) {
                $redactedContext[$contextKey] = '[redacted]';
            } elseif (is_array($contextValue)) {
                $redactedContext[$contextKey] = self::redact($contextValue);
            } else {
                $redactedContext[$contextKey] = $contextValue;
            }
        }

        return $redactedContext;
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $logLine = json_encode([
            'time' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'message' => $message,
            'context' => self::redact($context),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $logDirectory = dirname($this->logFilePath);
        if (!is_dir($logDirectory)) {
            @mkdir($logDirectory, 0750, true);
        }

        // A failing log write must never break the request, so errors are suppressed here.
        @file_put_contents($this->logFilePath, $logLine . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
