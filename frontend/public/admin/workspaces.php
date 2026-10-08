<?php

/**
 * Shared workspace administration for system administrators (D050): see every shared workspace
 * with its Owners, and manage its members, e.g. when its only Owner was disabled. Administrators
 * never see notes. Non-admins get 404 from the API and this page then shows "not available".
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require dirname(__DIR__) . '/includes/page.php';

renderPageStart('Workspaces', '../');
renderAppNavbar('../');
?>
<main id="main-content" class="container py-4 px-3">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <div id="admin-not-available" class="alert alert-secondary d-none" role="alert">
        This page is not available.
    </div>

    <section id="shell-content" class="d-none" aria-labelledby="workspaces-heading">
        <h1 id="workspaces-heading" class="h3 mb-1">Shared workspaces</h1>
        <p class="small text-muted-brand mb-3">
            You can manage the members of any shared workspace, for example when its only Owner was disabled.
            You cannot see the notes. Personal workspaces are not listed.
        </p>
        <div id="workspaces-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
        <p id="workspaces-empty" class="text-muted-brand d-none">No shared workspaces yet.</p>
        <ul id="workspaces-list" class="list-group mb-4"></ul>

        <div id="members-card" class="card border-0 shadow-sm mb-4 d-none" tabindex="-1" aria-labelledby="members-heading">
            <div class="card-body">
                <div class="d-flex align-items-start gap-2 mb-3">
                    <h2 id="members-heading" class="h5 mb-0 flex-grow-1 text-break"></h2>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="members-close">Close</button>
                </div>
                <div id="members-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
                <ul id="members-list" class="list-group list-group-flush mb-3"></ul>

                <form id="add-member-form" class="row g-2" novalidate>
                    <h3 class="h6 mb-0">Add a member</h3>
                    <div class="col-12 col-sm">
                        <label for="member-username" class="visually-hidden">Username</label>
                        <input type="text" class="form-control" id="member-username" placeholder="Username" autocapitalize="none"
                               autocomplete="off" spellcheck="false" aria-describedby="member-username-feedback">
                        <div id="member-username-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="col-7 col-sm-auto">
                        <label for="member-role" class="visually-hidden">Role</label>
                        <select class="form-select" id="member-role" aria-describedby="member-role-feedback">
                            <option value="owner">Owner</option>
                            <option value="admin">Admin</option>
                            <option value="editor" selected>Editor</option>
                            <option value="reader">Reader</option>
                        </select>
                        <div id="member-role-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="col-5 col-sm-auto">
                        <button type="submit" class="btn btn-primary w-100" id="add-member-submit">Add</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'admin-workspaces.js'], '../');
