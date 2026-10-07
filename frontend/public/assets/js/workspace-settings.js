/*
 * Workspace settings page: rename, member management, leave and delete.
 * The role rules mirrored here (canAssign) only decide which controls to show; the API
 * enforces the real rules (decision D029) on every request.
 */
(function () {
    'use strict';

    document.body.setAttribute('data-shell-hold', '');

    var workspaceId = MuninnApi.queryParameter('id') || '';
    var workspacePath = '/api/v1/workspaces/' + encodeURIComponent(workspaceId);

    var pageContent = document.getElementById('shell-content');
    var notAvailableAlert = document.getElementById('workspace-not-available');
    var heading = document.getElementById('workspace-heading');
    var summary = document.getElementById('workspace-summary');
    var openNotesLink = document.getElementById('open-notes-link');
    var errorAlert = document.getElementById('workspace-error');
    var renameCard = document.getElementById('rename-card');
    var renameForm = document.getElementById('rename-form');
    var renameInput = document.getElementById('rename-input');
    var membersCard = document.getElementById('members-card');
    var membersList = document.getElementById('members-list');
    var addMemberForm = document.getElementById('add-member-form');
    var memberUsernameInput = document.getElementById('member-username');
    var memberRoleSelect = document.getElementById('member-role');
    var deleteCard = document.getElementById('delete-card');
    var deleteButton = document.getElementById('delete-workspace-button');

    var allRoles = ['owner', 'admin', 'editor', 'reader'];
    var currentUserId = null;
    var workspace = null;

    /** Mirrors WorkspaceRole::canAssign(): Owners hand out any role, Admins Editor and Reader. */
    function canAssign(actorRole, targetRole) {
        if (actorRole === 'owner') {
            return true;
        }
        return actorRole === 'admin' && (targetRole === 'editor' || targetRole === 'reader');
    }

    /** Builds a <select> of the roles the user may hand out, with $selectedRole chosen. */
    function buildRoleSelect(selectedRole) {
        var roleSelect = MuninnApi.createElement('select', 'form-select form-select-sm w-auto');
        allRoles.filter(function (roleValue) {
            return canAssign(workspace.your_role, roleValue);
        }).forEach(function (roleValue) {
            var roleOption = MuninnApi.createElement('option', null, MuninnApi.roleLabel(roleValue));
            roleOption.value = roleValue;
            roleOption.selected = roleValue === selectedRole;
            roleSelect.appendChild(roleOption);
        });
        return roleSelect;
    }

    /** Builds one member row with the controls the user's role allows. */
    function buildMemberRow(member) {
        var memberRow = MuninnApi.createElement('li', 'list-group-item px-0 d-flex flex-wrap align-items-center gap-2');
        var isSelf = member.user_id === currentUserId;
        var mayManage = workspace.permissions.manage_members && canAssign(workspace.your_role, member.role);

        var nameBlock = MuninnApi.createElement('div', 'flex-grow-1 text-break');
        nameBlock.appendChild(MuninnApi.createElement('div', 'fw-semibold', member.display_name + (isSelf ? ' (you)' : '')));
        nameBlock.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', member.username + (member.is_disabled ? ' · disabled account' : '')));
        memberRow.appendChild(nameBlock);

        if (mayManage) {
            var roleSelect = buildRoleSelect(member.role);
            roleSelect.setAttribute('aria-label', 'Role of ' + member.display_name);
            roleSelect.addEventListener('change', function () {
                changeRole(member, roleSelect.value);
            });
            memberRow.appendChild(roleSelect);
        } else {
            memberRow.appendChild(MuninnApi.createElement('span', 'badge text-bg-light', MuninnApi.roleLabel(member.role)));
        }

        if (workspace.kind === 'shared' && (mayManage || isSelf)) {
            var removeButton = MuninnApi.createElement('button', 'btn btn-outline-danger btn-sm', isSelf ? 'Leave' : 'Remove');
            removeButton.type = 'button';
            removeButton.setAttribute('aria-label', (isSelf ? 'Leave workspace' : 'Remove ' + member.display_name));
            removeButton.addEventListener('click', function () {
                removeMember(member, isSelf);
            });
            memberRow.appendChild(removeButton);
        }
        return memberRow;
    }

    async function loadWorkspace() {
        var workspaceData = await MuninnApi.request('GET', workspacePath);
        workspace = workspaceData.workspace;
        var displayName = workspace.kind === 'personal' ? 'Personal' : workspace.name;

        heading.textContent = displayName;
        summary.textContent = (workspace.kind === 'personal' ? 'Your personal workspace. Only you can see it.' : 'Shared workspace.')
            + ' Your role: ' + MuninnApi.roleLabel(workspace.your_role) + '.';
        openNotesLink.href = 'index.php?workspace=' + encodeURIComponent(workspace.id);
        renameInput.value = workspace.name;

        renameCard.classList.toggle('d-none', !(workspace.permissions.manage_workspace && workspace.kind === 'shared'));
        deleteCard.classList.toggle('d-none', !(workspace.permissions.manage_workspace && workspace.kind === 'shared'));
        membersCard.classList.toggle('d-none', workspace.kind === 'personal');
        addMemberForm.classList.toggle('d-none', !workspace.permissions.manage_members);

        memberRoleSelect.replaceChildren();
        buildRoleSelect('editor').querySelectorAll('option').forEach(function (roleOption) {
            memberRoleSelect.appendChild(roleOption);
        });
    }

    async function loadMembers() {
        if (workspace.kind === 'personal') {
            return;
        }
        var memberData = await MuninnApi.request('GET', workspacePath + '/members');
        membersList.replaceChildren();
        memberData.members.forEach(function (member) {
            membersList.appendChild(buildMemberRow(member));
        });
    }

    /** Reloads everything; used after any change, since a change can alter the user's own rights. */
    async function reloadAll() {
        try {
            await loadWorkspace();
            await loadMembers();
        } catch (reloadError) {
            if (reloadError.status === 404) {
                // The user is no longer a member (e.g. they just left).
                window.location.assign('workspaces.php');
                return;
            }
            MuninnApi.showAlert(errorAlert, reloadError.message);
        }
    }

    async function changeRole(member, newRole) {
        MuninnApi.showAlert(errorAlert, '');
        try {
            await MuninnApi.request('PATCH', workspacePath + '/members/' + encodeURIComponent(member.user_id), { role: newRole });
        } catch (changeError) {
            MuninnApi.showAlert(errorAlert, changeError.message);
        }
        await reloadAll();
    }

    async function removeMember(member, isSelf) {
        var question = isSelf
            ? 'Leave this workspace? You lose access to its notes.'
            : 'Remove ' + member.display_name + ' from this workspace?';
        if (!window.confirm(question)) {
            return;
        }
        MuninnApi.showAlert(errorAlert, '');
        try {
            await MuninnApi.request('DELETE', workspacePath + '/members/' + encodeURIComponent(member.user_id));
            if (isSelf) {
                window.location.assign('workspaces.php');
                return;
            }
        } catch (removeError) {
            MuninnApi.showAlert(errorAlert, removeError.message);
        }
        await reloadAll();
    }

    renameForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        MuninnApi.showAlert(errorAlert, '');
        MuninnApi.showFieldErrors({ name: 'rename-input' }, {});
        try {
            await MuninnApi.request('PATCH', workspacePath, { name: renameInput.value });
            await reloadAll();
        } catch (renameError) {
            MuninnApi.showFieldErrors({ name: 'rename-input' }, renameError.fields);
            MuninnApi.showAlert(errorAlert, renameError.message);
        }
    });

    addMemberForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        var memberFieldIds = { username: 'member-username', role: 'member-role' };
        MuninnApi.showAlert(errorAlert, '');
        MuninnApi.showFieldErrors(memberFieldIds, {});
        try {
            await MuninnApi.request('POST', workspacePath + '/members', {
                username: memberUsernameInput.value.trim(),
                role: memberRoleSelect.value,
            });
            memberUsernameInput.value = '';
            await reloadAll();
        } catch (addError) {
            MuninnApi.showFieldErrors(memberFieldIds, addError.fields);
            MuninnApi.showAlert(errorAlert, addError.message);
        }
    });

    deleteButton.addEventListener('click', async function () {
        if (!window.confirm('Delete this workspace? This cannot be undone.')) {
            return;
        }
        MuninnApi.showAlert(errorAlert, '');
        try {
            await MuninnApi.request('DELETE', workspacePath);
            window.location.assign('workspaces.php');
        } catch (deleteError) {
            MuninnApi.showAlert(errorAlert, deleteError.message);
        }
    });

    document.addEventListener('muninn:user-ready', async function (readyEvent) {
        currentUserId = readyEvent.detail.id;
        if (readyEvent.detail.is_system_admin) {
            notAvailableAlert.classList.remove('d-none');
            return;
        }
        try {
            await loadWorkspace();
            await loadMembers();
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
