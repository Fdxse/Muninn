/*
 * Workspaces page: lists the user's workspaces, creates shared ones, and lists the Shared
 * Workspaces run by the administrator, which users ask to join (D067).
 * All data is inserted with textContent, never innerHTML.
 */
(function () {
    'use strict';

    document.body.setAttribute('data-shell-hold', '');

    var pageContent = document.getElementById('shell-content');
    var adminNotice = document.getElementById('admin-account-notice');
    var errorAlert = document.getElementById('workspaces-error');
    var workspacesList = document.getElementById('workspaces-list');
    var createForm = document.getElementById('create-workspace-form');
    var nameInput = document.getElementById('workspace-name');
    var createButton = document.getElementById('create-workspace-submit');
    var openWorkspacesSection = document.getElementById('open-workspaces-section');
    var openWorkspacesList = document.getElementById('open-workspaces-list');
    var openWorkspacesError = document.getElementById('open-workspaces-error');
    var openWorkspacesStatus = document.getElementById('open-workspaces-status');

    /** The small grey line under a workspace's name in the user's own list. */
    function kindLabel(workspace) {
        if (workspace.kind === 'personal') {
            return 'Only you';
        }
        return workspace.kind === 'open' ? 'Shared Workspace' : 'Shared';
    }

    /** Builds one list entry: name, kind and the user's role, linking to its settings page. */
    function buildWorkspaceEntry(workspace) {
        var workspaceLink = MuninnApi.createElement('a', 'list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2 py-3');
        workspaceLink.href = 'workspace.php?id=' + encodeURIComponent(workspace.id);

        var nameBlock = MuninnApi.createElement('div', 'text-break');
        nameBlock.appendChild(MuninnApi.createElement('div', 'fw-semibold', workspace.kind === 'personal' ? 'Personal' : workspace.name));
        nameBlock.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', kindLabel(workspace)));
        workspaceLink.appendChild(nameBlock);

        workspaceLink.appendChild(MuninnApi.createElement('span', 'badge text-bg-light', MuninnApi.roleLabel(workspace.your_role)));
        return workspaceLink;
    }

    async function loadWorkspaces() {
        var workspaceData = await MuninnApi.request('GET', '/api/v1/workspaces');
        workspacesList.replaceChildren();
        workspaceData.workspaces.forEach(function (workspace) {
            workspacesList.appendChild(buildWorkspaceEntry(workspace));
        });
    }

    /** What the user's newest join request says, shown under the description. */
    function joinStatusText(openWorkspace) {
        if (openWorkspace.is_member) {
            return 'You are a member (' + MuninnApi.roleLabel(openWorkspace.your_role) + ').';
        }
        if (openWorkspace.join_request && openWorkspace.join_request.status === 'pending') {
            return 'You asked to join. Waiting for the administrator.';
        }
        if (openWorkspace.join_request && openWorkspace.join_request.status === 'declined') {
            return 'The administrator declined your last request. You can ask again.';
        }
        return '';
    }

    /**
     * Builds the small "why do you want to join?" form shown after pressing Ask to join.
     * The note is optional; the administrator sees it with the request.
     */
    function buildAskForm(openWorkspace, listItem) {
        var askForm = MuninnApi.createElement('form', 'w-100 row g-2 mt-1');
        askForm.noValidate = true;
        var noteInputId = 'join-note-' + openWorkspace.id;

        var noteColumn = MuninnApi.createElement('div', 'col-12 col-sm');
        var noteLabel = MuninnApi.createElement('label', 'form-label small mb-1', 'Note to the administrator (optional)');
        noteLabel.htmlFor = noteInputId;
        var noteInput = MuninnApi.createElement('input', 'form-control');
        noteInput.type = 'text';
        noteInput.id = noteInputId;
        noteInput.maxLength = 200;
        noteColumn.appendChild(noteLabel);
        noteColumn.appendChild(noteInput);
        askForm.appendChild(noteColumn);

        var buttonColumn = MuninnApi.createElement('div', 'col-12 col-sm-auto d-flex gap-2 align-items-end');
        var sendButton = MuninnApi.createElement('button', 'btn btn-primary', 'Send request');
        sendButton.type = 'submit';
        var cancelButton = MuninnApi.createElement('button', 'btn btn-outline-secondary', 'Cancel');
        cancelButton.type = 'button';
        cancelButton.addEventListener('click', function () {
            askForm.remove();
            listItem.querySelector('[data-ask-button]').focus();
        });
        buttonColumn.appendChild(sendButton);
        buttonColumn.appendChild(cancelButton);
        askForm.appendChild(buttonColumn);

        askForm.addEventListener('submit', async function (submitEvent) {
            submitEvent.preventDefault();
            MuninnApi.showAlert(openWorkspacesError, '');
            sendButton.disabled = true;
            try {
                await MuninnApi.request('POST', '/api/v1/open-workspaces/' + encodeURIComponent(openWorkspace.id) + '/join-request', { note: noteInput.value.trim() });
                openWorkspacesStatus.textContent = 'Request to join ' + openWorkspace.name + ' sent.';
                await loadOpenWorkspaces();
            } catch (askError) {
                MuninnApi.showAlert(openWorkspacesError, askError.message);
                sendButton.disabled = false;
            }
        });

        return askForm;
    }

    /** Builds one Shared Workspace entry with the action that fits the user's status. */
    function buildOpenWorkspaceItem(openWorkspace) {
        var listItem = MuninnApi.createElement('li', 'list-group-item d-flex flex-wrap align-items-center gap-2 py-3');

        var textBlock = MuninnApi.createElement('div', 'flex-grow-1 text-break');
        textBlock.appendChild(MuninnApi.createElement('div', 'fw-semibold', openWorkspace.name));
        if (openWorkspace.description) {
            textBlock.appendChild(MuninnApi.createElement('div', 'small', openWorkspace.description));
        }
        var statusText = joinStatusText(openWorkspace);
        if (statusText !== '') {
            textBlock.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', statusText));
        }
        listItem.appendChild(textBlock);

        var isPending = openWorkspace.join_request && openWorkspace.join_request.status === 'pending';
        if (openWorkspace.is_member) {
            var openLink = MuninnApi.createElement('a', 'btn btn-outline-primary btn-sm', 'Open');
            openLink.href = 'index.php?workspace=' + encodeURIComponent(openWorkspace.id);
            openLink.setAttribute('aria-label', 'Open ' + openWorkspace.name);
            listItem.appendChild(openLink);
        } else if (isPending) {
            var cancelRequestButton = MuninnApi.createElement('button', 'btn btn-outline-secondary btn-sm', 'Cancel request');
            cancelRequestButton.type = 'button';
            cancelRequestButton.setAttribute('aria-label', 'Cancel your request to join ' + openWorkspace.name);
            cancelRequestButton.addEventListener('click', async function () {
                MuninnApi.showAlert(openWorkspacesError, '');
                cancelRequestButton.disabled = true;
                try {
                    await MuninnApi.request('DELETE', '/api/v1/open-workspaces/' + encodeURIComponent(openWorkspace.id) + '/join-request');
                    openWorkspacesStatus.textContent = 'Request to join ' + openWorkspace.name + ' cancelled.';
                    await loadOpenWorkspaces();
                } catch (cancelError) {
                    MuninnApi.showAlert(openWorkspacesError, cancelError.message);
                    cancelRequestButton.disabled = false;
                }
            });
            listItem.appendChild(cancelRequestButton);
        } else {
            var askButton = MuninnApi.createElement('button', 'btn btn-primary btn-sm', 'Ask to join');
            askButton.type = 'button';
            askButton.setAttribute('data-ask-button', '');
            askButton.setAttribute('aria-label', 'Ask to join ' + openWorkspace.name);
            askButton.addEventListener('click', function () {
                // Only one note form per workspace at a time.
                if (listItem.querySelector('form')) {
                    return;
                }
                var askForm = buildAskForm(openWorkspace, listItem);
                listItem.appendChild(askForm);
                askForm.querySelector('input').focus();
            });
            listItem.appendChild(askButton);
        }

        return listItem;
    }

    /** Loads the Shared Workspaces; the section stays hidden while the administrator has made none. */
    async function loadOpenWorkspaces() {
        var openWorkspaceData = await MuninnApi.request('GET', '/api/v1/open-workspaces');
        openWorkspacesList.replaceChildren();
        openWorkspaceData.open_workspaces.forEach(function (openWorkspace) {
            openWorkspacesList.appendChild(buildOpenWorkspaceItem(openWorkspace));
        });
        openWorkspacesSection.classList.toggle('d-none', openWorkspaceData.open_workspaces.length === 0);
    }

    createForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        MuninnApi.showAlert(errorAlert, '');
        MuninnApi.showFieldErrors({ name: 'workspace-name' }, {});
        createButton.disabled = true;
        try {
            var createdData = await MuninnApi.request('POST', '/api/v1/workspaces', { name: nameInput.value });
            window.location.assign('workspace.php?id=' + encodeURIComponent(createdData.workspace.id));
        } catch (createError) {
            MuninnApi.showFieldErrors({ name: 'workspace-name' }, createError.fields);
            MuninnApi.showAlert(errorAlert, createError.message);
            createButton.disabled = false;
        }
    });

    document.addEventListener('muninn:user-ready', async function (readyEvent) {
        if (readyEvent.detail.is_system_admin) {
            adminNotice.classList.remove('d-none');
            return;
        }
        try {
            await loadWorkspaces();
        } catch (loadError) {
            MuninnApi.showAlert(errorAlert, loadError.message);
        }
        try {
            await loadOpenWorkspaces();
        } catch (openLoadError) {
            openWorkspacesSection.classList.remove('d-none');
            MuninnApi.showAlert(openWorkspacesError, openLoadError.message);
        }
        pageContent.classList.remove('d-none');
    });
})();
