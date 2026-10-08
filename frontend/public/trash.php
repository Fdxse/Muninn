<?php

/**
 * The Trash of one workspace: deleted notes, when each goes for good, and buttons to restore
 * them (Editors and up) or delete them for good right away (Admins and Owners, D039).
 *   trash.php?workspace=<workspace id>
 *
 * Buttons appear according to the user's role, but the API decides every action.
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require __DIR__ . '/includes/page.php';

renderPageStart('Trash');
renderAppNavbar();
?>
<main id="main-content" class="container py-4 px-3">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <div id="trash-not-available" class="alert alert-secondary d-none" role="alert">
        This workspace is not available. <a href="index.php" class="alert-link">Back to notes</a>
    </div>

    <section id="shell-content" class="d-none" aria-labelledby="trash-heading">
        <a id="back-link" href="index.php" class="small d-inline-block mb-2">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Notes
        </a>
        <div class="d-flex flex-wrap align-items-end gap-2 mb-2">
            <h1 id="trash-heading" class="h3 mb-0 flex-grow-1">Trash</h1>
            <button type="button" class="btn btn-outline-danger btn-sm d-none" id="empty-trash-button">
                <i class="bi bi-trash3" aria-hidden="true"></i> Empty Trash
            </button>
        </div>
        <p class="small text-muted-brand mb-3" id="trash-explanation"></p>

        <div id="trash-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
        <div id="trash-status" class="alert alert-success d-none" role="status"></div>
        <p id="trash-empty" class="text-muted-brand d-none">The Trash is empty.</p>
        <ul id="trash-list" class="list-group shadow-sm"></ul>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'trash.js']);
