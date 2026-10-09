<?php

declare(strict_types=1);

namespace Muninn\Api\Chat;

use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\UuidGenerator;
use PDO;

/**
 * Database access for chat messages (decision D062). Callers decide access first (ChatPolicy);
 * this class only stores and reads messages of the channel it is given.
 *
 * A channel is a shared workspace (its ID) or the global channel (null). Open chats stay up to
 * date by asking every few seconds for "what changed since <cursor>": new messages, and messages
 * deleted meanwhile (which then disappear from the screen). The cursor is the database's own
 * clock, never the browser's.
 */
final class ChatService
{
    /** Messages shown when a channel opens, and per "show earlier messages". */
    public const PAGE_SIZE = 50;

    /** Longest message, in characters. */
    public const MESSAGE_MAX_LENGTH = 2000;

    /** Messages one user may send per minute, across all channels. */
    public const MAXIMUM_MESSAGES_PER_MINUTE = 10;

    /**
     * Most changes one poll returns. A screen that missed more than this (e.g. a laptop that slept
     * for hours) is told to reload the channel instead.
     */
    private const MAXIMUM_CHANGES_PER_POLL = 200;

    /**
     * Polls look this far behind their cursor, so a message whose insert committed a moment after
     * the previous poll read the clock is never skipped. The browser ignores messages it already has.
     */
    private const POLL_OVERLAP_SECONDS = 5;

    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * The newest messages of a channel, oldest first, and whether there are older ones.
     *
     * @return array{messages: list<array<string, mixed>>, has_older: bool, cursor: string}
     */
    public function listLatest(?string $workspaceId, string $viewerUserId, bool $viewerCanModerate): array
    {
        // Read the clock first: anything that changes after this moment is picked up by the next poll.
        $cursor = $this->currentCursor();
        $messageRows = $this->selectMessages(
            'chat_messages.workspace_id <=> :workspace_id AND chat_messages.deleted_at IS NULL',
            ['workspace_id' => $workspaceId],
            'chat_messages.created_at DESC, chat_messages.id DESC',
            self::PAGE_SIZE + 1,
        );

        return $this->page($messageRows, $viewerUserId, $viewerCanModerate) + ['cursor' => $cursor];
    }

    /**
     * The messages just before $beforeMessageId in the same channel, oldest first. An unknown
     * anchor (for example one removed by the 90-day cleanup) simply gives an empty page.
     *
     * @return array{messages: list<array<string, mixed>>, has_older: bool}
     */
    public function listBefore(?string $workspaceId, string $beforeMessageId, string $viewerUserId, bool $viewerCanModerate): array
    {
        if (!UuidGenerator::isValid($beforeMessageId)) {
            throw HttpException::badRequest('invalid_before', 'The "before" value is not a message ID.');
        }
        $anchorStatement = $this->database->prepare(
            'SELECT created_at FROM chat_messages WHERE id = :id AND workspace_id <=> :workspace_id'
        );
        $anchorStatement->execute(['id' => $beforeMessageId, 'workspace_id' => $workspaceId]);
        $anchorCreatedAt = $anchorStatement->fetchColumn();
        if ($anchorCreatedAt === false) {
            return ['messages' => [], 'has_older' => false];
        }

        $messageRows = $this->selectMessages(
            'chat_messages.workspace_id <=> :workspace_id AND chat_messages.deleted_at IS NULL
             AND (chat_messages.created_at < :anchor_created_at
                  OR (chat_messages.created_at = :anchor_created_at_again AND chat_messages.id < :anchor_id))',
            [
                'workspace_id' => $workspaceId,
                'anchor_created_at' => $anchorCreatedAt,
                'anchor_created_at_again' => $anchorCreatedAt,
                'anchor_id' => $beforeMessageId,
            ],
            'chat_messages.created_at DESC, chat_messages.id DESC',
            self::PAGE_SIZE + 1,
        );

        return $this->page($messageRows, $viewerUserId, $viewerCanModerate);
    }

