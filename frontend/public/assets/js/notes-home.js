/*
 * Notes home: loads the user's workspaces into the picker and lists the notes of the chosen
 * one, optionally narrowed to a folder and/or a tag. Editors can add, rename and delete folders.
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
    var folderManagementList = document.getElementById('folder-management-list');

    /** Folder filter values besides folder IDs. */
    var ALL_FOLDERS = '';
    var NO_FOLDER = 'none';

    var workspacesById = {};
    var currentWorkspaceId = null;
    var currentFolders = [];
    // The active filters: a folder ID, NO_FOLDER or ALL_FOLDERS; a tag name or null.
    var selectedFolder = ALL_FOLDERS;
    var selectedTag = null;

    function workspacePath() {
        return '/api/v1/workspaces/' + encodeURIComponent(currentWorkspaceId);
    }

    /** Keeps the address bar in step with the filters, so a filtered list can be reloaded or bookmarked. */
    function updateAddress() {
        var queryParameters = new URLSearchParams({ workspace: currentWorkspaceId });
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
            choices.push({ value: folder.id, label: folder.name + ' (' + folder.note_count + ')' });
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

    /** Fills the folder management dialog. */
    function showFolderManagement() {
        folderManagementList.replaceChildren();
        if (currentFolders.length === 0) {
            folderManagementList.appendChild(MuninnApi.createElement('li', 'list-group-item text-muted-brand', 'No folders yet.'));
            return;
        }
        currentFolders.forEach(function (folder) {
            var folderItem = MuninnApi.createElement('li', 'list-group-item d-flex align-items-center gap-2');
            folderItem.appendChild(MuninnApi.createElement('span', 'flex-grow-1 text-break', folder.name));
            folderItem.appendChild(MuninnApi.createElement('small', 'text-muted-brand text-nowrap', folder.note_count + (folder.note_count === 1 ? ' note' : ' notes')));

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

    /** Reloads folders, tags and notes for the current workspace and filters. */
    async function refreshList() {
        MuninnApi.showAlert(notesErrorAlert, '');
        try {
            var folderData = await MuninnApi.request('GET', workspacePath() + '/folders');
            var tagData = await MuninnApi.request('GET', workspacePath() + '/tags');
            currentFolders = folderData.folders;
            showFolderChoices();
            showTagChoices(tagData.tags);
            showFolderManagement();

            var listQuery = new URLSearchParams();
            if (selectedFolder !== ALL_FOLDERS) {
                listQuery.set('folder', selectedFolder);
            }
            if (selectedTag !== null) {
                listQuery.set('tag', selectedTag);
            }
            var queryString = listQuery.toString();
            var listData = await MuninnApi.request('GET', workspacePath() + '/notes' + (queryString ? '?' + queryString : ''));
            notesList.replaceChildren();
            listData.notes.forEach(function (note) {
                notesList.appendChild(buildNoteEntry(note));
            });
            var isFiltered = selectedFolder !== ALL_FOLDERS || selectedTag !== null;
            notesEmptyMessage.textContent = isFiltered ? 'No notes match this folder and tag.' : 'No notes here yet.';
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
        updateAddress();
    }

    /** Switches to a workspace and shows its notes. */
    async function showWorkspace(workspaceId) {
        var workspace = workspacesById[workspaceId];
        currentWorkspaceId = workspaceId;
        MuninnApi.rememberWorkspace(workspaceId);

        // Convenience only: the API refuses note and folder changes for Readers anyway.
        newNoteLink.classList.toggle('d-none', !workspace.permissions.write_notes);
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

    async function deleteFolder(folder) {
        var question = 'Delete the folder "' + folder.name + '"?';
        if (folder.note_count > 0) {
            question += ' Its ' + folder.note_count + (folder.note_count === 1 ? ' note moves' : ' notes move') + ' to "No folder".';
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
            await MuninnApi.request('POST', workspacePath() + '/folders', { name: newFolderNameInput.value });
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
            }
            await showWorkspace(initialWorkspaceId);
        } catch (workspaceError) {
            MuninnApi.showAlert(notesErrorAlert, workspaceError.message);
        }
        pageContent.classList.remove('d-none');
    });
})();
