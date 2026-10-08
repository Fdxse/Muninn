<?php

declare(strict_types=1);

namespace Muninn\Api\Tags;

use Muninn\Api\Http\Request;
use Muninn\Api\Http\RequestContext;
use Muninn\Api\Http\Response;
use Muninn\Api\Workspaces\WorkspaceAuthorizer;
use Muninn\Api\Workspaces\WorkspacePermission;

/**
 * Tag endpoint (requires a signed-in user):
 *   GET /api/v1/workspaces/{id}/tags   tags in use by active notes, with counts (Reader+)
 *
 * Tags are set through the note endpoints ("tags" field), so there is nothing to create or
 * delete here.
 */
final class TagController
{
    public function __construct(
        private readonly TagService $tagService,
        private readonly WorkspaceAuthorizer $workspaceAuthorizer,
    ) {
    }

    /** GET /api/v1/workspaces/{id}/tags */
    public function list(Request $request, RequestContext $context): Response
    {
        $membership = $this->workspaceAuthorizer->requireWorkspacePermission(
            $context->requireSession()->user,
            (string) $request->routeParameter('id'),
            WorkspacePermission::ReadNotes,
        );

        return Response::data(['tags' => $this->tagService->listInWorkspace($membership)]);
    }
}
