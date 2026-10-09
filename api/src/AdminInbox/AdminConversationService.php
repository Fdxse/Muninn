<?php

declare(strict_types=1);

namespace Muninn\Api\AdminInbox;

use Muninn\Api\Database\UtcTimestamp;
use Muninn\Api\Http\HttpException;
use Muninn\Api\Security\UuidGenerator;
use PDO;
use Throwable;

/**
 * Database access for the administrator's inbox (decision D065): conversations that start with a
 * "Contact admin" message (D058) and continue with replies from both sides.
 *
 * Callers decide access first (AdminConversationController): an everyday user only ever reaches
 * their own conversations, and only system administrators reach the whole inbox. This class only
 * stores and reads what it is asked for.
 */
final class AdminConversationService
{
    /** Who wrote a message. */
    public const ROLE_USER = 'user';
    public const ROLE_ADMIN = 'admin';

    /** Conversation states. */
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    /** Longest message, in characters (the same as the first "Contact admin" message). */
    public const MESSAGE_MAX_LENGTH = 1000;

    /** Longest "How can the admin reach you?" answer, in characters. */
    public const CONTACT_MAX_LENGTH = 200;

    /**
     * Most messages in one conversation, so a conversation always fits on one screen without
     * paging. After that the user starts a new conversation.
     */
    public const MAXIMUM_MESSAGES_PER_CONVERSATION = 200;

    /** Most conversations one inbox or "My messages" list returns, newest first. */
    public const LIST_LIMIT = 200;

