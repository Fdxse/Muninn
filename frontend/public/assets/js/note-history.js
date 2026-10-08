/*
 * History page: lists a note's earlier versions (newest first), shows the chosen one as
 * sanitised Markdown, and lets Editors restore it. A restore is an ordinary save based on the
 * note's current revision, so it is refused (409) if someone saved in the meantime.
 * All text is inserted with textContent, never innerHTML.
 */
(function () {
    'use strict';

    document.body.setAttribute('data-shell-hold', '');

    var pageContent = document.getElementById('shell-content');
    var notAvailableAlert = document.getElementById('history-not-available');
    var backLink = document.getElementById('back-link');
    var historyHeading = document.getElementById('history-heading');
    var historyUsage = document.getElementById('history-usage');
    var errorAlert = document.getElementById('history-error');
    var emptyMessage = document.getElementById('history-empty');
    var versionList = document.getElementById('version-list');
    var versionView = document.getElementById('version-view');
    var versionTitle = document.getElementById('version-title');
    var versionMeta = document.getElementById('version-meta');
    var versionOrganisation = document.getElementById('version-organisation');
    var versionContent = document.getElementById('version-content');
    var restoreButton = document.getElementById('restore-version-button');

    var currentNote = null;
    var currentWorkspace = null;
    var selectedVersion = null;

    /** Builds one clickable list entry for a version. */
    function buildVersionEntry(versionSummary) {
        var versionButton = MuninnApi.createElement('button', 'list-group-item list-group-item-action py-2');
        versionButton.type = 'button';
        versionButton.setAttribute('aria-pressed', 'false');
        versionButton.dataset.versionId = versionSummary.id;

        var topLine = MuninnApi.createElement('div', 'd-flex justify-content-between align-items-baseline gap-2');
        topLine.appendChild(MuninnApi.createElement('span', 'fw-semibold', MuninnApi.formatDateTime(versionSummary.edited_at)));
        topLine.appendChild(MuninnApi.createElement('small', 'text-muted-brand text-nowrap', versionSummary.edited_by));
        versionButton.appendChild(topLine);
        versionButton.appendChild(MuninnApi.createElement('div', 'small text-muted-brand text-truncate',
            (versionSummary.title || 'Untitled') + ' · ' + versionSummary.content_length + ' characters'));

        versionButton.addEventListener('click', function () {
            showVersion(versionSummary.id);
        });
        return versionButton;
    }

    /** Marks the chosen version in the list. */
    function markSelectedEntry(versionId) {
        versionList.querySelectorAll('[data-version-id]').forEach(function (versionButton) {
            var isSelected = versionButton.dataset.versionId === versionId;
            versionButton.classList.toggle('active', isSelected);
            versionButton.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
        });
    }

    /** Loads and shows one version. */
    async function showVersion(versionId) {
        MuninnApi.showAlert(errorAlert, '');
        try {
            var versionData = await MuninnApi.request('GET', '/api/v1/notes/' + encodeURIComponent(currentNote.id)
                + '/versions/' + encodeURIComponent(versionId));
            selectedVersion = versionData.version;
        } catch (versionError) {
            MuninnApi.showAlert(errorAlert, versionError.message);
            return;
        }

        markSelectedEntry(versionId);
        versionTitle.textContent = selectedVersion.title || 'Untitled';
        versionMeta.textContent = 'Saved ' + MuninnApi.formatDateTime(selectedVersion.edited_at) + ' by ' + selectedVersion.edited_by;

        versionOrganisation.replaceChildren();
        if (selectedVersion.folder_name) {
            var folderBadge = MuninnApi.createElement('span', 'badge rounded-pill text-bg-light border');
            var folderIcon = MuninnApi.createElement('i', 'bi bi-folder me-1');
            folderIcon.setAttribute('aria-hidden', 'true');
            folderBadge.appendChild(folderIcon);
            folderBadge.appendChild(document.createTextNode(selectedVersion.folder_name));
            versionOrganisation.appendChild(folderBadge);
        }
        selectedVersion.tags.forEach(function (tagName) {
            versionOrganisation.appendChild(MuninnApi.createElement('span', 'badge rounded-pill muninn-tag-badge', '#' + tagName));
        });
        versionOrganisation.classList.toggle('d-none', versionOrganisation.childElementCount === 0);

        // Read-only rendering: no clickable checklist boxes for an old version.
        MuninnMarkdown.render(selectedVersion.content, versionContent);
        if (selectedVersion.content.trim() === '') {
            versionContent.replaceChildren(MuninnApi.createElement('p', 'text-muted-brand mb-0', 'This version was empty.'));
        }

        // Convenience only: the API refuses restores from Readers anyway.
        restoreButton.classList.toggle('d-none', !currentWorkspace.permissions.write_notes);
        versionView.classList.remove('d-none');
        // On a phone the version shows below the list, so bring it into view.
        versionView.focus();
    }

    restoreButton.addEventListener('click', async function () {
        if (!window.confirm('Restore this version? The current text is kept in the history, so you can undo this.')) {
            return;
        }
        restoreButton.disabled = true;
        MuninnApi.showAlert(errorAlert, '');
        try {
            await MuninnApi.request('POST', '/api/v1/notes/' + encodeURIComponent(currentNote.id)
                + '/versions/' + encodeURIComponent(selectedVersion.id) + '/restore', { revision: currentNote.revision });
            window.location.assign('note.php?id=' + encodeURIComponent(currentNote.id));
        } catch (restoreError) {
            restoreButton.disabled = false;
            MuninnApi.showAlert(errorAlert, restoreError.status === 409
                ? 'Someone changed this note while you were looking. Reload the page and try again.'
                : restoreError.message);
        }
    });

    document.addEventListener('muninn:user-ready', async function (readyEvent) {
        var noteId = MuninnApi.queryParameter('id');
        if (readyEvent.detail.is_system_admin || !noteId) {
            notAvailableAlert.classList.remove('d-none');
            return;
        }

        try {
            var noteData = await MuninnApi.request('GET', '/api/v1/notes/' + encodeURIComponent(noteId));
            currentNote = noteData.note;
            var workspaceData = await MuninnApi.request('GET', '/api/v1/workspaces/' + encodeURIComponent(currentNote.workspace_id));
            currentWorkspace = workspaceData.workspace;
            var historyData = await MuninnApi.request('GET', '/api/v1/notes/' + encodeURIComponent(noteId) + '/versions');

            backLink.href = 'note.php?id=' + encodeURIComponent(currentNote.id);
            historyHeading.textContent = 'History · ' + (currentNote.title || 'Untitled');
            historyUsage.textContent = historyData.history_count + ' / ' + historyData.history_limit
                + ' earlier versions kept. Saves made within a few minutes of each other count as one.';
            historyData.versions.forEach(function (versionSummary) {
                versionList.appendChild(buildVersionEntry(versionSummary));
            });
            emptyMessage.classList.toggle('d-none', historyData.versions.length > 0);
        } catch (loadError) {
            if (loadError.status === 404) {
                notAvailableAlert.classList.remove('d-none');
                return;
            }
            MuninnApi.showAlert(errorAlert, loadError.message);
        }
        pageContent.classList.remove('d-none');
    });
})();
