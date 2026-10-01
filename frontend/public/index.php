<?php

/**
 * Signed-in home. In Week 1 it only proves the session works; notes arrive in Week 2.
 * Signed-out visitors are sent to login.php by assets/js/app-shell.js.
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require __DIR__ . '/includes/page.php';

renderPageStart('Home');
renderAppNavbar();
?>
<main id="main-content" class="container py-4 px-3">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <section id="shell-content" class="d-none" aria-labelledby="home-heading">
        <h1 id="home-heading" class="h3 mb-3">Welcome, <span id="home-display-name"></span></h1>
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <p class="mb-2">You are signed in to Muninn.</p>
                <p class="mb-0 text-muted-brand">Workspaces and notes are coming next.</p>
            </div>
        </div>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js']);
