<?php

/**
 * Invitation acceptance page. The invitation token arrives in the URL fragment (#token=...),
 * which browsers never send to this server, so it cannot appear in our access logs.
 * assets/js/invite.js reads it, checks it with the API, and submits the form.
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require __DIR__ . '/includes/page.php';

renderPageStart('Accept invitation');
?>
<main id="main-content" class="muninn-auth-backdrop d-flex flex-column align-items-center justify-content-center px-3 py-5">
    <div class="text-center mb-4 muninn-auth-brand">
        <img src="assets/icons/icon-192.png" alt="" width="72" height="72" class="mb-3 rounded-3">
        <h1 class="muninn-wordmark display-6 mb-1">Welcome to Muninn</h1>
        <p class="muninn-tagline mb-0">Your notes. Your knowledge.</p>
    </div>

    <div class="card muninn-auth-card shadow border-0">
        <div class="card-body p-4">
            <p id="invite-checking" class="mb-0" role="status">Checking your invitation…</p>

            <div id="invite-invalid" class="d-none" role="alert">
                <h2 class="h5">This invitation can't be used</h2>
                <p class="mb-0">The link is invalid, has expired, or was already used. Ask the person who invited you for a new one.</p>
            </div>

            <div id="invite-form-section" class="d-none">
                <h2 class="h5 mb-1">Create your account</h2>
                <p class="small text-muted-brand mb-3">This invitation expires <span id="invite-expiry"></span>.</p>

                <div id="invite-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>

                <form id="invite-form" novalidate>
                    <div class="mb-3">
                        <label for="invite-username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="invite-username" name="username"
                               autocomplete="username" autocapitalize="none" spellcheck="false"
                               aria-describedby="invite-username-help invite-username-feedback" required>
                        <div id="invite-username-help" class="form-text">3–32 characters: letters, digits, dot, underscore or hyphen.</div>
                        <div id="invite-username-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="invite-display-name" class="form-label">Display name</label>
                        <input type="text" class="form-control" id="invite-display-name" name="display_name"
                               autocomplete="name" aria-describedby="invite-display-name-feedback" required>
                        <div id="invite-display-name-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="invite-password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="invite-password" name="password"
                               autocomplete="new-password" aria-describedby="invite-password-help invite-password-feedback" required>
                        <div id="invite-password-help" class="form-text">At least 12 characters. A short sentence works well.</div>
                        <div id="invite-password-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-4">
                        <label for="invite-password-repeat" class="form-label">Repeat password</label>
                        <input type="password" class="form-control" id="invite-password-repeat"
                               autocomplete="new-password" aria-describedby="invite-password-repeat-feedback" required>
                        <div id="invite-password-repeat-feedback" class="invalid-feedback"></div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" id="invite-submit">Create account</button>
                </form>
            </div>
        </div>
    </div>
</main>
<?php
renderPageEnd(['invite.js']);
