<?php

declare(strict_types=1);

namespace Muninn\Api\MagicLinks;

use DateTimeImmutable;
use DateTimeZone;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\InputReader;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Validation\TextRules;
use Muninn\Api\Workspaces\WorkspaceAuthorizer;
use Muninn\Api\Workspaces\WorkspacePermission;

/**
 * Managing Magic Links (D059), for signed-in users:
 *   GET    /api/v1/workspaces/{id}/magic-links   list the workspace's links (Admin+)
 *   POST   /api/v1/workspaces/{id}/magic-links   create one; the full link is in the answer, once (Admin+)
 *   DELETE /api/v1/magic-links/{id}              revoke (Admin+ of the link's workspace)
 * and for system administrators (an overview without note data, like D050):
 *   GET    /api/v1/admin/magic-links             every link
 *   DELETE /api/v1/admin/magic-links/{id}        revoke any link
 *
 * Creating and managing links needs the same role as managing members (Admin or Owner),
 * because a link lets people in much like adding a member does.
 */
final class MagicLinkController
{
    private const LABEL_MAX_LENGTH = 100;

    public function __construct(
        private readonly MagicLinkService $magicLinkService,
        private readonly WorkspaceAuthorizer $workspaceAuthorizer,
        private readonly AuditLog $auditLog,
        private readonly string $frontendBaseUrl,
        private readonly int $defaultValidDays,
        private readonly int $maximumValidDays,
    ) {
    }

    /** GET /api/v1/workspaces/{id}/magic-links */
    public function list(Request $request, RequestContext $context): Response
    {
        $membership = $this->workspaceAuthorizer->requireWorkspacePermission(
            $context->requireSession()->user,
            (string) $request->routeParameter('id'),
            WorkspacePermission::ManageMembers,
        );

        return Response::data([
            'magic_links' => $this->magicLinkService->listForWorkspace($membership),
            'timezone' => $this->magicLinkService->windowTimeZoneName(),
            'default_valid_days' => $this->defaultValidDays,
            'max_valid_days' => $this->maximumValidDays,
        ]);
    }

    /**
     * POST /api/v1/workspaces/{id}/magic-links
     *   {"label": "...", "target_type": "workspace|folder|note", "target_id": "...",
     *    "permission": "read|write", "valid_from": "<ISO 8601>", "valid_until": "<ISO 8601>",
     *    "daily_start_time": "07:00", "daily_end_time": "18:00"}
     *
     * Answers 201 with the link and "link_url". The URL holds the raw token and is never shown again.
     */
    public function create(Request $request, RequestContext $context): Response
    {
        $membership = $this->workspaceAuthorizer->requireWorkspacePermission(
            $context->requireSession()->user,
            (string) $request->routeParameter('id'),
            WorkspacePermission::ManageMembers,
        );
        $linkInput = $this->readInput($request->jsonBody());

        $createdLink = $this->magicLinkService->create($membership, $linkInput);
        $this->auditLog->record(
            AuditLog::MAGIC_LINK_CREATED,
            $membership->userId,
            'magic_link',
            (string) $createdLink['magic_link']['id'],
            $context->clientIp,
            [
                'workspace_id' => $membership->workspaceId,
                'target_type' => $linkInput->targetType,
                'target_id' => $linkInput->targetId,
                'permission' => $linkInput->permission,
                'valid_until' => $createdLink['magic_link']['valid_until'],
            ],
        );

        return Response::data([
            'magic_link' => $createdLink['magic_link'],
            // The token sits after "#", so browsers never send it to the web server (no access logs).
            'link_url' => rtrim($this->frontendBaseUrl, '/') . '/link.php#token=' . $createdLink['raw_token'],
        ], 201);
    }

    /** DELETE /api/v1/magic-links/{id} */
    public function revoke(Request $request, RequestContext $context): Response
    {
        $linkId = (string) $request->routeParameter('id');
        $workspaceId = $this->magicLinkService->findWorkspaceIdOfLink($linkId);
        if ($workspaceId === null) {
            throw HttpException::notFound();
        }
        $membership = $this->workspaceAuthorizer->requireWorkspacePermission(
            $context->requireSession()->user,
            $workspaceId,
            WorkspacePermission::ManageMembers,
        );

        if ($this->magicLinkService->revoke($membership, $linkId)) {
            $this->auditLog->record(
                AuditLog::MAGIC_LINK_REVOKED,
                $membership->userId,
                'magic_link',
                $linkId,
                $context->clientIp,
                ['workspace_id' => $workspaceId],
            );
        }

        return Response::noContent();
    }

    /** GET /api/v1/admin/magic-links */
    public function listForAdministrator(Request $request, RequestContext $context): Response
    {
        return Response::data(['magic_links' => $this->magicLinkService->listForAdministrator()]);
    }

