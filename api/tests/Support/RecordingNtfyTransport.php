<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Support;

use Muninn\Api\Notifications\NtfyTransport;

/** Test double: remembers every published message and answers with a chosen status code. */
final class RecordingNtfyTransport implements NtfyTransport
{
    /** @var list<array{server_url: string, message: array<string, mixed>, access_token: string}> */
    public array $publishedMessages = [];

    /** The HTTP status every publish answers with (0 = server unreachable). */
    public int $answerStatusCode = 200;

    public function publish(string $serverUrl, array $message, string $accessToken, int $timeoutSeconds): int
    {
        $this->publishedMessages[] = ['server_url' => $serverUrl, 'message' => $message, 'access_token' => $accessToken];

        return $this->answerStatusCode;
    }
}
