<?php

/**
 * Magic Link overview for system administrators (D059): every link in the installation, who made
 * it, in which workspace, and whether it still works, with a Revoke button. Like the workspace
 * overview (D050) it shows no note data: no link names, folder names or note titles.
 * Non-admins get 404 from the API and this page then shows "not available".
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require dirname(__DIR__) . '/includes/page.php';

renderPageStart('Magic Links', '../');
renderAppNavbar('../');
?>
<main id="main-content" class="container py-4 px-3">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <div id="admin-not-available" class="alert alert-secondary d-none" role="alert">
        This page is not available.
    </div>

    <section id="shell-content" class="d-none" aria-labelledby="admin-links-heading">
        <a href="workspaces.php" class="small d-inline-block mb-2">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Shared workspaces
        </a>
        <h1 id="admin-links-heading" class="h3 mb-1">Magic Links</h1>
        <p class="small text-muted-brand mb-3">
            Every link that opens a workspace, folder or note without signing in. Workspace Admins and
            Owners create and manage them; you can revoke any of them, for example when one was shared
            by mistake. You cannot see what the links open.
        </p>
        <div id="admin-links-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
        <p id="admin-links-empty" class="text-muted-brand d-none">No Magic Links yet.</p>
        <ul id="admin-links-list" class="list-group mb-4"></ul>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'admin-magic-links.js'], '../');
