<?php

/**
 * One note: read it, edit it, or move it to Trash.
 *   note.php?id=<note id>              open an existing note
 *   note.php?workspace=<workspace id>  write a new note in that workspace
 *
 * Week 2 shows the Markdown source as plain text; rendered Markdown arrives in Week 3.
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require __DIR__ . '/includes/page.php';

renderPageStart('Note');
renderAppNavbar();
?>
<main id="main-content" class="container py-4 px-3 muninn-note-page">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <div id="note-not-available" class="alert alert-secondary d-none" role="alert">
        This note is not available. It may have been deleted, or you may not have access to it.
        <a href="index.php" class="alert-link">Back to notes</a>
    </div>

    <section id="shell-content" class="d-none" aria-labelledby="note-heading">
        <a id="back-link" href="index.php" class="small d-inline-block mb-2">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Notes
        </a>

        <div id="note-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>

        <!-- Read view -->
        <article id="note-view" class="d-none">
            <div class="d-flex flex-wrap align-items-start gap-2 mb-2">
                <h1 id="note-heading" class="h3 mb-0 flex-grow-1 text-break"></h1>
                <div class="d-flex gap-2" id="note-view-actions">
                    <button type="button" class="btn btn-primary btn-sm" id="edit-note-button">
                        <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="trash-note-button">
                        <i class="bi bi-trash" aria-hidden="true"></i> Delete
                    </button>
                </div>
            </div>
            <p class="small text-muted-brand mb-3" id="note-meta"></p>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div id="note-content" class="muninn-note-source"></div>
                </div>
            </div>
        </article>

        <!-- Edit view (also used for new notes) -->
        <form id="note-form" class="d-none" novalidate>
            <h1 class="h3 mb-3" id="note-form-heading">Edit note</h1>
            <div class="mb-3">
                <label for="note-title" class="form-label">Title</label>
                <input type="text" class="form-control" id="note-title" maxlength="200" aria-describedby="note-title-feedback">
                <div id="note-title-feedback" class="invalid-feedback"></div>
            </div>
            <div class="mb-3">
                <label for="note-body" class="form-label">Note <span class="text-muted-brand small">(Markdown)</span></label>
                <textarea class="form-control muninn-note-editor" id="note-body" rows="14" aria-describedby="note-body-feedback"></textarea>
                <div id="note-body-feedback" class="invalid-feedback"></div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="save-note-button">Save</button>
                <button type="button" class="btn btn-outline-secondary" id="cancel-edit-button">Cancel</button>
            </div>
        </form>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'note-page.js']);
