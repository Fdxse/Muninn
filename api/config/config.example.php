<?php

/**
 * Muninn API configuration — EXAMPLE ONLY.
 *
 * Copy this file to config/config.php on the server and fill in real values.
 * config/config.php is git-ignored and is never shipped in a release zip,
 * so a deploy can never overwrite it. Never commit real credentials.
 *
 * Every value marked REQUIRED must be present or the API refuses to start.
 */

declare(strict_types=1);

return [
    'app' => [
        // REQUIRED. 'production' hides all internal error details from responses.
        // Any other value (e.g. 'development') adds exception details to the log only.
        'environment' => 'production',
    ],

    'database' => [
        // REQUIRED. Connection used by the API at runtime. Grant this user
        // SELECT, INSERT, UPDATE, DELETE only (see docs/deployment.md).
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'muninn',
        'username' => 'muninn_app',
        'password' => 'CHANGE_ME',
    ],

    'migrations' => [
        // OPTIONAL. Separate, more privileged account used only by bin/migrate.php.
        // When omitted, bin/migrate.php falls back to the 'database' credentials.
        'username' => 'muninn_migrate',
        'password' => 'CHANGE_ME',
    ],

    'cors' => [
        // REQUIRED. Exact browser origins allowed to call the API with credentials.
        // Never use '*'. Production: the frontend origin only.
        'allowed_origins' => [
            'https://www.dx.se',
        ],
    ],

    'frontend' => [
        // REQUIRED. Base URL of the frontend, used to build invitation links.
        // Include the subfolder if the frontend is not at the domain root,
        // e.g. 'https://www.dx.se/muninn' (invite links become <base_url>/invite.php#token=...).
        'base_url' => 'https://www.dx.se',
    ],

    'session' => [
        // Cookie name. The __Host- prefix makes browsers enforce Secure, Path=/ and no Domain.
        'cookie_name' => '__Host-muninn_session',
        // Must stay true in production. Only set false for plain-http local development
        // AND rename the cookie (the __Host- prefix requires Secure).
        'cookie_secure' => true,
        // A session expires after this many hours without any request.
        'idle_timeout_hours' => 168,
        // A session always expires this many hours after login.
        'absolute_timeout_hours' => 720,
    ],

    'security' => [
        // IP addresses of reverse proxies whose X-Forwarded-For header is trusted,
        // e.g. the Synology reverse proxy. Leave empty when PHP sees clients directly.
        'trusted_proxies' => [],
        // Failed logins allowed per username + IP inside the window before 429.
        'login_max_failures_per_username' => 5,
        // Failed logins allowed per IP (any username) inside the window before 429.
        'login_max_failures_per_ip' => 20,
        // Invalid invitation tokens allowed per IP inside the window before 429.
        'invitation_max_failures_per_ip' => 20,
        // Length of the rate-limit window in minutes.
        'rate_limit_window_minutes' => 15,
    ],

    'invitations' => [
        // Default lifetime of a new invitation, in hours.
        'default_expiry_hours' => 72,
        // Upper limit an administrator may choose, in hours.
        'max_expiry_hours' => 720,
    ],

    'attachments' => [
        // OPTIONAL. Folder for note images. Must be OUTSIDE the web root and writable by the
        // web server user. Empty (the default) means storage/attachments in the application folder.
        'storage_path' => '',
        // OPTIONAL. Largest image upload in bytes. PHP's post_max_size must be a bit larger.
        'max_upload_bytes' => 10000000,
    ],

    'trash' => [
        // OPTIONAL. Days a deleted note stays in Trash before bin/purge-trash.php (run daily by
        // the NAS Task Scheduler) deletes it for good, with its history and images. 1 to 3650.
        'retention_days' => 30,
    ],

    'logging' => [
        // REQUIRED. Absolute path of the application log file. Must be OUTSIDE the web root.
        'file_path' => '/volume1/secrets/muninn/storage/api.log',
    ],
];
