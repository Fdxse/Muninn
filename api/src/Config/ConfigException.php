<?php

declare(strict_types=1);

namespace Muninn\Api\Config;

use RuntimeException;

/**
 * Thrown when configuration is missing or invalid. The message may name config keys
 * (never values), so it is safe for the server log but is never sent to clients.
 */
final class ConfigException extends RuntimeException
{
}
