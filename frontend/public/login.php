<?php

/**
 * Sign-in page. The forms are submitted by assets/js/login.js as JSON to the API.
 * Two ways in: username and password, or a 6-digit code shown on a device that is already
 * signed in (D068).
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
            <!-- Username and password. -->
            <div id="password-sign-in">
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

                <button type="button" class="btn btn-link w-100 mt-3" id="use-code-button">
                    <i class="bi bi-phone" aria-hidden="true"></i> Sign in with a code from another device
                </button>
            </div>

            <!-- A 6-digit code from a signed-in device (D068). Shown instead of the form above. -->
            <div id="code-sign-in" class="d-none">
                <h2 class="h5 mb-2">Sign in with a code</h2>
                <p class="small text-muted-brand mb-3">
                    On a device where you are signed in, open your account page and choose "Show a code".
                    Then type the 6 digits here.
                </p>

                <div id="code-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>

                <form id="code-form" novalidate>
                    <div class="mb-4">
                        <label for="sign-in-code" class="form-label">Code</label>
                        <input type="text" class="form-control form-control-lg text-center font-monospace" id="sign-in-code"
                               name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7"
                               placeholder="123 456" spellcheck="false" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" id="code-submit">Sign in</button>
                </form>

                <button type="button" class="btn btn-link w-100 mt-3" id="use-password-button">
                    Sign in with username and password instead
                </button>
            </div>
        </div>
    </div>

    <p class="small mt-4 mb-0 text-center">
        Accounts are by invitation only.<br>
        Forgot your password? Ask your administrator for a reset link.
    </p>
    <!-- The static help pages (help/), in English and Swedish. -->
    <p class="small mt-3 mb-0 text-center">
        <a href="help/" class="muninn-auth-help-link"><i class="bi bi-question-circle" aria-hidden="true"></i> Help and quick start</a>
    </p>
</main>
<?php
renderPageEnd(['login.js']);
