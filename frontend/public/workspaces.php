<?php

/**
 * The user's workspaces: their personal one and the shared ones they belong to, a form to create
 * a new shared workspace (any user may, and becomes its Owner; decision D030), and the Shared
 * Workspaces the administrator runs, which users ask to join (D067).
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require __DIR__ . '/includes/page.php';

renderPageStart('Workspaces');
renderAppNavbar();
?>
<main id="main-content" class="container py-4 px-3">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <div id="admin-account-notice" class="alert alert-secondary d-none" role="status">
        Administrator accounts have no workspaces. Sign in with your everyday account.
    </div>

    <section id="shell-content" class="d-none" aria-labelledby="workspaces-heading">
        <h1 id="workspaces-heading" class="h3 mb-3">Workspaces</h1>

        <div id="workspaces-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
        <div id="workspaces-list" class="list-group shadow-sm mb-4"></div>

        <!-- Shared Workspaces (D067): run by the administrator; anyone may ask to join. -->
        <section id="open-workspaces-section" class="mb-4 d-none" aria-labelledby="open-workspaces-heading">
            <h2 id="open-workspaces-heading" class="h5 mb-1">Shared Workspaces</h2>
            <p class="small text-muted-brand mb-2">
                Workspaces for everyone, run by the administrator. Ask to join, and the administrator decides.
            </p>
            <div id="open-workspaces-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
            <div id="open-workspaces-status" class="visually-hidden" role="status" aria-live="polite"></div>
            <ul id="open-workspaces-list" class="list-group shadow-sm"></ul>
        </section>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3">New shared workspace</h2>
                <p class="small text-muted-brand">You become its Owner and can add other users by their username.</p>
                <form id="create-workspace-form" class="row g-2" novalidate>
                    <div class="col-12 col-sm-8">
                        <label for="workspace-name" class="visually-hidden">Workspace name</label>
                        <input type="text" class="form-control" id="workspace-name" maxlength="100" placeholder="Workspace name" aria-describedby="workspace-name-feedback">
                        <div id="workspace-name-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="col-12 col-sm-4">
                        <button type="submit" class="btn btn-primary w-100" id="create-workspace-submit">Create</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'workspaces.js']);
