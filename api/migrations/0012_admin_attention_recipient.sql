-- Muninn migration 0012: the everyday account told when admin work is waiting (D066).
-- All timestamps are UTC.

-- At most one row (id is always 1). recipient_user_id is the everyday account that sees the
-- "Something is waiting on the admin side" icon; NULL (or no row) means nobody is told.
-- The icon never says what is waiting, so the recipient learns nothing an admin page shows.
CREATE TABLE admin_attention_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    recipient_user_id CHAR(36) CHARACTER SET ascii NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT admin_attention_settings_single_row CHECK (id = 1),
    -- An account removed by bin/reset-data.php simply stops being the recipient.
    CONSTRAINT admin_attention_settings_recipient_fk FOREIGN KEY (recipient_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
