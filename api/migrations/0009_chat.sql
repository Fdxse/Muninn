-- Muninn migration 0009: chat (D062).
-- All timestamps are UTC. All primary keys are random UUIDv4 strings generated in PHP (D024).

-- How much chat each account may use, set by the system administrator on the Users page:
--   off                no chat at all;
--   own_workspaces     chat only in shared workspaces where they are Owner;
--   member_workspaces  chat in every shared workspace they are a member of (the default);
--   global             member_workspaces, plus writing in the global channel.
-- Everyone except "off" may read the global channel. The workspace role still applies on top
-- (Readers read, Editors and up write). Existing accounts get the default.
ALTER TABLE users
    ADD COLUMN chat_access ENUM('off', 'own_workspaces', 'member_workspaces', 'global')
        NOT NULL DEFAULT 'member_workspaces' AFTER status;

-- One chat message. A workspace's channel when workspace_id is set, the global channel when it
-- is NULL. Messages are deleted for good after chat.retention_days (90 by default).
CREATE TABLE chat_messages (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    -- NULL = the global channel.
    workspace_id CHAR(36) CHARACTER SET ascii NULL,
    author_user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- Plain text (no Markdown, no HTML), at most 2000 characters (checked in PHP). Emptied when
    -- the message is deleted, so a deleted message keeps no text.
    body TEXT NOT NULL,
    -- Microseconds, so messages sent in the same second keep their order.
    created_at DATETIME(6) NOT NULL,
    -- When the message last changed: created_at, or the moment it was deleted. Open chats ask
    -- for "everything that changed since"; that is how a deletion reaches other screens.
    changed_at DATETIME(6) NOT NULL,
    deleted_at DATETIME(6) NULL,
    deleted_by_user_id CHAR(36) CHARACTER SET ascii NULL,
    -- Polling: the changes in one channel after a moment.
    KEY chat_messages_channel_changed_index (workspace_id, changed_at),
    -- Opening a channel and scrolling back: the newest messages before a moment.
    KEY chat_messages_channel_created_index (workspace_id, created_at),
    -- The rate limit: messages one user sent during the last minute.
    KEY chat_messages_author_created_index (author_user_id, created_at),
    -- The 90-day cleanup.
    KEY chat_messages_created_index (created_at),
    -- Deleting a workspace deletes its chat.
    CONSTRAINT chat_messages_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
    CONSTRAINT chat_messages_author_fk FOREIGN KEY (author_user_id) REFERENCES users (id),
    CONSTRAINT chat_messages_deleted_by_fk FOREIGN KEY (deleted_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
