-- Muninn migration 0003: folders, tags and image attachments (Course MVP Week 3).
-- All timestamps are UTC. All primary keys are random UUIDv4 strings generated in PHP (D024).
-- Folders and tags always belong to exactly one workspace (D033, D034), so their names can never
-- be seen outside that workspace.

CREATE TABLE folders (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    workspace_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- Flat folders, one level per workspace (D033). Names are unique per workspace, compared
    -- case-insensitively by the collation.
    name VARCHAR(100) NOT NULL,
    created_by_user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY folders_workspace_name_unique (workspace_id, name),
    -- Only empty shared workspaces can be deleted (D045); their folders go with them.
    CONSTRAINT folders_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
    CONSTRAINT folders_created_by_fk FOREIGN KEY (created_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A note sits in at most one folder. Deleting a folder moves its notes to "No folder" (D033),
-- which ON DELETE SET NULL does in the same statement.
ALTER TABLE notes
    ADD COLUMN folder_id CHAR(36) CHARACTER SET ascii NULL AFTER workspace_id,
    ADD KEY notes_folder_index (folder_id),
    ADD CONSTRAINT notes_folder_fk FOREIGN KEY (folder_id) REFERENCES folders (id) ON DELETE SET NULL;

CREATE TABLE tags (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    workspace_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- Unique per workspace, case-insensitively; the first spelling used is kept.
    name VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY tags_workspace_name_unique (workspace_id, name),
    CONSTRAINT tags_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE note_tags (
    note_id CHAR(36) CHARACTER SET ascii NOT NULL,
    tag_id CHAR(36) CHARACTER SET ascii NOT NULL,
    PRIMARY KEY (note_id, tag_id),
    -- Filtering a workspace's notes by tag goes from the tag to its notes.
    KEY note_tags_tag_index (tag_id, note_id),
    CONSTRAINT note_tags_note_fk FOREIGN KEY (note_id) REFERENCES notes (id) ON DELETE CASCADE,
    CONSTRAINT note_tags_tag_fk FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attachments (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    -- Copied from the note for clarity and indexing; a note never changes workspace (D032).
    workspace_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- Access to an attachment always comes from read access to this note.
    note_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- The file on disk is named after the id (never after anything the client sent).
    -- original_filename is display text only.
    original_filename VARCHAR(255) NOT NULL,
    -- Detected by the API from the file's own bytes, never taken from the browser.
    media_type VARCHAR(50) CHARACTER SET ascii NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    width_pixels INT UNSIGNED NOT NULL,
    height_pixels INT UNSIGNED NOT NULL,
    sha256_hex CHAR(64) CHARACTER SET ascii NOT NULL,
    created_by_user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    created_at DATETIME NOT NULL,
    KEY attachments_note_index (note_id, created_at),
    CONSTRAINT attachments_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id),
    CONSTRAINT attachments_note_fk FOREIGN KEY (note_id) REFERENCES notes (id),
    CONSTRAINT attachments_created_by_fk FOREIGN KEY (created_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
