<?php

declare(strict_types=1);

namespace Muninn\Api\Tests\Support;

use Muninn\Api\Http\Response;

/**
 * Helpers for workspace and note tests: signed-in test users, workspaces and notes created
 * through the API itself, so every fixture passes the same checks real requests do.
 */
abstract class WorkspaceTestCase extends IntegrationTestCase
{
    /**
     * Creates a user, signs them in and returns their credentials plus user ID.
     *
     * @return array{session_token: string, csrf_token: string, user_id: string}
     */
    protected function signedInUser(string $username, bool $isSystemAdmin = false): array
    {
        $newUserId = $this->createUser($username, self::DEFAULT_PASSWORD, $isSystemAdmin);

        return $this->login($username) + ['user_id' => $newUserId];
    }

    /** Returns the ID of the user's personal workspace (created on first listing). */
    protected function personalWorkspaceId(array $credentials): string
    {
        $listResponse = $this->sendAs($credentials, 'GET', '/api/v1/workspaces');
        self::assertSame(200, $listResponse->statusCode(), $listResponse->body());
        foreach ($listResponse->json()['data']['workspaces'] as $workspace) {
            if ($workspace['kind'] === 'personal') {
                return (string) $workspace['id'];
            }
        }
        self::fail('The user has no personal workspace.');
    }

    /** Creates a shared workspace owned by $ownerCredentials and returns its ID. */
    protected function createSharedWorkspace(array $ownerCredentials, string $workspaceName = 'Team'): string
    {
        $createResponse = $this->sendAs($ownerCredentials, 'POST', '/api/v1/workspaces', ['name' => $workspaceName]);
        self::assertSame(201, $createResponse->statusCode(), $createResponse->body());

        return (string) $createResponse->json()['data']['workspace']['id'];
    }

    /** Adds a member through the API as $actorCredentials and returns the response. */
    protected function addMember(array $actorCredentials, string $workspaceId, string $username, string $role): Response
    {
        return $this->sendAs($actorCredentials, 'POST', '/api/v1/workspaces/' . $workspaceId . '/members', [
            'username' => $username,
            'role' => $role,
        ]);
    }

    /** Creates a note as $authorCredentials and returns the note as the API returned it. */
    protected function createNote(array $authorCredentials, string $workspaceId, string $title = 'Secret plan', string $content = 'Top secret content'): array
    {
        $createResponse = $this->sendAs($authorCredentials, 'POST', '/api/v1/workspaces/' . $workspaceId . '/notes', [
            'title' => $title,
            'content' => $content,
        ]);
        self::assertSame(201, $createResponse->statusCode(), $createResponse->body());

        return $createResponse->json()['data']['note'];
    }

    /**
     * Saves changes to a note through the API as $editorCredentials and returns the response.
     *
     * @param array<string, mixed> $changedFields
     */
    protected function updateNote(array $editorCredentials, string $noteId, int $basedOnRevision, array $changedFields): Response
    {
        return $this->sendAs($editorCredentials, 'PATCH', '/api/v1/notes/' . $noteId, ['revision' => $basedOnRevision] + $changedFields);
    }

    /**
     * Sends an authenticated GET with query parameters (e.g. ?q= for search).
     *
     * @param array{session_token: string, csrf_token: string} $credentials
     * @param array<string, string> $queryParameters
     */
    protected function getAs(array $credentials, string $path, array $queryParameters = []): Response
    {
        return $this->application->handle(new \Muninn\Api\Http\Request(
            'GET',
            $path,
            [],
            [self::COOKIE_NAME => $credentials['session_token']],
            '',
            '203.0.113.10',
            $queryParameters,
        ));
    }

    /**
     * Asserts a 200 response and returns its "data" part.
     *
     * @return array<string, mixed>
     */
    protected function assertOkData(Response $response): array
    {
        self::assertSame(200, $response->statusCode(), $response->body());

        return $response->json()['data'];
    }

    /** A valid 1x1 pixel PNG image. */
    protected const TINY_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==';

    protected static function tinyPng(): string
    {
        return (string) base64_decode(self::TINY_PNG_BASE64, true);
    }

    /**
     * Uploads raw bytes as an attachment of a note, exactly as the browser does: the bytes are
     * the body, the filename travels percent-encoded in X-Filename.
     *
     * @param array{session_token: string, csrf_token: string} $credentials
     */
    protected function uploadAttachment(
        array $credentials,
        string $noteId,
        string $fileBytes,
        string $contentType = 'image/png',
        ?string $filename = 'photo.png',
    ): Response {
        $uploadHeaders = ['X-CSRF-Token' => $credentials['csrf_token'], 'Content-Type' => $contentType];
        if ($filename !== null) {
            $uploadHeaders['X-Filename'] = rawurlencode($filename);
        }

        return $this->application->handle(new \Muninn\Api\Http\Request(
            'POST',
            '/api/v1/notes/' . $noteId . '/attachments',
            $uploadHeaders,
            [self::COOKIE_NAME => $credentials['session_token']],
            $fileBytes,
            '203.0.113.10',
        ));
    }

    /** Creates a folder through the API and returns it. */
    protected function createFolder(array $credentials, string $workspaceId, string $folderName = 'Projects'): array
    {
        $createResponse = $this->sendAs($credentials, 'POST', '/api/v1/workspaces/' . $workspaceId . '/folders', ['name' => $folderName]);
        self::assertSame(201, $createResponse->statusCode(), $createResponse->body());

        return $createResponse->json()['data']['folder'];
    }
}
