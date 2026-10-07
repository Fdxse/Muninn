<?php

/**
 * Account administration for system administrators: list accounts, disable and re-enable them
 * (decision D031: accounts are disabled, never deleted). Non-admins get 404 from the API and
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
        <p class="small text-muted-brand">Disabling an account signs it out everywhere and blocks sign-in. Its notes are kept.</p>
        <div id="users-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
        <div class="table-responsive">
            <table class="table align-middle bg-white rounded-3 overflow-hidden" id="users-table">
                <thead>
                    <tr>
                        <th scope="col">User</th>
                        <th scope="col">Status</th>
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
