-- Muninn migration 0016: news-ticker broadcasts (D074).
-- Adds a fourth kind to broadcasts. The new value goes at the end of the ENUM, so every existing
-- broadcast keeps its kind and no data changes.

-- ticker  a line that scrolls across the top of every page, like a news ticker, from its start to
--         its end. Users cannot close it. Several running at once share one line.
ALTER TABLE broadcasts
    MODIFY COLUMN kind ENUM('once', 'sticky', 'vote', 'ticker') NOT NULL;
