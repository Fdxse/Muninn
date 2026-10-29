/*
 * Magic Link page (D059): open the link, then browse, read and (for write links) edit notes.
 *
 * The token comes from the URL fragment (#token=...), which no server ever sees. It is traded for
 * a visit cookie with POST /api/v1/link/open; after that every call goes to the /api/v1/link/
 * endpoints, which check the link again each time. Buttons appear according to the link's access,
 * but the API decides every action.
 */
(function () {
    'use strict';

    var checkingMessage = document.getElementById('link-checking');
    var unavailableAlert = document.getElementById('link-unavailable');
    var unavailableMessage = document.getElementById('link-unavailable-message');
    var closedAlert = document.getElementById('link-closed');
    var linkContent = document.getElementById('link-content');
    var closeLinkButton = document.getElementById('close-link-button');
    var linkHeading = document.getElementById('link-heading');
    var accessBadge = document.getElementById('link-access-badge');
    var daysBadge = document.getElementById('link-days-badge');
    var hoursBadge = document.getElementById('link-hours-badge');
    var codeBadge = document.getElementById('link-code-badge');
    var linkSummary = document.getElementById('link-summary');
    var errorAlert = document.getElementById('link-error');

    var listView = document.getElementById('list-view');
    var folderFilterGroup = document.getElementById('folder-filter-group');
    var folderFilter = document.getElementById('folder-filter');
    var newNoteButton = document.getElementById('new-note-button');
    var notesEmpty = document.getElementById('notes-empty');
    var notesList = document.getElementById('notes-list');

    var noteView = document.getElementById('note-view');
    var backToListButton = document.getElementById('back-to-list-button');
    var noteHeading = document.getElementById('note-heading');
    var editNoteButton = document.getElementById('edit-note-button');
    var noteMeta = document.getElementById('note-meta');
    var noteContent = document.getElementById('note-content');

    var noteForm = document.getElementById('note-form');
    var noteFormHeading = document.getElementById('note-form-heading');
    var titleInput = document.getElementById('note-title');
    var noteFolderGroup = document.getElementById('note-folder-group');
    var noteFolderSelect = document.getElementById('note-folder');
    var bodyInput = document.getElementById('note-body');
    var saveButton = document.getElementById('save-note-button');
    var cancelEditButton = document.getElementById('cancel-edit-button');

    /** The token from the address this page opened; a different one means another link. */
    var openedLinkToken = '';
    /** What the link opens, as POST /api/v1/link/open describes it. */
    var openedLink = null;
    /** Folders the link reaches (empty for note links). */
    var reachableFolders = [];
    /** The note being read or edited; null while writing a brand-new note that is not saved yet. */
    var currentNote = null;
    var isSavingTask = false;
    var hasUnsavedChanges = false;

    /** API field name → input ID, for showing validation messages. */
    var noteFieldIds = { title: 'note-title', content: 'note-body', folder_id: 'note-folder' };

    function canWrite() {
        return openedLink !== null && openedLink.permission === 'write';
    }

    /** Shows exactly one of the page's main parts. */
    function showOnly(visiblePart) {
        [listView, noteView, noteForm].forEach(function (pagePart) {
            pagePart.classList.toggle('d-none', pagePart !== visiblePart);
        });
    }

    /**
     * Removes everything the page has shown of a link: headings, lists, the note and the editor.
     * Used whenever there is any doubt about which link this page belongs to (D071), so nothing
     * stays in the page, not even hidden.
     */
    function clearShownContent() {
        openedLink = null;
        currentNote = null;
        reachableFolders = [];
        linkHeading.textContent = '';
        accessBadge.textContent = '';
        clearInfoBadge(daysBadge);
        clearInfoBadge(hoursBadge);
        clearInfoBadge(codeBadge);
        linkSummary.textContent = '';
        notesList.replaceChildren();
        folderFilter.replaceChildren();
        noteFolderSelect.replaceChildren();
        noteHeading.textContent = '';
        noteMeta.textContent = '';
        noteContent.replaceChildren();
        titleInput.value = '';
        bodyInput.value = '';
        document.title = 'Shared notes · Muninn';
    }

    /** Replaces the page with the "does not work" message (e.g. revoked while open). */
    function showUnavailable(message) {
        hasUnsavedChanges = false;
        checkingMessage.classList.add('d-none');
        linkContent.classList.add('d-none');
        closeLinkButton.classList.add('d-none');
        clearShownContent();
        if (message) {
            unavailableMessage.textContent = message;
        }
        unavailableAlert.classList.remove('d-none');
        unavailableAlert.focus();
    }

    /**
     * Shows an API error. A 401 means the link stopped working while the page was open (revoked,
     * expired, outside its hours), so the whole page gives way to the "does not work" message.
     */
    function handleError(apiError) {
        if (apiError.status === 401) {
            showUnavailable(apiError.message);
            return;
        }
        MuninnApi.showAlert(errorAlert, apiError.message);
    }

    /** Fills the folder filter and the new note's folder choice. */
    function fillFolderChoices() {
        folderFilter.replaceChildren();
        noteFolderSelect.replaceChildren();

        var isWorkspaceLink = openedLink.target_type === 'workspace';
        var allOption = MuninnApi.createElement('option', null, isWorkspaceLink ? 'All notes' : 'All folders');
        allOption.value = '';
        folderFilter.appendChild(allOption);
        if (isWorkspaceLink) {
            var noFolderFilterOption = MuninnApi.createElement('option', null, 'No folder');
            noFolderFilterOption.value = 'none';
            folderFilter.appendChild(noFolderFilterOption);
            var noFolderOption = MuninnApi.createElement('option', null, 'No folder');
            noFolderOption.value = '';
            noteFolderSelect.appendChild(noFolderOption);
        }
        reachableFolders.forEach(function (folder) {
            var filterOption = MuninnApi.createElement('option', null, MuninnApi.folderOptionLabel(folder));
            filterOption.value = folder.id;
            folderFilter.appendChild(filterOption);
            var choiceOption = MuninnApi.createElement('option', null, MuninnApi.folderOptionLabel(folder));
            choiceOption.value = folder.id;
            noteFolderSelect.appendChild(choiceOption);
        });
        folderFilterGroup.classList.toggle('invisible', reachableFolders.length === 0);
    }

    async function loadNotes() {
        MuninnApi.showAlert(errorAlert, '');
        var listPath = '/api/v1/link/notes' + (folderFilter.value ? '?folder=' + encodeURIComponent(folderFilter.value) : '');
        try {
            var notesData = await MuninnApi.request('GET', listPath);
            notesList.replaceChildren();
            notesData.notes.forEach(function (noteSummary) {
                var noteButton = MuninnApi.createElement('button', 'list-group-item list-group-item-action py-3');
                noteButton.type = 'button';
                noteButton.appendChild(MuninnApi.createElement('div', 'fw-semibold text-break', noteSummary.title || 'Untitled note'));
                if (noteSummary.excerpt) {
                    noteButton.appendChild(MuninnApi.createElement('div', 'small text-muted-brand text-break', noteSummary.excerpt));
                }
                noteButton.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', 'Updated ' + MuninnApi.formatDateTime(noteSummary.updated_at)));
                noteButton.addEventListener('click', function () {
                    openNote(noteSummary.id);
                });
                notesList.appendChild(noteButton);
            });
            notesEmpty.classList.toggle('d-none', notesData.notes.length > 0);
            showOnly(listView);
        } catch (listError) {
            handleError(listError);
        }
    }

    /** Ticks or unticks a checklist box straight from the read view (write links only). */
    async function toggleTask(taskIndex, isChecked) {
        if (isSavingTask) {
            showNote();
            return;
        }
        var updatedContent = MuninnMarkdown.setTaskChecked(currentNote.content, taskIndex, isChecked);
        if (updatedContent === null) {
            showNote();
            return;
        }
        isSavingTask = true;
        MuninnApi.showAlert(errorAlert, '');
        try {
            var savedData = await MuninnApi.request('PATCH', '/api/v1/link/notes/' + encodeURIComponent(currentNote.id), {
                revision: currentNote.revision,
                content: updatedContent,
            });
            currentNote = savedData.note;
        } catch (taskError) {
            if (taskError.status === 409) {
                MuninnApi.showAlert(errorAlert, 'Someone else changed this note. Reload the page to see the latest version, then tick the box again.');
            } else {
                handleError(taskError);
            }
        } finally {
            isSavingTask = false;
            if (!linkContent.classList.contains('d-none')) {
                showNote();
            }
        }
    }

    /** Shows the current note in the read view. */
    function showNote() {
        noteHeading.textContent = currentNote.title || 'Untitled note';
        var metaText = 'Updated ' + MuninnApi.formatDateTime(currentNote.updated_at);
        if (currentNote.folder_name) {
            metaText += ' · ' + currentNote.folder_name;
        }
        noteMeta.textContent = metaText;
        MuninnMarkdown.render(currentNote.content, noteContent, canWrite() ? toggleTask : undefined);
        if (currentNote.content.trim() === '') {
            noteContent.replaceChildren(MuninnApi.createElement('p', 'text-muted-brand mb-0', 'This note is empty.'));
        }
        editNoteButton.classList.toggle('d-none', !canWrite());
        // A note link has nothing else to go back to.
        backToListButton.classList.toggle('d-none', openedLink.target_type === 'note');
        showOnly(noteView);
    }

    async function openNote(noteId) {
        MuninnApi.showAlert(errorAlert, '');
        try {
            var noteData = await MuninnApi.request('GET', '/api/v1/link/notes/' + encodeURIComponent(noteId));
            currentNote = noteData.note;
            showNote();
            noteHeading.focus();
        } catch (noteError) {
            handleError(noteError);
        }
    }

    /** Opens the editor for the current note, or for a new note when currentNote is null. */
    function showEditor() {
        MuninnApi.showAlert(errorAlert, '');
        MuninnApi.showFieldErrors(noteFieldIds, {});
        var isNewNote = currentNote === null;
        noteFormHeading.textContent = isNewNote ? 'New note' : 'Edit note';
        titleInput.value = isNewNote ? '' : currentNote.title;
        bodyInput.value = isNewNote ? '' : currentNote.content;
        // The folder is chosen only for new notes; editing never moves a note.
        noteFolderGroup.classList.toggle('d-none', !isNewNote || noteFolderSelect.options.length === 0);
        if (isNewNote && folderFilter.value && folderFilter.value !== 'none') {
            noteFolderSelect.value = folderFilter.value;
        }
        hasUnsavedChanges = false;
        showOnly(noteForm);
        titleInput.focus();
    }

    /** Creates the new note on the server if it does not exist yet (needed before adding images). */
    async function ensureNoteExists() {
        if (currentNote !== null) {
            return currentNote;
        }
        var createData = await MuninnApi.request('POST', '/api/v1/link/notes', {
            title: titleInput.value.trim(),
            content: bodyInput.value,
            folder_id: noteFolderSelect.value || undefined,
        });
        currentNote = createData.note;
        noteFolderGroup.classList.add('d-none');
        noteFormHeading.textContent = 'Edit note';
        return currentNote;
    }

    /** Stores an image through the visitor endpoint and returns the Markdown to insert. */
    async function uploadImage(imageFile) {
        var noteForImage = await ensureNoteExists();
        var uploadData = await MuninnApi.uploadFile(
            '/api/v1/link/notes/' + encodeURIComponent(noteForImage.id) + '/attachments',
            imageFile,
            imageFile.name || 'pasted-image'
        );
        return uploadData.attachment.markdown;
    }

    MuninnNoteEditor.attach({
        textArea: bodyInput,
        toolbar: document.getElementById('editor-toolbar'),
        imageInput: document.getElementById('editor-image-input'),
        writeTab: document.getElementById('editor-write-tab'),
        previewTab: document.getElementById('editor-preview-tab'),
        previewPane: document.getElementById('editor-preview'),
        statusLine: document.getElementById('editor-status'),
        uploadImage: uploadImage,
    });

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

    noteForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        MuninnApi.showAlert(errorAlert, '');
        MuninnApi.showFieldErrors(noteFieldIds, {});
        saveButton.disabled = true;
        try {
            if (currentNote === null) {
                await ensureNoteExists();
            } else {
                var savedData = await MuninnApi.request('PATCH', '/api/v1/link/notes/' + encodeURIComponent(currentNote.id), {
                    revision: currentNote.revision,
                    title: titleInput.value.trim(),
                    content: bodyInput.value,
                });
                currentNote = savedData.note;
            }
            hasUnsavedChanges = false;
            showNote();
        } catch (saveError) {
            if (saveError.status === 409) {
                MuninnApi.showAlert(errorAlert, 'Someone else changed this note since you opened it. Copy your text, reload the page, and add your changes again.');
            } else if (saveError.code === 'validation_failed') {
                MuninnApi.showFieldErrors(noteFieldIds, saveError.fields);
                MuninnApi.showAlert(errorAlert, 'Please correct the highlighted fields.');
            } else {
                handleError(saveError);
            }
        } finally {
            saveButton.disabled = false;
        }
    });

    cancelEditButton.addEventListener('click', function () {
        if (hasUnsavedChanges && !window.confirm('Discard your changes?')) {
            return;
        }
        hasUnsavedChanges = false;
        if (currentNote === null) {
            loadNotes();
        } else {
            showNote();
        }
    });

    editNoteButton.addEventListener('click', showEditor);
    backToListButton.addEventListener('click', loadNotes);
    folderFilter.addEventListener('change', loadNotes);
    newNoteButton.addEventListener('click', function () {
        currentNote = null;
        showEditor();
    });

    closeLinkButton.addEventListener('click', async function () {
        if (hasUnsavedChanges && !window.confirm('Discard your changes?')) {
            return;
        }
        try {
            await MuninnApi.request('POST', '/api/v1/link/close');
        } catch (closeError) {
            // Closing an already-stopped visit changes nothing; show "closed" either way.
        }
        hasUnsavedChanges = false;
        linkContent.classList.add('d-none');
        closeLinkButton.classList.add('d-none');
        clearShownContent();
        closedAlert.classList.remove('d-none');
        closedAlert.focus();
    });

    /** Empties and hides one of the link information badges. */
    function clearInfoBadge(infoBadge) {
        infoBadge.replaceChildren();
        infoBadge.removeAttribute('title');
        infoBadge.classList.add('d-none');
    }

    /**
     * Fills one information badge with an icon and a short text. The longer explanation goes in
     * the tooltip and in hidden text for screen readers, since tooltips are not read out.
     */
    function fillInfoBadge(infoBadge, iconName, shortText, longText) {
        var badgeIcon = MuninnApi.createElement('i', 'bi ' + iconName + ' me-1');
        badgeIcon.setAttribute('aria-hidden', 'true');
        var visibleText = MuninnApi.createElement('span', '', shortText);
        visibleText.setAttribute('aria-hidden', 'true');
        var spokenText = MuninnApi.createElement('span', 'visually-hidden', longText);
        infoBadge.replaceChildren(badgeIcon, visibleText, spokenText);
        infoBadge.title = longText;
        infoBadge.classList.remove('d-none');
    }

    /**
     * "12 days left", "1 day left" or "Less than a day left" until the link stops working.
     * Counted from this device's clock; the API still decides when the link really stops.
     */
    function daysLeftText(validUntilIso) {
        var millisecondsPerDay = 24 * 60 * 60 * 1000;
        var millisecondsLeft = new Date(validUntilIso).getTime() - Date.now();
        var wholeDaysLeft = Math.floor(millisecondsLeft / millisecondsPerDay);
        if (wholeDaysLeft < 1) {
            return 'Less than a day left';
        }
        return wholeDaysLeft === 1 ? '1 day left' : wholeDaysLeft + ' days left';
    }

    /** Shows the expiry badge and, when the link has daily hours, the opening-hours badge. */
    function showInfoBadges() {
        // The "works until" date used to sit in the line under the heading; it now lives here,
        // together with how many days that is from today.
        var untilText = 'Until ' + MuninnApi.formatDateTime(openedLink.valid_until);
        var daysText = daysLeftText(openedLink.valid_until);
        fillInfoBadge(daysBadge, 'bi-hourglass-split', untilText + ' \u00B7 ' + daysText,
            'Link works until ' + MuninnApi.formatDateTime(openedLink.valid_until) + ' (' + daysText.toLowerCase() + ').');

        if (openedLink.daily_start_time && openedLink.daily_end_time) {
            // The hours are in the server's window time zone (e.g. Europe/Stockholm), not
            // necessarily this device's, so the tooltip names it.
            var hoursText = openedLink.daily_start_time + '\u2013' + openedLink.daily_end_time;
            fillInfoBadge(hoursBadge, 'bi-clock', 'Open ' + hoursText,
                'Open every day ' + openedLink.daily_start_time + ' to ' + openedLink.daily_end_time
                    + ' (' + openedLink.window_time_zone + ' time).');
        } else {
            clearInfoBadge(hoursBadge);
        }

        // A link that hides code (D073): the API already took the code out, this only says so.
        if (openedLink.hide_code) {
            fillInfoBadge(codeBadge, 'bi-code-slash', 'Code hidden',
                'Code is hidden on this link. It is not sent to this page at all.');
        } else {
            clearInfoBadge(codeBadge);
        }
    }

    /** Shows what the link opens and loads its first view. */
    async function startVisit(linkDescription) {
        openedLink = linkDescription.link;
        linkHeading.textContent = openedLink.target_name;
        accessBadge.textContent = canWrite() ? 'Read and write' : 'Read only';
        showInfoBadges();
        var summaryText = openedLink.target_type === 'workspace' ? 'Shared workspace' : 'From ' + openedLink.workspace_name;
        linkSummary.textContent = summaryText;
        document.title = openedLink.target_name + ' · Muninn';

        checkingMessage.classList.add('d-none');
        linkContent.classList.remove('d-none');
        closeLinkButton.classList.remove('d-none');

        if (openedLink.target_type === 'note') {
            await openNote(openedLink.target_id);
            return;
        }
        var foldersData = await MuninnApi.request('GET', '/api/v1/link/folders');
        reachableFolders = foldersData.folders;
        fillFolderChoices();
        newNoteButton.classList.toggle('d-none', !canWrite());
        await loadNotes();
    }

    /**
     * Opens the link from the fragment. Without a token the page shows nothing (D071): it never
     * falls back to whatever link this browser opened last, which may be another workspace.
     */
    async function start() {
        var fragmentParameters = new URLSearchParams(window.location.hash.replace(/^#/, ''));
        var linkToken = fragmentParameters.get('token') || '';
        openedLinkToken = linkToken;
        if (linkToken === '') {
            showUnavailable('This address has no link in it. Open the full link you were given.');
            return;
        }
        try {
            var linkDescription = await MuninnApi.request('POST', '/api/v1/link/open', { token: linkToken });
            await startVisit(linkDescription);
        } catch (openError) {
            // "Outside its hours", "too many attempts", "another link opened" and network trouble
            // say so; the rest is one message.
            var specificMessage = ['link_outside_hours', 'rate_limited', 'network_error', 'link_visit_mismatch'].indexOf(openError.code) !== -1
                ? openError.message
                : null;
            showUnavailable(specificMessage);
        }
    }

    // Pasting another link into this tab changes only the part after "#", which does not reload
    // the page. Hide everything at once and load the page again, so the new link is opened from
    // scratch and the old one is never shown under it (D071).
    window.addEventListener('hashchange', function () {
        var changedParameters = new URLSearchParams(window.location.hash.replace(/^#/, ''));
        var changedToken = changedParameters.get('token');
        // A fragment without a token is not a link (nothing to open); the same token is the same link.
        if (changedToken === null || changedToken === openedLinkToken) {
            return;
        }
        hasUnsavedChanges = false;
        checkingMessage.classList.remove('d-none');
        linkContent.classList.add('d-none');
        closeLinkButton.classList.add('d-none');
        unavailableAlert.classList.add('d-none');
        closedAlert.classList.add('d-none');
        clearShownContent();
        window.location.reload();
    });

    // The skip link would put "#main-content" in the address in place of the token, and a reload
    // would then find no link. Move the focus without touching the address instead.
    var skipLink = document.querySelector('.muninn-skip-link');
    if (skipLink) {
        skipLink.addEventListener('click', function (skipEvent) {
            skipEvent.preventDefault();
            var mainContent = document.getElementById('main-content');
            mainContent.setAttribute('tabindex', '-1');
            mainContent.focus();
        });
    }

    // Coming back with the Back button can restore this page from the browser's memory, showing
    // what it showed before; load it again instead, so the link is checked anew.
    window.addEventListener('pageshow', function (pageShowEvent) {
        if (pageShowEvent.persisted) {
            clearShownContent();
            window.location.reload();
        }
    });

    start();
})();
