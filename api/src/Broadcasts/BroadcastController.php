<?php

declare(strict_types=1);

namespace Muninn\Api\Broadcasts;

use DateTimeImmutable;
use DateTimeZone;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\InputReader;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Logging\AuditLog;
use Muninn\Api\Notifications\AdminNotifier;
use Muninn\Api\Validation\TextRules;

/**
 * Administrator broadcast messages (decision D061).
 *
 * Signed-in everyday users (administrator accounts get an empty list and 404s):
 *   GET  /api/v1/broadcasts                 the broadcasts showing to the caller right now
 *   POST /api/v1/broadcasts/{id}/seen       a one-time banner has been shown
 *   POST /api/v1/broadcasts/{id}/dismiss    a sticky banner was closed with its X
 *   POST /api/v1/broadcasts/{id}/vote       {"option_ids": ["..."]}
 * System administrators:
 *   GET    /api/v1/admin/broadcasts         every broadcast, with vote results
 *   POST   /api/v1/admin/broadcasts         {"kind", "message", "starts_at", "ends_at",
 *                                            "allows_multiple_choices", "options": ["..."]}
 *   PATCH  /api/v1/admin/broadcasts/{id}    {"message", "starts_at", "ends_at"}
 *   DELETE /api/v1/admin/broadcasts/{id}
 */
final class BroadcastController
{
    private const MESSAGE_MAX_BYTES = 1000;
    private const OPTION_MAX_LENGTH = 100;

    public function __construct(
        private readonly BroadcastService $broadcastService,
        private readonly AuditLog $auditLog,
        private readonly AdminNotifier $adminNotifier,
    ) {
    }

    /** GET /api/v1/broadcasts */
    public function listShowing(Request $request, RequestContext $context): Response
    {
        $currentUser = $context->requireSession()->user;
        // The administrator writes the broadcasts; their own account is not the audience.
        if ($currentUser->isSystemAdmin) {
            return Response::data(['broadcasts' => []]);
        }

        return Response::data(['broadcasts' => $this->broadcastService->listShowingForUser($currentUser->id)]);
    }

    /** POST /api/v1/broadcasts/{id}/seen */
    public function markSeen(Request $request, RequestContext $context): Response
    {
        return $this->markDone($request, $context, BroadcastService::KIND_ONCE);
    }

    /** POST /api/v1/broadcasts/{id}/dismiss */
    public function dismiss(Request $request, RequestContext $context): Response
    {
        return $this->markDone($request, $context, BroadcastService::KIND_STICKY);
    }

    /** POST /api/v1/broadcasts/{id}/vote  {"option_ids": ["..."]} */
    public function vote(Request $request, RequestContext $context): Response
    {
        $currentUser = $context->requireSession()->user;
        if ($currentUser->isSystemAdmin) {
            throw HttpException::notFound();
        }
        $broadcastId = (string) $request->routeParameter('id');

        $requestBody = $request->jsonBody();
        $submittedOptionIds = $requestBody['option_ids'] ?? null;
        if (!is_array($submittedOptionIds) || !array_is_list($submittedOptionIds)) {
            throw HttpException::validation(['option_ids' => 'Choose an answer.']);
        }
        foreach ($submittedOptionIds as $submittedOptionId) {
            if (!is_string($submittedOptionId)) {
                throw HttpException::validation(['option_ids' => 'Choose one of the answers shown.']);
            }
        }

        $voteSummary = $this->broadcastService->vote($broadcastId, $currentUser->id, $submittedOptionIds);
        // Sent after this response, like the other administrator notifications (D057).
        $this->adminNotifier->broadcastVoted(
            $currentUser->displayName,
            $currentUser->username,
            $voteSummary['message'],
            $voteSummary['chosen_labels'],
            $broadcastId,
        );

        return Response::noContent();
    }

    /** GET /api/v1/admin/broadcasts */
    public function listAll(Request $request, RequestContext $context): Response
    {
        return Response::data([
            'broadcasts' => $this->broadcastService->listForAdministrator(),
            'max_options' => BroadcastService::MAXIMUM_OPTIONS,
        ]);
    }

