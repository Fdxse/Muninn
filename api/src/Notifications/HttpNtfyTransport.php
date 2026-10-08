<?php

declare(strict_types=1);

namespace Muninn\Api\Notifications;

/**
 * Posts to ntfy over HTTP(S) with PHP's curl extension, or with PHP's built-in HTTP stream
 * when curl is not installed (it then needs allow_url_fopen). No library is needed for this.
 *
 * Messages use ntfy's JSON publishing (POST to the server's base URL), so titles and messages
 * may contain any UTF-8 text without header encoding.
 */
final class HttpNtfyTransport implements NtfyTransport
{
    public function publish(string $serverUrl, array $message, string $accessToken, int $timeoutSeconds): int
    {
        $requestBody = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $requestHeaders = ['Content-Type: application/json'];
        if ($accessToken !== '') {
            $requestHeaders[] = 'Authorization: Bearer ' . $accessToken;
        }

        if (function_exists('curl_init')) {
            return self::publishWithCurl($serverUrl, $requestBody, $requestHeaders, $timeoutSeconds);
        }

        return self::publishWithStream($serverUrl, $requestBody, $requestHeaders, $timeoutSeconds);
    }

    /** @param list<string> $requestHeaders */
    private static function publishWithCurl(string $serverUrl, string $requestBody, array $requestHeaders, int $timeoutSeconds): int
    {
        $curlHandle = curl_init($serverUrl);
        if ($curlHandle === false) {
            return 0;
        }
        curl_setopt_array($curlHandle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            // Never follow redirects: the token must only ever go to the configured server.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $responseBody = curl_exec($curlHandle);
        $statusCode = $responseBody === false ? 0 : (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        curl_close($curlHandle);

        return $statusCode;
    }

    /** @param list<string> $requestHeaders */
    private static function publishWithStream(string $serverUrl, string $requestBody, array $requestHeaders, int $timeoutSeconds): int
    {
        $streamContext = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $requestHeaders),
            'content' => $requestBody,
            'timeout' => $timeoutSeconds,
            'follow_location' => 0,
            // Read the status of error answers too, instead of getting false.
            'ignore_errors' => true,
        ]]);
        $responseBody = @file_get_contents($serverUrl, false, $streamContext);
        // PHP 8.4 added http_get_last_response_headers(); older versions fill $http_response_header.
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : ($http_response_header ?? null);
        if ($responseBody === false || !isset($responseHeaders[0])) {
            return 0;
        }

        // The first header line looks like "HTTP/1.1 200 OK".
        return preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHeaders[0], $statusMatch) === 1 ? (int) $statusMatch[1] : 0;
    }
}
