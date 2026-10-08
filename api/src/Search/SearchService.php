<?php

declare(strict_types=1);

namespace Muninn\Api\Search;

use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Notes\MarkdownExcerpt;
use Muninn\Api\Tags\TagService;
use PDO;

/**
 * Global note search (decisions D011 and D038).
 *
 * Search looks only inside the workspaces the caller's memberships allow (the list comes from
 * WorkspaceAuthorizer), never at trashed notes, and at archived notes only when asked. Nothing
 * about other notes leaks out: no counts, no titles, no hint that more matched elsewhere.
 *
 * Matching: every word typed must appear somewhere in the note's title, text or one of its tag
 * names, anywhere inside a word (so "möte" finds "mötesanteckningar"). The table collation makes
 * matching ignore case. Notes whose title contains every word come first, then the most
 * recently changed.
 */
final class SearchService
{
    /** Most results one search returns. */
    public const RESULT_LIMIT = 50;

    /** Words used from one query; more are ignored. */
    public const MAXIMUM_TERMS = 10;

    /** Characters shown around the first hit in the note's text. */
    private const SNIPPET_LENGTH = 180;

    /** Characters of context shown before the first hit. */
    private const SNIPPET_LEAD = 50;

    /** Escape character for LIKE patterns, so %, _ and ! typed by users are matched literally. */
    private const LIKE_ESCAPE_CHARACTER = '!';

    public function __construct(
        private readonly PDO $database,
        private readonly TagService $tagService,
    ) {
    }

    /**
     * Splits what the user typed into search words: whitespace separates them, duplicates
     * (ignoring case) are dropped, and at most MAXIMUM_TERMS are kept.
     *
     * @return list<string>
     */
    public static function searchTerms(string $queryText): array
    {
        $termsByLowercase = [];
        foreach (preg_split('/\s+/u', trim($queryText)) ?: [] as $searchTerm) {
            if ($searchTerm !== '') {
                $termsByLowercase[mb_strtolower($searchTerm)] ??= $searchTerm;
            }
        }

        return array_slice(array_values($termsByLowercase), 0, self::MAXIMUM_TERMS);
    }

    /**
     * Searches the notes of the given workspaces.
     *
     * @param list<string> $readableWorkspaceIds From WorkspaceAuthorizer: the ONLY workspaces searched.
     * @param list<string> $searchTerms From searchTerms(); must not be empty.
     * @return list<array<string, mixed>>
     */
    public function search(array $readableWorkspaceIds, array $searchTerms, bool $includeArchived): array
    {
        if ($readableWorkspaceIds === [] || $searchTerms === []) {
            return [];
        }

        $queryParameters = [];
        $workspacePlaceholders = [];
        foreach ($readableWorkspaceIds as $workspaceIndex => $workspaceId) {
            $workspacePlaceholders[] = ':workspace_' . $workspaceIndex;
            $queryParameters['workspace_' . $workspaceIndex] = $workspaceId;
        }

        // Real prepared statements cannot reuse a placeholder, so each use gets its own name.
        $termConditions = [];
        $titleConditions = [];
        foreach ($searchTerms as $termIndex => $searchTerm) {
            $likePattern = '%' . self::escapeLikePattern($searchTerm) . '%';
            foreach (['title', 'content', 'tag', 'rank'] as $placeholderUse) {
                $queryParameters[$placeholderUse . '_' . $termIndex] = $likePattern;
            }
            $termConditions[] = "(notes.title LIKE :title_$termIndex ESCAPE '!'
                                  OR notes.content LIKE :content_$termIndex ESCAPE '!'
                                  OR EXISTS (SELECT 1 FROM note_tags JOIN tags ON tags.id = note_tags.tag_id
                                             WHERE note_tags.note_id = notes.id AND tags.name LIKE :tag_$termIndex ESCAPE '!'))";
            $titleConditions[] = "notes.title LIKE :rank_$termIndex ESCAPE '!'";
        }
        // Where the first word occurs in the text, to cut a snippet around it (0 = only in title/tags).
        $queryParameters['snippet_term'] = $searchTerms[0];

