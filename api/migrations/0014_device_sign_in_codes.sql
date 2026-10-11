-- Muninn migration 0014: signing in on another device with a 6-digit code (D068).
-- All timestamps are UTC. All primary keys are random UUIDv4 strings generated in PHP (D024).

-- A short-lived code that a signed-in device shows so another device can sign in as the same
-- account. Only the SHA-256 hash of the code is stored. A code works once, for 5 minutes, and
-- only while the session that created it is still signed in. One code per account at a time:
-- creating a new one revokes the older one. Rows are pruned a day after they stop mattering.
CREATE TABLE device_sign_in_codes (
    id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    -- The account the code signs in as.
    user_id CHAR(36) CHARACTER SET ascii NOT NULL,
    -- SHA-256 of the 6-digit code.
    code_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    -- The signed-in session (usually a phone) that showed the code. Signing it out kills the code.
    created_by_session_id CHAR(36) CHARACTER SET ascii NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    -- Set when the code was used; the new device's session is recorded for the audit trail.
    used_at DATETIME NULL,
    used_by_session_id CHAR(36) CHARACTER SET ascii NULL,
    -- Set when a newer code replaced this one, the user cancelled it or changed their password.
    revoked_at DATETIME NULL,
    -- Sign-in looks a code up by its hash among the ones that have not expired yet.
    KEY device_sign_in_codes_hash_index (code_hash, expires_at),
    KEY device_sign_in_codes_user_index (user_id, created_at),
    CONSTRAINT device_sign_in_codes_user_fk FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT device_sign_in_codes_created_by_session_fk FOREIGN KEY (created_by_session_id) REFERENCES sessions (id),
    CONSTRAINT device_sign_in_codes_used_by_session_fk FOREIGN KEY (used_by_session_id) REFERENCES sessions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
