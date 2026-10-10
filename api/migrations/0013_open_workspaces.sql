-- Muninn migration 0013: Shared Workspaces that users ask to join (D067).
-- All timestamps are UTC. All primary keys are random UUIDv4 strings generated in PHP (D024).

-- 'open': a Shared Workspace created by a system administrator, such as "General". Every user
-- can see its name and description and ask to join; the administrator approves, and manages its
-- members. It never has an Owner or workspace Admin: members are Editors or Readers only.
-- Adding a value at the end of the ENUM leaves every existing row unchanged.
ALTER TABLE workspaces
    MODIFY kind ENUM('personal', 'shared', 'open') NOT NULL,
    -- A short text shown to everyone deciding whether to ask to join. Only used by 'open' ones.
    ADD COLUMN description VARCHAR(300) NULL AFTER name;

-- Lets the "every Shared Workspace" list for users and administrators find them quickly.
CREATE INDEX workspaces_kind_index ON workspaces (kind, name);

-- A user's request to join an open workspace (D067). An administrator approves it (the user then
-- becomes a member) or declines it; the user can cancel it while it is pending.
CREATE TABLE workspace_join_requests (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    -- Internal order of filing, so "the user's newest request" is exact even within one second.
    -- Never returned by the API (D024: no sequential identifiers in responses).
    sequence_number BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Requests disappear with their workspace.
    workspace_id CHAR(36) CHARACTER SET ascii NOT NULL,
    user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- Optional short note from the user to the administrator ("why I want to join").
    note VARCHAR(200) NOT NULL DEFAULT '',
    -- pending → approved | declined | cancelled. At most one pending request per user and
    -- workspace; the service checks this while holding a lock on the workspace row.
    status ENUM('pending', 'approved', 'declined', 'cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    -- The administrator who approved or declined it (NULL when the user cancelled), and when.
    decided_by_user_id CHAR(36) CHARACTER SET ascii NULL,
    decided_at DATETIME NULL,
    UNIQUE KEY workspace_join_requests_sequence_unique (sequence_number),
    KEY workspace_join_requests_status_index (status, created_at),
    KEY workspace_join_requests_user_index (user_id, workspace_id, created_at),
    CONSTRAINT workspace_join_requests_workspace_fk FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
    CONSTRAINT workspace_join_requests_user_fk FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT workspace_join_requests_decided_by_fk FOREIGN KEY (decided_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
