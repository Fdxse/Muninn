<?php

/**
 * Search across every workspace the user can read (D011, D038).
 *   search.php?q=<words>[&archived=1]
 *
 * The API decides what may be searched; Trash is never searched, the Archive only on request.
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require __DIR__ . '/includes/page.php';

renderPageStart('Search');
renderAppNavbar();
?>
<main id="main-content" class="container py-4 px-3">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <div id="admin-account-notice" class="alert alert-secondary d-none" role="status">
        Administrator accounts have no notes to search. Sign in with your everyday account.
    </div>

    <section id="shell-content" class="d-none" aria-labelledby="search-heading">
        <h1 id="search-heading" class="h3 mb-3">Search</h1>

        <form id="search-form" class="mb-3" role="search" novalidate>
            <div class="d-flex gap-2 mb-2">
                <label for="search-input" class="visually-hidden">Search all notes</label>
                <input type="search" class="form-control" id="search-input" name="q" maxlength="200"
                       placeholder="Words in a title, text or tag" autocomplete="off" enterkeyhint="search">
                <button type="submit" class="btn btn-primary text-nowrap">
                    <i class="bi bi-search" aria-hidden="true"></i> Search
                </button>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="include-archived" name="archived" value="1">
                <label class="form-check-label small" for="include-archived">Include archived notes</label>
            </div>
        </form>

        <div id="search-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
        <p id="search-summary" class="small text-muted-brand" aria-live="polite"></p>
        <div id="search-results" class="list-group shadow-sm"></div>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'search.js']);