    /**
     * Everything that changed in a channel since $cursor: new messages, and deleted ones (marked
     * is_deleted, without text), oldest change first. When more changed than one poll returns,
     * "reset" tells the browser to reload the channel instead.
     *
     * @return array{messages: list<array<string, mixed>>, reset: bool, cursor: string}
     * @throws HttpException 400 when the cursor is not one this API handed out.
     */
    public function listChangedSince(?string $workspaceId, string $cursor, string $viewerUserId, bool $viewerCanModerate): array
    {
        $sinceDatabaseTime = self::cursorToDatabaseTime($cursor);
        $newCursor = $this->currentCursor();
        $messageRows = $this->selectMessages(
            'chat_messages.workspace_id <=> :workspace_id
             AND chat_messages.changed_at > CAST(:since AS DATETIME(6)) - INTERVAL ' . self::POLL_OVERLAP_SECONDS . ' SECOND',
            ['workspace_id' => $workspaceId, 'since' => $sinceDatabaseTime],
            'chat_messages.changed_at, chat_messages.id',
            self::MAXIMUM_CHANGES_PER_POLL + 1,
        );
        if (count($messageRows) > self::MAXIMUM_CHANGES_PER_POLL) {
            return ['messages' => [], 'reset' => true, 'cursor' => $newCursor];
        }

        return [
            'messages' => array_map(
                fn (array $messageRow): array => $this->toPublicArray($messageRow, $viewerUserId, $viewerCanModerate),
                $messageRows,
            ),
            'reset' => false,
            'cursor' => $newCursor,
        ];
    }