    /** Length of the preview of the first message shown in lists. */
    private const EXCERPT_LENGTH = 120;

    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * Starts a conversation with the user's first message, in one transaction.
     *
     * @param string $messageText Already validated plain text.
     * @param string $contactDetails Already validated; '' when the user gave none.
     * @return string The new conversation's ID.
     */
    public function start(string $userId, string $messageText, string $contactDetails): string
    {
        $conversationId = UuidGenerator::generate();
        $this->database->beginTransaction();
        try {
            $insertConversation = $this->database->prepare(
                'INSERT INTO admin_conversations
                    (id, user_id, contact_details, status, last_message_by, unread_by_user, unread_by_admin, created_at, last_message_at)
                 VALUES (:id, :user_id, :contact_details, :status, :last_message_by, 0, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
            );
            $insertConversation->execute([
                'id' => $conversationId,
                'user_id' => $userId,
                'contact_details' => $contactDetails,
                'status' => self::STATUS_OPEN,
                'last_message_by' => self::ROLE_USER,
            ]);
            $this->insertMessage($conversationId, $userId, self::ROLE_USER, $messageText);
            $this->database->commit();
        } catch (Throwable $insertFailure) {
            $this->database->rollBack();
            throw $insertFailure;
        }

        return $conversationId;
    }

    /**
     * Adds a reply to an existing conversation and updates who it now waits for. An administrator's
     * reply reopens a closed conversation; the controller refuses a user's reply to a closed one.
     *
     * @param string $messageText Already validated plain text.
     * @throws HttpException 409 when the conversation already holds the most messages allowed.
     */
    public function reply(string $conversationId, string $authorUserId, string $authorRole, string $messageText): void
    {
        $this->database->beginTransaction();
        try {
            // Lock the conversation so two replies at once cannot both slip under the message limit.
            $lockStatement = $this->database->prepare('SELECT id FROM admin_conversations WHERE id = :id FOR UPDATE');
            $lockStatement->execute(['id' => $conversationId]);
            if ($lockStatement->fetchColumn() === false) {
                throw HttpException::notFound();
            }
            if ($this->countMessages($conversationId) >= self::MAXIMUM_MESSAGES_PER_CONVERSATION) {
                throw HttpException::conflict('conversation_full', 'This conversation is full. Please start a new one.');
            }

            $this->insertMessage($conversationId, $authorUserId, $authorRole, $messageText);

            if ($authorRole === self::ROLE_ADMIN) {
                // An administrator's answer: the user has something new to read, and a closed
                // conversation opens again so the user can answer back.
                $updateStatement = $this->database->prepare(
                    'UPDATE admin_conversations
                     SET last_message_by = :last_message_by, last_message_at = UTC_TIMESTAMP(6),
                         unread_by_user = 1, unread_by_admin = 0,
                         status = :open_status, closed_at = NULL, closed_by_user_id = NULL
                     WHERE id = :id'
                );
                $updateStatement->execute(['last_message_by' => self::ROLE_ADMIN, 'open_status' => self::STATUS_OPEN, 'id' => $conversationId]);
            } else {
                // The user's answer: it waits for an administrator again.
                $updateStatement = $this->database->prepare(
                    'UPDATE admin_conversations
                     SET last_message_by = :last_message_by, last_message_at = UTC_TIMESTAMP(6),
                         unread_by_user = 0, unread_by_admin = 1
                     WHERE id = :id'
                );
                $updateStatement->execute(['last_message_by' => self::ROLE_USER, 'id' => $conversationId]);
            }
            $this->database->commit();
        } catch (Throwable $replyFailure) {
            $this->database->rollBack();
            throw $replyFailure;
        }
    }

    /**
     * One conversation's facts (no messages), or null when it does not exist. With $ownerUserId
     * set, a conversation of anyone else counts as not existing.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $conversationId, ?string $ownerUserId = null): ?array
    {
        if (!UuidGenerator::isValid($conversationId)) {
            return null;
        }
        $conversationRows = $this->selectConversations(
            'admin_conversations.id = :id' . ($ownerUserId === null ? '' : ' AND admin_conversations.user_id = :owner_user_id'),
            $ownerUserId === null ? ['id' => $conversationId] : ['id' => $conversationId, 'owner_user_id' => $ownerUserId],
            1,
        );

        return $conversationRows[0] ?? null;
    }

    /**
     * The user's own conversations, newest activity first.
     *
     * @return list<array<string, mixed>>
     */
    public function listForUser(string $userId): array
    {
        return $this->selectConversations('admin_conversations.user_id = :user_id', ['user_id' => $userId], self::LIST_LIMIT);
    }

    /**
     * The administrator's inbox, newest activity first.
     *
     * @param string|null $status 'open', 'closed', or null for all.
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(?string $status): array
    {
        if ($status === null) {
            return $this->selectConversations('1 = 1', [], self::LIST_LIMIT);
        }

        return $this->selectConversations('admin_conversations.status = :status', ['status' => $status], self::LIST_LIMIT);
    }

    /**
     * A conversation's messages, oldest first, with the author's names (the controller decides
     * what each side may see of them).
     *
     * @return list<array<string, mixed>>
     */
    public function messages(string $conversationId): array
    {
        $messageStatement = $this->database->prepare(
            'SELECT admin_conversation_messages.id, admin_conversation_messages.author_user_id,
                    admin_conversation_messages.author_role, admin_conversation_messages.body,
                    admin_conversation_messages.created_at, users.display_name AS author_display_name
             FROM admin_conversation_messages
             JOIN users ON users.id = admin_conversation_messages.author_user_id
             WHERE admin_conversation_messages.conversation_id = :conversation_id
             ORDER BY admin_conversation_messages.created_at, admin_conversation_messages.id'
        );
        $messageStatement->execute(['conversation_id' => $conversationId]);

        return array_map(
            static fn (array $messageRow): array => [
                'id' => (string) $messageRow['id'],
                'author_user_id' => (string) $messageRow['author_user_id'],
                'author_role' => (string) $messageRow['author_role'],
                'author_display_name' => (string) $messageRow['author_display_name'],
                'body' => (string) $messageRow['body'],
                'created_at' => UtcTimestamp::toIso((string) $messageRow['created_at']),
            ],
            $messageStatement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /** The user has now seen the newest administrator reply. */
    public function markReadByUser(string $conversationId): void
    {
        $this->database->prepare('UPDATE admin_conversations SET unread_by_user = 0 WHERE id = :id')
            ->execute(['id' => $conversationId]);
    }

    /** An administrator has now seen the newest message from the user. */
    public function markReadByAdmin(string $conversationId): void
    {
        $this->database->prepare('UPDATE admin_conversations SET unread_by_admin = 0 WHERE id = :id')
            ->execute(['id' => $conversationId]);
    }

    /** Closes a conversation: the user can read it but no longer reply. */
    public function close(string $conversationId, string $adminUserId): void
    {
        $closeStatement = $this->database->prepare(
            'UPDATE admin_conversations
             SET status = :closed_status, closed_at = UTC_TIMESTAMP(6), closed_by_user_id = :admin_user_id, unread_by_admin = 0
             WHERE id = :id AND status = :open_status'
        );
        $closeStatement->execute([
            'closed_status' => self::STATUS_CLOSED,
            'admin_user_id' => $adminUserId,
            'id' => $conversationId,
            'open_status' => self::STATUS_OPEN,
        ]);
    }

    /** Reopens a closed conversation, so the user may reply again. */
    public function reopen(string $conversationId): void
    {
        $reopenStatement = $this->database->prepare(
            'UPDATE admin_conversations SET status = :open_status, closed_at = NULL, closed_by_user_id = NULL WHERE id = :id'
        );
        $reopenStatement->execute(['open_status' => self::STATUS_OPEN, 'id' => $conversationId]);
    }

    /** Conversations with an administrator reply the user has not seen yet. */
    public function countUnreadForUser(string $userId): int
    {
        $countStatement = $this->database->prepare(
            'SELECT COUNT(*) FROM admin_conversations WHERE user_id = :user_id AND unread_by_user = 1'
        );
        $countStatement->execute(['user_id' => $userId]);

        return (int) $countStatement->fetchColumn();
    }

    /** Conversations with a user message no administrator has seen yet. */
    public function countUnreadForAdmin(): int
    {
        return (int) $this->database->query('SELECT COUNT(*) FROM admin_conversations WHERE unread_by_admin = 1')->fetchColumn();
    }

    /** Replies the user wrote during the last hour, for the hourly reply limit. */
    public function countUserRepliesInLastHour(string $userId): int
    {
        $countStatement = $this->database->prepare(
            'SELECT COUNT(*) FROM admin_conversation_messages
             WHERE author_user_id = :user_id AND author_role = :user_role
               AND created_at > UTC_TIMESTAMP(6) - INTERVAL 1 HOUR'
        );
        $countStatement->execute(['user_id' => $userId, 'user_role' => self::ROLE_USER]);

        return (int) $countStatement->fetchColumn();
    }

    /**
     * Deletes conversations whose newest message is older than the retention, at most
     * $maximumConversations at a time. Their messages go with them (ON DELETE CASCADE).
     *
     * @return int Conversations deleted.
     */
    public function deleteExpired(int $retentionDays, int $maximumConversations): int
    {
        $deleteStatement = $this->database->prepare(
            'DELETE FROM admin_conversations
             WHERE last_message_at < UTC_TIMESTAMP(6) - INTERVAL ' . $retentionDays . ' DAY
             ORDER BY last_message_at
             LIMIT ' . $maximumConversations
        );
        $deleteStatement->execute();

        return $deleteStatement->rowCount();
    }

    /** Stores one message. Runs inside the caller's transaction. */
    private function insertMessage(string $conversationId, string $authorUserId, string $authorRole, string $messageText): void
    {
        $insertStatement = $this->database->prepare(
            'INSERT INTO admin_conversation_messages (id, conversation_id, author_user_id, author_role, body, created_at)
             VALUES (:id, :conversation_id, :author_user_id, :author_role, :body, UTC_TIMESTAMP(6))'
        );
        $insertStatement->execute([
            'id' => UuidGenerator::generate(),
            'conversation_id' => $conversationId,
            'author_user_id' => $authorUserId,
            'author_role' => $authorRole,
            'body' => $messageText,
        ]);
    }

    private function countMessages(string $conversationId): int
    {
        $countStatement = $this->database->prepare('SELECT COUNT(*) FROM admin_conversation_messages WHERE conversation_id = :conversation_id');
        $countStatement->execute(['conversation_id' => $conversationId]);

        return (int) $countStatement->fetchColumn();
    }

    /**
     * Conversations with their user, a preview of the first message and the message count,
     * newest activity first.
     *
     * @param array<string, string> $parameters
     * @return list<array<string, mixed>>
     */
    private function selectConversations(string $whereClause, array $parameters, int $limit): array
    {
        $selectStatement = $this->database->prepare(
            'SELECT admin_conversations.id, admin_conversations.user_id, admin_conversations.contact_details,
                    admin_conversations.status, admin_conversations.last_message_by,
                    admin_conversations.unread_by_user, admin_conversations.unread_by_admin,
                    admin_conversations.created_at, admin_conversations.last_message_at, admin_conversations.closed_at,
                    users.username, users.display_name, users.status AS user_status,
                    (SELECT COUNT(*) FROM admin_conversation_messages
                     WHERE admin_conversation_messages.conversation_id = admin_conversations.id) AS message_count,
                    (SELECT admin_conversation_messages.body FROM admin_conversation_messages
                     WHERE admin_conversation_messages.conversation_id = admin_conversations.id
                     ORDER BY admin_conversation_messages.created_at, admin_conversation_messages.id
                     LIMIT 1) AS first_message_body
             FROM admin_conversations
             JOIN users ON users.id = admin_conversations.user_id
             WHERE ' . $whereClause . '
             ORDER BY admin_conversations.last_message_at DESC, admin_conversations.id DESC
             LIMIT ' . $limit
        );
        $selectStatement->execute($parameters);

        return array_map(
            static fn (array $conversationRow): array => [
                'id' => (string) $conversationRow['id'],
                'user_id' => (string) $conversationRow['user_id'],
                'username' => (string) $conversationRow['username'],
                'display_name' => (string) $conversationRow['display_name'],
                'user_status' => (string) $conversationRow['user_status'],
                'contact_details' => (string) $conversationRow['contact_details'],
                'status' => (string) $conversationRow['status'],
                'last_message_by' => (string) $conversationRow['last_message_by'],
                'unread_by_user' => (bool) $conversationRow['unread_by_user'],
                'unread_by_admin' => (bool) $conversationRow['unread_by_admin'],
                'created_at' => UtcTimestamp::toIso((string) $conversationRow['created_at']),
                'last_message_at' => UtcTimestamp::toIso((string) $conversationRow['last_message_at']),
                'closed_at' => UtcTimestamp::toIsoOrNull($conversationRow['closed_at']),
                'message_count' => (int) $conversationRow['message_count'],
                'excerpt' => self::excerpt((string) ($conversationRow['first_message_body'] ?? '')),
            ],
            $selectStatement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /** The start of a message on one line, for lists. */
    private static function excerpt(string $messageText): string
    {
        $oneLineText = trim((string) preg_replace('/\s+/u', ' ', $messageText));
        if (mb_strlen($oneLineText) <= self::EXCERPT_LENGTH) {
            return $oneLineText;
        }

        return rtrim(mb_substr($oneLineText, 0, self::EXCERPT_LENGTH - 1)) . '…';
    }
}
