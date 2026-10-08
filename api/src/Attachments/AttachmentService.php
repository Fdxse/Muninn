<?php

declare(strict_types=1);

namespace Muninn\Api\Attachments;

use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Workspaces\WorkspaceMembership;
use PDO;
use Throwable;

/**
 * Image attachments of notes (D008, D036). Every attachment belongs to one note, and access to
 * it always comes from access to that note: the note's workspace membership is required, and the
 * note must be active (an attachment of a trashed note is as invisible as the note itself).
 *
 * Methods take the WorkspaceMembership from WorkspaceAuthorizer and scope queries to it.
 */
final class AttachmentService
{
    /** Most attachments one note may hold; bounds storage use and list size. */
    private const MAXIMUM_ATTACHMENTS_PER_NOTE = 100;

    private const FILENAME_MAX_LENGTH = 200;

    public function __construct(
        private readonly PDO $database,
        private readonly AttachmentStorage $attachmentStorage,
    ) {
    }

    /**
     * Finds the workspace and note of an attachment whose note is active, so the caller's access
     * can be checked. Returns null for unknown or malformed IDs and for attachments of trashed notes.
     *
     * @return array{workspace_id: string, note_id: string}|null
     */
    public function findOwnerOfActiveAttachment(string $attachmentId): ?array
    {
        if (!UuidGenerator::isValid($attachmentId)) {
            return null;
        }
        $selectStatement = $this->database->prepare(
            'SELECT attachments.workspace_id, attachments.note_id
             FROM attachments
             JOIN notes ON notes.id = attachments.note_id AND notes.workspace_id = attachments.workspace_id
             WHERE attachments.id = :id AND notes.trashed_at IS NULL'
        );
        $selectStatement->execute(['id' => $attachmentId]);
        $ownerRow = $selectStatement->fetch();

        return $ownerRow === false ? null : ['workspace_id' => (string) $ownerRow['workspace_id'], 'note_id' => (string) $ownerRow['note_id']];
    }

    /**
     * Stores an uploaded image for a note and returns the new attachment.
     *
     * @param string $noteId Must be an active note of the membership's workspace (checked again here).
     * @param string|null $requestedFilename The name the user's file had, display only; may be null.
     * @return array<string, mixed>
     * @throws HttpException 404 when the note is not active in the workspace, 409 at the per-note
     *                       limit, 422 when the bytes are not an accepted image.
     */
    public function create(WorkspaceMembership $membership, string $noteId, ?string $requestedFilename, string $fileBytes): array
    {
        $imageDetails = ImageInspector::inspect($fileBytes);
        if ($imageDetails === null) {
            throw HttpException::validation(['file' => 'Only PNG, JPEG, GIF and WebP images up to '
                . number_format(ImageInspector::MAXIMUM_DIMENSION_PIXELS) . ' pixels wide and high can be attached.']);
        }

        $newAttachmentId = UuidGenerator::generate();
        $displayFilename = self::displayFilename($requestedFilename, $imageDetails['extension']);

        // The row and the file are written together: the transaction is committed only after the
        // file is safely on disk, and the file is removed again if anything fails.
        $this->database->beginTransaction();
        try {
            $this->lockActiveNote($membership, $noteId);
            $this->refuseWhenNoteIsFull($noteId);

            $insertStatement = $this->database->prepare(
                'INSERT INTO attachments (id, workspace_id, note_id, original_filename, media_type, byte_size,
                                          width_pixels, height_pixels, sha256_hex, created_by_user_id, created_at)
                 VALUES (:id, :workspace_id, :note_id, :original_filename, :media_type, :byte_size,
                         :width_pixels, :height_pixels, :sha256_hex, :user_id, UTC_TIMESTAMP())'
            );
            $insertStatement->execute([
                'id' => $newAttachmentId,
                'workspace_id' => $membership->workspaceId,
                'note_id' => $noteId,
                'original_filename' => $displayFilename,
                'media_type' => $imageDetails['media_type'],
                'byte_size' => strlen($fileBytes),
                'width_pixels' => $imageDetails['width'],
                'height_pixels' => $imageDetails['height'],
                'sha256_hex' => hash('sha256', $fileBytes),
                'user_id' => $membership->userId,
            ]);

            $this->attachmentStorage->write($newAttachmentId, $fileBytes);
            $this->database->commit();
        } catch (Throwable $uploadFailure) {
            $this->database->rollBack();
            $this->attachmentStorage->delete($newAttachmentId);
            throw $uploadFailure;
        }

