-- Muninn migration 0007: Magic Links (D019, D059).
-- All timestamps are UTC. All primary keys are random UUIDv4 strings generated in PHP (D024).
-- The daily time window is stored as local wall-clock times; the API evaluates them in the
-- configured magic_links.timezone (default Europe/Stockholm), never in the server's own zone.

-- A link that opens one workspace, folder (with its sub-folders) or note without signing in.
-- The raw token is shown once when the link is created; only its SHA-256 hash is stored.
-- Links are reusable until they expire or are revoked: hashing does not make them single-use.
CREATE TABLE magic_links (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    -- The workspace everything the link opens lives in. Deleting an (empty) shared workspace
    -- removes its links with it.
    workspace_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- What the link opens. target_id is NULL for 'workspace' and otherwise a folder or note ID.
    -- There is no foreign key on target_id (it points at two different tables); every use of the
    -- link checks that the target still exists, in this workspace, and is not in Trash.
    target_type ENUM('workspace', 'folder', 'note') NOT NULL,
    target_id CHAR(36) CHARACTER SET ascii NULL,
    -- A name the creator chooses, e.g. "Kitchen tablet", so links can be told apart.
    label VARCHAR(100) NOT NULL,
    -- 'read' or 'write' (edit and create notes). Never delete (D019).
    permission ENUM('read', 'write') NOT NULL,
    -- SHA-256 of the raw token.
    token_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    valid_from DATETIME NOT NULL,
    valid_until DATETIME NOT NULL,
    -- Optional daily window, local time in magic_links.timezone. Both set or both NULL. When
    -- start is later than end the window runs over midnight (e.g. 22:00-06:00).
    daily_start_time TIME NULL,
    daily_end_time TIME NULL,
    -- The workspace Admin or Owner who created the link. The link only works while this account
    -- is active and still an Admin or Owner of the workspace.
    created_by_user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    created_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    revoked_by_user_id CHAR(36) CHARACTER SET ascii NULL,
    -- When the link was last opened, and how many times.
    last_used_at DATETIME NULL,
    use_count INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY magic_links_token_hash_unique (token_hash),
    KEY magic_links_workspace_index (workspace_id, created_at),
    CONSTRAINT magic_links_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
    CONSTRAINT magic_links_created_by_fk FOREIGN KEY (created_by_user_id) REFERENCES users (id),
    CONSTRAINT magic_links_revoked_by_fk FOREIGN KEY (revoked_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A browser that opened a Magic Link. Opening the link trades the token for a short-lived,
-- separate cookie (never the normal sign-in cookie). Every request made with it checks the link
-- again (revoked, validity, daily window, creator, target), so revoking takes effect at once.
CREATE TABLE magic_link_sessions (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    magic_link_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- SHA-256 of the raw cookie token.
    token_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    -- Synchronizer token for state-changing requests (D023), useless without the cookie.
    csrf_token CHAR(43) CHARACTER SET ascii NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    -- Set when the visitor leaves ("Close link" on the page).
    ended_at DATETIME NULL,
    UNIQUE KEY magic_link_sessions_token_hash_unique (token_hash),
    KEY magic_link_sessions_link_index (magic_link_id),
    CONSTRAINT magic_link_sessions_link_fk FOREIGN KEY (magic_link_id) REFERENCES magic_links (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
