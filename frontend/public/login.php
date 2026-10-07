<?php

/**
 * Sign-in page. The form is submitted by assets/js/login.js as JSON to the API.
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require __DIR__ . '/includes/page.php';

renderPageStart('Sign in');
?>
<main id="main-content" class="muninn-auth-backdrop d-flex flex-column align-items-center justify-content-center px-3 py-5">
    <div class="text-center mb-4 muninn-auth-brand">
        <!-- The logo image contains the name and tagline; the heading text comes from its alt text. -->
        <h1 class="mb-0">
            <img src="assets/branding/muninn-logo-horizontal-dark.webp" alt="Muninn" width="400" height="160" class="muninn-auth-logo">
        </h1>
        <p class="visually-hidden">Your notes. Your knowledge.</p>
    </div>

    <div class="card muninn-auth-card shadow border-0">
        <div class="card-body p-4">
            <h2 class="h5 mb-3">Sign in</h2>

            <div id="login-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>

            <form id="login-form" novalidate>
                <div class="mb-3">
                    <label for="login-username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="login-username" name="username"
                           autocomplete="username" autocapitalize="none" spellcheck="false" required>
                </div>
                <div class="mb-4">
                    <label for="login-password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="login-password" name="password"
                           autocomplete="current-password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100" id="login-submit">Sign in</button>
            </form>
        </div>
    </div>

    <p class="small mt-4 mb-0 text-center">Accounts are by invitation only.</p>
</main>
<?php
renderPageEnd(['login.js']);
