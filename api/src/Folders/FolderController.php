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
 *   GET    /api/v1/workspaces/{id}/folders   list with note counts (Reader+)
 *   POST   /api/v1/workspaces/{id}/folders   create (Editor+)
 *   PATCH  /api/v1/folders/{id}              rename (Editor+)
 *   DELETE /api/v1/folders/{id}              delete; its notes move to "No folder" (Editor+)
 *
 * Folders organise notes, so the note permissions apply: whoever may edit notes may also
 * organise them. Deleting a folder never deletes a note (D033).
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

    /** POST /api/v1/workspaces/{id}/folders  {"name": "..."} */
    public function create(Request $request, RequestContext $context): Response
    {
        $membership = $this->workspaceAuthorizer->requireWorkspacePermission(
            $context->requireSession()->user,
            (string) $request->routeParameter('id'),
            WorkspacePermission::WriteNotes,
        );
        $folderName = $this->readName($request->jsonBody());

        $newFolderId = $this->folderService->create($membership, $folderName);

        return Response::data(['folder' => $this->folderService->find($membership, $newFolderId)], 201);
    }

    /** PATCH /api/v1/folders/{id}  {"name": "..."} */
    public function rename(Request $request, RequestContext $context): Response
    {
        [$membership, $folderId] = $this->authorizeFolder($request, $context);
        $newName = $this->readName($request->jsonBody());

        $this->folderService->rename($membership, $folderId, $newName);

        return Response::data(['folder' => $this->folderService->find($membership, $folderId)]);
    }

    /** DELETE /api/v1/folders/{id} */
    public function delete(Request $request, RequestContext $context): Response
    {
        [$membership, $folderId] = $this->authorizeFolder($request, $context);

        $this->folderService->delete($membership, $folderId);
        $this->auditLog->record(
            AuditLog::FOLDER_DELETED,
            $membership->userId,
            'folder',
            $folderId,
            $context->clientIp,
            ['workspace_id' => $membership->workspaceId],
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
