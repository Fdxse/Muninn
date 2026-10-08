<?php

declare(strict_types=1);

namespace Muninn\Api\Notes;

/**
 * The validated fields of a note create/update request. A null field (or folderIsSet = false)
 * means "not sent", which for an update leaves that part of the note unchanged.
 */
final class NoteInput
{
    /**
     * @param list<string>|null $tagNames Normalised tag names, or null when "tags" was not sent.
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $content = null,
        /** True when "folder_id" was sent at all (null then means "No folder"). */
        public readonly bool $folderIsSet = false,
        public readonly ?string $folderId = null,
        public readonly ?array $tagNames = null,
    ) {
    }
}
