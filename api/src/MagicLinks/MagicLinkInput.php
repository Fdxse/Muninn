<?php

declare(strict_types=1);

namespace Muninn\Api\MagicLinks;

use DateTimeImmutable;

/**
 * The validated fields of a "create Magic Link" request (see MagicLinkController::readInput()).
 */
final class MagicLinkInput
{
    public function __construct(
        public readonly string $label,
        /** One of the MagicLinkAccess::TARGET_* values. */
        public readonly string $targetType,
        /** Folder or note ID; null for a workspace link. Checked against the workspace by the service. */
        public readonly ?string $targetId,
        /** MagicLinkAccess::PERMISSION_READ or PERMISSION_WRITE. */
        public readonly string $permission,
        public readonly DateTimeImmutable $validFromUtc,
        public readonly DateTimeImmutable $validUntilUtc,
        /** "HH:MM", or null when the link works all day. */
        public readonly ?string $dailyStartTime,
        public readonly ?string $dailyEndTime,
    ) {
    }
}
