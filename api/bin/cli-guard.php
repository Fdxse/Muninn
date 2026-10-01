<?php

/**
 * Shared guard for command-line scripts: refuse to run from a web request.
 * bin/ is outside the web root, but this makes accidental exposure harmless too.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';
