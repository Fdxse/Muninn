<?php

/**
 * Signed-in home: the notes of one workspace. A workspace picker switches between the user's
 * personal workspace and the shared workspaces they belong to; the folder picker and tag chips
 * narrow the list, and Editors can manage the workspace's folders. A switch shows the workspace's
 * Archive instead, and a link leads to its Trash. Search lives in the top menu (search.php), so it
 * is reachable from every page and the note list starts higher on a phone.
 * Signed-out visitors are sent to login.php by assets/js/app-shell.js.
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require __DIR__ . '/includes/page.php';

renderPageStart('Notes');
renderAppNavbar();
?>
<main id="main-content" class="container py-4 px-3">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <!-- Shown to system administrator accounts, which have no notes (decision D025). -->
    <div id="admin-account-notice" class="alert alert-secondary d-none" role="status">
        This is an administrator account. It manages users and invitations and has no notes.
        Sign in with your everyday account to write notes.
        <a href="admin/overview.php" class="alert-link d-block mt-2">Open the admin overview</a>
    </div>

    <section id="shell-content" class="d-none" aria-labelledby="notes-heading">
        <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
            <div class="flex-grow-1">
                <h1 id="notes-heading" class="h3 mb-2">Notes</h1>
                <label for="workspace-picker" class="visually-hidden">Workspace</label>
                <select id="workspace-picker" class="form-select"></select>
            </div>
            <a id="new-note-link" class="btn btn-primary d-none" href="note.php">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> New note
            </a>
        </div>

        <p id="workspace-role-hint" class="small text-muted-brand mb-2"></p>

        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <div class="btn-group btn-group-sm muninn-toggle-group" role="group" aria-label="Which notes to show">
                <button type="button" class="btn active" id="show-notes-button" aria-pressed="true">
                    <i class="bi bi-journal-text" aria-hidden="true"></i> Notes
                </button>
                <button type="button" class="btn" id="show-archive-button" aria-pressed="false">
                    <i class="bi bi-archive" aria-hidden="true"></i> Archive
                </button>
            </div>
            <a class="btn btn-outline-secondary btn-sm" id="trash-link" href="trash.php">
                <i class="bi bi-trash" aria-hidden="true"></i> Trash
            </a>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2 mb-2" id="folder-filter-row">
            <label for="folder-filter" class="visually-hidden">Folder</label>
            <select id="folder-filter" class="form-select form-select-sm muninn-folder-filter"></select>
            <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="manage-folders-button"
                    data-bs-toggle="modal" data-bs-target="#folders-modal">
                <i class="bi bi-folder-plus" aria-hidden="true"></i> Folders
            </button>
        </div>
        <div id="tag-filter" class="d-flex flex-wrap gap-1 mb-3" role="group" aria-label="Filter by tag"></div>
        <div id="notes-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
        <p id="archive-hint" class="small text-muted-brand d-none">Archived notes stay readable and searchable on request, but leave the normal note list.</p>
        <p id="notes-empty" class="text-muted-brand d-none">No notes here yet.</p>
        <div id="notes-list" class="list-group shadow-sm"></div>
    </section>

    <!-- Folder management (Editors and up; the API checks the role again). -->
    <div class="modal fade" id="folders-modal" tabindex="-1" aria-labelledby="folders-modal-heading" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="folders-modal-heading">Folders</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="folders-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
                    <form id="new-folder-form" class="row g-2 mb-3" novalidate>
                        <div class="col-12 col-sm-6">
                            <label for="new-folder-name" class="visually-hidden">New folder name</label>
                            <input type="text" class="form-control" id="new-folder-name" maxlength="100" placeholder="New folder name" required>
                        </div>
                        <div class="col">
                            <label for="new-folder-parent" class="visually-hidden">Create inside</label>
                            <select class="form-select" id="new-folder-parent"></select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary text-nowrap">
                                <i class="bi bi-plus-lg" aria-hidden="true"></i> Add
                            </button>
                        </div>
                    </form>
                    <p class="small text-muted-brand">
                        Folders can be up to 3 levels deep. Deleting a folder never deletes notes: its notes and
                        sub-folders move up one level, into the folder above it or to "No folder".
                    </p>
                    <ul id="folder-management-list" class="list-group"></ul>
                </div>
            </div>
        </div>
    </div>
</main>
<?php
renderPageEnd(['app-shell.js', 'notes-home.js']);
