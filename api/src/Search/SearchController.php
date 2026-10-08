<?php

declare(strict_types=1);

namespace Muninn\Api\Search;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Validation\TextRules;
use Muninn\Api\Workspaces\WorkspaceAuthorizer;
use Muninn\Api\Workspaces\WorkspacePermission;

/**
 * Search endpoint (requires a signed-in user):
 *   GET /api/v1/search?q=<words>[&archived=1]
 *
 * Searches every workspace the caller may read (Reader and up), as decided by
 * WorkspaceAuthorizer; trashed notes never, archived notes only with archived=1.
 */
final class SearchController
{
    /** Longest query accepted, in characters. */
    private const QUERY_MAX_LENGTH = 200;

    public function __construct(
        private readonly SearchService $searchService,
        private readonly WorkspaceAuthorizer $workspaceAuthorizer,
    ) {
    }

    /** GET /api/v1/search */
    public function search(Request $request, RequestContext $context): Response
    {
        // Tabs and line breaks only separate words, so fold them into spaces before validating.
        $queryText = trim((string) preg_replace('/\s+/u', ' ', (string) $request->queryParameter('q')));
        $queryError = TextRules::singleLineError($queryText, self::QUERY_MAX_LENGTH, true);
        if ($queryError !== null) {
            throw HttpException::validation(['q' => $queryError]);
        }

        $searchTerms = SearchService::searchTerms($queryText);
        $readableWorkspaceIds = $this->workspaceAuthorizer->workspaceIdsWithPermission(
            $context->requireSession()->user,
            WorkspacePermission::ReadNotes,
        );
        $results = $this->searchService->search($readableWorkspaceIds, $searchTerms, $request->queryParameter('archived') === '1');

        return Response::data([
            'results' => $results,
            // The words actually searched for, so the page can highlight them.
            'terms' => $searchTerms,
            // True when there may be more matches than were returned.
            'limited' => count($results) >= SearchService::RESULT_LIMIT,
        ]);
    }
}
