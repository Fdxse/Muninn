<?php

/**
 * Muninn frontend configuration — EXAMPLE ONLY.
 *
 * Copy to includes/config.php on the web host and adjust. config.php is git-ignored and is not
 * shipped in the release zip, so uploading a new release never overwrites it.
 * Nothing here is secret: the frontend holds no credentials.
 */

declare(strict_types=1);

return [
    // Base URL of the API, without a trailing slash. Production: https://api.dx.se
    // (an alias of the NAS, so the session cookie is first-party; see DECISIONS.md D022).
    'api_base_url' => 'https://api.dx.se',

    // 'production' or 'development'.
    'environment' => 'production',
];
