<?php

declare(strict_types=1);

namespace Muninn\Api\Attachments;

use Muninn\Api\Security\UuidGenerator;
use RuntimeException;

/**
 * Stores attachment files on the NAS filesystem, outside the web root.
 *
 * Files are named only after the attachment's random UUID (D024), never after anything the
 * client sent, and grouped by the UUID's first two characters so no folder grows huge:
 *   <storage folder>/3f/3f2a…-….bin
 * Because only validated UUIDs ever become paths, path traversal is impossible.
 */
final class AttachmentStorage
{
    public function __construct(private readonly string $storageFolder)
    {
    }

    /** The folder holding all attachment files (used by the data reset tool). */
    public function storageFolder(): string
    {
        return $this->storageFolder;
    }

    /**
     * Writes a file atomically: a temporary file in the same folder is renamed into place, so a
     * reader never sees half a file.
     *
     * @throws RuntimeException when the folder cannot be created or the file cannot be written.
     */
    public function write(string $attachmentId, string $fileBytes): void
    {
        $finalPath = $this->pathFor($attachmentId);
        $folderPath = dirname($finalPath);
        if (!is_dir($folderPath) && !@mkdir($folderPath, 0700, true) && !is_dir($folderPath)) {
            throw new RuntimeException('Cannot create the attachment folder.');
        }

        $temporaryPath = $folderPath . '/.upload-' . bin2hex(random_bytes(8));
        if (@file_put_contents($temporaryPath, $fileBytes, LOCK_EX) !== strlen($fileBytes)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Cannot write the attachment file.');
        }
        @chmod($temporaryPath, 0600);
        if (!@rename($temporaryPath, $finalPath)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Cannot move the attachment file into place.');
        }
    }

    /** Returns the file's bytes, or null when the file is missing. */
    public function read(string $attachmentId): ?string
    {
        $filePath = $this->pathFor($attachmentId);
        if (!is_file($filePath)) {
            return null;
        }
        $fileBytes = @file_get_contents($filePath);

        return $fileBytes === false ? null : $fileBytes;
    }

    /** Deletes a file if it exists (used to undo a failed upload). */
    public function delete(string $attachmentId): void
    {
        $filePath = $this->pathFor($attachmentId);
        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }

    /**
     * Deletes every stored attachment file and the now-empty sub-folders, keeping the storage
     * folder itself. Only files whose names are attachment UUIDs are touched.
     *
     * @return int Number of files deleted.
     */
    public function deleteAllFiles(): int
    {
        if (!is_dir($this->storageFolder)) {
            return 0;
        }
        $deletedFileCount = 0;
        foreach (glob($this->storageFolder . '/*', GLOB_ONLYDIR) ?: [] as $subFolderPath) {
            if (!preg_match('/^[0-9a-f]{2}$/', basename($subFolderPath))) {
                continue;
            }
            foreach (glob($subFolderPath . '/*.bin') ?: [] as $filePath) {
                if (UuidGenerator::isValid(basename($filePath, '.bin')) && @unlink($filePath)) {
                    $deletedFileCount++;
                }
            }
            @rmdir($subFolderPath);
        }

        return $deletedFileCount;
    }

    /** Counts the stored attachment files (shown by the data reset tool before it deletes). */
    public function countFiles(): int
    {
        return count(glob($this->storageFolder . '/[0-9a-f][0-9a-f]/*.bin') ?: []);
    }

    /**
     * The file path for an attachment ID.
     *
     * @throws RuntimeException for anything that is not a valid UUID (never reached with client
     *                          input, which is validated earlier, but it guards the filesystem).
     */
    private function pathFor(string $attachmentId): string
    {
        if (!UuidGenerator::isValid($attachmentId)) {
            throw new RuntimeException('Refusing a non-UUID attachment path.');
        }
        $lowercaseId = strtolower($attachmentId);

        return rtrim($this->storageFolder, '/') . '/' . substr($lowercaseId, 0, 2) . '/' . $lowercaseId . '.bin';
    }
}