        $selectStatement = $this->database->prepare(
            'SELECT notes.id, notes.workspace_id, notes.title, notes.updated_at, notes.archived_at,
                    workspaces.name AS workspace_name, workspaces.kind AS workspace_kind,
                    folders.name AS folder_name,
                    LOCATE(:snippet_term, notes.content) AS first_hit_position,
                    (' . implode(' AND ', $titleConditions) . ') AS title_has_every_term
             FROM notes
             JOIN workspaces ON workspaces.id = notes.workspace_id
             LEFT JOIN folders ON folders.id = notes.folder_id
             WHERE notes.workspace_id IN (' . implode(', ', $workspacePlaceholders) . ')
               AND notes.trashed_at IS NULL
               ' . ($includeArchived ? '' : 'AND notes.archived_at IS NULL') . '
               AND ' . implode(' AND ', $termConditions) . '
             ORDER BY title_has_every_term DESC, notes.updated_at DESC, notes.id
             LIMIT ' . self::RESULT_LIMIT
        );
        $selectStatement->execute($queryParameters);
        $resultRows = $selectStatement->fetchAll();
        if ($resultRows === []) {
            return [];
        }

        $noteIds = array_map(static fn (array $resultRow): string => (string) $resultRow['id'], $resultRows);
        $tagNamesByNote = $this->tagService->tagNamesByNote($noteIds);
        $snippetsByNote = $this->snippets($resultRows);

        return array_map(static fn (array $resultRow): array => [
            'id' => $resultRow['id'],
            'workspace_id' => $resultRow['workspace_id'],
            'workspace_name' => $resultRow['workspace_name'],
            'workspace_kind' => $resultRow['workspace_kind'],
            'folder_name' => $resultRow['folder_name'],
            'title' => $resultRow['title'],
            'snippet' => $snippetsByNote[$resultRow['id']] ?? '',
            'tags' => $tagNamesByNote[$resultRow['id']] ?? [],
            'updated_at' => UtcTimestamp::toIso((string) $resultRow['updated_at']),
            'archived_at' => UtcTimestamp::toIsoOrNull($resultRow['archived_at']),
        ], $resultRows);
    }

    /**
     * Cuts a one-line piece of each result's text: around the first word's first hit, or the
     * start of the text when that word only matched the title or a tag. Only the short piece
     * is read from the database, never the whole (possibly large) note.
     *
     * @param list<array<string, mixed>> $resultRows
     * @return array<string, string> Note ID => snippet.
     */
    private function snippets(array $resultRows): array
    {
        $snippetStatement = $this->database->prepare(
            'SELECT SUBSTRING(content, :start_position, ' . self::SNIPPET_LENGTH . ') FROM notes WHERE id = :id'
        );

        $snippetsByNote = [];
        foreach ($resultRows as $resultRow) {
            $firstHitPosition = (int) $resultRow['first_hit_position'];
            $startPosition = max(1, $firstHitPosition - self::SNIPPET_LEAD);
            $snippetStatement->execute(['start_position' => $startPosition, 'id' => $resultRow['id']]);
            $snippetText = (string) $snippetStatement->fetchColumn();

            // Remove Markdown markers so the snippet is one readable line (D051), and mark cut-off ends.
            $snippetText = MarkdownExcerpt::plainText($snippetText);
            if ($startPosition > 1 && $snippetText !== '') {
                $snippetText = '…' . $snippetText;
            }
            $snippetsByNote[(string) $resultRow['id']] = $snippetText;
        }

        return $snippetsByNote;
    }

    /** Escapes LIKE wildcards so the user's text is matched literally. */
    private static function escapeLikePattern(string $searchTerm): string
    {
        return str_replace(
            [self::LIKE_ESCAPE_CHARACTER, '%', '_'],
            [self::LIKE_ESCAPE_CHARACTER . self::LIKE_ESCAPE_CHARACTER, self::LIKE_ESCAPE_CHARACTER . '%', self::LIKE_ESCAPE_CHARACTER . '_'],
            $searchTerm,
        );
    }
}
