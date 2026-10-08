-- Muninn migration 0005: password reset links (D040) and invitation requests (D049).
-- All timestamps are UTC. All primary keys are random UUIDv4 strings generated in PHP (D024).

-- One-time password reset links that a system administrator creates for a user (D040).
-- The raw token is shown to the administrator once and never stored; only its SHA-256 hash is.
-- At most one link per user is usable at a time: creating a new one revokes the older ones.
CREATE TABLE password_resets (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    -- The account whose password the link resets.
    user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- SHA-256 of the raw reset token.
    token_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    -- The administrator who created the link.
    created_by_user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    -- Set when the link has been used to choose a new password (single use).
    used_at DATETIME NULL,
    -- Set when a newer link replaced this one, or the account was disabled.
    revoked_at DATETIME NULL,
    UNIQUE KEY password_resets_token_hash_unique (token_hash),
    KEY password_resets_user_index (user_id, created_at),
    CONSTRAINT password_resets_user_fk FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT password_resets_created_by_fk FOREIGN KEY (created_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A user's request that someone be invited (D049). An administrator approves or declines it;
-- after approval the requesting user creates the invitation link and sends it themselves.
CREATE TABLE invitation_requests (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    requested_by_user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- Who should be invited and why, written by the requesting user for the administrator.
    note VARCHAR(200) NOT NULL,
    -- pending → approved | declined | cancelled; approved → declined (withdrawn) | cancelled.
    -- "Completed" is not stored: it means the request's invitation has been accepted.
    status ENUM('pending', 'approved', 'declined', 'cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    -- The administrator who last approved or declined it, and when.
    decided_by_user_id CHAR(36) CHARACTER SET ascii NULL,
    decided_at DATETIME NULL,
    -- The most recent invitation link the requesting user created for this request, if any.
    invitation_id CHAR(36) CHARACTER SET ascii NULL,
    KEY invitation_requests_requester_index (requested_by_user_id, created_at),
    KEY invitation_requests_status_index (status, created_at),
    UNIQUE KEY invitation_requests_invitation_unique (invitation_id),
    CONSTRAINT invitation_requests_requester_fk FOREIGN KEY (requested_by_user_id) REFERENCES users (id),
    CONSTRAINT invitation_requests_decided_by_fk FOREIGN KEY (decided_by_user_id) REFERENCES users (id),
    CONSTRAINT invitation_requests_invitation_fk FOREIGN KEY (invitation_id) REFERENCES invitations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
