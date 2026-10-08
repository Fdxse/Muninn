<?php

/**
 * Magic Link page (D059): opens a workspace, folder or note without signing in.
 *   link.php#token=<token>
 *
 * The token is in the URL fragment, which browsers never send to any server, so it cannot appear
 * in access logs or Referer headers. It stays in the address bar on purpose: the link is meant to
 * be bookmarked. assets/js/link-page.js trades it for a separate visit cookie through the API,
 * and every request after that is checked again by the API (revoked, dates, daily hours, target).
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require __DIR__ . '/includes/page.php';

renderPageStart('Shared notes', '', true);
?>
<nav class="navbar muninn-navbar" aria-label="Shared link">
    <div class="container-fluid px-3 gap-2">
        <span class="navbar-brand d-flex align-items-center gap-2 me-auto">
            <img src="assets/icons/muninn-symbol-64.png" alt="" width="32" height="32" class="rounded-circle">
            <span class="muninn-wordmark">Muninn</span>
        </span>
        <button type="button" class="btn btn-outline-light btn-sm d-none" id="close-link-button">
            <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Close
        </button>
    </div>
</nav>
<main id="main-content" class="container py-4 px-3 muninn-note-page">
    <p id="link-checking" role="status" class="text-muted-brand">Opening the link…</p>

    <div id="link-unavailable" class="alert alert-secondary d-none" role="alert" tabindex="-1">
        <h1 class="h5">This link does not work</h1>
        <p class="mb-0" id="link-unavailable-message">It may have expired or been switched off. Ask the person who gave it to you for a new one.</p>
    </div>

    <div id="link-closed" class="alert alert-secondary d-none" role="status" tabindex="-1">
        <h1 class="h5">Closed</h1>
        <p class="mb-0">This browser no longer has the link open. Open the link again to come back.</p>
    </div>

    <section id="link-content" class="d-none" aria-labelledby="link-heading">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
            <h1 id="link-heading" class="h4 mb-0 text-break"></h1>
            <span id="link-access-badge" class="badge text-bg-light border"></span>
        </div>
        <p id="link-summary" class="small text-muted-brand mb-3"></p>

        <div id="link-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>

        <!-- The list of notes (workspace and folder links). -->
        <div id="list-view" class="d-none">
            <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
                <div class="flex-grow-1" id="folder-filter-group">
                    <label for="folder-filter" class="form-label small mb-1">Folder</label>
                    <select class="form-select" id="folder-filter"></select>
                </div>
                <button type="button" class="btn btn-primary d-none" id="new-note-button">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> New note
                </button>
            </div>
            <p id="notes-empty" class="text-muted-brand d-none">No notes here yet.</p>
            <div id="notes-list" class="list-group"></div>
        </div>

        <!-- One note. -->
        <article id="note-view" class="d-none" aria-labelledby="note-heading">
            <button type="button" class="btn btn-link btn-sm px-0 mb-2" id="back-to-list-button">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All notes
            </button>
            <div class="d-flex flex-wrap align-items-start gap-2 mb-2">
                <h2 id="note-heading" class="h3 mb-0 flex-grow-1 text-break" tabindex="-1"></h2>
                <button type="button" class="btn btn-primary btn-sm d-none" id="edit-note-button">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                </button>
            </div>
            <p id="note-meta" class="small text-muted-brand mb-3"></p>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div id="note-content" class="muninn-note-content"></div>
                </div>
            </div>
        </article>

        <!-- Editing (write links only). Same editor as the signed-in note page. -->
        <form id="note-form" class="d-none" novalidate>
            <h2 class="h3 mb-3" id="note-form-heading">Edit note</h2>
            <div class="mb-3">
                <label for="note-title" class="form-label">Title</label>
                <input type="text" class="form-control" id="note-title" maxlength="200" aria-describedby="note-title-feedback">
                <div id="note-title-feedback" class="invalid-feedback"></div>
            </div>
            <div class="mb-3 d-none" id="note-folder-group">
                <label for="note-folder" class="form-label">Folder</label>
                <select class="form-select" id="note-folder" aria-describedby="note-folder-feedback"></select>
                <div id="note-folder-feedback" class="invalid-feedback"></div>
            </div>
            <div class="mb-3">
                <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-2">
                    <label for="note-body" class="form-label mb-0">Note <span class="text-muted-brand small">(Markdown)</span></label>
                    <div class="btn-group btn-group-sm muninn-editor-tabs" role="tablist" aria-label="Editor view">
                        <button type="button" class="btn active" id="editor-write-tab" role="tab" aria-selected="true" aria-controls="note-body">Write</button>
                        <button type="button" class="btn" id="editor-preview-tab" role="tab" aria-selected="false" aria-controls="editor-preview">Preview</button>
                    </div>
                </div>
                <div class="muninn-editor-toolbar btn-toolbar gap-1 mb-2" id="editor-toolbar" role="toolbar" aria-label="Formatting">
                    <button type="button" class="btn btn-light btn-sm" data-editor-action="bold" title="Bold"><i class="bi bi-type-bold" aria-hidden="true"></i><span class="visually-hidden">Bold</span></button>
                    <button type="button" class="btn btn-light btn-sm" data-editor-action="italic" title="Italic"><i class="bi bi-type-italic" aria-hidden="true"></i><span class="visually-hidden">Italic</span></button>
                    <button type="button" class="btn btn-light btn-sm" data-editor-action="heading" title="Heading"><i class="bi bi-type-h2" aria-hidden="true"></i><span class="visually-hidden">Heading</span></button>
                    <button type="button" class="btn btn-light btn-sm" data-editor-action="bullets" title="Bulleted list"><i class="bi bi-list-ul" aria-hidden="true"></i><span class="visually-hidden">Bulleted list</span></button>
                    <button type="button" class="btn btn-light btn-sm" data-editor-action="checklist" title="Checklist"><i class="bi bi-check2-square" aria-hidden="true"></i><span class="visually-hidden">Checklist</span></button>
                    <button type="button" class="btn btn-light btn-sm" data-editor-action="code" title="Code"><i class="bi bi-code-slash" aria-hidden="true"></i><span class="visually-hidden">Code</span></button>
                    <button type="button" class="btn btn-light btn-sm" data-editor-action="link" title="Link"><i class="bi bi-link-45deg" aria-hidden="true"></i><span class="visually-hidden">Link</span></button>
                    <button type="button" class="btn btn-light btn-sm" data-editor-action="image" title="Add image"><i class="bi bi-image" aria-hidden="true"></i><span class="visually-hidden">Add image</span></button>
                    <input type="file" id="editor-image-input" class="d-none" multiple tabindex="-1" aria-hidden="true">
                </div>
                <textarea class="form-control muninn-note-editor" id="note-body" rows="14" aria-describedby="note-body-feedback editor-status"></textarea>
                <div id="editor-preview" class="muninn-note-content muninn-editor-preview form-control d-none" role="tabpanel" aria-label="Preview"></div>
                <div id="note-body-feedback" class="invalid-feedback"></div>
                <p id="editor-status" class="small text-muted-brand mt-1 mb-0" aria-live="polite"></p>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="save-note-button">Save</button>
                <button type="button" class="btn btn-outline-secondary" id="cancel-edit-button">Cancel</button>
            </div>
        </form>
    </section>
</main>
<?php
renderPageEnd(['markdown-renderer.js', 'note-editor.js', 'link-page.js'], '', [
    'marked-18.0.14/marked.umd.js',
    'dompurify-3.4.16/purify.min.js',
    'highlightjs-11.12.0/highlight.min.js',
]);
