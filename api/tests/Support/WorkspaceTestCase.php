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
}
