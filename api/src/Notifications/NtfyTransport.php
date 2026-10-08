<?php

declare(strict_types=1);

namespace Muninn\Api\Notifications;

/**
 * Sends one JSON message to an ntfy server. Separate from AdminNotifier so tests can record
 * messages instead of making network calls.
 */
interface NtfyTransport
{
    /**
     * Posts the message and returns the HTTP status code, or 0 when no answer was received
     * (server unreachable, timeout).
     *
     * @param string $serverUrl Base URL of the ntfy server, without a trailing slash.
     * @param array<string, mixed> $message ntfy's JSON publish format (topic, title, message, ...).
     * @param string $accessToken ntfy access token, or '' when the server needs none.
     */
    public function publish(string $serverUrl, array $message, string $accessToken, int $timeoutSeconds): int;
}
