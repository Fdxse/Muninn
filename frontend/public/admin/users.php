<?php

/**
 * Account administration for system administrators: list accounts, disable and re-enable them
 * (decision D031: accounts are disabled, never deleted), create one-time password reset
 * links (D040), set each account's chat level (D062) and pick the account that is told when
 * admin work is waiting (D066). Non-admins get 404 from the API and
 * this page then shows "not available".
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require dirname(__DIR__) . '/includes/page.php';

renderPageStart('Users', '../');
renderAppNavbar('../');
?>
<main id="main-content" class="container py-4 px-3">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <div id="admin-not-available" class="alert alert-secondary d-none" role="alert">
        This page is not available.
    </div>

    <section id="shell-content" class="d-none" aria-labelledby="users-heading">
        <h1 id="users-heading" class="h3 mb-3">Users</h1>
        <p class="small text-muted-brand">
            Disabling an account signs it out everywhere and blocks sign-in. Its notes are kept.
            A password reset link lets someone who forgot their password choose a new one.
            Chat decides where someone may chat: nowhere, only in workspaces they own, in all their
            workspaces, or also shout out to everyone. You cannot read chats yourself.
        </p>
        <div id="users-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>

        <div id="reset-link-result" class="alert alert-warning d-none" role="status" tabindex="-1">
            <p class="fw-semibold mb-1">Password reset link for <span id="reset-link-username"></span>. Copy it now: it is shown only once.</p>
            <p class="small mb-2">
                Send it to them yourself. It works once, until <span id="reset-link-expiry"></span>, and signs them
                out everywhere when used. Creating another link for the same person stops this one.
            </p>
            <p class="muninn-one-time-link small bg-white rounded-2 p-2 mb-2" id="reset-link"></p>
            <button type="button" class="btn btn-outline-dark btn-sm" id="copy-reset-link">
                <i class="bi bi-clipboard" aria-hidden="true"></i> Copy link
            </button>
            <span class="small ms-2" id="copy-reset-link-status" role="status"></span>
        </div>
        <!-- "Something is waiting on the admin side" (D066): one everyday account sees a shield icon
             while invitation requests or unread messages wait here. Filled in by admin-users.js. -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <label for="attention-recipient-select" class="form-label fw-semibold mb-1">Tell this account when admin work is waiting</label>
                <p class="small text-muted-brand mb-2" id="attention-recipient-hint">
                    The account sees a gold shield icon while invitation requests or unread messages wait for you.
                    It never says what is waiting, and the account still cannot see any admin page.
                </p>
                <select class="form-select w-auto mw-100" id="attention-recipient-select" aria-describedby="attention-recipient-hint attention-recipient-status">
                    <option value="">Nobody</option>
                </select>
                <span class="small ms-sm-2 d-block d-sm-inline mt-1 mt-sm-0" id="attention-recipient-status" role="status"></span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table align-middle bg-white rounded-3 overflow-hidden" id="users-table">
                <thead>
                    <tr>
                        <th scope="col">User</th>
                        <th scope="col" class="d-none d-sm-table-cell">Status</th>
                        <th scope="col" class="d-none d-md-table-cell">Last sign-in</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody id="users-table-body"></tbody>
            </table>
        </div>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'admin-users.js'], '../');
