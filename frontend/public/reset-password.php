<?php

/**
 * Password reset page (D040). The reset token arrives in the URL fragment (#token=...), which
 * browsers never send to this server, so it cannot appear in our access logs.
 * assets/js/reset-password.js reads it, checks it with the API, and submits the new password.
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require __DIR__ . '/includes/page.php';

renderPageStart('Reset password');
?>
<main id="main-content" class="muninn-auth-backdrop d-flex flex-column align-items-center justify-content-center px-3 py-5">
    <div class="text-center mb-4 muninn-auth-brand">
        <img src="assets/branding/muninn-logo-horizontal-dark.webp" alt="" width="400" height="160" class="muninn-auth-logo mb-2">
        <h1 class="muninn-wordmark h3 mb-0">Reset your password</h1>
    </div>

    <div class="card muninn-auth-card shadow border-0">
        <div class="card-body p-4">
            <p id="reset-checking" class="mb-0" role="status">Checking your link…</p>

            <div id="reset-invalid" class="d-none" role="alert">
                <h2 class="h5">This link can't be used</h2>
                <p class="mb-0">The link is invalid, has expired, or was already used. Ask your administrator for a new one.</p>
            </div>

            <div id="reset-done" class="d-none" role="status" tabindex="-1">
                <h2 class="h5">Your password was changed</h2>
                <p>You have been signed out on every device. Sign in with your new password.</p>
                <a class="btn btn-primary w-100" href="login.php">Sign in</a>
            </div>

            <div id="reset-form-section" class="d-none">
                <h2 class="h5 mb-1">Choose a new password</h2>
                <p class="small text-muted-brand mb-3">For <span id="reset-username" class="fw-semibold"></span>. This link expires <span id="reset-expiry"></span>.</p>

                <div id="reset-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>

                <form id="reset-form" novalidate>
                    <!-- Lets password managers store the new password for the right account. -->
                    <input type="text" id="reset-form-username" autocomplete="username" class="d-none" tabindex="-1" aria-hidden="true">
                    <div class="mb-3">
                        <label for="reset-password" class="form-label">New password</label>
                        <input type="password" class="form-control" id="reset-password" name="password"
                               autocomplete="new-password" aria-describedby="reset-password-help reset-password-feedback" required>
                        <div id="reset-password-help" class="form-text">At least 12 characters. A short sentence works well.</div>
                        <div id="reset-password-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-4">
                        <label for="reset-password-repeat" class="form-label">Repeat new password</label>
                        <input type="password" class="form-control" id="reset-password-repeat"
                               autocomplete="new-password" aria-describedby="reset-password-repeat-feedback" required>
                        <div id="reset-password-repeat-feedback" class="invalid-feedback"></div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" id="reset-submit">Save new password</button>
                </form>
            </div>
        </div>
    </div>
</main>
<?php
renderPageEnd(['reset-password.js']);
