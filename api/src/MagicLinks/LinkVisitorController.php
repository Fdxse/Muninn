<?php

declare(strict_types=1);

namespace Muninn\Api\MagicLinks;

use Muninn\Api\Attachments\AttachmentController;
use Muninn\Api\Attachments\AttachmentService;
use Muninn\Api\Auth\RateLimiter;
use Muninn\Api\Auth\SessionCookie;
use Muninn\Api\Folders\FolderService;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\InputReader;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Notes\NoteController;
use Muninn\Api\Notes\NoteInput;
use Muninn\Api\Notes\NoteService;

/**
 * Everything a browser can do after opening a Magic Link (D059), under /api/v1/link/:
 *   POST  /api/v1/link/open                      {"token"} → sets the visit cookie (public, rate limited)
 *   GET   /api/v1/link/me                        what the link opens, plus the CSRF token
 *   POST  /api/v1/link/close                     end the visit
 *   GET   /api/v1/link/folders                   folders the link reaches
 *   GET   /api/v1/link/notes?folder=<id|none>    notes the link reaches (no content)
 *   GET   /api/v1/link/notes/{id}                one note with content
 *   POST  /api/v1/link/notes                     create a note (write links to a workspace or folder)
 *   PATCH /api/v1/link/notes/{id}                save title/content with revision check (write links)
 *   POST  /api/v1/link/notes/{id}/attachments    upload an image (write links)
 *   GET   /api/v1/link/attachments/{id}/content  an image of a reachable note
 *
 * These are separate endpoints on purpose: a visit cookie never reaches the signed-in API
 * (history, Trash, search, tags, members, deleting, moving), and a sign-in cookie never reaches
 * these. Before every request the Application checks the link again (MagicLinkService), and every
 * handler asks MagicLinkScope whether the note or folder is inside what the link opens. Anything
 * outside gives the same 404 as something that does not exist.
 */
final class LinkVisitorController
{
    public function __construct(
        private readonly MagicLinkService $magicLinkService,
        private readonly MagicLinkScope $magicLinkScope,
        private readonly NoteService $noteService,
        private readonly FolderService $folderService,
        private readonly AttachmentService $attachmentService,
        private readonly RateLimiter $rateLimiter,
        private readonly SessionCookie $visitCookie,
        private readonly AuditLog $auditLog,
        private readonly int $maximumUploadBytes,
    ) {
    }

    /**
     * POST /api/v1/link/open  {"token": "..."}
     *
     * Unknown, revoked, expired and not-yet-valid links (and links whose creator lost access or
     * whose target is gone) all give the same 404. A link that works but not at this hour gives
     * 403 with its hours, which only someone holding the token can learn.
     */
    public function open(Request $request, RequestContext $context): Response
    {
        $this->rateLimiter->assertMagicLinkAllowed($context->clientIp);
        $rawToken = (string) InputReader::optionalString($request->jsonBody(), 'token');

        $openResult = $this->magicLinkService->open($rawToken);
        if ($openResult['outcome'] === 'unknown') {
            // Only guesses count towards the rate limit; a real link that is merely expired does not.
            $this->rateLimiter->recordAttempt(RateLimiter::TYPE_MAGIC_LINK, null, $context->clientIp, false);
        }
        if ($openResult['outcome'] === 'outside_daily_window') {
            throw HttpException::forbidden(
                'link_outside_hours',
                'This link only works between ' . $openResult['daily_start_time'] . ' and ' . $openResult['daily_end_time']
                    . ' (' . $this->magicLinkService->windowTimeZoneName() . ' time).',
            );
        }
        if ($openResult['outcome'] !== 'opened') {
            throw HttpException::notFound('link_unavailable', 'This link does not work. It may have expired or been switched off.');
        }

        // A browser that opens a link again gets a fresh visit; the old one ends.
        $this->magicLinkService->endVisitByRawToken($request->cookie($this->visitCookie->name()));
        $rawVisitToken = (string) $openResult['raw_visit_token'];
        $this->auditLog->record(
            AuditLog::MAGIC_LINK_OPENED,
            null,
            'magic_link',
            $openResult['link_id'],
            $context->clientIp,
        );

        $access = $this->magicLinkService->findActiveVisit($rawVisitToken);
        if ($access === null) {
            // Only possible if the link was revoked in the same instant.
            throw HttpException::notFound('link_unavailable', 'This link does not work. It may have expired or been switched off.');
        }

        return Response::data($this->describe($access))->withSetCookie($this->visitCookie->issue($rawVisitToken));
    }

