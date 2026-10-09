<?php

declare(strict_types=1);

namespace Muninn\Api\Admin;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;

/**
 * The administrator's overview page (D060). System administrators only (the router answers 404
 * to everyone else):
 *
 *   GET  /api/v1/admin/overview                          counts, sizes, heat maps, warnings
 *   GET  /api/v1/admin/sign-in-attempts[?reveal=true]    sign-in attempts; reveal is audited
 *   POST /api/v1/admin/audit-log/archive                 zip and delete entries past the cut-off
 *   GET  /api/v1/admin/audit-log/archives/{archiveId}    download one archive (its name without .zip)
 */
final class SystemOverviewController
{
    public function __construct(
        private readonly SystemOverviewService $overviewService,
        private readonly SignInAttemptReport $signInAttemptReport,
        private readonly AuditLogArchiver $auditLogArchiver,
        private readonly AuditLog $auditLog,
    ) {
    }

    /** GET /api/v1/admin/overview */
    public function overview(Request $request, RequestContext $context): Response
    {
        $overview = $this->overviewService->build();
        // Usernames typed at failed sign-ins are always masked here; revealing them is a separate, audited call.
        $overview['sign_in_attempts'] = $this->signInAttemptReport->build(false);

        return Response::data($overview);
    }

    /** GET /api/v1/admin/sign-in-attempts[?reveal=true] */
    public function signInAttempts(Request $request, RequestContext $context): Response
    {
        $revealUsernames = $request->queryParameter('reveal') === 'true';
        if ($revealUsernames) {
            // Typed usernames can contain passwords typed into the wrong field, so every look is on record.
            $this->auditLog->record(AuditLog::SIGN_IN_USERNAMES_REVEALED, $context->requireSession()->user->id, null, null, $context->clientIp);
        }

        return Response::data(['sign_in_attempts' => $this->signInAttemptReport->build($revealUsernames)]);
    }

    /** POST /api/v1/admin/audit-log/archive */
    public function archive(Request $request, RequestContext $context): Response
    {
        try {
            $newArchive = $this->auditLogArchiver->archiveOldEntries();
        } catch (AuditLogArchiveException $archiveFailure) {
            $statusCode = match ($archiveFailure->errorCode) {
                'archive_running' => 409,
                'zip_unavailable' => 503,
                default => 500,
            };
            throw new HttpException($statusCode, $archiveFailure->errorCode, $archiveFailure->getMessage());
        }

        if ($newArchive === null) {
            throw HttpException::conflict('nothing_to_archive', 'No audit log entries are old enough to archive.');
        }

        // Recorded after the deletion, so this entry itself stays in the database.
        $this->auditLog->record(AuditLog::AUDIT_LOG_ARCHIVED, $context->requireSession()->user->id, null, null, $context->clientIp, [
            'file_name' => $newArchive['file_name'],
            'entry_count' => $newArchive['entry_count'],
            'cutoff' => $newArchive['cutoff'],
        ]);

        return Response::data(['archive' => $newArchive], 201);
    }

    /** GET /api/v1/admin/audit-log/archives/{archiveId} */
    public function downloadArchive(Request $request, RequestContext $context): Response
    {
        $requestedFileName = $request->routeParameter('archiveId') . '.zip';
        $archivePath = $this->auditLogArchiver->archivePath($requestedFileName);
        if ($archivePath === null) {
            throw HttpException::notFound();
        }
        $archiveBytes = file_get_contents($archivePath);
        if ($archiveBytes === false) {
            throw HttpException::notFound();
        }

        $this->auditLog->record(AuditLog::AUDIT_LOG_ARCHIVE_DOWNLOADED, $context->requireSession()->user->id, null, null, $context->clientIp, [
            'file_name' => $requestedFileName,
        ]);

        return Response::file($archiveBytes, 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $requestedFileName . '"');
    }
}
