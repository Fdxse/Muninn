<?php

/**
 * The signed-in user's account page:
 * - change their own password (D040),
 * - ask for someone to be invited, and once an administrator approves, create the invitation
 *   link and send it themselves (D049). Administrator accounts invite directly under Admin.
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require __DIR__ . '/includes/page.php';

renderPageStart('Your account');
renderAppNavbar();
?>
<main id="main-content" class="container py-4 px-3 muninn-narrow-page">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <div id="shell-content" class="d-none">
        <h1 class="h3 mb-1">Your account</h1>
        <p class="text-muted-brand mb-4">Signed in as <span id="account-username" class="fw-semibold"></span>.</p>

        <section class="card border-0 shadow-sm mb-4" aria-labelledby="password-heading">
            <div class="card-body">
                <h2 id="password-heading" class="h5 mb-3">Change password</h2>
                <div id="password-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
                <div id="password-success" class="alert alert-success d-none" role="status" tabindex="-1">
                    Your password was changed. Other devices have been signed out.
                </div>
                <form id="password-form" novalidate>
                    <!-- Lets password managers connect the new password to this account. -->
                    <input type="text" id="password-form-username" autocomplete="username" class="d-none" tabindex="-1" aria-hidden="true">
                    <div class="mb-3">
                        <label for="current-password" class="form-label">Current password</label>
                        <input type="password" class="form-control" id="current-password" autocomplete="current-password"
                               aria-describedby="current-password-feedback" required>
                        <div id="current-password-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="new-password" class="form-label">New password</label>
                        <input type="password" class="form-control" id="new-password" autocomplete="new-password"
                               aria-describedby="new-password-help new-password-feedback" required>
                        <div id="new-password-help" class="form-text">At least 12 characters. A short sentence works well.</div>
                        <div id="new-password-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="new-password-repeat" class="form-label">Repeat new password</label>
                        <input type="password" class="form-control" id="new-password-repeat" autocomplete="new-password"
                               aria-describedby="new-password-repeat-feedback" required>
                        <div id="new-password-repeat-feedback" class="invalid-feedback"></div>
                    </div>
                    <button type="submit" class="btn btn-primary" id="password-submit">Change password</button>
                </form>
            </div>
        </section>

        <section id="requests-card" class="card border-0 shadow-sm mb-4 d-none" aria-labelledby="requests-heading">
            <div class="card-body">
                <h2 id="requests-heading" class="h5 mb-1">Invite someone</h2>
                <p class="small text-muted-brand mb-3">
                    Ask the administrator to let someone join Muninn. Once your request is approved, create the
                    invitation link here and send it to them yourself. Each link works once.
                </p>
                <div id="request-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
                <form id="request-form" class="row g-2 mb-3" novalidate>
                    <div class="col-12 col-sm">
                        <label for="request-note" class="form-label">Who should be invited, and why?</label>
                        <input type="text" class="form-control" id="request-note" maxlength="200"
                               aria-describedby="request-note-feedback" required>
                        <div id="request-note-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="col-12 col-sm-auto d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100" id="request-submit">Send request</button>
                    </div>
                </form>

                <div id="request-link-result" class="alert alert-warning d-none" role="status" tabindex="-1">
                    <p class="fw-semibold mb-1">Copy this link now. It is shown only once.</p>
                    <p class="small mb-2">Send it to the person yourself. Anyone with the link can create an account until it is used or expires.</p>
                    <p class="muninn-one-time-link small bg-white rounded-2 p-2 mb-2" id="request-link"></p>
                    <button type="button" class="btn btn-outline-dark btn-sm" id="copy-request-link">
                        <i class="bi bi-clipboard" aria-hidden="true"></i> Copy link
                    </button>
                    <span class="small ms-2" id="copy-request-link-status" role="status"></span>
                </div>

                <h3 class="h6 mt-2">Your requests</h3>
                <p id="requests-empty" class="small text-muted-brand mb-0 d-none">You have not asked for anyone yet.</p>
                <ul id="requests-list" class="list-group list-group-flush"></ul>
            </div>
        </section>

        <!-- Links to the static help pages (help/). They open in a new tab so nothing here is lost. -->
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="help-heading">
            <div class="card-body">
                <h2 id="help-heading" class="h5 mb-2">Help</h2>
                <p class="small text-muted-brand">How to use Muninn, in English and Swedish.</p>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-primary" href="help/quick-start.html" target="_blank" rel="noopener">
                        <i class="bi bi-lightning" aria-hidden="true"></i> Quick start
                    </a>
                    <a class="btn btn-outline-primary" href="help/manual.html" target="_blank" rel="noopener">
                        <i class="bi bi-book" aria-hidden="true"></i> Manual
                    </a>
                </div>
            </div>
        </section>
    </div>
</main>
<?php
renderPageEnd(['app-shell.js', 'account.js']);