        return $this->find($membership, $newAttachmentId);
    }

    /**
     * Lists a note's attachments, oldest first. The caller has authorized the note.
     *
     * @return list<array<string, mixed>>
     */
    public function listForNote(WorkspaceMembership $membership, string $noteId): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT * FROM attachments WHERE note_id = :note_id AND workspace_id = :workspace_id ORDER BY created_at, id'
        );
        $selectStatement->execute(['note_id' => $noteId, 'workspace_id' => $membership->workspaceId]);

        return array_map(self::toPublicArray(...), $selectStatement->fetchAll());
    }

    /**
     * Returns one attachment of the membership's workspace.
     *
     * @return array<string, mixed>
     * @throws HttpException 404 when it is not an attachment of that workspace.
     */
    public function find(WorkspaceMembership $membership, string $attachmentId): array
    {
        $selectStatement = $this->database->prepare('SELECT * FROM attachments WHERE id = :id AND workspace_id = :workspace_id');
        $selectStatement->execute(['id' => $attachmentId, 'workspace_id' => $membership->workspaceId]);
        $attachmentRow = $selectStatement->fetch();
        if ($attachmentRow === false) {
            throw HttpException::notFound();
        }

        return self::toPublicArray($attachmentRow);
    }

    /**
     * Returns an attachment's metadata and bytes for download.
     *
     * @return array{attachment: array<string, mixed>, bytes: string}
     * @throws HttpException 404 when it is not in the workspace or its file is missing.
     */
    public function readContent(WorkspaceMembership $membership, string $attachmentId): array
    {
        $attachment = $this->find($membership, $attachmentId);
        $fileBytes = $this->attachmentStorage->read($attachmentId);
        if ($fileBytes === null) {
            // The row exists but the file does not (e.g. a lost disk). A 404 tells the user
            // nothing more than "not available"; the request ID leads an operator to the log.
            throw HttpException::notFound();
        }

        return ['attachment' => $attachment, 'bytes' => $fileBytes];
    }

    /**
     * Locks the note row for the rest of the transaction, so the note cannot be trashed or the
     * per-note limit raced while an upload is being stored.
     *
     * @throws HttpException 404 when it is not an active note of the workspace.
     */
    private function lockActiveNote(WorkspaceMembership $membership, string $noteId): void
    {
        $lockStatement = $this->database->prepare(
            'SELECT id FROM notes WHERE id = :id AND workspace_id = :workspace_id AND trashed_at IS NULL FOR UPDATE'
        );
        $lockStatement->execute(['id' => $noteId, 'workspace_id' => $membership->workspaceId]);
        if ($lockStatement->fetchColumn() === false) {
            throw HttpException::notFound();
        }
    }

    /** @throws HttpException 409 when the note already holds the maximum number of attachments. */
    private function refuseWhenNoteIsFull(string $noteId): void
    {
        $countStatement = $this->database->prepare('SELECT COUNT(*) FROM attachments WHERE note_id = :note_id');
        $countStatement->execute(['note_id' => $noteId]);
        if ((int) $countStatement->fetchColumn() >= self::MAXIMUM_ATTACHMENTS_PER_NOTE) {
            throw HttpException::conflict('attachment_limit', 'This note already has the maximum of ' . self::MAXIMUM_ATTACHMENTS_PER_NOTE . ' images.');
        }
    }

    /**
     * Turns the user's filename into a safe display name ending in the detected extension.
     * The name is only ever shown and offered as a download name; it is never a path.
     */
    public static function displayFilename(?string $requestedFilename, string $detectedExtension): string
    {
        $candidateName = (string) $requestedFilename;
        // Keep only the last path segment and drop control and path characters.
        $candidateName = basename(str_replace('\\', '/', $candidateName));
        $candidateName = (string) preg_replace('/[\x00-\x1F\x7F"\/\\\\<>:|?*]/u', '', $candidateName);
        if (!mb_check_encoding($candidateName, 'UTF-8')) {
            $candidateName = '';
        }
        // Replace whatever extension the client claimed with the real one.
        $baseName = trim((string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $candidateName), " .\t");
        if ($baseName === '') {
            $baseName = 'image';
        }
        $baseName = mb_substr($baseName, 0, self::FILENAME_MAX_LENGTH);

        return $baseName . '.' . $detectedExtension;
    }

    /**
     * @param array<string, mixed> $attachmentRow
     * @return array<string, mixed>
     */
    private static function toPublicArray(array $attachmentRow): array
    {
        $filename = (string) $attachmentRow['original_filename'];
        // Square brackets would end the Markdown alt text early.
        $altText = str_replace(['[', ']'], '', (string) preg_replace('/\.[a-z]+$/', '', $filename));

        return [
            'id' => $attachmentRow['id'],
            'note_id' => $attachmentRow['note_id'],
            'filename' => $filename,
            'media_type' => $attachmentRow['media_type'],
            'byte_size' => (int) $attachmentRow['byte_size'],
            'width' => (int) $attachmentRow['width_pixels'],
            'height' => (int) $attachmentRow['height_pixels'],
            'created_at' => UtcTimestamp::toIso((string) $attachmentRow['created_at']),
            // What the editor inserts into the note. The frontend renders only attachment: images (D036).
            'markdown' => '![' . $altText . '](attachment:' . $attachmentRow['id'] . ')',
            'sha256' => $attachmentRow['sha256_hex'],
        ];
    }
}
