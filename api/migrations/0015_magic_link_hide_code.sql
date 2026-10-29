-- Muninn migration 0015: Magic Links that hide code (D073).
-- Adds one column with a default, so every existing link keeps working exactly as before.

-- 1 = the link never sends code (code blocks, inline code, <pre>/<code> HTML) to the visitor:
-- the API replaces it with a placeholder before the note leaves the server. Such a link is
-- always read-only; the API refuses to create one with write permission.
ALTER TABLE magic_links
    ADD COLUMN hide_code TINYINT(1) NOT NULL DEFAULT 0 AFTER permission;
