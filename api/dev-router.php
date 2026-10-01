<?php

/**
 * Router script for PHP's built-in development server ONLY:
 *   php -S localhost:8000 -t public dev-router.php
 * Production uses Apache/nginx rewrites to public/index.php instead.
 */

declare(strict_types=1);

require __DIR__ . '/public/index.php';