    /** GET /api/v1/link/me */
    public function me(Request $request, RequestContext $context): Response
    {
        return Response::data($this->describe($context->requireMagicLinkAccess()));
    }

    /** POST /api/v1/link/close */
    public function close(Request $request, RequestContext $context): Response
    {
        $this->magicLinkService->endVisit($context->requireMagicLinkAccess()->visitId);

        return Response::noContent()->withSetCookie($this->visitCookie->clear());
    }

    /** GET /api/v1/link/folders — in tree order, only the folders the link reaches. */
    public function folders(Request $request, RequestContext $context): Response
    {
        $access = $context->requireMagicLinkAccess();
        $reachableFolderIds = $this->magicLinkScope->reachableFolderIds($access);
        $allFolders = $this->folderService->listInWorkspace($access->serviceMembership());

        $visibleFolders = $reachableFolderIds === null
            ? $allFolders
            : array_values(array_filter($allFolders, static fn (array $folder): bool => in_array($folder['id'], $reachableFolderIds, true)));

        return Response::data(['folders' => $visibleFolders]);
    }

    /**
     * GET /api/v1/link/notes?folder=<id|none>
     *
     * Workspace links list the whole workspace (optionally one folder, or "none"); folder links
     * list their folder with its sub-folders (optionally narrowed to one of those); note links
     * list their one note. Archived notes are left out, as in the normal list.
     */
    public function notes(Request $request, RequestContext $context): Response
    {
        $access = $context->requireMagicLinkAccess();
        $requestedFolder = (string) $request->queryParameter('folder');

        if ($access->targetType === MagicLinkAccess::TARGET_NOTE) {
            $noteSummaries = [];
            $targetNote = $this->noteService->find($access->serviceMembership(), (string) $access->targetId);
            $noteSummaries[] = self::withoutPeople([
                'id' => $targetNote['id'],
                'folder_id' => null,
                'title' => $targetNote['title'],
                'excerpt' => '',
                'tags' => $targetNote['tags'],
                'revision' => $targetNote['revision'],
                'created_at' => $targetNote['created_at'],
                'updated_at' => $targetNote['updated_at'],
                'archived_at' => $targetNote['archived_at'],
            ]);

            return Response::data(['notes' => $noteSummaries]);
        }

        $folderFilter = null;
        if ($requestedFolder === NoteService::FOLDER_FILTER_NONE && $access->targetType === MagicLinkAccess::TARGET_WORKSPACE) {
            $folderFilter = NoteService::FOLDER_FILTER_NONE;
        } elseif ($requestedFolder !== '') {
            if (!$this->magicLinkScope->reachesFolder($access, $requestedFolder)) {
                throw HttpException::notFound();
            }
            $folderFilter = $requestedFolder;
        } elseif ($access->targetType === MagicLinkAccess::TARGET_FOLDER) {
            // A folder link always lists its own branch, never the rest of the workspace.
            $folderFilter = (string) $access->targetId;
        }

        $noteSummaries = $this->noteService->listInWorkspace($access->serviceMembership(), $folderFilter);

        return Response::data(['notes' => array_map(self::withoutPeople(...), $noteSummaries)]);
    }

