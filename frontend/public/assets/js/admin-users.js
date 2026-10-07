/*
 * Account administration: list accounts, disable and re-enable them.
 */
(function () {
    'use strict';

    document.body.setAttribute('data-shell-hold', '');

    var pageContent = document.getElementById('shell-content');
    var notAvailableAlert = document.getElementById('admin-not-available');
    var errorAlert = document.getElementById('users-error');
    var usersTableBody = document.getElementById('users-table-body');
    var currentUserId = null;

    /** Builds one table row for an account. */
    function buildUserRow(user) {
        var tableRow = document.createElement('tr');

        var nameCell = MuninnApi.createElement('td');
        nameCell.appendChild(MuninnApi.createElement('div', 'fw-semibold text-break', user.display_name));
        nameCell.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', user.username + (user.is_system_admin ? ' · administrator' : '')));
        tableRow.appendChild(nameCell);

        var statusCell = MuninnApi.createElement('td');
        var isActive = user.status === 'active';
        statusCell.appendChild(MuninnApi.createElement('span', 'badge ' + (isActive ? 'text-bg-success' : 'text-bg-secondary'), isActive ? 'active' : 'disabled'));
        tableRow.appendChild(statusCell);

        tableRow.appendChild(MuninnApi.createElement('td', 'd-none d-md-table-cell small', user.last_login_at ? MuninnApi.formatDateTime(user.last_login_at) : 'Never'));

        var actionCell = MuninnApi.createElement('td', 'text-end');
        if (user.id !== currentUserId) {
            var actionButton = MuninnApi.createElement('button', 'btn btn-sm ' + (isActive ? 'btn-outline-danger' : 'btn-outline-primary'), isActive ? 'Disable' : 'Enable');
            actionButton.type = 'button';
            actionButton.setAttribute('aria-label', (isActive ? 'Disable ' : 'Enable ') + user.username);
            actionButton.addEventListener('click', function () {
                setUserEnabled(user, !isActive, actionButton);
            });
            actionCell.appendChild(actionButton);
        }
        tableRow.appendChild(actionCell);

        return tableRow;
    }

    async function loadUsers() {
        var userData = await MuninnApi.request('GET', '/api/v1/admin/users');
        usersTableBody.replaceChildren();
        userData.users.forEach(function (user) {
            usersTableBody.appendChild(buildUserRow(user));
        });
    }

    async function setUserEnabled(user, shouldEnable, actionButton) {
        if (!shouldEnable && !window.confirm('Disable ' + user.username + '? They are signed out at once.')) {
            return;
        }
        actionButton.disabled = true;
        MuninnApi.showAlert(errorAlert, '');
        try {
            await MuninnApi.request('POST', '/api/v1/admin/users/' + encodeURIComponent(user.id) + (shouldEnable ? '/enable' : '/disable'));
            await loadUsers();
        } catch (changeError) {
            MuninnApi.showAlert(errorAlert, changeError.message);
            actionButton.disabled = false;
        }
    }

    document.addEventListener('muninn:user-ready', async function (readyEvent) {
        currentUserId = readyEvent.detail.id;
        if (!readyEvent.detail.is_system_admin) {
            notAvailableAlert.classList.remove('d-none');
            return;
        }
        try {
            await loadUsers();
        } catch (loadError) {
            MuninnApi.showAlert(errorAlert, loadError.message);
        }
        pageContent.classList.remove('d-none');
    });
})();
