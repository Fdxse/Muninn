-- Muninn migration 0002: workspaces, workspace memberships and notes (Course MVP Week 2).
-- All timestamps are UTC. All primary keys are random UUIDv4 strings generated in PHP (D024).
-- Existing users get their personal workspace from the API the first time it is needed
-- (MariaDB's UUID() is version 1, not random, so this migration does not backfill rows).

CREATE TABLE workspaces (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    -- 'personal': created automatically, exactly one per user, never shared.
    -- 'shared': created by a user, who becomes its Owner (D030).
    kind ENUM('personal', 'shared') NOT NULL,
    -- Set only for personal workspaces. The unique key guarantees one personal workspace per
    -- user even if two requests try to create it at the same moment.
    personal_owner_user_id CHAR(36) CHARACTER SET ascii NULL,
    created_by_user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY workspaces_personal_owner_unique (personal_owner_user_id),
    CONSTRAINT workspaces_personal_owner_fk FOREIGN KEY (personal_owner_user_id) REFERENCES users (id),
    CONSTRAINT workspaces_created_by_fk FOREIGN KEY (created_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE workspace_members (
    workspace_id CHAR(36) CHARACTER SET ascii NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- Role permissions are defined in PHP (Workspaces\WorkspaceRole, decision D029).
    role ENUM('owner', 'admin', 'editor', 'reader') NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (workspace_id, user_id),
    -- Every authorization check looks up (user, workspace); listing "my workspaces" goes by user.
    KEY workspace_members_user_index (user_id, workspace_id),
    CONSTRAINT workspace_members_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
    CONSTRAINT workspace_members_user_fk FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notes (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    -- A note never moves between workspaces in the MVP (D032), so this column never changes.
    workspace_id CHAR(36) CHARACTER SET ascii NOT NULL,
    title VARCHAR(200) NOT NULL,
    -- Markdown source. Rendering arrives in Week 3; the API stores and returns it as text.
    content MEDIUMTEXT NOT NULL,
    -- Optimistic concurrency: starts at 1 and goes up by one on every update. An update must
    -- name the revision it was based on, otherwise it is refused with 409 (D010).
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_by_user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    updated_by_user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    -- Set when the note is deleted (moved to Trash). Trashed notes are invisible through the
    -- API until the Trash view, restore and purge arrive in Week 4.
    trashed_at DATETIME NULL,
    trashed_by_user_id CHAR(36) CHARACTER SET ascii NULL,
    KEY notes_workspace_listing_index (workspace_id, trashed_at, updated_at),
    CONSTRAINT notes_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id),
    CONSTRAINT notes_created_by_fk FOREIGN KEY (created_by_user_id) REFERENCES users (id),
    CONSTRAINT notes_updated_by_fk FOREIGN KEY (updated_by_user_id) REFERENCES users (id),
    CONSTRAINT notes_trashed_by_fk FOREIGN KEY (trashed_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