    /** GET /api/v1/link/notes/{id} */
    public function showNote(Request $request, RequestContext $context): Response
    {
        $access = $context->requireMagicLinkAccess();
        $noteId = $this->requireReachableNote($access, $request);

        return Response::data(['note' => $this->visibleNote($access, $noteId)]);
    }

    /**
     * POST /api/v1/link/notes  {"title": "...", "content": "...", "folder_id": "..."}
     *
     * Write links to a workspace or folder only. A folder link puts the note in its own folder
     * unless one of its sub-folders is chosen.
     */
    public function createNote(Request $request, RequestContext $context): Response
    {
        $access = $context->requireMagicLinkAccess();
        self::requireWrite($access);
        if ($access->targetType === MagicLinkAccess::TARGET_NOTE) {
            throw HttpException::forbidden('link_cannot_create', 'This link opens one note; it cannot create new notes.');
        }
        $requestBody = $request->jsonBody();

        $folderId = $access->targetType === MagicLinkAccess::TARGET_FOLDER ? (string) $access->targetId : null;
        $requestedFolderId = InputReader::optionalString($requestBody, 'folder_id');
        if ($requestedFolderId !== null && $requestedFolderId !== '') {
            if (!$this->magicLinkScope->reachesFolder($access, $requestedFolderId)) {
                throw HttpException::validation(['folder_id' => 'This folder is not available through this link.']);
            }
            $folderId = $requestedFolderId;
        }

        $membership = $access->serviceMembership();
        $newNoteId = $this->noteService->create($membership, new NoteInput(
            title: NoteController::readTitle($requestBody),
            content: NoteController::readContent($requestBody),
            folderIsSet: true,
            folderId: $folderId,
        ));
        $this->auditLog->record(
            AuditLog::MAGIC_LINK_NOTE_CREATED,
            null,
            'note',
            $newNoteId,
            $context->clientIp,
            ['workspace_id' => $access->workspaceId, 'magic_link_id' => $access->linkId],
        );

        return Response::data(['note' => $this->visibleNote($access, $newNoteId)], 201);
    }

    /**
     * PATCH /api/v1/link/notes/{id}  {"revision": 3, "title": "...", "content": "..."}
     *
     * Only title and content; folders and tags stay as they are. A stale revision gives 409, so a
     * visitor never silently overwrites someone else's save (D010). The replaced state goes to the
     * note's history like any other save.
     */
    public function updateNote(Request $request, RequestContext $context): Response
    {
        $access = $context->requireMagicLinkAccess();
        self::requireWrite($access);
        $noteId = $this->requireReachableNote($access, $request);
        $requestBody = $request->jsonBody();

        $this->noteService->update(
            $access->serviceMembership(),
            $noteId,
            NoteController::readExpectedRevision($requestBody),
            new NoteInput(title: NoteController::readTitle($requestBody), content: NoteController::readContent($requestBody)),
        );
        $this->auditLog->record(
            AuditLog::MAGIC_LINK_NOTE_UPDATED,
            null,
            'note',
            $noteId,
            $context->clientIp,
            ['workspace_id' => $access->workspaceId, 'magic_link_id' => $access->linkId],
        );

        return Response::data(['note' => $this->visibleNote($access, $noteId)]);
    }

    /** POST /api/v1/link/notes/{id}/attachments — the same checks as the signed-in upload. */
    public function uploadAttachment(Request $request, RequestContext $context): Response
    {
        $access = $context->requireMagicLinkAccess();
        self::requireWrite($access);
        $noteId = $this->requireReachableNote($access, $request);

        [$fileBytes, $requestedFilename] = AttachmentController::readUploadedImage($request, $this->maximumUploadBytes);
        $attachment = $this->attachmentService->create($access->serviceMembership(), $noteId, $requestedFilename, $fileBytes);
        $this->auditLog->record(
            AuditLog::MAGIC_LINK_ATTACHMENT_UPLOADED,
            null,
            'attachment',
            (string) $attachment['id'],
            $context->clientIp,
            ['workspace_id' => $access->workspaceId, 'note_id' => $noteId, 'magic_link_id' => $access->linkId, 'byte_size' => $attachment['byte_size']],
        );

        return Response::data(['attachment' => $attachment], 201);
    }

