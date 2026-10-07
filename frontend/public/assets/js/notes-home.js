/*
 * Notes home: loads the user's workspaces into the picker and lists the notes of the chosen
 * one. All text is inserted with textContent, never innerHTML.
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

    var workspacesById = {};

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
        return noteLink;
    }

    /** Loads and shows the notes of one workspace. */
    async function showWorkspace(workspaceId) {
        var workspace = workspacesById[workspaceId];
        MuninnApi.rememberWorkspace(workspaceId);
        MuninnApi.showAlert(notesErrorAlert, '');

        // Convenience only: the API refuses note creation for Readers anyway.
        newNoteLink.classList.toggle('d-none', !workspace.permissions.write_notes);
        newNoteLink.href = 'note.php?workspace=' + encodeURIComponent(workspaceId);
        roleHint.textContent = workspace.kind === 'personal'
            ? 'Your personal workspace. Only you can see it.'
            : 'Shared workspace. Your role: ' + MuninnApi.roleLabel(workspace.your_role) + '.';

        try {
            var listData = await MuninnApi.request('GET', '/api/v1/workspaces/' + encodeURIComponent(workspaceId) + '/notes');
            notesList.replaceChildren();
            listData.notes.forEach(function (note) {
                notesList.appendChild(buildNoteEntry(note));
            });
            notesEmptyMessage.classList.toggle('d-none', listData.notes.length > 0);
        } catch (listError) {
            notesList.replaceChildren();
            MuninnApi.showAlert(notesErrorAlert, listError.message);
        }
    }

    workspacePicker.addEventListener('change', function () {
        showWorkspace(workspacePicker.value);
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
            await showWorkspace(initialWorkspaceId);
        } catch (workspaceError) {
            MuninnApi.showAlert(notesErrorAlert, workspaceError.message);
        }
        pageContent.classList.remove('d-none');
    });
})();
