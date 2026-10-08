-- Muninn migration 0006: sub-folders (D055, replaces the "one level" part of D033).
-- Existing folders all become top-level folders (parent NULL); no data is changed or removed.

-- A folder may sit inside another folder of the same workspace, at most 3 levels deep. The API
-- enforces the depth limit, keeps parent and child in one workspace and refuses loops (D055).
-- Names stay unique per workspace (D033), so a folder name always identifies one folder.
-- Deleting a folder through the API first moves its notes and sub-folders up to its parent in
-- the same transaction (D055). SET NULL is only the safety net for deletes outside the API (a
-- workspace deleted with its folders, the data reset tool): a sub-folder whose parent disappears
-- becomes a top-level folder, so nothing is ever deleted along with a parent.
ALTER TABLE folders
    ADD COLUMN parent_folder_id CHAR(36) CHARACTER SET ascii NULL AFTER workspace_id,
    ADD KEY folders_parent_index (parent_folder_id),
    ADD CONSTRAINT folders_parent_fk FOREIGN KEY (parent_folder_id) REFERENCES folders (id) ON DELETE SET NULL;