    /** GET /api/v1/link/attachments/{id}/content */
    public function attachmentContent(Request $request, RequestContext $context): Response
    {
        $access = $context->requireMagicLinkAccess();
        $attachmentId = (string) $request->routeParameter('id');
        $attachmentOwner = $this->attachmentService->findOwnerOfActiveAttachment($attachmentId);
        if ($attachmentOwner === null
            || $attachmentOwner['workspace_id'] !== $access->workspaceId
            || !$this->magicLinkScope->reachesNote($access, $attachmentOwner['note_id'])) {
            throw HttpException::notFound();
        }

        $membership = $access->serviceMembership();
        $attachment = $this->attachmentService->find($membership, $attachmentId);
        $entityTag = '"' . $attachment['sha256'] . '"';
        if ($request->header('If-None-Match') === $entityTag) {
            return AttachmentController::withFileHeaders(Response::notModified(), $attachment, $entityTag);
        }
        $download = $this->attachmentService->readContent($membership, $attachmentId);

        return AttachmentController::withFileHeaders(Response::file($download['bytes'], (string) $attachment['media_type']), $attachment, $entityTag);
    }

    /**
     * What the visitor's page needs to know about the link. No IDs or names beyond what the link
     * opens: for a note link not even the folder the note sits in.
     *
     * @return array<string, mixed>
     */
    private function describe(MagicLinkAccess $access): array
    {
        $targetName = $access->workspaceName;
        if ($access->targetType === MagicLinkAccess::TARGET_FOLDER) {
            $targetName = (string) $this->folderService->find($access->serviceMembership(), (string) $access->targetId)['name'];
        } elseif ($access->targetType === MagicLinkAccess::TARGET_NOTE) {
            $targetName = (string) $this->noteService->find($access->serviceMembership(), (string) $access->targetId)['title'];
        }

        return [
            'link' => [
                'label' => $access->label,
                'target_type' => $access->targetType,
                'target_id' => $access->targetId,
                'target_name' => $targetName,
                'workspace_name' => $access->workspaceName,
                'permission' => $access->permission,
                'valid_until' => $access->validUntilIso,
            ],
            'csrf_token' => $access->csrfToken,
        ];
    }

    /**
     * The note as a visitor sees it: no author names, no history counter (history is not
     * reachable through a link), and for note links no folder.
     *
     * @return array<string, mixed>
     */
    private function visibleNote(MagicLinkAccess $access, string $noteId): array
    {
        $note = self::withoutPeople($this->noteService->find($access->serviceMembership(), $noteId));
        unset($note['workspace_id'], $note['history_count'], $note['history_limit']);
        if ($access->targetType === MagicLinkAccess::TARGET_NOTE) {
            $note['folder_id'] = null;
            $note['folder_name'] = null;
        }

        return $note;
    }

    /**
     * Resolves {id} to a note the link reaches; anything else is a 404.
     */
    private function requireReachableNote(MagicLinkAccess $access, Request $request): string
    {
        $noteId = (string) $request->routeParameter('id');
        if (!$this->magicLinkScope->reachesNote($access, $noteId)) {
            throw HttpException::notFound();
        }

        return $noteId;
    }

    /** @throws HttpException 403 for read-only links. */
    private static function requireWrite(MagicLinkAccess $access): void
    {
        if (!$access->canWrite()) {
            throw HttpException::forbidden('link_read_only', 'This link can only read notes.');
        }
    }

    /**
     * Removes member display names: a visitor is not a member and has no reason to learn who
     * else works in the workspace.
     *
     * @param array<string, mixed> $note
     * @return array<string, mixed>
     */
    private static function withoutPeople(array $note): array
    {
        unset($note['created_by'], $note['updated_by']);

        return $note;
    }
}