    /** POST /api/v1/admin/broadcasts */
    public function create(Request $request, RequestContext $context): Response
    {
        $administrator = $context->requireSession()->user;
        $requestBody = $request->jsonBody();
        $fieldErrors = [];

        $kind = InputReader::optionalString($requestBody, 'kind');
        if (!in_array($kind, BroadcastService::KINDS, true)) {
            $fieldErrors['kind'] = 'Choose a one-time banner, a sticky banner or a vote.';
        }
        $message = $this->readMessage($requestBody, $fieldErrors);
        [$startsAtUtc, $endsAtUtc] = $this->readSchedule($requestBody, $fieldErrors, true);

        $allowsMultipleChoices = false;
        $optionLabels = [];
        if ($kind === BroadcastService::KIND_VOTE) {
            $multipleChoiceInput = $requestBody['allows_multiple_choices'] ?? false;
            if (!is_bool($multipleChoiceInput)) {
                $fieldErrors['allows_multiple_choices'] = 'Must be true or false.';
            }
            $allowsMultipleChoices = $multipleChoiceInput === true;
            $optionLabels = $this->readOptions($requestBody, $fieldErrors);
        }

        if ($fieldErrors !== []) {
            throw HttpException::validation($fieldErrors);
        }

        $broadcastId = $this->broadcastService->create(
            (string) $kind,
            $message,
            $allowsMultipleChoices,
            $startsAtUtc,
            $endsAtUtc,
            $optionLabels,
            $administrator->id,
        );
        $this->auditLog->record(AuditLog::BROADCAST_CREATED, $administrator->id, 'broadcast', $broadcastId, $context->clientIp, ['kind' => $kind]);

        return Response::data(['broadcast' => $this->broadcastService->findForAdministrator($broadcastId)], 201);
    }

    /**
     * PATCH /api/v1/admin/broadcasts/{id}  {"message", "starts_at", "ends_at"}
     *
     * All three are required, so the form always sends what it shows. The end may be in the
     * past here, which is how "End now" works.
     */
    public function update(Request $request, RequestContext $context): Response
    {
        $administrator = $context->requireSession()->user;
        $broadcastId = (string) $request->routeParameter('id');
        $requestBody = $request->jsonBody();
        $fieldErrors = [];

        // The kind and the options are fixed, so earlier votes keep meaning what the voter saw.
        foreach (['kind', 'options', 'allows_multiple_choices'] as $fixedFieldName) {
            if (array_key_exists($fixedFieldName, $requestBody)) {
                $fieldErrors[$fixedFieldName] = 'This cannot be changed. Delete the broadcast and create a new one.';
            }
        }
        $message = $this->readMessage($requestBody, $fieldErrors);
        [$startsAtUtc, $endsAtUtc] = $this->readSchedule($requestBody, $fieldErrors, false);
        if ($fieldErrors !== []) {
            throw HttpException::validation($fieldErrors);
        }

        $this->broadcastService->update($broadcastId, $message, $startsAtUtc, $endsAtUtc);
        $this->auditLog->record(AuditLog::BROADCAST_UPDATED, $administrator->id, 'broadcast', $broadcastId, $context->clientIp);

        return Response::data(['broadcast' => $this->broadcastService->findForAdministrator($broadcastId)]);
    }

    /** DELETE /api/v1/admin/broadcasts/{id} — also deletes its votes. */
    public function delete(Request $request, RequestContext $context): Response
    {
        $administrator = $context->requireSession()->user;
        $broadcastId = (string) $request->routeParameter('id');

        $this->broadcastService->delete($broadcastId);
        $this->auditLog->record(AuditLog::BROADCAST_DELETED, $administrator->id, 'broadcast', $broadcastId, $context->clientIp);

        return Response::noContent();
    }

    /** Shared by "seen" and "dismiss": records that the user is done with a banner. */
    private function markDone(Request $request, RequestContext $context, string $expectedKind): Response
    {
        $currentUser = $context->requireSession()->user;
        if ($currentUser->isSystemAdmin) {
            throw HttpException::notFound();
        }

        $this->broadcastService->markDone((string) $request->routeParameter('id'), $currentUser->id, $expectedKind);

        return Response::noContent();
    }

