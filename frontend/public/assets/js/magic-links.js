/*
 * Magic Links page (D059): create links for the workspace, a folder or a note, show the new
 * link once, list every link with its state, and revoke links. The API checks the role (Admin
 * or Owner) and every rule of a link on each request; this page only shows the controls.
 */
(function () {
    'use strict';

    document.body.setAttribute('data-shell-hold', '');

    var workspaceId = MuninnApi.queryParameter('workspace') || '';
    var workspacePath = '/api/v1/workspaces/' + encodeURIComponent(workspaceId);

    var pageContent = document.getElementById('shell-content');
    var notAvailableAlert = document.getElementById('links-not-available');
    var backLink = document.getElementById('back-to-workspace-link');
    var workspaceNameElement = document.getElementById('links-workspace-name');
    var createForm = document.getElementById('create-link-form');
    var createError = document.getElementById('create-error');
    var createSubmitButton = document.getElementById('create-link-submit');
    var labelInput = document.getElementById('link-label');
    var targetTypeSelect = document.getElementById('link-target-type');
    var targetChoice = document.getElementById('link-target-choice');
    var targetChoiceLabel = document.getElementById('link-target-id-label');
    var targetIdSelect = document.getElementById('link-target-id');
    var validFromInput = document.getElementById('link-valid-from');
    var validUntilInput = document.getElementById('link-valid-until');
    var dailyStartInput = document.getElementById('link-daily-start');
    var dailyEndInput = document.getElementById('link-daily-end');
    var newLinkResult = document.getElementById('new-link-result');
    var newLinkUrl = document.getElementById('new-link-url');
    var copyLinkButton = document.getElementById('copy-link-button');
    var copyLinkStatus = document.getElementById('copy-link-status');
    var listError = document.getElementById('list-error');
    var linksEmpty = document.getElementById('links-empty');
    var linksList = document.getElementById('links-list');

    /** API field name → input ID, for showing validation messages. */
    var formFieldIds = {
        label: 'link-label',
        target_type: 'link-target-type',
        target_id: 'link-target-id',
        valid_from: 'link-valid-from',
        valid_until: 'link-valid-until',
        daily_start_time: 'link-daily-start',
        daily_end_time: 'link-daily-end',
    };

    /** Folders and notes of the workspace, loaded once for the "Opens" choice. */
    var workspaceFolders = [];
    var workspaceNotes = [];
    var defaultValidDays = 30;

    var statusLabels = {
        active: 'Active',
        scheduled: 'Not started yet',
        expired: 'Expired',
        revoked: 'Revoked',
        creator_lost_access: 'Stopped: its creator lost access',
        target_gone: 'Stopped: the folder or note is gone',
    };
    var targetTypeLabels = { workspace: 'Workspace', folder: 'Folder', note: 'Note' };

    /** Formats a Date as the value of a datetime-local input (the device's local time). */
    function toLocalInputValue(dateValue) {
        function twoDigits(numberValue) {
            return String(numberValue).padStart(2, '0');
        }
        return dateValue.getFullYear() + '-' + twoDigits(dateValue.getMonth() + 1) + '-' + twoDigits(dateValue.getDate())
            + 'T' + twoDigits(dateValue.getHours()) + ':' + twoDigits(dateValue.getMinutes());
    }

    /** Converts a datetime-local value (local time) to ISO 8601 UTC for the API, or undefined when empty. */
    function toIsoOrUndefined(localInputValue) {
        if (!localInputValue) {
            return undefined;
        }
        var parsedDate = new Date(localInputValue);
        return isNaN(parsedDate.getTime()) ? localInputValue : parsedDate.toISOString();
    }

    function resetValidUntil() {
        var defaultEnd = new Date(Date.now() + defaultValidDays * 24 * 60 * 60 * 1000);
        validUntilInput.value = toLocalInputValue(defaultEnd);
    }

    /** Fills the folder or note drop-down to match the "Opens" choice. */
    function refreshTargetChoice() {
        var targetType = targetTypeSelect.value;
        targetIdSelect.replaceChildren();
        targetChoice.classList.toggle('d-none', targetType === 'workspace');
        if (targetType === 'workspace') {
            return;
        }

        targetChoiceLabel.textContent = targetType === 'folder' ? 'Folder' : 'Note';
        var choices = targetType === 'folder' ? workspaceFolders : workspaceNotes;
        if (choices.length === 0) {
            var emptyOption = MuninnApi.createElement('option', null, targetType === 'folder' ? 'This workspace has no folders yet' : 'This workspace has no notes yet');
            emptyOption.value = '';
            targetIdSelect.appendChild(emptyOption);
            return;
        }
        choices.forEach(function (choice) {
            var choiceText = targetType === 'folder' ? MuninnApi.folderOptionLabel(choice) : (choice.title || 'Untitled note');
            var choiceOption = MuninnApi.createElement('option', null, choiceText);
            choiceOption.value = choice.id;
            targetIdSelect.appendChild(choiceOption);
        });
    }

    /** One line of the link list, with a Revoke button while the link can still be used. */
    function buildLinkRow(magicLink) {
        var linkRow = MuninnApi.createElement('li', 'list-group-item');
        var headerLine = MuninnApi.createElement('div', 'd-flex flex-wrap align-items-center gap-2');
        headerLine.appendChild(MuninnApi.createElement('span', 'fw-semibold text-break', magicLink.label));
        var isUsable = magicLink.status === 'active' || magicLink.status === 'scheduled';
        headerLine.appendChild(MuninnApi.createElement(
            'span',
            'badge ' + (isUsable ? 'text-bg-success' : 'text-bg-secondary'),
            statusLabels[magicLink.status] || magicLink.status
        ));
        headerLine.appendChild(MuninnApi.createElement(
            'span',
            'badge text-bg-light border',
            magicLink.permission === 'write' ? 'Read and write' : 'Read only'
        ));
        linkRow.appendChild(headerLine);

        var targetText = targetTypeLabels[magicLink.target_type] + ': ' + (magicLink.target_name || '(no longer available)');
        linkRow.appendChild(MuninnApi.createElement('div', 'small text-break', targetText));

        var whenText = 'Works ' + MuninnApi.formatDateTime(magicLink.valid_from) + ' – ' + MuninnApi.formatDateTime(magicLink.valid_until);
        if (magicLink.daily_start_time) {
            whenText += ', daily ' + magicLink.daily_start_time + '–' + magicLink.daily_end_time + ' (' + magicLink.timezone + ')';
        }
        linkRow.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', whenText));

        var usageText = 'Created by ' + magicLink.created_by + ' · ' + (magicLink.use_count === 1 ? 'Opened once' : 'Opened ' + magicLink.use_count + ' times');
        if (magicLink.last_used_at) {
            usageText += ', last ' + MuninnApi.formatDateTime(magicLink.last_used_at);
        }
        linkRow.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', usageText));

        if (magicLink.status !== 'revoked' && magicLink.status !== 'expired') {
            var revokeButton = MuninnApi.createElement('button', 'btn btn-outline-danger btn-sm mt-2');
            revokeButton.type = 'button';
            revokeButton.textContent = 'Revoke';
            revokeButton.setAttribute('aria-label', 'Revoke link ' + magicLink.label);
            revokeButton.addEventListener('click', async function () {
                if (!window.confirm('Revoke "' + magicLink.label + '"? It stops working at once, also for anyone who has it open.')) {
                    return;
                }
                revokeButton.disabled = true;
                try {
                    await MuninnApi.request('DELETE', '/api/v1/magic-links/' + encodeURIComponent(magicLink.id));
                    await loadLinks();
                } catch (revokeError) {
                    revokeButton.disabled = false;
                    MuninnApi.showAlert(listError, revokeError.message);
                }
            });
            linkRow.appendChild(revokeButton);
        }
        return linkRow;
    }

    async function loadLinks() {
        var linksData = await MuninnApi.request('GET', workspacePath + '/magic-links');
        defaultValidDays = linksData.default_valid_days;
        document.getElementById('link-timezone').textContent = linksData.timezone;
        document.getElementById('max-valid-days').textContent = String(linksData.max_valid_days);

        linksList.replaceChildren();
        linksData.magic_links.forEach(function (magicLink) {
            linksList.appendChild(buildLinkRow(magicLink));
        });
        linksEmpty.classList.toggle('d-none', linksData.magic_links.length > 0);
    }

    async function loadPage() {
        try {
            var workspaceData = await MuninnApi.request('GET', workspacePath);
            var workspace = workspaceData.workspace;
            workspaceNameElement.textContent = workspace.kind === 'personal' ? 'Personal workspace' : workspace.name;
            backLink.href = 'workspace.php?id=' + encodeURIComponent(workspace.id);

            await loadLinks();
            var foldersData = await MuninnApi.request('GET', workspacePath + '/folders');
            workspaceFolders = foldersData.folders;
            var notesData = await MuninnApi.request('GET', workspacePath + '/notes');
            workspaceNotes = notesData.notes;

            resetValidUntil();
            refreshTargetChoice();
            pageContent.classList.remove('d-none');
        } catch (loadError) {
            // 404 (not a member) and 403 (role too weak) both mean "not for you".
            notAvailableAlert.classList.remove('d-none');
        }
    }

    targetTypeSelect.addEventListener('change', refreshTargetChoice);

    createForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        MuninnApi.showAlert(createError, '');
        MuninnApi.showFieldErrors(formFieldIds, {});
        newLinkResult.classList.add('d-none');

        var linkRequest = {
            label: labelInput.value.trim(),
            target_type: targetTypeSelect.value,
            permission: createForm.querySelector('input[name="link-permission"]:checked').value,
            valid_from: toIsoOrUndefined(validFromInput.value),
            valid_until: toIsoOrUndefined(validUntilInput.value),
            daily_start_time: dailyStartInput.value || undefined,
            daily_end_time: dailyEndInput.value || undefined,
        };
        if (linkRequest.target_type !== 'workspace') {
            linkRequest.target_id = targetIdSelect.value;
        }

        createSubmitButton.disabled = true;
        try {
            var createdData = await MuninnApi.request('POST', workspacePath + '/magic-links', linkRequest);
            newLinkUrl.textContent = createdData.link_url;
            copyLinkStatus.textContent = '';
            newLinkResult.classList.remove('d-none');
            newLinkResult.focus();
            createForm.reset();
            resetValidUntil();
            refreshTargetChoice();
            await loadLinks();
        } catch (createFailure) {
            MuninnApi.showFieldErrors(formFieldIds, createFailure.fields);
            document.getElementById('link-permission-feedback').textContent = createFailure.fields.permission || '';
            MuninnApi.showAlert(createError, createFailure.code === 'validation_failed' ? 'Please correct the highlighted fields.' : createFailure.message);
        } finally {
            createSubmitButton.disabled = false;
        }
    });

    copyLinkButton.addEventListener('click', function () {
        MuninnApi.copyLinkText(newLinkUrl, copyLinkStatus);
    });

    document.addEventListener('muninn:user-ready', loadPage);
})();