    /** DELETE /api/v1/admin/magic-links/{id} */
    public function revokeAsAdministrator(Request $request, RequestContext $context): Response
    {
        $linkId = (string) $request->routeParameter('id');
        $administratorUserId = $context->requireSession()->user->id;

        $workspaceId = $this->magicLinkService->revokeAsAdministrator($linkId, $administratorUserId);
        $this->auditLog->record(
            AuditLog::MAGIC_LINK_REVOKED,
            $administratorUserId,
            'magic_link',
            $linkId,
            $context->clientIp,
            ['workspace_id' => $workspaceId, 'by_system_administrator' => true],
        );

        return Response::noContent();
    }

    /**
     * Validates a create request.
     *
     * @param array<string, mixed> $requestBody
     * @throws HttpException 422 listing every invalid field.
     */
    private function readInput(array $requestBody): MagicLinkInput
    {
        $fieldErrors = [];

        $label = trim((string) InputReader::optionalString($requestBody, 'label'));
        $labelError = TextRules::singleLineError($label, self::LABEL_MAX_LENGTH, true);
        if ($labelError !== null) {
            $fieldErrors['label'] = $labelError;
        }

        $targetType = InputReader::optionalString($requestBody, 'target_type');
        $targetId = null;
        if (!in_array($targetType, [MagicLinkAccess::TARGET_WORKSPACE, MagicLinkAccess::TARGET_FOLDER, MagicLinkAccess::TARGET_NOTE], true)) {
            $fieldErrors['target_type'] = 'Choose the workspace, a folder or a note.';
        } elseif ($targetType !== MagicLinkAccess::TARGET_WORKSPACE) {
            $targetId = InputReader::optionalString($requestBody, 'target_id');
            if ($targetId === null || $targetId === '') {
                $fieldErrors['target_id'] = $targetType === MagicLinkAccess::TARGET_FOLDER ? 'Choose a folder.' : 'Choose a note.';
            }
        }

        // Read is the default; write must be asked for explicitly (D059).
        $permission = InputReader::optionalString($requestBody, 'permission') ?? MagicLinkAccess::PERMISSION_READ;
        if ($permission !== MagicLinkAccess::PERMISSION_READ && $permission !== MagicLinkAccess::PERMISSION_WRITE) {
            $fieldErrors['permission'] = 'Choose read or write.';
        }

        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $latestAllowedEndUtc = $nowUtc->modify('+' . $this->maximumValidDays . ' days');

        $validFromUtc = InputReader::optionalUtcTimestamp($requestBody, 'valid_from', $fieldErrors) ?? $nowUtc;
        $validUntilUtc = InputReader::optionalUtcTimestamp($requestBody, 'valid_until', $fieldErrors)
            ?? $validFromUtc->modify('+' . $this->defaultValidDays . ' days');
        if (!isset($fieldErrors['valid_from']) && !isset($fieldErrors['valid_until'])) {
            if ($validUntilUtc <= $validFromUtc) {
                $fieldErrors['valid_until'] = 'The end must be after the start.';
            } elseif ($validUntilUtc <= $nowUtc) {
                $fieldErrors['valid_until'] = 'The end must be in the future.';
            } elseif ($validUntilUtc > $latestAllowedEndUtc) {
                // Every link expires (D059): no links that work forever.
                $fieldErrors['valid_until'] = 'A link can work for at most ' . $this->maximumValidDays . ' days from today.';
            }
        }

        [$dailyStartTime, $dailyEndTime] = self::readDailyWindow($requestBody, $fieldErrors);

        if ($fieldErrors !== []) {
            throw HttpException::validation($fieldErrors);
        }

        return new MagicLinkInput(
            label: $label,
            targetType: (string) $targetType,
            targetId: $targetId,
            permission: $permission,
            validFromUtc: $validFromUtc,
            validUntilUtc: $validUntilUtc,
            dailyStartTime: $dailyStartTime,
            dailyEndTime: $dailyEndTime,
        );
    }

    /**
     * Reads the optional daily window: both times or neither, "HH:MM", and not equal.
     *
     * @param array<string, mixed> $requestBody
     * @param array<string, string> $fieldErrors Receives errors, if any.
     * @return array{0: string|null, 1: string|null}
     */
    private static function readDailyWindow(array $requestBody, array &$fieldErrors): array
    {
        $startInput = trim((string) InputReader::optionalString($requestBody, 'daily_start_time'));
        $endInput = trim((string) InputReader::optionalString($requestBody, 'daily_end_time'));
        if ($startInput === '' && $endInput === '') {
            return [null, null];
        }

        $dailyStartTime = MagicLinkSchedule::parseTimeOfDay($startInput);
        $dailyEndTime = MagicLinkSchedule::parseTimeOfDay($endInput);
        if ($dailyStartTime === null) {
            $fieldErrors['daily_start_time'] = 'Use a time like 07:00, or leave both times empty.';
        }
        if ($dailyEndTime === null) {
            $fieldErrors['daily_end_time'] = 'Use a time like 18:00, or leave both times empty.';
        }
        if ($dailyStartTime !== null && $dailyStartTime === $dailyEndTime) {
            $fieldErrors['daily_end_time'] = 'The end time must differ from the start time.';
        }

        return [$dailyStartTime, $dailyEndTime];
    }
}
