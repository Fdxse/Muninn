<?php

/**
 * Workspace administration for system administrators:
 * - Shared Workspaces (D067): create, rename, describe and delete them, decide join requests and
 *   manage their members (Editors and Readers only).
 * - Workspaces created by users (D050): see every one with its Owners, and manage the members of
 *   those without an active Owner (e.g. when the only Owner was disabled).
 * Administrators never see notes. Non-admins get 404 from the API and this page then shows "not available".
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

    <section id="shell-content" class="d-none" aria-labelledby="open-workspaces-heading">
        <!-- Shared Workspaces (D067): run by the administrator; users ask to join. -->
        <h1 id="open-workspaces-heading" class="h3 mb-1">Shared Workspaces</h1>
        <p class="small text-muted-brand mb-3">
            Workspaces for everyone, such as General. Every user sees their names and descriptions and can
            ask to join. You decide the requests and manage the members, who are Editors or Readers. You
            cannot see the notes.
        </p>
        <div id="open-workspaces-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
        <div id="open-workspaces-status" class="visually-hidden" role="status" aria-live="polite"></div>

        <h2 class="h5 mb-2">Join requests</h2>
        <p id="join-requests-empty" class="text-muted-brand small d-none">No requests are waiting.</p>
        <ul id="join-requests-list" class="list-group mb-4"></ul>

        <h2 class="h5 mb-2">Your Shared Workspaces</h2>
        <p id="open-workspaces-empty" class="text-muted-brand small d-none">None yet. Create the first one below.</p>
        <ul id="open-workspaces-list" class="list-group mb-3"></ul>

        <div id="edit-open-workspace-card" class="card border-0 shadow-sm mb-3 d-none" tabindex="-1" aria-labelledby="edit-open-workspace-heading">
            <div class="card-body">
                <h3 id="edit-open-workspace-heading" class="h6 mb-3">Edit Shared Workspace</h3>
                <form id="edit-open-workspace-form" class="row g-2" novalidate>
                    <div class="col-12">
                        <label for="edit-open-workspace-name" class="form-label small mb-1">Name</label>
                        <input type="text" class="form-control" id="edit-open-workspace-name" maxlength="100" aria-describedby="edit-open-workspace-name-feedback">
                        <div id="edit-open-workspace-name-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="col-12">
                        <label for="edit-open-workspace-description" class="form-label small mb-1">Description (optional)</label>
                        <input type="text" class="form-control" id="edit-open-workspace-description" maxlength="300" aria-describedby="edit-open-workspace-description-feedback">
                        <div id="edit-open-workspace-description-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="edit-open-workspace-submit">Save</button>
                        <button type="button" class="btn btn-outline-secondary" id="edit-open-workspace-cancel">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h3 class="h6 mb-3">New Shared Workspace</h3>
                <form id="create-open-workspace-form" class="row g-2" novalidate>
                    <div class="col-12 col-sm-5">
                        <label for="open-workspace-name" class="visually-hidden">Name</label>
                        <input type="text" class="form-control" id="open-workspace-name" maxlength="100" placeholder="Name, e.g. General"
                               aria-describedby="open-workspace-name-feedback">
                        <div id="open-workspace-name-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="col-12 col-sm">
                        <label for="open-workspace-description" class="visually-hidden">Description (optional)</label>
                        <input type="text" class="form-control" id="open-workspace-description" maxlength="300" placeholder="Description (optional)"
                               aria-describedby="open-workspace-description-feedback">
                        <div id="open-workspace-description-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="col-12 col-sm-auto">
                        <button type="submit" class="btn btn-primary w-100" id="create-open-workspace-submit">Create</button>
                    </div>
                </form>
            </div>
        </div>

        <h2 id="workspaces-heading" class="h5 mb-1">Workspaces created by users</h2>
        <p class="small text-muted-brand mb-3">
            Every shared workspace and its Owners. You can manage the members only of a workspace that has
            no active Owner, for example because its only Owner was disabled: give it a new Owner, who then
            takes over. You cannot see the notes. Personal workspaces are not listed.
        </p>
        <p class="small mb-3">
            <a href="magic-links.php"><i class="bi bi-link-45deg" aria-hidden="true"></i> Magic Links overview</a>
            <span class="text-muted-brand">: every link that opens a workspace without signing in, with a Revoke button.</span>
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
