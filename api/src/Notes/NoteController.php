<?php

declare(strict_types=1);

namespace Muninn\Api\Notes;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\InputReader;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Tags\TagService;
use Muninn\Api\Validation\TextRules;
use Muninn\Api\Workspaces\WorkspaceAuthorizer;
use Muninn\Api\Workspaces\WorkspaceMembership;
use Muninn\Api\Workspaces\WorkspacePermission;

/**
 * Note endpoints (all require a signed-in user):
 *   GET    /api/v1/workspaces/{id}/notes   list, optionally ?folder=<id|none>&tag=<name> (Reader+)
 *   POST   /api/v1/workspaces/{id}/notes   create (Editor+)
 *   GET    /api/v1/notes/{id}              read (Reader+)
 *   PATCH  /api/v1/notes/{id}              update with revision check (Editor+)
 *   DELETE /api/v1/notes/{id}              move to Trash (Editor+)
 *
 * A note's workspace is looked up first and the caller's membership of THAT workspace is then
 * required, so the only way to a note is through a membership of its workspace.
 */
final class NoteController
{
    private const TITLE_MAX_LENGTH = 200;
    /** 1 MB of Markdown is far more than any hand-written note needs. */
    private const CONTENT_MAX_BYTES = 1_000_000;

    public function __construct(
        private readonly NoteService $noteService,
        private readonly WorkspaceAuthorizer $workspaceAuthorizer,
        private readonly AuditLog $auditLog,
    ) {
    }

    /** GET /api/v1/workspaces/{id}/notes */
    public function list(Request $request, RequestContext $context): Response
    {
        $membership = $this->workspaceAuthorizer->requireWorkspacePermission(
            $context->requireSession()->user,
            (string) $request->routeParameter('id'),
            WorkspacePermission::ReadNotes,
        );

        $folderFilter = $request->queryParameter('folder');
        if ($folderFilter === '') {
            $folderFilter = null;
        }
        $tagFilter = trim((string) $request->queryParameter('tag'));

        return Response::data(['notes' => $this->noteService->listInWorkspace(
            $membership,
            $folderFilter,
            $tagFilter === '' ? null : $tagFilter,
        )]);
    }

    /** POST /api/v1/workspaces/{id}/notes  {"title": "...", "content": "...", "folder_id": "...", "tags": ["..."]} */
    public function create(Request $request, RequestContext $context): Response
    {
        $membership = $this->workspaceAuthorizer->requireWorkspacePermission(
            $context->requireSession()->user,
            (string) $request->routeParameter('id'),
            WorkspacePermission::WriteNotes,
        );
        $newNoteId = $this->noteService->create($membership, $this->readNoteInput($request->jsonBody()));

        return Response::data(['note' => $this->noteService->find($membership, $newNoteId)], 201);
    }

    /** GET /api/v1/notes/{id} */
    public function show(Request $request, RequestContext $context): Response
    {
        [$membership, $noteId] = $this->authorizeNote($request, $context, WorkspacePermission::ReadNotes);

        return Response::data(['note' => $this->noteService->find($membership, $noteId)]);
    }

    /**
     * PATCH /api/v1/notes/{id}  {"revision": 3, "title": "...", "content": "...", "folder_id": "...", "tags": ["..."]}
     *
     * "revision" is the revision the edit was based on and is required; every other field is
     * optional and left unchanged when absent ("folder_id": null moves the note to No folder). A stale revision is refused with 409 so no save silently overwrites another.
     */
    public function update(Request $request, RequestContext $context): Response
    {
        [$membership, $noteId] = $this->authorizeNote($request, $context, WorkspacePermission::WriteNotes);
        $requestBody = $request->jsonBody();

        $expectedRevision = InputReader::optionalInt($requestBody, 'revision');
        if ($expectedRevision === null || $expectedRevision < 1) {
            throw HttpException::validation(['revision' => 'Send the revision of the note you edited.']);
        }

        $this->noteService->update($membership, $noteId, $expectedRevision, $this->readNoteInput($requestBody));

        return Response::data(['note' => $this->noteService->find($membership, $noteId)]);
    }

    /** DELETE /api/v1/notes/{id} — moves the note to Trash. */
    public function trash(Request $request, RequestContext $context): Response
    {
        [$membership, $noteId] = $this->authorizeNote($request, $context, WorkspacePermission::WriteNotes);

        $this->noteService->trash($membership, $noteId);
        $this->auditLog->record(
            AuditLog::NOTE_TRASHED,
            $membership->userId,
            'note',
            $noteId,
            $context->clientIp,
            ['workspace_id' => $membership->workspaceId],
        );

        return Response::noContent();
    }

    /**
     * Resolves {id} to the note's workspace and requires the caller's permission there.
     * Unknown notes and notes in workspaces the caller is not a member of give the same 404.
     *
     * @return array{0: WorkspaceMembership, 1: string}
     */
    private function authorizeNote(Request $request, RequestContext $context, WorkspacePermission $permission): array
    {
        $noteId = (string) $request->routeParameter('id');
        $workspaceId = $this->noteService->findWorkspaceIdOfActiveNote($noteId);
        if ($workspaceId === null) {
            throw HttpException::notFound();
        }

        $membership = $this->workspaceAuthorizer->requireWorkspacePermission($context->requireSession()->user, $workspaceId, $permission);

        return [$membership, $noteId];
    }

    /**
     * Validates the note fields shared by create and update.
     *
     * @param array<string, mixed> $requestBody
     */
    private function readNoteInput(array $requestBody): NoteInput
    {
        $folderIsSet = array_key_exists('folder_id', $requestBody);
        $folderId = $folderIsSet ? InputReader::optionalString($requestBody, 'folder_id') : null;

        return new NoteInput(
            title: $this->readTitle($requestBody),
            content: $this->readContent($requestBody),
            folderIsSet: $folderIsSet,
            // An empty string is treated like null: "No folder". Whether the folder belongs to
            // the note's workspace is checked by NoteService inside the save's transaction.
            folderId: $folderId === '' ? null : $folderId,
            tagNames: array_key_exists('tags', $requestBody) ? TagService::normaliseTagNames($requestBody['tags']) : null,
        );
    }

    /**
     * Returns the trimmed title, or null when the field is absent.
     *
     * @param array<string, mixed> $requestBody
     */
    private function readTitle(array $requestBody): ?string
    {
        $rawTitle = InputReader::optionalString($requestBody, 'title');
        if ($rawTitle === null) {
            return null;
        }
        $title = trim($rawTitle);
        $titleError = TextRules::singleLineError($title, self::TITLE_MAX_LENGTH, false);
        if ($titleError !== null) {
            throw HttpException::validation(['title' => $titleError]);
        }

        return $title;
    }

    /**
     * Returns the content exactly as sent (Markdown whitespace matters), or null when absent.
     *
     * @param array<string, mixed> $requestBody
     */
    private function readContent(array $requestBody): ?string
    {
        $content = InputReader::optionalString($requestBody, 'content');
        if ($content === null) {
            return null;
        }
        $contentError = TextRules::multiLineError($content, self::CONTENT_MAX_BYTES);
        if ($contentError !== null) {
            throw HttpException::validation(['content' => $contentError]);
        }

        return $content;
    }
}
