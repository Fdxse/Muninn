<?php

/**
 * One note: read it, edit it, archive it, open its history, or move it to Trash.
 *   note.php?id=<note id>              open an existing note
 *   note.php?workspace=<workspace id>  write a new note in that workspace
 *
 * The note is shown as sanitised, rendered Markdown (assets/js/markdown-renderer.js). The editor
 * is a textarea with a Markdown toolbar, preview and image upload/paste (assets/js/note-editor.js),
 * plus a list of the note's images where each one can be removed.
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
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="archive-note-button">
                        <i class="bi bi-archive" aria-hidden="true"></i> <span id="archive-note-label">Archive</span>
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="trash-note-button">
                        <i class="bi bi-trash" aria-hidden="true"></i> Delete
                    </button>
                </div>
            </div>
            <div id="archived-notice" class="alert alert-secondary py-2 small d-none" role="status">
                <i class="bi bi-archive" aria-hidden="true"></i> This note is archived. It is left out of the note list.
            </div>
            <p class="small text-muted-brand mb-2">
                <span id="note-meta"></span>
                · <a id="history-link" href="#"><i class="bi bi-clock-history" aria-hidden="true"></i> <span id="history-link-label">History</span></a>
            </p>
            <div class="d-flex flex-wrap gap-1 mb-3" id="note-organisation"></div>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div id="note-content" class="muninn-note-content"></div>
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
            <div class="row g-2 mb-3">
                <div class="col-12 col-sm-5">
                    <label for="note-folder" class="form-label">Folder</label>
                    <select class="form-select" id="note-folder" aria-describedby="note-folder-feedback">
                        <option value="">No folder</option>
                    </select>
                    <div id="note-folder-feedback" class="invalid-feedback"></div>
                </div>
                <div class="col-12 col-sm-7">
                    <label for="note-tags" class="form-label">Tags <span class="text-muted-brand small">(separate with commas)</span></label>
                    <input type="text" class="form-control" id="note-tags" list="note-tag-suggestions" autocomplete="off"
                           autocapitalize="none" aria-describedby="note-tags-feedback">
                    <datalist id="note-tag-suggestions"></datalist>
                    <div id="note-tags-feedback" class="invalid-feedback"></div>
                </div>
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
            <!-- The note's images, each removable for good (filled by note-page.js; hidden when there are none). -->
            <section id="note-images" class="mb-3 d-none" aria-labelledby="note-images-heading">
                <h2 id="note-images-heading" class="h6 mb-2">Images on this note</h2>
                <ul id="note-image-list" class="list-unstyled mb-0 muninn-note-image-list"></ul>
            </section>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" id="save-note-button">Save</button>
                <button type="button" class="btn btn-outline-secondary" id="cancel-edit-button">Cancel</button>
            </div>
        </form>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'markdown-renderer.js', 'note-editor.js', 'note-page.js'], '', [
    'marked-18.0.14/marked.umd.js',
    'dompurify-3.4.16/purify.min.js',
    'highlightjs-11.12.0/highlight.min.js',
]);
