/*
 * Workspace administration:
 * - Shared Workspaces (D067): create, edit and delete them, approve or decline join requests, and
 *   manage their members (Editors and Readers only).
 * - Workspaces created by users (D050): list every one, and manage the members of those without
 *   an active Owner.
 * The API applies the same rules as for Owners, e.g. a workspace always keeps one Owner.
 * All data is inserted with textContent, never innerHTML.
 */
(function () {
    'use strict';

    document.body.setAttribute('data-shell-hold', '');

    var pageContent = document.getElementById('shell-content');
    var notAvailableAlert = document.getElementById('admin-not-available');
    var workspacesErrorAlert = document.getElementById('workspaces-error');
    var workspacesList = document.getElementById('workspaces-list');
    var workspacesEmpty = document.getElementById('workspaces-empty');
    var membersCard = document.getElementById('members-card');
    var membersHeading = document.getElementById('members-heading');
    var membersErrorAlert = document.getElementById('members-error');
    var membersList = document.getElementById('members-list');
    var addMemberForm = document.getElementById('add-member-form');
    var addMemberSubmit = document.getElementById('add-member-submit');
    var memberUsernameInput = document.getElementById('member-username');
    var memberRoleSelect = document.getElementById('member-role');

    var openWorkspacesErrorAlert = document.getElementById('open-workspaces-error');
    var openWorkspacesStatus = document.getElementById('open-workspaces-status');
    var joinRequestsList = document.getElementById('join-requests-list');
    var joinRequestsEmpty = document.getElementById('join-requests-empty');
    var openWorkspacesList = document.getElementById('open-workspaces-list');
    var openWorkspacesEmpty = document.getElementById('open-workspaces-empty');
    var createOpenWorkspaceForm = document.getElementById('create-open-workspace-form');
    var createOpenWorkspaceSubmit = document.getElementById('create-open-workspace-submit');
    var openWorkspaceNameInput = document.getElementById('open-workspace-name');
    var openWorkspaceDescriptionInput = document.getElementById('open-workspace-description');
    var editCard = document.getElementById('edit-open-workspace-card');
    var editForm = document.getElementById('edit-open-workspace-form');
    var editNameInput = document.getElementById('edit-open-workspace-name');
    var editDescriptionInput = document.getElementById('edit-open-workspace-description');

    var allRoles = ['owner', 'admin', 'editor', 'reader'];
    /** Members of Shared Workspaces (D067) are only ever Editors or Readers. */
    var openWorkspaceRoles = ['editor', 'reader'];
    /** The workspace whose members are shown, or null. Its kind decides which roles exist. */
    var selectedWorkspace = null;
    /** The Shared Workspace being edited, or null. */
    var editedWorkspace = null;

    /** The roles the selected workspace's members may have. */
    function rolesForSelectedWorkspace() {
        return selectedWorkspace && selectedWorkspace.kind === 'open' ? openWorkspaceRoles : allRoles;
    }

    /** Fills a role picker with the given roles and selects one. */
    function fillRoleSelect(roleSelect, roleValues, selectedRole) {
        roleSelect.replaceChildren();
        roleValues.forEach(function (roleValue) {
            var roleOption = MuninnApi.createElement('option', null, MuninnApi.roleLabel(roleValue));
            roleOption.value = roleValue;
            roleOption.selected = roleValue === selectedRole;
            roleSelect.appendChild(roleOption);
        });
    }

    /** Tells the navigation badge that the number of waiting join requests changed. */
    function announceJoinRequestsChanged() {
        document.dispatchEvent(new CustomEvent('muninn:workspace-join-requests-changed'));
    }

    /** Builds one join request row: who, which workspace, the note, a role and two buttons. */
    function buildJoinRequestItem(joinRequest) {
        var listItem = MuninnApi.createElement('li', 'list-group-item d-flex flex-wrap align-items-center gap-2');

        var textBlock = MuninnApi.createElement('div', 'flex-grow-1 text-break');
        textBlock.appendChild(MuninnApi.createElement('div', 'fw-semibold',
            joinRequest.user.display_name + ' (' + joinRequest.user.username + ') → ' + joinRequest.workspace.name));
        if (joinRequest.note) {
            textBlock.appendChild(MuninnApi.createElement('div', 'small', '“' + joinRequest.note + '”'));
        }
        var askedText = 'Asked ' + new Date(joinRequest.created_at).toLocaleString()
            + (joinRequest.user.is_disabled ? ' · disabled account' : '');
        textBlock.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', askedText));
        listItem.appendChild(textBlock);

        var roleSelect = MuninnApi.createElement('select', 'form-select form-select-sm w-auto');
        roleSelect.setAttribute('aria-label', 'Role for ' + joinRequest.user.display_name);
        fillRoleSelect(roleSelect, openWorkspaceRoles, 'editor');
        listItem.appendChild(roleSelect);

        var approveButton = MuninnApi.createElement('button', 'btn btn-primary btn-sm', 'Approve');
        approveButton.type = 'button';
        approveButton.setAttribute('aria-label', 'Approve ' + joinRequest.user.display_name + ' for ' + joinRequest.workspace.name);
        approveButton.addEventListener('click', function () {
            decideJoinRequest(joinRequest, 'approve', { role: roleSelect.value });
        });
        listItem.appendChild(approveButton);

        var declineButton = MuninnApi.createElement('button', 'btn btn-outline-danger btn-sm', 'Decline');
        declineButton.type = 'button';
        declineButton.setAttribute('aria-label', 'Decline ' + joinRequest.user.display_name + ' for ' + joinRequest.workspace.name);
        declineButton.addEventListener('click', function () {
            decideJoinRequest(joinRequest, 'decline', null);
        });
        listItem.appendChild(declineButton);

        return listItem;
    }

    async function decideJoinRequest(joinRequest, decision, requestBody) {
        MuninnApi.showAlert(openWorkspacesErrorAlert, '');
        try {
            await MuninnApi.request('POST', '/api/v1/admin/workspace-join-requests/' + encodeURIComponent(joinRequest.id) + '/' + decision, requestBody);
            openWorkspacesStatus.textContent = (decision === 'approve' ? 'Approved ' : 'Declined ') + joinRequest.user.display_name + '.';
        } catch (decideError) {
            MuninnApi.showAlert(openWorkspacesErrorAlert, decideError.message);
        }
        announceJoinRequestsChanged();
        await reloadOpenSide();
    }

    /** Builds one Shared Workspace row with Members, Edit and Delete. */
    function buildOpenWorkspaceItem(openWorkspace) {
        var listItem = MuninnApi.createElement('li', 'list-group-item d-flex flex-wrap align-items-center gap-2');

        var textBlock = MuninnApi.createElement('div', 'flex-grow-1 text-break');
        textBlock.appendChild(MuninnApi.createElement('div', 'fw-semibold', openWorkspace.name));
        if (openWorkspace.description) {
            textBlock.appendChild(MuninnApi.createElement('div', 'small', openWorkspace.description));
        }
        var countText = openWorkspace.member_count + (openWorkspace.member_count === 1 ? ' member' : ' members');
        if (openWorkspace.pending_request_count > 0) {
            countText += ' · ' + openWorkspace.pending_request_count + ' waiting';
        }
        textBlock.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', countText));
        listItem.appendChild(textBlock);

        var membersButton = MuninnApi.createElement('button', 'btn btn-outline-primary btn-sm', 'Members');
        membersButton.type = 'button';
        membersButton.setAttribute('aria-label', 'Manage the members of ' + openWorkspace.name);
        membersButton.addEventListener('click', function () {
            openMembers({ id: openWorkspace.id, name: openWorkspace.name, kind: 'open' });
        });
        listItem.appendChild(membersButton);

        var editButton = MuninnApi.createElement('button', 'btn btn-outline-secondary btn-sm', 'Edit');
        editButton.type = 'button';
        editButton.setAttribute('aria-label', 'Edit ' + openWorkspace.name);
        editButton.addEventListener('click', function () {
            openEditCard(openWorkspace);
        });
        listItem.appendChild(editButton);

        var deleteButton = MuninnApi.createElement('button', 'btn btn-outline-danger btn-sm', 'Delete');
        deleteButton.type = 'button';
        deleteButton.setAttribute('aria-label', 'Delete ' + openWorkspace.name);
        deleteButton.addEventListener('click', function () {
            deleteOpenWorkspace(openWorkspace);
        });
        listItem.appendChild(deleteButton);

        return listItem;
    }

    async function loadJoinRequests() {
        var requestData = await MuninnApi.request('GET', '/api/v1/admin/workspace-join-requests');
        joinRequestsList.replaceChildren();
        requestData.join_requests.forEach(function (joinRequest) {
            joinRequestsList.appendChild(buildJoinRequestItem(joinRequest));
        });
        joinRequestsEmpty.classList.toggle('d-none', requestData.join_requests.length > 0);
    }

    async function loadOpenWorkspaces() {
        var openWorkspaceData = await MuninnApi.request('GET', '/api/v1/admin/open-workspaces');
        openWorkspacesList.replaceChildren();
        openWorkspaceData.open_workspaces.forEach(function (openWorkspace) {
            openWorkspacesList.appendChild(buildOpenWorkspaceItem(openWorkspace));
        });
        openWorkspacesEmpty.classList.toggle('d-none', openWorkspaceData.open_workspaces.length > 0);
    }

    /** Reloads the requests and the Shared Workspaces (counts change with every decision). */
    async function reloadOpenSide() {
        try {
            await loadJoinRequests();
            await loadOpenWorkspaces();
            if (selectedWorkspace && selectedWorkspace.kind === 'open') {
                await loadMembers();
            }
        } catch (reloadError) {
            MuninnApi.showAlert(openWorkspacesErrorAlert, reloadError.message);
        }
    }

    function openEditCard(openWorkspace) {
        editedWorkspace = openWorkspace;
        MuninnApi.showFieldErrors({ name: 'edit-open-workspace-name', description: 'edit-open-workspace-description' }, {});
        editNameInput.value = openWorkspace.name;
        editDescriptionInput.value = openWorkspace.description || '';
        editCard.classList.remove('d-none');
        editNameInput.focus();
    }

    function closeEditCard() {
        editedWorkspace = null;
        editCard.classList.add('d-none');
    }

    async function deleteOpenWorkspace(openWorkspace) {
        if (!window.confirm('Delete ' + openWorkspace.name + '? Its members lose it. This only works while it holds no notes.')) {
            return;
        }
        MuninnApi.showAlert(openWorkspacesErrorAlert, '');
        try {
            await MuninnApi.request('DELETE', '/api/v1/admin/open-workspaces/' + encodeURIComponent(openWorkspace.id));
            openWorkspacesStatus.textContent = openWorkspace.name + ' deleted.';
            if (selectedWorkspace && selectedWorkspace.id === openWorkspace.id) {
                selectedWorkspace = null;
                membersCard.classList.add('d-none');
            }
            if (editedWorkspace && editedWorkspace.id === openWorkspace.id) {
                closeEditCard();
            }
        } catch (deleteError) {
            MuninnApi.showAlert(openWorkspacesErrorAlert, deleteError.message);
        }
        announceJoinRequestsChanged();
        await reloadOpenSide();
    }

    createOpenWorkspaceForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        var fieldInputIds = { name: 'open-workspace-name', description: 'open-workspace-description' };
        MuninnApi.showAlert(openWorkspacesErrorAlert, '');
        MuninnApi.showFieldErrors(fieldInputIds, {});
        createOpenWorkspaceSubmit.disabled = true;
        try {
            await MuninnApi.request('POST', '/api/v1/admin/open-workspaces', {
                name: openWorkspaceNameInput.value,
                description: openWorkspaceDescriptionInput.value,
            });
            openWorkspacesStatus.textContent = openWorkspaceNameInput.value + ' created.';
            openWorkspaceNameInput.value = '';
            openWorkspaceDescriptionInput.value = '';
            await reloadOpenSide();
        } catch (createError) {
            MuninnApi.showFieldErrors(fieldInputIds, createError.fields);
            MuninnApi.showAlert(openWorkspacesErrorAlert, createError.code === 'validation_failed' ? 'Please correct the highlighted fields.' : createError.message);
        } finally {
            createOpenWorkspaceSubmit.disabled = false;
        }
    });

    editForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        if (!editedWorkspace) {
            return;
        }
        var fieldInputIds = { name: 'edit-open-workspace-name', description: 'edit-open-workspace-description' };
        MuninnApi.showAlert(openWorkspacesErrorAlert, '');
        MuninnApi.showFieldErrors(fieldInputIds, {});
        try {
            await MuninnApi.request('PATCH', '/api/v1/admin/open-workspaces/' + encodeURIComponent(editedWorkspace.id), {
                name: editNameInput.value,
                description: editDescriptionInput.value,
            });
            openWorkspacesStatus.textContent = 'Saved.';
            closeEditCard();
            await reloadOpenSide();
        } catch (saveError) {
            MuninnApi.showFieldErrors(fieldInputIds, saveError.fields);
            MuninnApi.showAlert(openWorkspacesErrorAlert, saveError.code === 'validation_failed' ? 'Please correct the highlighted fields.' : saveError.message);
        }
    });

    document.getElementById('edit-open-workspace-cancel').addEventListener('click', closeEditCard);

    /** Builds one list item for a shared workspace. */
    function buildWorkspaceItem(workspace) {
        var listItem = MuninnApi.createElement('li', 'list-group-item d-flex flex-wrap align-items-center gap-2');

        var textBlock = MuninnApi.createElement('div', 'flex-grow-1 text-break');
        textBlock.appendChild(MuninnApi.createElement('div', 'fw-semibold', workspace.name));
        var ownerText = workspace.owners.length > 0 ? 'Owners: ' + workspace.owners.join(', ') : 'No Owner';
        textBlock.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', ownerText + ' · ' + workspace.member_count + (workspace.member_count === 1 ? ' member' : ' members')));
        listItem.appendChild(textBlock);

        // Nobody but an administrator can manage members when no active Owner is left.
        if (workspace.active_owner_count === 0) {
            listItem.appendChild(MuninnApi.createElement('span', 'badge text-bg-danger', 'No active Owner'));
        }

        // Administrators may only manage workspaces without an active Owner (D050); the API enforces it.
        if (workspace.active_owner_count === 0) {
            var manageButton = MuninnApi.createElement('button', 'btn btn-outline-primary btn-sm', 'Members');
            manageButton.type = 'button';
            manageButton.setAttribute('aria-label', 'Manage the members of ' + workspace.name);
            manageButton.addEventListener('click', function () {
                openMembers({ id: workspace.id, name: workspace.name, kind: 'shared' });
            });
            listItem.appendChild(manageButton);
        }

        return listItem;
    }

    async function loadWorkspaces() {
        var workspaceData = await MuninnApi.request('GET', '/api/v1/admin/workspaces');
        workspacesList.replaceChildren();
        workspaceData.workspaces.forEach(function (workspace) {
            workspacesList.appendChild(buildWorkspaceItem(workspace));
        });
        workspacesEmpty.classList.toggle('d-none', workspaceData.workspaces.length > 0);
    }

    /** The members API path of the open workspace. */
    function membersPath() {
        return '/api/v1/admin/workspaces/' + encodeURIComponent(selectedWorkspace.id) + '/members';
    }

    /** Builds one member row with a role picker and a Remove button. */
    function buildMemberRow(member) {
        var memberRow = MuninnApi.createElement('li', 'list-group-item px-0 d-flex flex-wrap align-items-center gap-2');

        var nameBlock = MuninnApi.createElement('div', 'flex-grow-1 text-break');
        nameBlock.appendChild(MuninnApi.createElement('div', 'fw-semibold', member.display_name));
        nameBlock.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', member.username + (member.is_disabled ? ' · disabled account' : '')));
        memberRow.appendChild(nameBlock);

        var roleSelect = MuninnApi.createElement('select', 'form-select form-select-sm w-auto');
        roleSelect.setAttribute('aria-label', 'Role of ' + member.display_name);
        fillRoleSelect(roleSelect, rolesForSelectedWorkspace(), member.role);
        roleSelect.addEventListener('change', function () {
            changeRole(member, roleSelect.value);
        });
        memberRow.appendChild(roleSelect);

        var removeButton = MuninnApi.createElement('button', 'btn btn-outline-danger btn-sm', 'Remove');
        removeButton.type = 'button';
        removeButton.setAttribute('aria-label', 'Remove ' + member.display_name);
        removeButton.addEventListener('click', function () {
            removeMember(member);
        });
        memberRow.appendChild(removeButton);

        return memberRow;
    }

    async function loadMembers() {
        var memberData = await MuninnApi.request('GET', membersPath());
        membersHeading.textContent = 'Members of ' + memberData.workspace.name;
        membersList.replaceChildren();
        memberData.members.forEach(function (member) {
            membersList.appendChild(buildMemberRow(member));
        });
    }

    /** Reloads the members and the overview (Owner counts and member counts change). */
    async function reloadAll() {
        if (selectedWorkspace && selectedWorkspace.kind === 'open') {
            await reloadOpenSide();
            return;
        }
        try {
            await loadWorkspaces();
            await loadMembers();
        } catch (reloadError) {
            if (reloadError.status === 404) {
                // The workspace has an active Owner again, so its Owners manage it from now on.
                membersCard.classList.add('d-none');
                MuninnApi.showAlert(workspacesErrorAlert, selectedWorkspace.name + ' has an active Owner again. Its Owners manage the members from now on.');
                selectedWorkspace = null;
                return;
            }
            MuninnApi.showAlert(membersErrorAlert, reloadError.message);
        }
    }

    async function openMembers(workspace) {
        selectedWorkspace = workspace;
        MuninnApi.showAlert(membersErrorAlert, '');
        MuninnApi.showFieldErrors({ username: 'member-username', role: 'member-role' }, {});
        membersList.replaceChildren();
        membersHeading.textContent = 'Members of ' + workspace.name;
        // Shared Workspaces (D067) only have Editors and Readers.
        fillRoleSelect(memberRoleSelect, rolesForSelectedWorkspace(), 'editor');
        membersCard.classList.remove('d-none');
        membersCard.focus();
        try {
            await loadMembers();
        } catch (loadError) {
            MuninnApi.showAlert(membersErrorAlert, loadError.message);
        }
    }

    async function changeRole(member, newRole) {
        MuninnApi.showAlert(membersErrorAlert, '');
        try {
            await MuninnApi.request('PATCH', membersPath() + '/' + encodeURIComponent(member.user_id), { role: newRole });
        } catch (changeError) {
            MuninnApi.showAlert(membersErrorAlert, changeError.message);
        }
        await reloadAll();
    }

    async function removeMember(member) {
        if (!window.confirm('Remove ' + member.display_name + ' from ' + selectedWorkspace.name + '?')) {
            return;
        }
        MuninnApi.showAlert(membersErrorAlert, '');
        try {
            await MuninnApi.request('DELETE', membersPath() + '/' + encodeURIComponent(member.user_id));
        } catch (removeError) {
            MuninnApi.showAlert(membersErrorAlert, removeError.message);
        }
        await reloadAll();
    }

    addMemberForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        var fieldInputIds = { username: 'member-username', role: 'member-role' };
        MuninnApi.showAlert(membersErrorAlert, '');
        MuninnApi.showFieldErrors(fieldInputIds, {});
        addMemberSubmit.disabled = true;
        try {
            await MuninnApi.request('POST', membersPath(), {
                username: memberUsernameInput.value.trim(),
                role: memberRoleSelect.value,
            });
            memberUsernameInput.value = '';
            await reloadAll();
        } catch (addError) {
            MuninnApi.showFieldErrors(fieldInputIds, addError.fields);
            MuninnApi.showAlert(membersErrorAlert, addError.code === 'validation_failed' ? 'Please correct the highlighted fields.' : addError.message);
        } finally {
            addMemberSubmit.disabled = false;
        }
    });

    document.getElementById('members-close').addEventListener('click', function () {
        selectedWorkspace = null;
        membersCard.classList.add('d-none');
        // Return keyboard focus to the list, so it is not lost on the hidden card.
        var firstMembersButton = workspacesList.querySelector('button');
        if (firstMembersButton) {
            firstMembersButton.focus();
        }
    });

    document.addEventListener('muninn:user-ready', async function (readyEvent) {
        if (!readyEvent.detail.is_system_admin) {
            notAvailableAlert.classList.remove('d-none');
            return;
        }
        await reloadOpenSide();
        try {
            await loadWorkspaces();
        } catch (loadError) {
            MuninnApi.showAlert(workspacesErrorAlert, loadError.message);
        }
        pageContent.classList.remove('d-none');
    });
})();
