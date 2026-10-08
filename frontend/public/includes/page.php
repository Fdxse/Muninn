<?php

/**
 * Shared page helpers for the Muninn frontend.
 *
 * The frontend is a thin PHP shell: it renders static HTML and security headers, and all data
 * flows from the browser to the API as JSON. It holds no session and no credentials.
 */

declare(strict_types=1);

// Refuse to run when requested directly (the .htaccess in this folder should already block it).
if (!defined('MUNINN_FRONTEND')) {
    http_response_code(404);
    exit;
}

/** Loads includes/config.php, falling back to the example for first-time local runs. */
function muninnConfig(): array
{
    static $loadedConfig = null;
    if ($loadedConfig === null) {
        $configFilePath = is_file(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : __DIR__ . '/config.example.php';
        $loadedConfig = require $configFilePath;
    }

    return $loadedConfig;
}

/** HTML-escapes a value for safe output. */
function escapeHtml(string $unsafeText): string
{
    return htmlspecialchars($unsafeText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Returns "scheme://host[:port]" of a URL, used to scope the content security policy. */
function originOf(string $url): string
{
    $urlParts = parse_url($url);
    $origin = ($urlParts['scheme'] ?? 'https') . '://' . ($urlParts['host'] ?? '');
    if (isset($urlParts['port'])) {
        $origin .= ':' . $urlParts['port'];
    }

    return $origin;
}

/**
 * Sends security headers. The CSP allows scripts and styles from this site only (no inline
 * scripts at all), and network calls only to this site and the API.
 */
function sendSecurityHeaders(): void
{
    $apiOrigin = originOf((string) muninnConfig()['api_base_url']);

    $contentSecurityPolicy = implode('; ', [
        "default-src 'self'",
        "script-src 'self'",
        "style-src 'self'",
        "img-src 'self' data: " . $apiOrigin,
        "font-src 'self'",
        "connect-src 'self' " . $apiOrigin,
        "manifest-src 'self'",
        "worker-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ]);

    header('Content-Security-Policy: ' . $contentSecurityPolicy);
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
}

/**
 * Prints the top of a page.
 *
 * @param string $pageTitle Shown in the browser tab.
 * @param string $assetPrefix Relative path back to the site root ('' or '../').
 */
function renderPageStart(string $pageTitle, string $assetPrefix = ''): void
{
    sendSecurityHeaders();
    $apiBaseUrl = (string) muninnConfig()['api_base_url'];
    ?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escapeHtml($pageTitle) ?> · Muninn</title>
    <meta name="description" content="Muninn — Your notes. Your knowledge.">
    <meta name="theme-color" content="#0b1a2b">
    <!-- Home-screen app on iOS: name under the icon, full-screen launch, dark status bar. -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Muninn">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <!-- The API address is passed to JavaScript through a meta tag, so no inline script is needed. -->
    <meta name="muninn-api-base" content="<?= escapeHtml($apiBaseUrl) ?>">
    <meta name="muninn-site-root" content="<?= escapeHtml($assetPrefix === '' ? './' : $assetPrefix) ?>">
    <link rel="manifest" href="<?= $assetPrefix ?>manifest.webmanifest">
    <link rel="icon" href="<?= $assetPrefix ?>assets/icons/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="icon" href="<?= $assetPrefix ?>assets/icons/favicon-16.png" sizes="16x16" type="image/png">
    <link rel="apple-touch-icon" href="<?= $assetPrefix ?>assets/icons/apple-touch-icon.png">
    <link rel="stylesheet" href="<?= $assetPrefix ?>assets/vendor/bootstrap-5.3.8/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $assetPrefix ?>assets/vendor/bootstrap-icons-1.13.1/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= $assetPrefix ?>assets/css/muninn.css">
</head>
<body>
<a class="visually-hidden-focusable muninn-skip-link" href="#main-content">Skip to main content</a>
    <?php
}

/**
 * Prints the bottom of a page with its scripts.
 *
 * @param list<string> $pageScripts Script files under assets/js/ to load after the shared ones.
 * @param list<string> $vendorScripts Vendored libraries under assets/vendor/ the page needs
 *                                    (e.g. the Markdown renderer); loaded before the page scripts.
 */
function renderPageEnd(array $pageScripts, string $assetPrefix = '', array $vendorScripts = []): void
{
    $allScripts = array_merge(['api-client.js', 'pwa.js'], $pageScripts);
    ?>
<script src="<?= $assetPrefix ?>assets/vendor/bootstrap-5.3.8/js/bootstrap.bundle.min.js" defer></script>
<?php foreach ($vendorScripts as $vendorScriptFile): ?>
<script src="<?= $assetPrefix ?>assets/vendor/<?= escapeHtml($vendorScriptFile) ?>" defer></script>
<?php endforeach; ?>
<?php foreach ($allScripts as $scriptFile): ?>
<script src="<?= $assetPrefix ?>assets/js/<?= escapeHtml($scriptFile) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
    <?php
}

/**
 * Prints the top navigation bar for signed-in pages. The user's name and the admin link are
 * filled in by assets/js/app-shell.js after it asks the API who is signed in.
 */
function renderAppNavbar(string $assetPrefix = ''): void
{
    ?>
<nav class="navbar navbar-expand muninn-navbar" aria-label="Main">
    <div class="container-fluid px-3">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= $assetPrefix ?>index.php">
            <img src="<?= $assetPrefix ?>assets/icons/muninn-symbol-64.png" alt="" width="32" height="32" class="rounded-circle">
            <span class="muninn-wordmark">Muninn</span>
        </a>
        <ul class="navbar-nav ms-auto align-items-center gap-1">
            <li class="nav-item d-none" id="nav-search-item">
                <a class="nav-link" href="<?= $assetPrefix ?>search.php">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <span class="d-none d-sm-inline">Search</span>
                    <span class="visually-hidden d-sm-none">Search</span>
                </a>
            </li>
            <li class="nav-item d-none" id="nav-workspaces-item">
                <a class="nav-link" href="<?= $assetPrefix ?>workspaces.php">
                    <i class="bi bi-people" aria-hidden="true"></i>
                    <span class="d-none d-sm-inline">Workspaces</span>
                    <span class="visually-hidden d-sm-none">Workspaces</span>
                </a>
            </li>
            <li class="nav-item d-none" id="nav-admin-users-item">
                <a class="nav-link" href="<?= $assetPrefix ?>admin/users.php">
                    <i class="bi bi-person-gear" aria-hidden="true"></i>
                    <span class="d-none d-sm-inline">Users</span>
                    <span class="visually-hidden d-sm-none">Users</span>
                </a>
            </li>
            <li class="nav-item d-none" id="nav-admin-item">
                <a class="nav-link" href="<?= $assetPrefix ?>admin/invitations.php">
                    <i class="bi bi-person-plus" aria-hidden="true"></i>
                    <span class="d-none d-sm-inline">Invitations</span>
                    <span class="visually-hidden d-sm-none">Invitations</span>
                    <!-- Count of invitation requests waiting for a decision; filled in by app-shell.js. -->
                    <span class="badge rounded-pill muninn-nav-badge d-none" id="nav-admin-invitations-badge"></span>
                </a>
            </li>
            <li class="nav-item d-none" id="nav-admin-workspaces-item">
                <a class="nav-link" href="<?= $assetPrefix ?>admin/workspaces.php">
                    <i class="bi bi-diagram-3" aria-hidden="true"></i>
                    <span class="d-none d-sm-inline">Workspaces</span>
                    <span class="visually-hidden d-sm-none">Workspaces</span>
                </a>
            </li>
            <li class="nav-item">
                <!-- The account page: change password, and ask for someone to be invited (D040, D049). -->
                <a class="nav-link" href="<?= $assetPrefix ?>account.php">
                    <i class="bi bi-person-circle" aria-hidden="true"></i>
                    <span class="d-none d-sm-inline" id="nav-user-name">Account</span>
                    <span class="visually-hidden d-sm-none">Your account</span>
                </a>
            </li>
            <li class="nav-item">
                <!-- Icon only on phones, so the bar fits on one line even with the admin links. -->
                <button type="button" class="btn btn-outline-light btn-sm" id="nav-logout-button">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                    <span class="d-none d-sm-inline">Sign out</span>
                    <span class="visually-hidden d-sm-none">Sign out</span>
                </button>
            </li>
        </ul>
    </div>
</nav>
    <?php
}
