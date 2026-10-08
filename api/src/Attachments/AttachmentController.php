<?php

declare(strict_types=1);

namespace Muninn\Api\Attachments;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Notes\NoteService;
use Muninn\Api\Workspaces\WorkspaceAuthorizer;
use Muninn\Api\Workspaces\WorkspaceMembership;
use Muninn\Api\Workspaces\WorkspacePermission;

/**
 * Attachment endpoints (all require a signed-in user):
 *   POST /api/v1/notes/{id}/attachments        upload one image as the raw request body (Editor+)
 *   GET  /api/v1/notes/{id}/attachments        list a note's images (Reader+)
 *   GET  /api/v1/attachments/{id}/content      the image itself (Reader+)
 *
 * Uploads are the raw file bytes, not multipart forms: the body's Content-Type must be an image
 * type or application/octet-stream (the actual type is detected from the bytes), and the
 * original filename may be sent percent-encoded in the X-Filename header. Like every other
 * authenticated state change, an upload needs the X-CSRF-Token header. Clipboard pastes use
 * exactly this endpoint, so they pass the same validation.
 */
final class AttachmentController
{
    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly NoteService $noteService,
        private readonly WorkspaceAuthorizer $workspaceAuthorizer,
        private readonly AuditLog $auditLog,
        private readonly int $maximumUploadBytes,
    ) {
    }

    /** POST /api/v1/notes/{id}/attachments */
    public function upload(Request $request, RequestContext $context): Response
    {
        [$membership, $noteId] = $this->authorizeNote($request, $context, WorkspacePermission::WriteNotes);

        $declaredContentType = strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0]));
        if (!str_starts_with($declaredContentType, 'image/') && $declaredContentType !== 'application/octet-stream') {
            throw new HttpException(415, 'unsupported_media_type', 'Upload an image file as the request body.');
        }

        $fileBytes = $request->rawBody();
        if ($fileBytes === '') {
            throw HttpException::validation(['file' => 'The upload is empty.']);
        }
        if (strlen($fileBytes) > $this->maximumUploadBytes) {
            throw new HttpException(413, 'file_too_large', 'Images can be at most ' . self::megabytes($this->maximumUploadBytes) . ' MB.');
        }

        $encodedFilename = $request->header('X-Filename');
        $requestedFilename = $encodedFilename === null ? null : rawurldecode($encodedFilename);

        $attachment = $this->attachmentService->create($membership, $noteId, $requestedFilename, $fileBytes);
        $this->auditLog->record(
            AuditLog::ATTACHMENT_UPLOADED,
            $membership->userId,
            'attachment',
            (string) $attachment['id'],
            $context->clientIp,
            ['workspace_id' => $membership->workspaceId, 'note_id' => $noteId, 'byte_size' => $attachment['byte_size']],
        );

        return Response::data(['attachment' => $attachment], 201);
    }

    /** GET /api/v1/notes/{id}/attachments */
    public function list(Request $request, RequestContext $context): Response
    {
        [$membership, $noteId] = $this->authorizeNote($request, $context, WorkspacePermission::ReadNotes);

        return Response::data(['attachments' => $this->attachmentService->listForNote($membership, $noteId)]);
    }

    /**
     * GET /api/v1/attachments/{id}/content
     *
     * Loaded by <img> tags on the frontend with the session cookie (www.dx.se and api.dx.se are
     * the same site, D022). Unknown attachments, attachments of trashed notes and attachments in
     * workspaces the caller is not a member of all give the same 404.
     */
    public function content(Request $request, RequestContext $context): Response
    {
        $attachmentId = (string) $request->routeParameter('id');
        $attachmentOwner = $this->attachmentService->findOwnerOfActiveAttachment($attachmentId);
        if ($attachmentOwner === null) {
            throw HttpException::notFound();
        }
        $membership = $this->workspaceAuthorizer->requireWorkspacePermission(
            $context->requireSession()->user,
            $attachmentOwner['workspace_id'],
            WorkspacePermission::ReadNotes,
        );

        // The content never changes, so its hash is a perfect ETag. Checked only after authorization.
        $attachment = $this->attachmentService->find($membership, $attachmentId);
        $entityTag = '"' . $attachment['sha256'] . '"';
        if ($request->header('If-None-Match') === $entityTag) {
            return self::withFileHeaders(Response::notModified(), $attachment, $entityTag);
        }

        $download = $this->attachmentService->readContent($membership, $attachmentId);

        return self::withFileHeaders(Response::file($download['bytes'], (string) $attachment['media_type']), $attachment, $entityTag);
    }

    /**
     * Headers for serving a private image: shown inline under its display name, cached only by
     * the user's own browser and only briefly, and never usable as a document or by other sites.
     *
     * @param array<string, mixed> $attachment
     */
    private static function withFileHeaders(Response $response, array $attachment, string $entityTag): Response
    {
        $filename = (string) $attachment['filename'];
        // ASCII fallback plus the exact UTF-8 name (RFC 6266).
        $asciiFilename = (string) preg_replace('/[^A-Za-z0-9._-]/u', '_', $filename);

        return $response
            ->withHeader('Content-Disposition', 'inline; filename="' . $asciiFilename . '"; filename*=UTF-8\'\'' . rawurlencode($filename))
            ->withHeader('Cache-Control', 'private, max-age=300')
            ->withHeader('ETag', $entityTag)
            ->withHeader('Cross-Origin-Resource-Policy', 'same-site');
    }

    /**
     * Resolves {id} to an active note's workspace and requires $permission there. Unknown notes
     * and notes in workspaces the caller is not a member of give the same 404.
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

    private static function megabytes(int $byteCount): string
    {
        return rtrim(rtrim(number_format($byteCount / 1_000_000, 1), '0'), '.');
    }
}
