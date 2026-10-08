<?php

declare(strict_types=1);

namespace Muninn\Api\Folders;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\InputReader;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Validation\TextRules;
use Muninn\Api\Workspaces\WorkspaceAuthorizer;
use Muninn\Api\Workspaces\WorkspaceMembership;
use Muninn\Api\Workspaces\WorkspacePermission;

/**
 * Folder endpoints (all require a signed-in user):
 *   GET    /api/v1/workspaces/{id}/folders   list in tree order with note counts (Reader+)
 *   POST   /api/v1/workspaces/{id}/folders   create, optionally inside parent_id (Editor+)
 *   PATCH  /api/v1/folders/{id}              rename and/or move to another parent (Editor+)
 *   DELETE /api/v1/folders/{id}              delete; its notes and sub-folders move up one level (Editor+)
 *
 * Folders organise notes, so the note permissions apply: whoever may edit notes may also
 * organise them. Deleting a folder never deletes a note (D033, D055).
 */
final class FolderController
{
    private const NAME_MAX_LENGTH = 100;

    public function __construct(
        private readonly FolderService $folderService,
        private readonly WorkspaceAuthorizer $workspaceAuthorizer,
        private readonly AuditLog $auditLog,
    ) {
    }

    /** GET /api/v1/workspaces/{id}/folders */
    public function list(Request $request, RequestContext $context): Response
    {
        $membership = $this->workspaceAuthorizer->requireWorkspacePermission(
            $context->requireSession()->user,
            (string) $request->routeParameter('id'),
            WorkspacePermission::ReadNotes,
        );

        return Response::data(['folders' => $this->folderService->listInWorkspace($membership)]);
    }

    /** POST /api/v1/workspaces/{id}/folders  {"name": "...", "parent_id": "..." or null} */
    public function create(Request $request, RequestContext $context): Response
    {
        $membership = $this->workspaceAuthorizer->requireWorkspacePermission(
            $context->requireSession()->user,
            (string) $request->routeParameter('id'),
            WorkspacePermission::WriteNotes,
        );
        $requestBody = $request->jsonBody();
        $folderName = $this->readName($requestBody);
        $parentFolderId = self::readParentId($requestBody);

        $newFolderId = $this->folderService->create($membership, $folderName, $parentFolderId);

        return Response::data(['folder' => $this->folderService->find($membership, $newFolderId)], 201);
    }

    /**
     * PATCH /api/v1/folders/{id}  {"name": "...", "parent_id": "..." or null}
     *
     * Both fields are optional, but at least one is required. Leaving parent_id out keeps the
     * folder where it is; "parent_id": null moves it to the top level.
     */
    public function update(Request $request, RequestContext $context): Response
    {
        [$membership, $folderId] = $this->authorizeFolder($request, $context);
        $requestBody = $request->jsonBody();

        $renameRequested = array_key_exists('name', $requestBody);
        $moveRequested = array_key_exists('parent_id', $requestBody);
        if (!$renameRequested && !$moveRequested) {
            throw HttpException::validation(['name' => 'Send a new name, a new parent_id, or both.']);
        }
        $newName = $renameRequested ? $this->readName($requestBody) : null;
        $newParentFolderId = $moveRequested ? self::readParentId($requestBody) : null;

        $this->folderService->update($membership, $folderId, $newName, $moveRequested, $newParentFolderId);

        return Response::data(['folder' => $this->folderService->find($membership, $folderId)]);
    }

    /** DELETE /api/v1/folders/{id} */
    public function delete(Request $request, RequestContext $context): Response
    {
        [$membership, $folderId] = $this->authorizeFolder($request, $context);

        $contentsMovedToFolderId = $this->folderService->delete($membership, $folderId);
        $this->auditLog->record(
            AuditLog::FOLDER_DELETED,
            $membership->userId,
            'folder',
            $folderId,
            $context->clientIp,
            ['workspace_id' => $membership->workspaceId, 'contents_moved_to_folder_id' => $contentsMovedToFolderId],
        );

        return Response::noContent();
    }

    /**
     * Resolves {id} to the folder's workspace and requires note-editing rights there. Unknown
     * folders and folders of workspaces the caller is not a member of give the same 404.
     *
     * @return array{0: WorkspaceMembership, 1: string}
     */
    private function authorizeFolder(Request $request, RequestContext $context): array
    {
        $folderId = (string) $request->routeParameter('id');
        $workspaceId = $this->folderService->findWorkspaceIdOfFolder($folderId);
        if ($workspaceId === null) {
            throw HttpException::notFound();
        }

        $membership = $this->workspaceAuthorizer->requireWorkspacePermission(
            $context->requireSession()->user,
            $workspaceId,
            WorkspacePermission::WriteNotes,
        );

        return [$membership, $folderId];
    }

    /**
     * Reads parent_id: a folder ID, or null / empty / absent for the top level. Whether the ID is
     * a folder of the same workspace is checked by FolderService.
     *
     * @param array<string, mixed> $requestBody
     */
    private static function readParentId(array $requestBody): ?string
    {
        $parentFolderId = InputReader::optionalString($requestBody, 'parent_id');

        return $parentFolderId === null || trim($parentFolderId) === '' ? null : trim($parentFolderId);
    }

    /** @param array<string, mixed> $requestBody */
    private function readName(array $requestBody): string
    {
        $folderName = trim((string) InputReader::optionalString($requestBody, 'name'));
        $nameError = TextRules::singleLineError($folderName, self::NAME_MAX_LENGTH, true);
        if ($nameError !== null) {
            throw HttpException::validation(['name' => $nameError]);
        }

        return $folderName;
    }
}
