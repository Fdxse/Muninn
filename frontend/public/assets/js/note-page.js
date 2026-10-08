/*
 * Note page: shows one note as sanitised Markdown, edits it with optimistic concurrency (title,
 * content, folder and tags), adds images, ticks checklist boxes, creates new notes and moves
 * notes to Trash. Markdown rendering lives in markdown-renderer.js, the editor controls in
 * note-editor.js.
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
    var noteOrganisation = document.getElementById('note-organisation');
    var folderSelect = document.getElementById('note-folder');
    var tagsInput = document.getElementById('note-tags');
    var tagSuggestions = document.getElementById('note-tag-suggestions');
    var viewActions = document.getElementById('note-view-actions');
    var editButton = document.getElementById('edit-note-button');
    var trashButton = document.getElementById('trash-note-button');
    var noteForm = document.getElementById('note-form');
    var formHeading = document.getElementById('note-form-heading');
    var titleInput = document.getElementById('note-title');
    var bodyInput = document.getElementById('note-body');
    var saveButton = document.getElementById('save-note-button');
    var cancelButton = document.getElementById('cancel-edit-button');

    var fieldInputIds = { title: 'note-title', content: 'note-body', folder_id: 'note-folder', tags: 'note-tags' };

    // The note being shown (null while writing a new one) and its workspace.
    var currentNote = null;
    var currentWorkspace = null;
    var hasUnsavedChanges = false;
    // True while a checklist tick is being saved, so ticks cannot overtake each other.
    var isSavingTask = false;
    var noteEditor = null;

    /** Builds the folder badge and tag badges under the title; each links to a filtered list. */
    function showOrganisation() {
        noteOrganisation.replaceChildren();
        if (currentNote.folder_id) {
            var folderLink = MuninnApi.createElement('a', 'badge rounded-pill text-bg-light border text-decoration-none');
            folderLink.href = workspaceHomeUrl() + '&folder=' + encodeURIComponent(currentNote.folder_id);
            var folderIcon = MuninnApi.createElement('i', 'bi bi-folder me-1');
            folderIcon.setAttribute('aria-hidden', 'true');
            folderLink.appendChild(folderIcon);
            folderLink.appendChild(document.createTextNode(currentNote.folder_name));
            noteOrganisation.appendChild(folderLink);
        }
        currentNote.tags.forEach(function (tagName) {
            var tagLink = MuninnApi.createElement('a', 'badge rounded-pill muninn-tag-badge text-decoration-none', '#' + tagName);
            tagLink.href = workspaceHomeUrl() + '&tag=' + encodeURIComponent(tagName);
            noteOrganisation.appendChild(tagLink);
        });
        noteOrganisation.classList.toggle('d-none', noteOrganisation.childElementCount === 0);
    }

    /** Ticks or clears one checklist box in the read view and saves the note right away. */
    async function toggleTask(taskIndex, isChecked) {
        if (isSavingTask) {
            showReadView();
            return;
        }
        var updatedContent = MuninnMarkdown.setTaskChecked(currentNote.content, taskIndex, isChecked);
        if (updatedContent === null) {
            showReadView();
            return;
        }
        isSavingTask = true;
        MuninnApi.showAlert(errorAlert, '');
        try {
            var savedData = await MuninnApi.request('PATCH', '/api/v1/notes/' + encodeURIComponent(currentNote.id), {
                revision: currentNote.revision,
                content: updatedContent,
            });
            currentNote = savedData.note;
        } catch (taskError) {
            MuninnApi.showAlert(errorAlert, taskError.status === 409
                ? 'Someone else changed this note. Reload the page to see the latest version, then tick the box again.'
                : taskError.message);
        } finally {
            isSavingTask = false;
            showReadView();
        }
    }

    /** Shows the read view for currentNote. */
    function showReadView() {
        noteHeading.textContent = currentNote.title || 'Untitled';
        noteMeta.textContent = 'Last changed ' + MuninnApi.formatDateTime(currentNote.updated_at) + ' by ' + currentNote.updated_by
            + ' · ' + (currentWorkspace.kind === 'personal' ? 'Personal' : currentWorkspace.name);
        var canWrite = currentWorkspace.permissions.write_notes;
        // Checklist boxes are clickable only for those who may edit (the API checks again).
        MuninnMarkdown.render(currentNote.content, noteContent, canWrite ? toggleTask : undefined);
        if (currentNote.content.trim() === '') {
            noteContent.replaceChildren(MuninnApi.createElement('p', 'text-muted-brand mb-0', 'This note is empty.'));
        }
        showOrganisation();
        // Convenience only: the API refuses edits from Readers anyway.
        viewActions.classList.toggle('d-none', !canWrite);

        noteForm.classList.add('d-none');
        noteView.classList.remove('d-none');
        hasUnsavedChanges = false;
    }

    /** Shows the editor, filled with currentNote or empty for a new note. */
    function showEditor() {
        formHeading.textContent = currentNote ? 'Edit note' : 'New note';
        titleInput.value = currentNote ? currentNote.title : '';
        bodyInput.value = currentNote ? currentNote.content : '';
        folderSelect.value = currentNote && currentNote.folder_id ? currentNote.folder_id : (MuninnApi.queryParameter('folder') || '');
        if (folderSelect.selectedIndex === -1) {
            folderSelect.value = '';
        }
        tagsInput.value = currentNote ? currentNote.tags.join(', ') : '';
        MuninnApi.showFieldErrors(fieldInputIds, {});
        noteEditor.showWrite();
        noteEditor.showStatus('', false);

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

    /** Fills the editor's folder list and tag suggestions from the workspace (editors only). */
    async function loadOrganisationChoices() {
        var workspacePath = '/api/v1/workspaces/' + encodeURIComponent(currentWorkspace.id);
        var folderData = await MuninnApi.request('GET', workspacePath + '/folders');
        var tagData = await MuninnApi.request('GET', workspacePath + '/tags');

        folderSelect.replaceChildren(MuninnApi.createElement('option', null, 'No folder'));
        folderSelect.firstChild.value = '';
        folderData.folders.forEach(function (folder) {
            var folderOption = MuninnApi.createElement('option', null, folder.name);
            folderOption.value = folder.id;
            folderSelect.appendChild(folderOption);
        });
        tagSuggestions.replaceChildren();
        tagData.tags.forEach(function (tag) {
            var tagOption = document.createElement('option');
            tagOption.value = tag.name;
            tagSuggestions.appendChild(tagOption);
        });
    }

    /** The editor's fields as the API expects them. */
    function editorFields() {
        return {
            title: titleInput.value,
            content: bodyInput.value,
            folder_id: folderSelect.value === '' ? null : folderSelect.value,
            tags: tagsInput.value.split(',').map(function (tagName) {
                return tagName.trim();
            }).filter(function (tagName) {
                return tagName !== '';
            }),
        };
    }

    /**
     * Makes sure the note exists on the server, so an image has a note to belong to. A new note is
     * saved as it stands; the editor stays open and the next Save updates it.
     */
    async function ensureNoteExists() {
        if (currentNote) {
            return currentNote;
        }
        var createdData = await MuninnApi.request('POST', '/api/v1/workspaces/' + encodeURIComponent(currentWorkspace.id) + '/notes', editorFields());
        currentNote = createdData.note;
        formHeading.textContent = 'Edit note';
        window.history.replaceState(null, '', 'note.php?id=' + encodeURIComponent(currentNote.id));
        return currentNote;
    }

    /** Uploads one image for the note and returns the Markdown that shows it. */
    async function uploadImage(imageFile) {
        var noteForImage = await ensureNoteExists();
        var uploadData = await MuninnApi.uploadFile(
            '/api/v1/notes/' + encodeURIComponent(noteForImage.id) + '/attachments',
            imageFile,
            imageFile.name || 'pasted-image'
        );
        return uploadData.attachment.markdown;
    }

    noteEditor = MuninnNoteEditor.attach({
        textArea: bodyInput,
        toolbar: document.getElementById('editor-toolbar'),
        imageInput: document.getElementById('editor-image-input'),
        writeTab: document.getElementById('editor-write-tab'),
        previewTab: document.getElementById('editor-preview-tab'),
        previewPane: document.getElementById('editor-preview'),
        statusLine: document.getElementById('editor-status'),
        uploadImage: uploadImage,
    });

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
                var updateFields = editorFields();
                updateFields.revision = currentNote.revision;
                savedData = await MuninnApi.request('PATCH', '/api/v1/notes/' + encodeURIComponent(currentNote.id), updateFields);
            } else {
                savedData = await MuninnApi.request('POST', '/api/v1/workspaces/' + encodeURIComponent(currentWorkspace.id) + '/notes', editorFields());
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
                if (currentWorkspace.permissions.write_notes) {
                    await loadOrganisationChoices();
                }
                showReadView();
            } else if (newNoteWorkspaceId) {
                await loadWorkspace(newNoteWorkspaceId);
                if (!currentWorkspace.permissions.write_notes) {
                    showNotAvailable();
                    return;
                }
                await loadOrganisationChoices();
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
