-- Muninn migration 0004: Archive and note version history (Course MVP Week 4).
-- All timestamps are UTC. All primary keys are random UUIDv4 strings generated in PHP (D024).
-- Trash itself needs no new columns: notes.trashed_at already exists since migration 0002 (D045).

-- Archived notes stay readable and editable but leave the normal note list (D012, D048).
ALTER TABLE notes
    ADD COLUMN archived_at DATETIME NULL AFTER updated_at,
    ADD COLUMN archived_by_user_id CHAR(36) CHARACTER SET ascii NULL AFTER archived_at,
    ADD CONSTRAINT notes_archived_by_fk FOREIGN KEY (archived_by_user_id) REFERENCES users (id);

-- Earlier states of a note (D009, D037). The note's current state lives in `notes`; each row
-- here is a state that a later save replaced. At most 100 rows per note are kept (oldest go first).
CREATE TABLE note_versions (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    -- Purging a note from Trash removes its history in the same statement.
    note_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- Copied from the note (which never changes workspace, D032) so every query can be scoped
    -- to the caller's workspace directly.
    workspace_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- The note's revision while it was in this state.
    revision INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    -- The folder the note was in. Deleting that folder later only forgets it (D033).
    folder_id CHAR(36) CHARACTER SET ascii NULL,
    -- JSON list of the tag names the note had. Names rather than tag IDs, because tags are
    -- removed once no note uses them (D034) and are created again by name on restore.
    tag_names TEXT NOT NULL,
    -- Who saved this state, and when.
    edited_by_user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    edited_at DATETIME NOT NULL,
    -- Who replaced it with a newer state, and when (used to merge quick successive saves).
    replaced_by_user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    replaced_at DATETIME NOT NULL,
    UNIQUE KEY note_versions_note_revision_unique (note_id, revision),
    CONSTRAINT note_versions_note_fk FOREIGN KEY (note_id) REFERENCES notes (id) ON DELETE CASCADE,
    CONSTRAINT note_versions_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id),
    CONSTRAINT note_versions_folder_fk FOREIGN KEY (folder_id) REFERENCES folders (id) ON DELETE SET NULL,
    CONSTRAINT note_versions_edited_by_fk FOREIGN KEY (edited_by_user_id) REFERENCES users (id),
    CONSTRAINT note_versions_replaced_by_fk FOREIGN KEY (replaced_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- When the API last started each housekeeping task (D039). The API cleans up expired Trash by
-- itself, at most once per hour, after answering a signed-in request; claiming the row with one
-- atomic statement makes sure two simultaneous requests never both run the cleanup.
CREATE TABLE maintenance_runs (
    task_name VARCHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    last_started_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
