<?php

/**
 * Invitation administration for system administrators: create, list and revoke invitations, and
 * approve or decline users' invitation requests (D049).
 * The API is the real gatekeeper: non-admins get 404 from every admin endpoint, and this page
 * then shows "not available" instead of any data.
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require dirname(__DIR__) . '/includes/page.php';

renderPageStart('Invitations', '../');
renderAppNavbar('../');
?>
<main id="main-content" class="container py-4 px-3">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <div id="admin-not-available" class="alert alert-secondary d-none" role="alert">
        This page is not available.
    </div>

    <section id="shell-content" class="d-none" aria-labelledby="invitations-heading">
        <h1 id="invitations-heading" class="h3 mb-3">Invitations</h1>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5 mb-3">New invitation</h2>
                <div id="create-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
                <form id="create-invitation-form" class="row g-3" novalidate>
                    <div class="col-12 col-md-7">
                        <label for="invitation-note" class="form-label">Note <span class="text-muted-brand small">(optional, e.g. who it is for)</span></label>
                        <input type="text" class="form-control" id="invitation-note" maxlength="200" aria-describedby="invitation-note-feedback">
                        <div id="invitation-note-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="col-12 col-md-3">
                        <label for="invitation-expiry" class="form-label">Valid for</label>
                        <select class="form-select" id="invitation-expiry" aria-describedby="invitation-expiry-feedback">
                            <option value="24">1 day</option>
                            <option value="72" selected>3 days</option>
                            <option value="168">7 days</option>
                            <option value="720">30 days</option>
                        </select>
                        <div id="invitation-expiry-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="col-12 col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100" id="create-invitation-submit">Create</button>
                    </div>
                </form>

                <div id="new-invitation-result" class="alert alert-warning mt-3 mb-0 d-none" role="status" tabindex="-1">
                    <p class="fw-semibold mb-1">Copy this link now. It is shown only once.</p>
                    <p class="small mb-2">Send it to the invited person yourself. Anyone with the link can create an account until it is used or expires.</p>
                    <p class="muninn-one-time-link small bg-white rounded-2 p-2 mb-2" id="new-invitation-link"></p>
                    <button type="button" class="btn btn-outline-dark btn-sm" id="copy-invitation-link">
                        <i class="bi bi-clipboard" aria-hidden="true"></i> Copy link
                    </button>
                    <span class="small ms-2" id="copy-invitation-status" role="status"></span>
                </div>
            </div>
        </div>

        <section class="mb-4" aria-labelledby="requests-heading">
            <h2 id="requests-heading" class="h5 mb-1">Requests from users</h2>
            <p class="small text-muted-brand mb-3">
                After you approve a request, the user who asked creates the invitation link and sends it.
                Declining an approved request stops any link they created.
            </p>
            <div id="requests-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
            <p id="requests-empty" class="text-muted-brand d-none">No requests yet.</p>
            <ul id="requests-list" class="list-group mb-2"></ul>
        </section>

        <h2 class="h5 mb-3">All invitations</h2>
        <div id="list-error" class="alert alert-danger d-none" role="alert"></div>
        <p id="invitations-empty" class="text-muted-brand d-none">No invitations yet.</p>
        <div class="table-responsive">
            <table class="table align-middle bg-white rounded-3 overflow-hidden d-none" id="invitations-table">
                <thead>
                    <tr>
                        <th scope="col">Note</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="d-none d-md-table-cell">Created</th>
                        <th scope="col" class="d-none d-sm-table-cell">Expires</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody id="invitations-table-body"></tbody>
            </table>
        </div>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'admin-invitations.js', 'admin-invitation-requests.js'], '../');