    /**
     * The message text: required, plain text, line breaks allowed.
     *
     * @param array<string, mixed> $requestBody
     * @param array<string, string> $fieldErrors
     */
    private function readMessage(array $requestBody, array &$fieldErrors): string
    {
        $message = trim(str_replace(["\r\n", "\r"], "\n", (string) InputReader::optionalString($requestBody, 'message')));
        if ($message === '') {
            $fieldErrors['message'] = 'This field is required.';
        } else {
            $messageError = TextRules::multiLineError($message, self::MESSAGE_MAX_BYTES);
            if ($messageError !== null) {
                $fieldErrors['message'] = $messageError;
            }
        }

        return $message;
    }

    /**
     * Start and end, both required, with an explicit offset (never the server's time zone).
     *
     * @param array<string, mixed> $requestBody
     * @param array<string, string> $fieldErrors
     * @param bool $endMustBeInFuture True when creating: a broadcast that already ended is a mistake.
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function readSchedule(array $requestBody, array &$fieldErrors, bool $endMustBeInFuture): array
    {
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $startsAtUtc = InputReader::optionalUtcTimestamp($requestBody, 'starts_at', $fieldErrors);
        $endsAtUtc = InputReader::optionalUtcTimestamp($requestBody, 'ends_at', $fieldErrors);
        if ($startsAtUtc === null && !isset($fieldErrors['starts_at'])) {
            $fieldErrors['starts_at'] = 'This field is required.';
        }
        if ($endsAtUtc === null && !isset($fieldErrors['ends_at'])) {
            $fieldErrors['ends_at'] = 'This field is required.';
        }
        if ($startsAtUtc !== null && $endsAtUtc !== null) {
            if ($endsAtUtc <= $startsAtUtc) {
                $fieldErrors['ends_at'] = 'The end must be after the start.';
            } elseif ($endMustBeInFuture && $endsAtUtc <= $nowUtc) {
                $fieldErrors['ends_at'] = 'The end must be in the future.';
            }
        }

        // The placeholders are never used: any error above stops the request first.
        return [$startsAtUtc ?? $nowUtc, $endsAtUtc ?? $nowUtc];
    }

    /**
     * The vote's answers: 2-5 different one-line labels.
     *
     * @param array<string, mixed> $requestBody
     * @param array<string, string> $fieldErrors
     * @return list<string>
     */
    private function readOptions(array $requestBody, array &$fieldErrors): array
    {
        $submittedOptions = $requestBody['options'] ?? null;
        if (!is_array($submittedOptions) || !array_is_list($submittedOptions)) {
            $fieldErrors['options'] = 'Write ' . BroadcastService::MINIMUM_OPTIONS . ' to ' . BroadcastService::MAXIMUM_OPTIONS . ' answers.';
            return [];
        }

        $optionLabels = [];
        foreach ($submittedOptions as $submittedOption) {
            if (!is_string($submittedOption)) {
                $fieldErrors['options'] = 'Every answer must be text.';
                return [];
            }
            $optionLabel = trim($submittedOption);
            // Empty answer boxes in the form are simply left out.
            if ($optionLabel === '') {
                continue;
            }
            $optionError = TextRules::singleLineError($optionLabel, self::OPTION_MAX_LENGTH, true);
            if ($optionError !== null) {
                $fieldErrors['options'] = 'Answer "' . mb_substr($optionLabel, 0, 30) . '": ' . $optionError;
                return [];
            }
            $optionLabels[] = $optionLabel;
        }

        $optionCount = count($optionLabels);
        if ($optionCount < BroadcastService::MINIMUM_OPTIONS || $optionCount > BroadcastService::MAXIMUM_OPTIONS) {
            $fieldErrors['options'] = 'Write ' . BroadcastService::MINIMUM_OPTIONS . ' to ' . BroadcastService::MAXIMUM_OPTIONS . ' answers.';
        } elseif (count(array_unique(array_map('mb_strtolower', $optionLabels))) !== $optionCount) {
            $fieldErrors['options'] = 'Every answer must be different.';
        }

        return $optionLabels;
    }
}
