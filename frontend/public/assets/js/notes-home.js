/*
 * Notes home: loads the user's workspaces into the picker and lists the notes of the chosen
 * one, optionally narrowed to a folder (sub-folders included) and/or a tag, or its Archive
 * instead. Editors can add, move, rename and delete folders, up to 3 levels deep (D055).
 * All text is inserted with textContent, never innerHTML.
 */
(function () {
    'use strict';

    // Keep the page hidden until the workspaces and the first note list have loaded.
    document.body.setAttribute('data-shell-hold', '');

    var pageContent = document.getElementById('shell-content');
    var adminNotice = document.getElementById('admin-account-notice');
    var workspacePicker = document.getElementById('workspace-picker');
    var newNoteLink = document.getElementById('new-note-link');
    var roleHint = document.getElementById('workspace-role-hint');
    var notesErrorAlert = document.getElementById('notes-error');
    var notesEmptyMessage = document.getElementById('notes-empty');
    var notesList = document.getElementById('notes-list');
    var folderFilter = document.getElementById('folder-filter');
    var manageFoldersButton = document.getElementById('manage-folders-button');
    var tagFilter = document.getElementById('tag-filter');
    var foldersErrorAlert = document.getElementById('folders-error');
    var newFolderForm = document.getElementById('new-folder-form');
    var newFolderNameInput = document.getElementById('new-folder-name');
    var newFolderParentSelect = document.getElementById('new-folder-parent');
    var folderManagementList = document.getElementById('folder-management-list');
    var showNotesButton = document.getElementById('show-notes-button');
    var showArchiveButton = document.getElementById('show-archive-button');
    var trashLink = document.getElementById('trash-link');
    var folderFilterRow = document.getElementById('folder-filter-row');
    var archiveHint = document.getElementById('archive-hint');

    /** Deepest folder level the API allows (D055); top-level folders are level 1. */
    var MAXIMUM_FOLDER_LEVEL = 3;

    /** Folder filter values besides folder IDs. */
    var ALL_FOLDERS = '';
    var NO_FOLDER = 'none';

    var workspacesById = {};
    var currentWorkspaceId = null;
    var currentFolders = [];
    // The active filters: a folder ID, NO_FOLDER or ALL_FOLDERS; a tag name or null.
    var selectedFolder = ALL_FOLDERS;
    var selectedTag = null;
    // True while the workspace's Archive is shown instead of its normal note list.
    var isShowingArchive = false;

    function workspacePath() {
        return '/api/v1/workspaces/' + encodeURIComponent(currentWorkspaceId);
    }

    /** Keeps the address bar in step with the filters, so a filtered list can be reloaded or bookmarked. */
    function updateAddress() {
        var queryParameters = new URLSearchParams({ workspace: currentWorkspaceId });
        if (isShowingArchive) {
            queryParameters.set('view', 'archive');
        }
        if (selectedFolder !== ALL_FOLDERS) {
            queryParameters.set('folder', selectedFolder);
        }
        if (selectedTag !== null) {
            queryParameters.set('tag', selectedTag);
        }
        window.history.replaceState(null, '', 'index.php?' + queryParameters.toString());
    }

    /** Builds one clickable list entry for a note. */
    function buildNoteEntry(note) {
        var noteLink = MuninnApi.createElement('a', 'list-group-item list-group-item-action py-3');
        noteLink.href = 'note.php?id=' + encodeURIComponent(note.id);

        var titleLine = MuninnApi.createElement('div', 'd-flex justify-content-between align-items-baseline gap-2');
        titleLine.appendChild(MuninnApi.createElement('span', 'fw-semibold text-break', note.title || 'Untitled'));
        titleLine.appendChild(MuninnApi.createElement('small', 'text-muted-brand text-nowrap', MuninnApi.formatDateTime(note.updated_at)));
        noteLink.appendChild(titleLine);

        if (note.excerpt) {
            noteLink.appendChild(MuninnApi.createElement('div', 'small text-muted-brand text-truncate', note.excerpt));
        }
        if (note.tags.length > 0) {
            var tagLine = MuninnApi.createElement('div', 'd-flex flex-wrap gap-1 mt-1');
            note.tags.forEach(function (tagName) {
                tagLine.appendChild(MuninnApi.createElement('span', 'badge rounded-pill muninn-tag-badge', '#' + tagName));
            });
            noteLink.appendChild(tagLine);
        }
        return noteLink;
    }

    /** Fills the folder picker, keeping the current choice when that folder still exists. */
    function showFolderChoices() {
        folderFilter.replaceChildren();
        var choices = [{ value: ALL_FOLDERS, label: 'All folders' }, { value: NO_FOLDER, label: 'No folder' }];
        currentFolders.forEach(function (folder) {
            // The count includes sub-folders, matching what choosing the folder lists.
            choices.push({ value: folder.id, label: MuninnApi.folderOptionLabel(folder) + ' (' + folder.total_note_count + ')' });
        });
        choices.forEach(function (choice) {
            var folderOption = MuninnApi.createElement('option', null, choice.label);
            folderOption.value = choice.value;
            folderFilter.appendChild(folderOption);
        });
        folderFilter.value = selectedFolder;
        if (folderFilter.value !== selectedFolder) {
            selectedFolder = ALL_FOLDERS;
            folderFilter.value = ALL_FOLDERS;
        }
    }

    /** Shows the workspace's tags as toggle buttons; the selected one is pressed. */
    function showTagChoices(workspaceTags) {
        tagFilter.replaceChildren();
        var selectedTagStillExists = false;
        workspaceTags.forEach(function (tag) {
            var isSelected = selectedTag !== null && tag.name.toLowerCase() === selectedTag.toLowerCase();
            selectedTagStillExists = selectedTagStillExists || isSelected;
            var tagButton = MuninnApi.createElement(
                'button',
                'btn btn-sm rounded-pill ' + (isSelected ? 'btn-primary' : 'btn-outline-secondary'),
                '#' + tag.name
            );
            tagButton.type = 'button';
            tagButton.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
            tagButton.addEventListener('click', function () {
                selectedTag = isSelected ? null : tag.name;
                refreshList();
            });
            tagFilter.appendChild(tagButton);
        });
        if (selectedTag !== null && !selectedTagStillExists) {
            selectedTag = null;
        }
        tagFilter.classList.toggle('d-none', workspaceTags.length === 0);
    }

    /** Finds a loaded folder by ID, or null. */
    function folderById(folderId) {
        for (var folderIndex = 0; folderIndex < currentFolders.length; folderIndex++) {
            if (currentFolders[folderIndex].id === folderId) {
                return currentFolders[folderIndex];
            }
        }
        return null;
    }

    /** The IDs of every folder below the given one (its sub-folders, their sub-folders, ...). */
    function descendantIdsOf(folderId) {
        var descendantIds = [];
        var parentIdsToVisit = [folderId];
        while (parentIdsToVisit.length > 0) {
            var visitedParentId = parentIdsToVisit.shift();
            currentFolders.forEach(function (folder) {
                if (folder.parent_id === visitedParentId && descendantIds.indexOf(folder.id) === -1) {
                    descendantIds.push(folder.id);
                    parentIdsToVisit.push(folder.id);
                }
            });
        }
        return descendantIds;
    }

    /** How many levels the folder's branch spans (1 when it has no sub-folders). */
    function branchHeightOf(folder) {
        var deepestLevel = folder.level;
        descendantIdsOf(folder.id).forEach(function (descendantId) {
            deepestLevel = Math.max(deepestLevel, folderById(descendantId).level);
        });
        return deepestLevel - folder.level + 1;
    }

    /**
     * The places a folder can move to: the top level, or any folder that is not the folder
     * itself or below it, and deep enough room for its whole branch. The API checks this again.
     */
    function moveTargetsFor(folder) {
        var excludedIds = descendantIdsOf(folder.id).concat([folder.id]);
        var branchHeight = branchHeightOf(folder);
        return currentFolders.filter(function (candidateFolder) {
            return excludedIds.indexOf(candidateFolder.id) === -1
                && candidateFolder.level + branchHeight <= MAXIMUM_FOLDER_LEVEL;
        });
    }

    /** Fills a <select> with "Top level" and the given folders, indented as a tree. */
    function fillParentSelect(parentSelect, parentFolders, selectedParentId) {
        parentSelect.replaceChildren(MuninnApi.createElement('option', null, 'Top level'));
        parentSelect.firstChild.value = '';
        parentFolders.forEach(function (parentFolder) {
            var parentOption = MuninnApi.createElement('option', null, MuninnApi.folderOptionLabel(parentFolder));
            parentOption.value = parentFolder.id;
            parentSelect.appendChild(parentOption);
        });
        parentSelect.value = selectedParentId || '';
        if (parentSelect.selectedIndex === -1) {
            parentSelect.value = '';
        }
    }

    /** Shows a small "move to" form under a folder in the management dialog. */
    function showMoveForm(folder, folderItem) {
        var existingForm = folderManagementList.querySelector('.muninn-folder-move-form');
        if (existingForm) {
            existingForm.remove();
        }
        var moveForm = MuninnApi.createElement('form', 'muninn-folder-move-form d-flex flex-wrap gap-2 w-100 mt-2');
        moveForm.noValidate = true;
        var moveSelectId = 'move-folder-' + folder.id;
        var moveLabel = MuninnApi.createElement('label', 'visually-hidden', 'Move "' + folder.name + '" into');
        moveLabel.htmlFor = moveSelectId;
        var moveSelect = MuninnApi.createElement('select', 'form-select form-select-sm flex-grow-1 w-auto');
        moveSelect.id = moveSelectId;
        fillParentSelect(moveSelect, moveTargetsFor(folder), folder.parent_id);
        var confirmButton = MuninnApi.createElement('button', 'btn btn-primary btn-sm', 'Move');
        confirmButton.type = 'submit';
        var cancelMoveButton = MuninnApi.createElement('button', 'btn btn-outline-secondary btn-sm', 'Cancel');
        cancelMoveButton.type = 'button';
        cancelMoveButton.addEventListener('click', function () {
            moveForm.remove();
        });
        moveForm.addEventListener('submit', function (submitEvent) {
            submitEvent.preventDefault();
            moveFolder(folder, moveSelect.value === '' ? null : moveSelect.value);
        });
        moveForm.appendChild(moveLabel);
        moveForm.appendChild(moveSelect);
        moveForm.appendChild(confirmButton);
        moveForm.appendChild(cancelMoveButton);
        folderItem.appendChild(moveForm);
        moveSelect.focus();
    }

    /** Fills the folder management dialog: the folder tree, and the "create inside" choices. */
    function showFolderManagement() {
        folderManagementList.replaceChildren();
        // A new folder can go inside any folder that is not yet at the deepest level.
        fillParentSelect(newFolderParentSelect, currentFolders.filter(function (folder) {
            return folder.level < MAXIMUM_FOLDER_LEVEL;
        }), newFolderParentSelect.value);
        if (currentFolders.length === 0) {
            folderManagementList.appendChild(MuninnApi.createElement('li', 'list-group-item text-muted-brand', 'No folders yet.'));
            return;
        }
        currentFolders.forEach(function (folder) {
            // Sub-folders are indented by level (CSS classes, so no inline styles are needed).
            var folderItem = MuninnApi.createElement('li', 'list-group-item d-flex flex-wrap align-items-center gap-2 muninn-folder-level-' + folder.level);
            var folderNameLabel = MuninnApi.createElement('span', 'flex-grow-1 text-break');
            folderNameLabel.appendChild(iconElement(folder.level > 1 ? 'bi-arrow-return-right' : 'bi-folder'));
            folderNameLabel.appendChild(document.createTextNode(' ' + folder.name));
            folderItem.appendChild(folderNameLabel);
            folderItem.appendChild(MuninnApi.createElement('small', 'text-muted-brand text-nowrap', folder.note_count + (folder.note_count === 1 ? ' note' : ' notes')));

            var moveButton = MuninnApi.createElement('button', 'btn btn-outline-secondary btn-sm');
            moveButton.type = 'button';
            moveButton.setAttribute('aria-label', 'Move folder ' + folder.name);
            moveButton.appendChild(iconElement('bi-folder-symlink'));
            moveButton.addEventListener('click', function () {
                showMoveForm(folder, folderItem);
            });

            var renameButton = MuninnApi.createElement('button', 'btn btn-outline-secondary btn-sm');
            renameButton.type = 'button';
            renameButton.setAttribute('aria-label', 'Rename folder ' + folder.name);
            renameButton.appendChild(iconElement('bi-pencil'));
            renameButton.addEventListener('click', function () {
                renameFolder(folder);
            });

            var deleteButton = MuninnApi.createElement('button', 'btn btn-outline-danger btn-sm');
            deleteButton.type = 'button';
            deleteButton.setAttribute('aria-label', 'Delete folder ' + folder.name);
            deleteButton.appendChild(iconElement('bi-trash'));
            deleteButton.addEventListener('click', function () {
                deleteFolder(folder);
            });

            folderItem.appendChild(moveButton);
            folderItem.appendChild(renameButton);
            folderItem.appendChild(deleteButton);
            folderManagementList.appendChild(folderItem);
        });
    }

    function iconElement(iconClass) {
        var icon = MuninnApi.createElement('i', 'bi ' + iconClass);
        icon.setAttribute('aria-hidden', 'true');
        return icon;
    }

    /**
     * Shows which list is selected. The Archive is one flat list, so the folder and tag filters
     * (which count only the normal list's notes) are hidden there.
     */
    function showViewChoice() {
        showNotesButton.classList.toggle('active', !isShowingArchive);
        showNotesButton.setAttribute('aria-pressed', isShowingArchive ? 'false' : 'true');
        showArchiveButton.classList.toggle('active', isShowingArchive);
        showArchiveButton.setAttribute('aria-pressed', isShowingArchive ? 'true' : 'false');
        folderFilterRow.classList.toggle('d-none', isShowingArchive);
        archiveHint.classList.toggle('d-none', !isShowingArchive);
        var canWrite = workspacesById[currentWorkspaceId].permissions.write_notes;
        newNoteLink.classList.toggle('d-none', !canWrite || isShowingArchive);
    }

    /** Reloads folders, tags and notes for the current workspace and filters. */
    async function refreshList() {
        MuninnApi.showAlert(notesErrorAlert, '');
        showViewChoice();
        try {
            var folderData = await MuninnApi.request('GET', workspacePath() + '/folders');
            var tagData = await MuninnApi.request('GET', workspacePath() + '/tags');
            currentFolders = folderData.folders;
            showFolderChoices();
            showTagChoices(isShowingArchive ? [] : tagData.tags);
            showFolderManagement();

            var listQuery = new URLSearchParams();
            if (isShowingArchive) {
                listQuery.set('archived', '1');
            } else {
                if (selectedFolder !== ALL_FOLDERS) {
                    listQuery.set('folder', selectedFolder);
                }
                if (selectedTag !== null) {
                    listQuery.set('tag', selectedTag);
                }
            }
            var queryString = listQuery.toString();
            var listData = await MuninnApi.request('GET', workspacePath() + '/notes' + (queryString ? '?' + queryString : ''));
            notesList.replaceChildren();
            listData.notes.forEach(function (note) {
                notesList.appendChild(buildNoteEntry(note));
            });
            var isFiltered = selectedFolder !== ALL_FOLDERS || selectedTag !== null;
            if (isShowingArchive) {
                notesEmptyMessage.textContent = 'The Archive is empty.';
            } else {
                notesEmptyMessage.textContent = isFiltered ? 'No notes match this folder and tag.' : 'No notes here yet.';
            }
            notesEmptyMessage.classList.toggle('d-none', listData.notes.length > 0);
        } catch (listError) {
            notesList.replaceChildren();
            MuninnApi.showAlert(notesErrorAlert, listError.message);
        }

        // New notes start in the folder being looked at.
        var newNoteAddress = 'note.php?workspace=' + encodeURIComponent(currentWorkspaceId);
        if (selectedFolder !== ALL_FOLDERS && selectedFolder !== NO_FOLDER) {
            newNoteAddress += '&folder=' + encodeURIComponent(selectedFolder);
        }
        newNoteLink.href = newNoteAddress;
        trashLink.href = 'trash.php?workspace=' + encodeURIComponent(currentWorkspaceId);
        updateAddress();
    }

    /** Switches to a workspace and shows its notes. */
    async function showWorkspace(workspaceId) {
        var workspace = workspacesById[workspaceId];
        currentWorkspaceId = workspaceId;
        MuninnApi.rememberWorkspace(workspaceId);

        // Convenience only: the API refuses note and folder changes for Readers anyway.
        manageFoldersButton.classList.toggle('d-none', !workspace.permissions.write_notes);
        roleHint.textContent = workspace.kind === 'personal'
            ? 'Your personal workspace. Only you can see it.'
            : 'Shared workspace. Your role: ' + MuninnApi.roleLabel(workspace.your_role) + '.';

        await refreshList();
    }

    async function renameFolder(folder) {
        var newName = window.prompt('New name for the folder "' + folder.name + '":', folder.name);
        if (newName === null || newName.trim() === '' || newName.trim() === folder.name) {
            return;
        }
        MuninnApi.showAlert(foldersErrorAlert, '');
        try {
            await MuninnApi.request('PATCH', '/api/v1/folders/' + encodeURIComponent(folder.id), { name: newName });
            await refreshList();
        } catch (renameError) {
            MuninnApi.showAlert(foldersErrorAlert, (renameError.fields && renameError.fields.name) || renameError.message);
        }
    }

    async function moveFolder(folder, newParentId) {
        MuninnApi.showAlert(foldersErrorAlert, '');
        try {
            await MuninnApi.request('PATCH', '/api/v1/folders/' + encodeURIComponent(folder.id), { parent_id: newParentId });
            await refreshList();
        } catch (moveError) {
            MuninnApi.showAlert(foldersErrorAlert, (moveError.fields && moveError.fields.parent_id) || moveError.message);
        }
    }

    async function deleteFolder(folder) {
        // Notes and sub-folders move up one level (D055): into the parent, or to the top level.
        var parentFolder = folder.parent_id ? folderById(folder.parent_id) : null;
        var subFolderCount = currentFolders.filter(function (candidateFolder) {
            return candidateFolder.parent_id === folder.id;
        }).length;
        var question = 'Delete the folder "' + folder.name + '"?';
        if (folder.note_count > 0) {
            question += ' Its ' + folder.note_count + (folder.note_count === 1 ? ' note moves' : ' notes move')
                + (parentFolder ? ' to "' + parentFolder.name + '".' : ' to "No folder".');
        }
        if (subFolderCount > 0) {
            question += ' Its ' + subFolderCount + (subFolderCount === 1 ? ' sub-folder moves' : ' sub-folders move')
                + (parentFolder ? ' into "' + parentFolder.name + '".' : ' to the top level.');
        }
        if (!window.confirm(question)) {
            return;
        }
        MuninnApi.showAlert(foldersErrorAlert, '');
        try {
            await MuninnApi.request('DELETE', '/api/v1/folders/' + encodeURIComponent(folder.id));
            await refreshList();
        } catch (deleteError) {
            MuninnApi.showAlert(foldersErrorAlert, deleteError.message);
        }
    }

    newFolderForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        MuninnApi.showAlert(foldersErrorAlert, '');
        try {
            await MuninnApi.request('POST', workspacePath() + '/folders', {
                name: newFolderNameInput.value,
                parent_id: newFolderParentSelect.value === '' ? null : newFolderParentSelect.value,
            });
            newFolderNameInput.value = '';
            await refreshList();
            newFolderNameInput.focus();
        } catch (createError) {
            MuninnApi.showAlert(foldersErrorAlert, (createError.fields && createError.fields.name) || createError.message);
        }
    });

    workspacePicker.addEventListener('change', function () {
        // Folders and tags belong to one workspace, so filters never carry over.
        selectedFolder = ALL_FOLDERS;
        selectedTag = null;
        showWorkspace(workspacePicker.value);
    });

    showNotesButton.addEventListener('click', function () {
        isShowingArchive = false;
        refreshList();
    });

    showArchiveButton.addEventListener('click', function () {
        isShowingArchive = true;
        refreshList();
    });

    folderFilter.addEventListener('change', function () {
        selectedFolder = folderFilter.value;
        refreshList();
    });

    document.addEventListener('muninn:user-ready', async function (readyEvent) {
        if (readyEvent.detail.is_system_admin) {
            adminNotice.classList.remove('d-none');
            return;
        }

        try {
            var workspaceData = await MuninnApi.request('GET', '/api/v1/workspaces');
            workspaceData.workspaces.forEach(function (workspace) {
                workspacesById[workspace.id] = workspace;
                var optionLabel = workspace.kind === 'personal' ? 'Personal' : workspace.name;
                var workspaceOption = MuninnApi.createElement('option', null, optionLabel);
                workspaceOption.value = workspace.id;
                workspacePicker.appendChild(workspaceOption);
            });

            // Open the workspace named in the URL, else the last one used, else the first (personal).
            var requestedWorkspaceId = MuninnApi.queryParameter('workspace') || MuninnApi.rememberedWorkspace();
            var initialWorkspaceId = workspacesById[requestedWorkspaceId] ? requestedWorkspaceId : workspaceData.workspaces[0].id;
            workspacePicker.value = initialWorkspaceId;
            // Filters from the address (e.g. a tag clicked on a note) apply to that workspace only.
            if (initialWorkspaceId === MuninnApi.queryParameter('workspace')) {
                selectedFolder = MuninnApi.queryParameter('folder') || ALL_FOLDERS;
                selectedTag = MuninnApi.queryParameter('tag');
                isShowingArchive = MuninnApi.queryParameter('view') === 'archive';
            }
            await showWorkspace(initialWorkspaceId);
        } catch (workspaceError) {
            MuninnApi.showAlert(notesErrorAlert, workspaceError.message);
        }
        pageContent.classList.remove('d-none');
    });
})();
