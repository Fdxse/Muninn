/*
 * Note page: shows one note as sanitised Markdown, edits it with optimistic concurrency (title,
 * content, folder and tags), adds and removes images, ticks checklist boxes, creates new notes,
 * archives them, links to their history and moves notes to Trash. Markdown rendering lives in markdown-renderer.js, the editor controls in
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
    var archiveButton = document.getElementById('archive-note-button');
    var archiveButtonLabel = document.getElementById('archive-note-label');
    var archivedNotice = document.getElementById('archived-notice');
    var historyLink = document.getElementById('history-link');
    var historyLinkLabel = document.getElementById('history-link-label');
    var noteForm = document.getElementById('note-form');
    var formHeading = document.getElementById('note-form-heading');
    var titleInput = document.getElementById('note-title');
    var bodyInput = document.getElementById('note-body');
    var saveButton = document.getElementById('save-note-button');
    var cancelButton = document.getElementById('cancel-edit-button');
    var imagesSection = document.getElementById('note-images');
    var imageList = document.getElementById('note-image-list');

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

        var isArchived = Boolean(currentNote.archived_at);
        archivedNotice.classList.toggle('d-none', !isArchived);
        archiveButtonLabel.textContent = isArchived ? 'Unarchive' : 'Archive';
        // History usage is always shown as "used / limit" (D009).
        historyLink.href = 'history.php?id=' + encodeURIComponent(currentNote.id);
        historyLinkLabel.textContent = 'History ' + currentNote.history_count + ' / ' + currentNote.history_limit;

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
        loadImageList();

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
            var folderOption = MuninnApi.createElement('option', null, MuninnApi.folderOptionLabel(folder));
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
        // Refreshed after the editor has inserted the Markdown, so the row shows it as used.
        window.setTimeout(loadImageList, 0);
        return uploadData.attachment.markdown;
    }

    /** Matches every Markdown image that shows the given attachment, with the line break after it. */
    function attachmentMarkdownPattern(attachmentId) {
        return new RegExp('!\\[[^\\]\\n]*\\]\\(attachment:' + attachmentId + '\\)[ \\t]*\\n?', 'g');
    }

    /** True when the editor's text still shows the attachment. */
    function editorUsesAttachment(attachmentId) {
        return attachmentMarkdownPattern(attachmentId).test(bodyInput.value);
    }

    /** Builds one row of the image list: thumbnail, name, whether the text uses it, Remove button. */
    function buildImageListItem(attachment) {
        var listItem = MuninnApi.createElement('li', 'd-flex align-items-center gap-2 py-1 border-bottom');

        var thumbnail = MuninnApi.createElement('img', 'muninn-note-image-thumbnail rounded');
        thumbnail.src = MuninnMarkdown.attachmentUrl('attachment:' + attachment.id);
        thumbnail.alt = '';
        thumbnail.loading = 'lazy';
        listItem.appendChild(thumbnail);

        var nameColumn = MuninnApi.createElement('div', 'flex-grow-1 small text-break');
        nameColumn.appendChild(MuninnApi.createElement('div', null, attachment.filename));
        if (!editorUsesAttachment(attachment.id)) {
            nameColumn.appendChild(MuninnApi.createElement('div', 'text-muted-brand', 'Not shown in the note text'));
        }
        listItem.appendChild(nameColumn);

        var removeButton = MuninnApi.createElement('button', 'btn btn-outline-danger btn-sm');
        removeButton.type = 'button';
        var removeIcon = MuninnApi.createElement('i', 'bi bi-x-lg');
        removeIcon.setAttribute('aria-hidden', 'true');
        removeButton.appendChild(removeIcon);
        removeButton.appendChild(document.createTextNode(' Remove'));
        // The visible label is the same on every row, so screen readers also hear which image.
        removeButton.setAttribute('aria-label', 'Remove image ' + attachment.filename);
        removeButton.addEventListener('click', function () {
            removeImage(attachment, removeButton);
        });
        listItem.appendChild(removeButton);

        return listItem;
    }

    /** Loads the note's images into the editor's image list; the list hides itself when empty. */
    async function loadImageList() {
        if (!currentNote) {
            imagesSection.classList.add('d-none');
            return;
        }
        try {
            var attachmentData = await MuninnApi.request('GET', '/api/v1/notes/' + encodeURIComponent(currentNote.id) + '/attachments');
            imageList.replaceChildren();
            attachmentData.attachments.forEach(function (attachment) {
                imageList.appendChild(buildImageListItem(attachment));
            });
            imagesSection.classList.toggle('d-none', attachmentData.attachments.length === 0);
        } catch (listError) {
            // The list is a convenience; the editor works without it.
            imagesSection.classList.add('d-none');
        }
    }

    /**
     * Removes one image for good after confirmation, then takes its Markdown out of the editor.
     * The text change is saved with the note as usual; the image file itself is gone at once.
     */
    async function removeImage(attachment, removeButton) {
        var usedInText = editorUsesAttachment(attachment.id);
        var confirmMessage = 'Remove the image "' + attachment.filename + '" for good? This cannot be undone.'
            + (usedInText ? ' It is also taken out of the note text; save the note to keep that change.' : '');
        if (!window.confirm(confirmMessage)) {
            return;
        }
        removeButton.disabled = true;
        try {
            await MuninnApi.request('DELETE', '/api/v1/attachments/' + encodeURIComponent(attachment.id));
        } catch (removeError) {
            removeButton.disabled = false;
            // A 404 means the image was already gone; refresh the list either way.
            noteEditor.showStatus(removeError.status === 404 ? 'That image was already removed.' : removeError.message, removeError.status !== 404);
            await loadImageList();
            return;
        }
        if (usedInText) {
            bodyInput.value = bodyInput.value.replace(attachmentMarkdownPattern(attachment.id), '');
            // Lets the page know there is something to save.
            bodyInput.dispatchEvent(new Event('input', { bubbles: true }));
            noteEditor.showStatus('Image removed. Save the note to keep the text change.', false);
        } else {
            noteEditor.showStatus('Image removed.', false);
        }
        await loadImageList();
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

    archiveButton.addEventListener('click', async function () {
        var archiveAction = currentNote.archived_at ? 'unarchive' : 'archive';
        archiveButton.disabled = true;
        MuninnApi.showAlert(errorAlert, '');
        try {
            var archiveData = await MuninnApi.request('POST', '/api/v1/notes/' + encodeURIComponent(currentNote.id) + '/' + archiveAction);
            currentNote = archiveData.note;
            showReadView();
        } catch (archiveError) {
            MuninnApi.showAlert(errorAlert, archiveError.message);
        } finally {
            archiveButton.disabled = false;
        }
    });

    trashButton.addEventListener('click', async function () {
        if (!window.confirm('Delete this note? It moves to Trash, where it can be restored for a while.')) {
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
