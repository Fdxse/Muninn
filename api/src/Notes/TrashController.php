<?php

declare(strict_types=1);

namespace Muninn\Api\Notes;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Workspaces\WorkspaceAuthorizer;
use Muninn\Api\Workspaces\WorkspaceMembership;
use Muninn\Api\Workspaces\WorkspacePermission;

/**
 * Trash endpoints (decisions D012, D039, D045; all require a signed-in user):
 *   GET    /api/v1/workspaces/{id}/trash   list the workspace's Trash (Reader+)
 *   DELETE /api/v1/workspaces/{id}/trash   empty it: delete every trashed note for good (Admin+)
 *   POST   /api/v1/trash/{noteId}/restore  bring a trashed note back (Editor+)
 *   DELETE /api/v1/trash/{noteId}          delete one trashed note for good (Admin+)
 *
 * /api/v1/trash/{noteId} only ever addresses trashed notes and /api/v1/notes/{id} only active
 * ones, so a slip in the frontend can never purge a note that is still in use. Notes are moved
 * into Trash with DELETE /api/v1/notes/{id} (NoteController).
 */
final class TrashController
{
    public function __construct(
        private readonly NoteService $noteService,
        private readonly NotePurger $notePurger,
        private readonly WorkspaceAuthorizer $workspaceAuthorizer,
        private readonly AuditLog $auditLog,
        private readonly int $retentionDays,
    ) {
    }

    /** GET /api/v1/workspaces/{id}/trash */
    public function list(Request $request, RequestContext $context): Response
    {
        $membership = $this->workspaceAuthorizer->requireWorkspacePermission(
            $context->requireSession()->user,
            (string) $request->routeParameter('id'),
            WorkspacePermission::ReadNotes,
        );

        return Response::data([
            'notes' => $this->noteService->listTrash($membership, $this->retentionDays),
            'retention_days' => $this->retentionDays,
        ]);
    }

    /** DELETE /api/v1/workspaces/{id}/trash */
    public function empty(Request $request, RequestContext $context): Response
    {
        $membership = $this->workspaceAuthorizer->requireWorkspacePermission(
            $context->requireSession()->user,
            (string) $request->routeParameter('id'),
            WorkspacePermission::PurgeNotes,
        );

        $purgedNoteCount = $this->notePurger->emptyTrash($membership);
        $this->auditLog->record(
            AuditLog::TRASH_EMPTIED,
            $membership->userId,
            'workspace',
            $membership->workspaceId,
            $context->clientIp,
            ['deleted_notes' => $purgedNoteCount],
        );

        return Response::data(['deleted_notes' => $purgedNoteCount]);
    }

    /** POST /api/v1/trash/{noteId}/restore */
    public function restore(Request $request, RequestContext $context): Response
    {
        [$membership, $noteId] = $this->authorizeTrashedNote($request, $context, WorkspacePermission::WriteNotes);

        $this->noteService->restoreFromTrash($membership, $noteId);
        $this->auditLog->record(
            AuditLog::NOTE_RESTORED_FROM_TRASH,
            $membership->userId,
            'note',
            $noteId,
            $context->clientIp,
            ['workspace_id' => $membership->workspaceId],
        );

        return Response::data(['note' => $this->noteService->find($membership, $noteId)]);
    }

    /** DELETE /api/v1/trash/{noteId} */
    public function purge(Request $request, RequestContext $context): Response
    {
        [$membership, $noteId] = $this->authorizeTrashedNote($request, $context, WorkspacePermission::PurgeNotes);

        $this->notePurger->purgeOne($membership, $noteId);
        $this->auditLog->record(
            AuditLog::NOTE_PURGED,
            $membership->userId,
            'note',
            $noteId,
            $context->clientIp,
            ['workspace_id' => $membership->workspaceId],
        );

        return Response::noContent();
    }

    /**
     * Resolves {noteId} to a trashed note's workspace and requires the caller's permission there.
     * Unknown notes, active notes and notes in other people's workspaces all give the same 404.
     *
     * @return array{0: WorkspaceMembership, 1: string}
     */
    private function authorizeTrashedNote(Request $request, RequestContext $context, WorkspacePermission $permission): array
    {
        $noteId = (string) $request->routeParameter('noteId');
        $workspaceId = $this->noteService->findWorkspaceIdOfTrashedNote($noteId);
        if ($workspaceId === null) {
            throw HttpException::notFound();
        }

        $membership = $this->workspaceAuthorizer->requireWorkspacePermission($context->requireSession()->user, $workspaceId, $permission);

        return [$membership, $noteId];
    }
}
