/*
 * Trash page: lists a workspace's deleted notes with when each will be deleted for good, and
 * offers Restore (Editors and up) and Delete forever / Empty Trash (Admins and Owners). The
 * buttons follow the workspace's permission flags for convenience; the API checks every action.
 * All text is inserted with textContent, never innerHTML.
 */
(function () {
    'use strict';

    document.body.setAttribute('data-shell-hold', '');

    var pageContent = document.getElementById('shell-content');
    var notAvailableAlert = document.getElementById('trash-not-available');
    var backLink = document.getElementById('back-link');
    var trashHeading = document.getElementById('trash-heading');
    var trashExplanation = document.getElementById('trash-explanation');
    var emptyTrashButton = document.getElementById('empty-trash-button');
    var errorAlert = document.getElementById('trash-error');
    var statusAlert = document.getElementById('trash-status');
    var emptyMessage = document.getElementById('trash-empty');
    var trashList = document.getElementById('trash-list');

    var currentWorkspace = null;
    var trashedNotes = [];

    function trashPath() {
        return '/api/v1/workspaces/' + encodeURIComponent(currentWorkspace.id) + '/trash';
    }

    /** Formats an ISO timestamp as a date only, e.g. "7 Nov 2026". */
    function formatDate(isoTimestamp) {
        return new Date(isoTimestamp).toLocaleDateString(undefined, { dateStyle: 'medium' });
    }

    function iconElement(iconClass) {
        var icon = MuninnApi.createElement('i', 'bi ' + iconClass);
        icon.setAttribute('aria-hidden', 'true');
        return icon;
    }

    /** Shows a short success message (e.g. "Restored"), replacing any earlier one. */
    function showStatus(message) {
        statusAlert.textContent = message;
        statusAlert.classList.toggle('d-none', !message);
    }

    /** Builds one Trash entry with its action buttons. */
    function buildTrashEntry(trashedNote) {
        var noteTitle = trashedNote.title || 'Untitled';
        var entry = MuninnApi.createElement('li', 'list-group-item py-3');

        var titleLine = MuninnApi.createElement('div', 'd-flex flex-wrap justify-content-between align-items-baseline gap-2');
        titleLine.appendChild(MuninnApi.createElement('span', 'fw-semibold text-break', noteTitle));
        titleLine.appendChild(MuninnApi.createElement('small', 'text-muted-brand',
            'Deleted ' + formatDate(trashedNote.trashed_at) + (trashedNote.trashed_by ? ' by ' + trashedNote.trashed_by : '')));
        entry.appendChild(titleLine);
        if (trashedNote.excerpt) {
            entry.appendChild(MuninnApi.createElement('div', 'small text-muted-brand text-truncate', trashedNote.excerpt));
        }
        entry.appendChild(MuninnApi.createElement('div', 'small text-muted-brand mt-1',
            'Deleted for good on ' + formatDate(trashedNote.purge_after) + '.'));

        var actionRow = MuninnApi.createElement('div', 'd-flex flex-wrap gap-2 mt-2');
        if (currentWorkspace.permissions.write_notes) {
            var restoreButton = MuninnApi.createElement('button', 'btn btn-outline-primary btn-sm');
            restoreButton.type = 'button';
            restoreButton.appendChild(iconElement('bi-arrow-counterclockwise'));
            restoreButton.appendChild(document.createTextNode(' Restore'));
            restoreButton.setAttribute('aria-label', 'Restore ' + noteTitle);
            restoreButton.addEventListener('click', function () {
                restoreNote(trashedNote, restoreButton);
            });
            actionRow.appendChild(restoreButton);
        }
        if (currentWorkspace.permissions.purge_notes) {
            var purgeButton = MuninnApi.createElement('button', 'btn btn-outline-danger btn-sm');
            purgeButton.type = 'button';
            purgeButton.appendChild(iconElement('bi-x-octagon'));
            purgeButton.appendChild(document.createTextNode(' Delete forever'));
            purgeButton.setAttribute('aria-label', 'Delete ' + noteTitle + ' forever');
            purgeButton.addEventListener('click', function () {
                purgeNote(trashedNote, purgeButton);
            });
            actionRow.appendChild(purgeButton);
        }
        if (actionRow.childElementCount > 0) {
            entry.appendChild(actionRow);
        }
        return entry;
    }

    /** Reloads and shows the Trash. */
    async function refreshTrash() {
        MuninnApi.showAlert(errorAlert, '');
        try {
            var trashData = await MuninnApi.request('GET', trashPath());
            trashedNotes = trashData.notes;
            trashExplanation.textContent = 'Deleted notes stay here for ' + trashData.retention_days
                + ' days and are then deleted for good, with their history and images.';
            trashList.replaceChildren();
            trashedNotes.forEach(function (trashedNote) {
                trashList.appendChild(buildTrashEntry(trashedNote));
            });
            emptyMessage.classList.toggle('d-none', trashedNotes.length > 0);
            emptyTrashButton.classList.toggle('d-none', !currentWorkspace.permissions.purge_notes || trashedNotes.length === 0);
        } catch (listError) {
            MuninnApi.showAlert(errorAlert, listError.message);
        }
    }

    async function restoreNote(trashedNote, restoreButton) {
        restoreButton.disabled = true;
        showStatus('');
        try {
            await MuninnApi.request('POST', '/api/v1/trash/' + encodeURIComponent(trashedNote.id) + '/restore');
            showStatus('Restored "' + (trashedNote.title || 'Untitled') + '".');
            await refreshTrash();
        } catch (restoreError) {
            restoreButton.disabled = false;
            MuninnApi.showAlert(errorAlert, restoreError.message);
        }
    }

    async function purgeNote(trashedNote, purgeButton) {
        var question = 'Delete "' + (trashedNote.title || 'Untitled') + '" forever? Its history and images go too. This cannot be undone.';
        if (!window.confirm(question)) {
            return;
        }
        purgeButton.disabled = true;
        showStatus('');
        try {
            await MuninnApi.request('DELETE', '/api/v1/trash/' + encodeURIComponent(trashedNote.id));
            showStatus('Deleted "' + (trashedNote.title || 'Untitled') + '" for good.');
            await refreshTrash();
        } catch (purgeError) {
            purgeButton.disabled = false;
            MuninnApi.showAlert(errorAlert, purgeError.message);
        }
    }

    emptyTrashButton.addEventListener('click', async function () {
        var question = 'Delete all ' + trashedNotes.length + (trashedNotes.length === 1 ? ' note' : ' notes')
            + ' in the Trash forever? Their history and images go too. This cannot be undone.';
        if (!window.confirm(question)) {
            return;
        }
        emptyTrashButton.disabled = true;
        showStatus('');
        try {
            var emptyData = await MuninnApi.request('DELETE', trashPath());
            showStatus('Deleted ' + emptyData.deleted_notes + (emptyData.deleted_notes === 1 ? ' note' : ' notes') + ' for good.');
            await refreshTrash();
        } catch (emptyError) {
            MuninnApi.showAlert(errorAlert, emptyError.message);
        } finally {
            emptyTrashButton.disabled = false;
        }
    });

    document.addEventListener('muninn:user-ready', async function (readyEvent) {
        var workspaceId = MuninnApi.queryParameter('workspace') || MuninnApi.rememberedWorkspace();
        if (readyEvent.detail.is_system_admin || !workspaceId) {
            notAvailableAlert.classList.remove('d-none');
            return;
        }
        try {
            var workspaceData = await MuninnApi.request('GET', '/api/v1/workspaces/' + encodeURIComponent(workspaceId));
            currentWorkspace = workspaceData.workspace;
        } catch (workspaceError) {
            notAvailableAlert.classList.remove('d-none');
            return;
        }
        var workspaceLabel = currentWorkspace.kind === 'personal' ? 'Personal' : currentWorkspace.name;
        trashHeading.textContent = 'Trash · ' + workspaceLabel;
        backLink.href = 'index.php?workspace=' + encodeURIComponent(currentWorkspace.id);
        await refreshTrash();
        pageContent.classList.remove('d-none');
    });
})();
