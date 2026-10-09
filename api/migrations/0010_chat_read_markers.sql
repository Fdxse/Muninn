-- Muninn migration 0010: unread chat messages (D063).
-- All timestamps are UTC.

-- How far each user has read each chat channel. A message is unread for a user when it was
-- written by someone else after last_read_at (or when there is no row yet for that channel).
CREATE TABLE chat_read_markers (
    user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- The workspace ID for a workspace chat, or the word 'global' for the global channel.
    -- Deliberately no foreign key to workspaces: a marker of a deleted workspace, or of one the
    -- user has left, is never read again (unread counts only look at chats the user can open).
    channel_key VARCHAR(36) CHARACTER SET ascii NOT NULL,
    -- Database time of the newest look at the channel (microseconds, like chat_messages).
    last_read_at DATETIME(6) NOT NULL,
    PRIMARY KEY (user_id, channel_key),
    CONSTRAINT chat_read_markers_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
