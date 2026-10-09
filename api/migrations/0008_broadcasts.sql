-- Muninn migration 0008: administrator broadcast messages (D061).
-- All timestamps are UTC. All primary keys are random UUIDv4 strings generated in PHP (D024).

-- A message the system administrator shows to every signed-in everyday user between starts_at
-- and ends_at. Three kinds:
--   once   a banner shown once per user, then never again;
--   sticky a banner shown on every page until the user closes it with its X;
--   vote   a question with 2-5 options, shown until the user has voted.
CREATE TABLE broadcasts (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    kind ENUM('once', 'sticky', 'vote') NOT NULL,
    -- Plain text (no Markdown, no HTML); line breaks are kept. For a vote this is the question.
    message VARCHAR(1000) NOT NULL,
    -- Votes only: 1 when users may pick several options, 0 for a single choice.
    allows_multiple_choices TINYINT(1) NOT NULL DEFAULT 0,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    created_by_user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    -- Every page load asks for the broadcasts showing right now.
    KEY broadcasts_schedule_index (starts_at, ends_at),
    CONSTRAINT broadcasts_created_by_fk FOREIGN KEY (created_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The answers of a vote, in the order the administrator wrote them. Fixed once created.
CREATE TABLE broadcast_options (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    broadcast_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- 1-5, the order shown to users.
    position TINYINT UNSIGNED NOT NULL,
    label VARCHAR(100) NOT NULL,
    UNIQUE KEY broadcast_options_position_unique (broadcast_id, position),
    CONSTRAINT broadcast_options_broadcast_fk FOREIGN KEY (broadcast_id) REFERENCES broadcasts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per user who is done with a broadcast: saw the one-time banner, closed the sticky
-- banner, or voted. A broadcast with a row for a user is never shown to that user again.
CREATE TABLE broadcast_receipts (
    broadcast_id CHAR(36) CHARACTER SET ascii NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    responded_at DATETIME NOT NULL,
    PRIMARY KEY (broadcast_id, user_id),
    KEY broadcast_receipts_user_index (user_id),
    CONSTRAINT broadcast_receipts_broadcast_fk FOREIGN KEY (broadcast_id) REFERENCES broadcasts (id) ON DELETE CASCADE,
    CONSTRAINT broadcast_receipts_user_fk FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The options each user picked in a vote (one row per picked option).
CREATE TABLE broadcast_votes (
    option_id CHAR(36) CHARACTER SET ascii NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    voted_at DATETIME NOT NULL,
    PRIMARY KEY (option_id, user_id),
    KEY broadcast_votes_user_index (user_id),
    CONSTRAINT broadcast_votes_option_fk FOREIGN KEY (option_id) REFERENCES broadcast_options (id) ON DELETE CASCADE,
    CONSTRAINT broadcast_votes_user_fk FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
