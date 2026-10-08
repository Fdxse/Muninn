<?php

declare(strict_types=1);

namespace Muninn\Api\Tags;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\UuidGenerator;
use Muninn\Api\Validation\TextRules;
use Muninn\Api\Workspaces\WorkspaceMembership;
use PDO;

/**
 * Tags (decision D034): every tag belongs to one workspace, never globally, so a tag name typed
 * in one workspace can never show up in another. Tags are created implicitly when a note is
 * saved with a new tag name and removed again once no note (Trash included) uses them.
 *
 * Every method takes the WorkspaceMembership from WorkspaceAuthorizer and stays inside that
 * workspace. The caller (NoteService) is responsible for authorization and transactions.
 */
final class TagService
{
    public const NAME_MAX_LENGTH = 50;
    public const MAXIMUM_TAGS_PER_NOTE = 20;

    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * Validates the "tags" field of a note request: a JSON array of tag names. Names are trimmed,
     * a leading "#" is dropped, and duplicates (ignoring case) are removed, keeping the first.
     *
     * @return list<string>
     * @throws HttpException 422 when the input is not an acceptable list of names.
     */
    public static function normaliseTagNames(mixed $tagsInput): array
    {
        if (!is_array($tagsInput) || !array_is_list($tagsInput)) {
            throw HttpException::validation(['tags' => 'Send the tags as a list of names.']);
        }

        $tagNamesByLowercase = [];
        foreach ($tagsInput as $tagInput) {
            if (!is_string($tagInput)) {
                throw HttpException::validation(['tags' => 'Every tag must be text.']);
            }
            $tagName = trim(ltrim(trim($tagInput), '#'));
            if ($tagName === '') {
                continue;
            }
            $nameError = TextRules::singleLineError($tagName, self::NAME_MAX_LENGTH, true);
            if ($nameError !== null) {
                throw HttpException::validation(['tags' => 'Tag "' . mb_substr($tagName, 0, 20) . '": ' . $nameError]);
            }
            // Commas separate tags in the editor, so a tag cannot contain one.
            if (str_contains($tagName, ',')) {
                throw HttpException::validation(['tags' => 'Tags cannot contain commas.']);
            }
            $tagNamesByLowercase[mb_strtolower($tagName)] ??= $tagName;
        }

        if (count($tagNamesByLowercase) > self::MAXIMUM_TAGS_PER_NOTE) {
            throw HttpException::validation(['tags' => 'Use at most ' . self::MAXIMUM_TAGS_PER_NOTE . ' tags per note.']);
        }

        return array_values($tagNamesByLowercase);
    }

    /**
     * Lists the tags used by at least one note of the workspace's normal note list (not archived,
     * not trashed), with how many such notes use each. Tags used only by archived or trashed notes
     * stay hidden, like those notes.
     *
     * @return list<array{name: string, note_count: int}>
     */
    public function listInWorkspace(WorkspaceMembership $membership): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT tags.name, COUNT(notes.id) AS note_count
             FROM tags
             JOIN note_tags ON note_tags.tag_id = tags.id
             JOIN notes ON notes.id = note_tags.note_id AND notes.trashed_at IS NULL AND notes.archived_at IS NULL
             WHERE tags.workspace_id = :workspace_id
             GROUP BY tags.id, tags.name
             ORDER BY tags.name'
        );
        $selectStatement->execute(['workspace_id' => $membership->workspaceId]);

        return array_map(static fn (array $tagRow): array => [
            'name' => (string) $tagRow['name'],
            'note_count' => (int) $tagRow['note_count'],
        ], $selectStatement->fetchAll());
    }

    /**
     * Returns the tag names of each given note, sorted by name. The notes must already have been
     * selected with the membership's workspace scope; this only reads their tag links.
     *
     * @param list<string> $noteIds
     * @return array<string, list<string>> Note ID => tag names (notes without tags are absent).
     */
    public function tagNamesByNote(array $noteIds): array
    {
        if ($noteIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($noteIds), '?'));
        $selectStatement = $this->database->prepare(
            'SELECT note_tags.note_id, tags.name
             FROM note_tags
             JOIN tags ON tags.id = note_tags.tag_id
             WHERE note_tags.note_id IN (' . $placeholders . ')
             ORDER BY tags.name'
        );
        $selectStatement->execute($noteIds);

        $tagNamesByNote = [];
        foreach ($selectStatement->fetchAll() as $linkRow) {
            $tagNamesByNote[(string) $linkRow['note_id']][] = (string) $linkRow['name'];
        }

        return $tagNamesByNote;
    }

    /**
     * Replaces a note's tags with $tagNames, creating missing tags in the workspace and removing
     * workspace tags no note uses any more. Must run inside the caller's transaction, after the
     * caller has confirmed the note belongs to the membership's workspace.
     *
     * @param list<string> $tagNames Already normalised with normaliseTagNames().
     */
    public function replaceNoteTags(WorkspaceMembership $membership, string $noteId, array $tagNames): void
    {
        $deleteLinksStatement = $this->database->prepare('DELETE FROM note_tags WHERE note_id = :note_id');
        $deleteLinksStatement->execute(['note_id' => $noteId]);

        $insertTagStatement = $this->database->prepare(
            // The unique key on (workspace_id, name) makes an existing tag a no-op.
            'INSERT INTO tags (id, workspace_id, name, created_at) VALUES (:id, :workspace_id, :name, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE id = id'
        );
        $findTagStatement = $this->database->prepare('SELECT id FROM tags WHERE workspace_id = :workspace_id AND name = :name');
        $insertLinkStatement = $this->database->prepare('INSERT IGNORE INTO note_tags (note_id, tag_id) VALUES (:note_id, :tag_id)');

        foreach ($tagNames as $tagName) {
            $insertTagStatement->execute(['id' => UuidGenerator::generate(), 'workspace_id' => $membership->workspaceId, 'name' => $tagName]);
            $findTagStatement->execute(['workspace_id' => $membership->workspaceId, 'name' => $tagName]);
            $tagId = (string) $findTagStatement->fetchColumn();
            $insertLinkStatement->execute(['note_id' => $noteId, 'tag_id' => $tagId]);
        }

        $this->deleteUnusedTags($membership->workspaceId);
    }

    /**
     * Removes the workspace's tags that no note (Trash included) uses any more. Also called after
     * notes are deleted for good from Trash, which may leave tags unused.
     */
    public function deleteUnusedTags(string $workspaceId): void
    {
        $deleteStatement = $this->database->prepare(
            'DELETE tags FROM tags
             LEFT JOIN note_tags ON note_tags.tag_id = tags.id
             WHERE tags.workspace_id = :workspace_id AND note_tags.tag_id IS NULL'
        );
        $deleteStatement->execute(['workspace_id' => $workspaceId]);
    }
}
