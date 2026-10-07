<?php

/**
 * Settings of one workspace: rename it, see and manage its members, leave it or delete it.
 *   workspace.php?id=<workspace id>
 *
 * Buttons appear according to the user's role, but the API decides every action (D029).
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require __DIR__ . '/includes/page.php';

renderPageStart('Workspace');
renderAppNavbar();
?>
<main id="main-content" class="container py-4 px-3">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <div id="workspace-not-available" class="alert alert-secondary d-none" role="alert">
        This workspace is not available. <a href="workspaces.php" class="alert-link">Back to workspaces</a>
    </div>

    <section id="shell-content" class="d-none" aria-labelledby="workspace-heading">
        <a href="workspaces.php" class="small d-inline-block mb-2">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Workspaces
        </a>
        <div class="d-flex flex-wrap align-items-start gap-2 mb-1">
            <h1 id="workspace-heading" class="h3 mb-0 flex-grow-1 text-break"></h1>
            <a id="open-notes-link" class="btn btn-primary btn-sm" href="index.php">
                <i class="bi bi-journal-text" aria-hidden="true"></i> Open notes
            </a>
        </div>
        <p id="workspace-summary" class="small text-muted-brand mb-4"></p>

        <div id="workspace-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>

        <!-- Owners only -->
        <div id="rename-card" class="card border-0 shadow-sm mb-4 d-none">
            <div class="card-body">
                <h2 class="h5 mb-3">Name</h2>
                <form id="rename-form" class="row g-2" novalidate>
                    <div class="col-12 col-sm-8">
                        <label for="rename-input" class="visually-hidden">Workspace name</label>
                        <input type="text" class="form-control" id="rename-input" maxlength="100" aria-describedby="rename-input-feedback">
                        <div id="rename-input-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="col-12 col-sm-4">
                        <button type="submit" class="btn btn-outline-dark w-100">Rename</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="members-card" class="card border-0 shadow-sm mb-4 d-none">
            <div class="card-body">
                <h2 class="h5 mb-3">Members</h2>
                <ul id="members-list" class="list-group list-group-flush mb-3"></ul>

                <!-- Owners and Admins only -->
                <form id="add-member-form" class="row g-2 d-none" novalidate>
                    <h3 class="h6 mb-0">Add a member</h3>
                    <div class="col-12 col-sm-6">
                        <label for="member-username" class="visually-hidden">Username</label>
                        <input type="text" class="form-control" id="member-username" placeholder="Username" autocapitalize="none" autocomplete="off" spellcheck="false" aria-describedby="member-username-feedback">
                        <div id="member-username-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="col-7 col-sm-3">
                        <label for="member-role" class="visually-hidden">Role</label>
                        <select class="form-select" id="member-role" aria-describedby="member-role-feedback"></select>
                        <div id="member-role-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="col-5 col-sm-3">
                        <button type="submit" class="btn btn-primary w-100">Add</button>
                    </div>
                    <p class="small text-muted-brand mb-0">Readers can read notes. Editors can also write and delete them. Admins can also manage Editors and Readers. Owners can do everything.</p>
                </form>
            </div>
        </div>

        <!-- Owners only, shared workspaces only -->
        <div id="delete-card" class="card border-danger-subtle shadow-sm d-none">
            <div class="card-body">
                <h2 class="h5 mb-2">Delete workspace</h2>
                <p class="small mb-3">Only an empty workspace can be deleted. Delete its notes first.</p>
                <button type="button" class="btn btn-outline-danger" id="delete-workspace-button">Delete workspace</button>
            </div>
        </div>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'workspace-settings.js']);
