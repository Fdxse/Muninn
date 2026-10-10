-- Muninn migration 0011: the administrator's inbox for "Contact admin" messages (D065).
-- All timestamps are UTC. All primary keys are random UUIDv4 strings generated in PHP (D024).

-- One conversation between an everyday user and the administrators. It starts with the user's
-- "Contact admin" message; after that both sides may reply until an administrator closes it.
-- Conversations are deleted for good one year (admin_messages.retention_days) after their newest
-- message.
CREATE TABLE admin_conversations (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    -- The everyday user who wrote to the administrator. Only they and the administrators see it.
    user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- The optional "How can the admin reach you?" answer from the first message (plain text,
    -- at most 200 characters, checked in PHP). '' when the user left it empty.
    contact_details VARCHAR(200) NOT NULL DEFAULT '',
    -- 'open' while both sides may write; 'closed' once an administrator has finished with it
    -- (the user then starts a new conversation; an administrator may reopen it).
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    -- Who wrote the newest message: 'user' means it waits for an administrator's answer.
    last_message_by ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    -- Unread flags: set when the other side writes, cleared when this side opens the
    -- conversation. The administrators share one flag: when any of them reads it, it is read.
    unread_by_user TINYINT(1) NOT NULL DEFAULT 0,
    unread_by_admin TINYINT(1) NOT NULL DEFAULT 1,
    -- Microseconds, so messages written in the same second keep their order.
    created_at DATETIME(6) NOT NULL,
    last_message_at DATETIME(6) NOT NULL,
    closed_at DATETIME(6) NULL,
    closed_by_user_id CHAR(36) CHARACTER SET ascii NULL,
    -- The user's own list, newest first.
    KEY admin_conversations_user_index (user_id, last_message_at),
    -- The administrator's inbox, filtered by status, newest first.
    KEY admin_conversations_status_index (status, last_message_at),
    -- The one-year cleanup.
    KEY admin_conversations_last_message_index (last_message_at),
    CONSTRAINT admin_conversations_user_fk FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT admin_conversations_closed_by_fk FOREIGN KEY (closed_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One message in a conversation, from the user or from an administrator.
CREATE TABLE admin_conversation_messages (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    conversation_id CHAR(36) CHARACTER SET ascii NOT NULL,
    author_user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- 'admin' for a system administrator's answer; the user sees it as "Administrator".
    author_role ENUM('user', 'admin') NOT NULL,
    -- Plain text (no Markdown, no HTML), at most 1000 characters (checked in PHP).
    body TEXT NOT NULL,
    created_at DATETIME(6) NOT NULL,
    -- Showing a conversation: its messages in order.
    KEY admin_conversation_messages_conversation_index (conversation_id, created_at),
    -- The hourly limit on the user's replies.
    KEY admin_conversation_messages_author_index (author_user_id, created_at),
    -- Deleting a conversation (the one-year cleanup) deletes its messages.
    CONSTRAINT admin_conversation_messages_conversation_fk FOREIGN KEY (conversation_id) REFERENCES admin_conversations (id) ON DELETE CASCADE,
    CONSTRAINT admin_conversation_messages_author_fk FOREIGN KEY (author_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
