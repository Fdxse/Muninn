<?php

declare(strict_types=1);

namespace Muninn\Api\Admin;

use RuntimeException;

/**
 * An audit log archive could not be made (D060). The code and message are safe to show to the
 * administrator: they never contain paths or database details.
 */
final class AuditLogArchiveException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
