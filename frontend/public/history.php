<?php

/**
 * Version history of one note (D009, D037): the earlier versions kept (at most 100, shown as
 * "used / limit"), each viewable as rendered Markdown, and restorable by Editors and up.
 *   history.php?id=<note id>
 *
 * Restoring saves the old version as the note's newest state, so it can itself be undone.
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require __DIR__ . '/includes/page.php';

renderPageStart('History');
renderAppNavbar();
?>
<main id="main-content" class="container py-4 px-3 muninn-note-page">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <div id="history-not-available" class="alert alert-secondary d-none" role="alert">
        This note is not available. It may have been deleted, or you may not have access to it.
        <a href="index.php" class="alert-link">Back to notes</a>
    </div>

    <section id="shell-content" class="d-none" aria-labelledby="history-heading">
        <a id="back-link" href="index.php" class="small d-inline-block mb-2">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Note
        </a>
        <h1 id="history-heading" class="h3 mb-1 text-break">History</h1>
        <p class="small text-muted-brand mb-3" id="history-usage"></p>

        <div id="history-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
        <p id="history-empty" class="text-muted-brand d-none">
            No earlier versions yet. When this note is changed, the version it replaces is kept here.
        </p>

        <div class="row g-3">
            <div class="col-12 col-md-5">
                <div id="version-list" class="list-group shadow-sm muninn-version-list" aria-label="Earlier versions"></div>
            </div>
            <div class="col-12 col-md-7">
                <!-- The selected version, rendered read-only. -->
                <article id="version-view" class="card border-0 shadow-sm d-none" aria-labelledby="version-title" tabindex="-1">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-start gap-2 mb-2">
                            <h2 id="version-title" class="h5 mb-0 flex-grow-1 text-break"></h2>
                            <button type="button" class="btn btn-primary btn-sm d-none" id="restore-version-button">
                                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restore this version
                            </button>
                        </div>
                        <p class="small text-muted-brand mb-2" id="version-meta"></p>
                        <div class="d-flex flex-wrap gap-1 mb-3" id="version-organisation"></div>
                        <div id="version-content" class="muninn-note-content"></div>
                    </div>
                </article>
            </div>
        </div>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'markdown-renderer.js', 'note-history.js'], '', [
    'marked-18.0.14/marked.umd.js',
    'dompurify-3.4.16/purify.min.js',
]);
