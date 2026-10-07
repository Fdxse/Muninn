/*
 * Note page: shows one note, edits it with optimistic concurrency, creates new notes and
 * moves notes to Trash. The note's Markdown source is shown as plain text (textContent) until
 * the sanitised Markdown renderer arrives in Week 3.
 */
(function () {
    'use strict';

    document.body.setAttribute('data-shell-hold', '');

    var pageContent = document.getElementById('shell-content');
    var notAvailableAlert = document.getElementById('note-not-available');
    var backLink = document.getElementById('back-link');
    var errorAlert = document.getElementById('note-error');
    var noteView = document.getElementById('note-view');
    var noteHeading = document.getElementById('note-heading');
    var noteMeta = document.getElementById('note-meta');
    var noteContent = document.getElementById('note-content');
    var viewActions = document.getElementById('note-view-actions');
    var editButton = document.getElementById('edit-note-button');
    var trashButton = document.getElementById('trash-note-button');
    var noteForm = document.getElementById('note-form');
    var formHeading = document.getElementById('note-form-heading');
    var titleInput = document.getElementById('note-title');
    var bodyInput = document.getElementById('note-body');
    var saveButton = document.getElementById('save-note-button');
    var cancelButton = document.getElementById('cancel-edit-button');

    var fieldInputIds = { title: 'note-title', content: 'note-body' };

    // The note being shown (null while writing a new one) and its workspace.
    var currentNote = null;
    var currentWorkspace = null;
    var hasUnsavedChanges = false;

    /** Shows the read view for currentNote. */
    function showReadView() {
        noteHeading.textContent = currentNote.title || 'Untitled';
        noteMeta.textContent = 'Last changed ' + MuninnApi.formatDateTime(currentNote.updated_at) + ' by ' + currentNote.updated_by
            + ' · ' + (currentWorkspace.kind === 'personal' ? 'Personal' : currentWorkspace.name);
        noteContent.textContent = currentNote.content;
        noteContent.classList.toggle('text-muted-brand', currentNote.content === '');
        if (currentNote.content === '') {
            noteContent.textContent = 'This note is empty.';
        }
        // Convenience only: the API refuses edits from Readers anyway.
        viewActions.classList.toggle('d-none', !currentWorkspace.permissions.write_notes);

        noteForm.classList.add('d-none');
        noteView.classList.remove('d-none');
        hasUnsavedChanges = false;
    }

    /** Shows the editor, filled with currentNote or empty for a new note. */
    function showEditor() {
        formHeading.textContent = currentNote ? 'Edit note' : 'New note';
        titleInput.value = currentNote ? currentNote.title : '';
        bodyInput.value = currentNote ? currentNote.content : '';
        MuninnApi.showFieldErrors(fieldInputIds, {});

        noteView.classList.add('d-none');
        noteForm.classList.remove('d-none');
        titleInput.focus();
    }

    function workspaceHomeUrl() {
        return 'index.php?workspace=' + encodeURIComponent(currentWorkspace.id);
    }

    async function loadWorkspace(workspaceId) {
        var workspaceData = await MuninnApi.request('GET', '/api/v1/workspaces/' + encodeURIComponent(workspaceId));
        currentWorkspace = workspaceData.workspace;
        backLink.href = workspaceHomeUrl();
    }

    /** Shows the "not available" message instead of the page (used for 404s). */
    function showNotAvailable() {
        notAvailableAlert.classList.remove('d-none');
        pageContent.classList.add('d-none');
    }

    noteForm.addEventListener('input', function () {
        hasUnsavedChanges = true;
    });

    // Warn before leaving the page with unsaved edits.
    window.addEventListener('beforeunload', function (unloadEvent) {
        if (hasUnsavedChanges) {
            unloadEvent.preventDefault();
            unloadEvent.returnValue = '';
        }
    });

    editButton.addEventListener('click', showEditor);

    cancelButton.addEventListener('click', function () {
        if (hasUnsavedChanges && !window.confirm('Discard your changes?')) {
            return;
        }
        hasUnsavedChanges = false;
        MuninnApi.showAlert(errorAlert, '');
        if (currentNote) {
            showReadView();
        } else {
            window.location.assign(workspaceHomeUrl());
        }
    });

    noteForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        MuninnApi.showAlert(errorAlert, '');
        MuninnApi.showFieldErrors(fieldInputIds, {});
        saveButton.disabled = true;

        try {
            var savedData;
            if (currentNote) {
                // The revision we edited: the API refuses the save if someone saved in between.
                savedData = await MuninnApi.request('PATCH', '/api/v1/notes/' + encodeURIComponent(currentNote.id), {
                    revision: currentNote.revision,
                    title: titleInput.value,
                    content: bodyInput.value,
                });
            } else {
                savedData = await MuninnApi.request('POST', '/api/v1/workspaces/' + encodeURIComponent(currentWorkspace.id) + '/notes', {
                    title: titleInput.value,
                    content: bodyInput.value,
                });
                // From now on this page shows the saved note.
                window.history.replaceState(null, '', 'note.php?id=' + encodeURIComponent(savedData.note.id));
            }
            currentNote = savedData.note;
            showReadView();
        } catch (saveError) {
            if (saveError.status === 409) {
                // Keep the user's text in the editor so nothing they typed is lost.
                MuninnApi.showAlert(errorAlert, saveError.message + ' Your text is still in the editor: copy it before reloading.');
            } else {
                MuninnApi.showFieldErrors(fieldInputIds, saveError.fields);
                MuninnApi.showAlert(errorAlert, saveError.message);
            }
        } finally {
            saveButton.disabled = false;
        }
    });

    trashButton.addEventListener('click', async function () {
        if (!window.confirm('Delete this note? It moves to Trash.')) {
            return;
        }
        trashButton.disabled = true;
        try {
            await MuninnApi.request('DELETE', '/api/v1/notes/' + encodeURIComponent(currentNote.id));
            window.location.assign(workspaceHomeUrl());
        } catch (trashError) {
            MuninnApi.showAlert(errorAlert, trashError.message);
            trashButton.disabled = false;
        }
    });

    document.addEventListener('muninn:user-ready', async function (readyEvent) {
        if (readyEvent.detail.is_system_admin) {
            showNotAvailable();
            return;
        }

        var noteId = MuninnApi.queryParameter('id');
        var newNoteWorkspaceId = MuninnApi.queryParameter('workspace');

        try {
            if (noteId) {
                var noteData = await MuninnApi.request('GET', '/api/v1/notes/' + encodeURIComponent(noteId));
                currentNote = noteData.note;
                await loadWorkspace(currentNote.workspace_id);
                showReadView();
            } else if (newNoteWorkspaceId) {
                await loadWorkspace(newNoteWorkspaceId);
                if (!currentWorkspace.permissions.write_notes) {
                    showNotAvailable();
                    return;
                }
                pageContent.classList.remove('d-none');
                showEditor();
                return;
            } else {
                showNotAvailable();
                return;
            }
        } catch (loadError) {
            if (loadError.status === 404) {
                showNotAvailable();
                return;
            }
            MuninnApi.showAlert(errorAlert, loadError.message);
        }
        pageContent.classList.remove('d-none');
    });
})();
