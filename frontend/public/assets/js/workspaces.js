/*
 * Workspaces page: lists the user's workspaces and creates shared ones.
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

    /** Builds one list entry: name, kind and the user's role, linking to its settings page. */
    function buildWorkspaceEntry(workspace) {
        var workspaceLink = MuninnApi.createElement('a', 'list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2 py-3');
        workspaceLink.href = 'workspace.php?id=' + encodeURIComponent(workspace.id);

        var nameBlock = MuninnApi.createElement('div', 'text-break');
        nameBlock.appendChild(MuninnApi.createElement('div', 'fw-semibold', workspace.kind === 'personal' ? 'Personal' : workspace.name));
        nameBlock.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', workspace.kind === 'personal' ? 'Only you' : 'Shared'));
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
        pageContent.classList.remove('d-none');
    });
})();
