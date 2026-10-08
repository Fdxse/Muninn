/*
 * Shared workspace administration (D050): list shared workspaces and manage their members.
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

    var allRoles = ['owner', 'admin', 'editor', 'reader'];
    /** The workspace whose members are open, or null. */
    var openWorkspace = null;

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

        var manageButton = MuninnApi.createElement('button', 'btn btn-outline-primary btn-sm', 'Members');
        manageButton.type = 'button';
        manageButton.setAttribute('aria-label', 'Manage the members of ' + workspace.name);
        manageButton.addEventListener('click', function () {
            openMembers(workspace);
        });
        listItem.appendChild(manageButton);

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
        return '/api/v1/admin/workspaces/' + encodeURIComponent(openWorkspace.id) + '/members';
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
        allRoles.forEach(function (roleValue) {
            var roleOption = MuninnApi.createElement('option', null, MuninnApi.roleLabel(roleValue));
            roleOption.value = roleValue;
            roleOption.selected = roleValue === member.role;
            roleSelect.appendChild(roleOption);
        });
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

    /** Reloads the members and the overview (Owner counts change with roles). */
    async function reloadAll() {
        try {
            await loadMembers();
            await loadWorkspaces();
        } catch (reloadError) {
            MuninnApi.showAlert(membersErrorAlert, reloadError.message);
        }
    }

    async function openMembers(workspace) {
        openWorkspace = workspace;
        MuninnApi.showAlert(membersErrorAlert, '');
        MuninnApi.showFieldErrors({ username: 'member-username', role: 'member-role' }, {});
        membersList.replaceChildren();
        membersHeading.textContent = 'Members of ' + workspace.name;
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
        if (!window.confirm('Remove ' + member.display_name + ' from ' + openWorkspace.name + '?')) {
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
        openWorkspace = null;
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
        try {
            await loadWorkspaces();
        } catch (loadError) {
            MuninnApi.showAlert(workspacesErrorAlert, loadError.message);
        }
        pageContent.classList.remove('d-none');
    });
})();
