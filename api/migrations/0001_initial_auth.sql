-- Muninn migration 0001: users, sessions, invitations, rate limiting and audit log.
-- All timestamps are UTC (the connection sets time_zone = '+00:00').
-- All primary keys are random UUIDv4 strings (decision D024).

CREATE TABLE users (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    -- Stored lowercase; 3-32 characters of a-z 0-9 . _ - (validated in PHP).
    username VARCHAR(32) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    -- System administrators manage users and invitations (decision D025).
    -- This is NOT a workspace role and grants no access to note content.
    is_system_admin TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    last_login_at DATETIME NULL,
    UNIQUE KEY users_username_unique (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sessions (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- SHA-256 of the raw cookie token. The raw token is never stored.
    token_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    -- Per-session CSRF token, sent by the client in X-CSRF-Token (decision D023).
    -- Stored raw because the API must return it from /auth/me; it is useless without the session cookie.
    csrf_token CHAR(43) CHARACTER SET ascii NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY sessions_token_hash_unique (token_hash),
    KEY sessions_user_id_index (user_id),
    CONSTRAINT sessions_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invitations (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    -- SHA-256 of the raw invitation token. The raw token is shown once and never stored.
    token_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    created_by_user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- Free-text reminder for the admin, e.g. who the invitation is for.
    note VARCHAR(200) NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME NULL,
    accepted_user_id CHAR(36) CHARACTER SET ascii NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY invitations_token_hash_unique (token_hash),
    KEY invitations_created_at_index (created_at),
    CONSTRAINT invitations_created_by_fk FOREIGN KEY (created_by_user_id) REFERENCES users (id),
    CONSTRAINT invitations_accepted_user_fk FOREIGN KEY (accepted_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- 'login' or 'invitation'. This table is internal and its IDs are never exposed.
    attempt_type VARCHAR(20) NOT NULL,
    -- Normalised username for logins; NULL for invitation attempts.
    subject VARCHAR(64) NULL,
    ip_address VARCHAR(45) NOT NULL,
    succeeded TINYINT(1) NOT NULL,
    attempted_at DATETIME NOT NULL,
    KEY auth_attempts_subject_index (attempt_type, subject, attempted_at),
    KEY auth_attempts_ip_index (attempt_type, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    event_type VARCHAR(64) NOT NULL,
    -- No foreign keys: audit history must survive changes to the rows it describes.
    actor_user_id CHAR(36) CHARACTER SET ascii NULL,
    target_type VARCHAR(32) NULL,
    target_id CHAR(36) CHARACTER SET ascii NULL,
    ip_address VARCHAR(45) NULL,
    -- JSON object with non-secret details. Never passwords or raw tokens.
    details TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    KEY audit_log_created_at_index (created_at),
    KEY audit_log_event_type_index (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