    /**
     * Stores a new message and returns it as its author sees it.
     *
     * @param string $messageBody Already validated plain text.
     * @return array<string, mixed>
     */
    public function post(?string $workspaceId, string $authorUserId, string $messageBody): array
    {
        $messageId = UuidGenerator::generate();
        $insertStatement = $this->database->prepare(
            'INSERT INTO chat_messages (id, workspace_id, author_user_id, body, created_at, changed_at)
             VALUES (:id, :workspace_id, :author_user_id, :body, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
        );
        $insertStatement->execute([
            'id' => $messageId,
            'workspace_id' => $workspaceId,
            'author_user_id' => $authorUserId,
            'body' => $messageBody,
        ]);

        $messageRows = $this->selectMessages('chat_messages.id = :id', ['id' => $messageId], 'chat_messages.id', 1);

        return $this->toPublicArray($messageRows[0], $authorUserId, false);
    }

    /** Messages the user sent during the last minute (deleted ones count too), for the rate limit. */
    public function countSentInLastMinute(string $userId): int
    {
        $countStatement = $this->database->prepare(
            'SELECT COUNT(*) FROM chat_messages
             WHERE author_user_id = :user_id AND created_at > UTC_TIMESTAMP(6) - INTERVAL 1 MINUTE'
        );
        $countStatement->execute(['user_id' => $userId]);

        return (int) $countStatement->fetchColumn();
    }

    /**
     * The facts needed to decide whether a message may be deleted, or null when there is no such
     * message that is still visible.
     *
     * @return array{id: string, workspace_id: ?string, author_user_id: string}|null
     */
    public function findUndeleted(string $messageId): ?array
    {
        if (!UuidGenerator::isValid($messageId)) {
            return null;
        }
        $selectStatement = $this->database->prepare(
            'SELECT id, workspace_id, author_user_id FROM chat_messages WHERE id = :id AND deleted_at IS NULL'
        );
        $selectStatement->execute(['id' => $messageId]);
        $messageRow = $selectStatement->fetch();
        if ($messageRow === false) {
            return null;
        }

        return [
            'id' => (string) $messageRow['id'],
            'workspace_id' => $messageRow['workspace_id'] === null ? null : (string) $messageRow['workspace_id'],
            'author_user_id' => (string) $messageRow['author_user_id'],
        ];
    }

    /**
     * Deletes a message: its text is removed at once, and the row stays as a marker until the
     * 90-day cleanup, so open chats learn about the deletion on their next poll.
     */
    public function markDeleted(string $messageId, string $deletedByUserId): void
    {
        $updateStatement = $this->database->prepare(
            'UPDATE chat_messages
             SET body = \'\', deleted_at = UTC_TIMESTAMP(6), changed_at = UTC_TIMESTAMP(6), deleted_by_user_id = :deleted_by
             WHERE id = :id AND deleted_at IS NULL'
        );
        $updateStatement->execute(['id' => $messageId, 'deleted_by' => $deletedByUserId]);
    }

    /**
     * Deletes, for good, messages older than $retentionDays (at most $maximumMessages per call).
     *
     * @return int Messages deleted.
     */
    public function deleteExpired(int $retentionDays, int $maximumMessages): int
    {
        // Both numbers come from configuration and constants, never from a request.
        $deleteStatement = $this->database->prepare(
            'DELETE FROM chat_messages
             WHERE created_at < UTC_TIMESTAMP(6) - INTERVAL ' . $retentionDays . ' DAY
             LIMIT ' . $maximumMessages
        );
        $deleteStatement->execute();

        return $deleteStatement->rowCount();
    }

    /**
     * Turns a cursor sent by a browser back into a database time.
     *
     * @throws HttpException 400 when it is not shaped like a cursor this API hands out.
     */
    public static function cursorToDatabaseTime(string $cursor): string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?)Z$/', $cursor, $cursorParts) !== 1) {
            throw HttpException::badRequest('invalid_since', 'The "since" value is not a chat cursor.');
        }

        return $cursorParts[1] . ' ' . $cursorParts[2];
    }

    /** The database's current UTC time, as a cursor for the next poll. */
    private function currentCursor(): string
    {
        return self::databaseTimeToIso((string) $this->database->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn());
    }

    /** "2026-10-09 13:00:00.123456" → "2026-10-09T13:00:00.123456Z". */
    private static function databaseTimeToIso(string $databaseTime): string
    {
        return str_replace(' ', 'T', $databaseTime) . 'Z';
    }

    /**
     * Turns rows read newest-first (one more than a page) into a page in reading order.
     *
     * @param list<array<string, mixed>> $messageRows
     * @return array{messages: list<array<string, mixed>>, has_older: bool}
     */
    private function page(array $messageRows, string $viewerUserId, bool $viewerCanModerate): array
    {
        $hasOlder = count($messageRows) > self::PAGE_SIZE;
        $pageRows = array_reverse(array_slice($messageRows, 0, self::PAGE_SIZE));

        return [
            'messages' => array_map(
                fn (array $messageRow): array => $this->toPublicArray($messageRow, $viewerUserId, $viewerCanModerate),
                $pageRows,
            ),
            'has_older' => $hasOlder,
        ];
    }

    /**
     * Messages with their authors' names.
     *
     * @param array<string, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function selectMessages(string $whereClause, array $parameters, string $orderBy, int $limit): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT chat_messages.id, chat_messages.author_user_id, chat_messages.body, chat_messages.created_at,
                    chat_messages.deleted_at, users.username, users.display_name
             FROM chat_messages
             JOIN users ON users.id = chat_messages.author_user_id
             WHERE ' . $whereClause . '
             ORDER BY ' . $orderBy . '
             LIMIT ' . $limit
        );
        $selectStatement->execute($parameters);

        return $selectStatement->fetchAll();
    }

    /**
     * One message as the viewer sees it. A deleted message keeps only its ID, so the browser
     * can take it off the screen.
     *
     * @param array<string, mixed> $messageRow
     * @return array<string, mixed>
     */
    private function toPublicArray(array $messageRow, string $viewerUserId, bool $viewerCanModerate): array
    {
        if ($messageRow['deleted_at'] !== null) {
            return ['id' => $messageRow['id'], 'is_deleted' => true];
        }
        $isOwnMessage = $messageRow['author_user_id'] === $viewerUserId;

        return [
            'id' => $messageRow['id'],
            'is_deleted' => false,
            'body' => $messageRow['body'],
            'created_at' => self::databaseTimeToIso((string) $messageRow['created_at']),
            'author' => [
                'username' => $messageRow['username'],
                'display_name' => $messageRow['display_name'],
            ],
            'is_own' => $isOwnMessage,
            // Convenience for the UI; the DELETE endpoint checks the same rule again.
            'can_delete' => $isOwnMessage || $viewerCanModerate,
        ];
    }
}
