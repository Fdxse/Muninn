<?php

/**
 * Signed-in home: the notes of one workspace. A workspace picker switches between the user's
 * personal workspace and the shared workspaces they belong to.
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

        <p id="workspace-role-hint" class="small text-muted-brand mb-3"></p>
        <div id="notes-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
        <p id="notes-empty" class="text-muted-brand d-none">No notes here yet.</p>
        <div id="notes-list" class="list-group shadow-sm"></div>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'notes-home.js']);
