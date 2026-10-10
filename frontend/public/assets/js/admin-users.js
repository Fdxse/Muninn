/*
 * Account administration: list accounts, disable and re-enable them, create one-time
 * password reset links (D040), choose how much chat each account may use (D062), and pick the
 * everyday account that is told when admin work is waiting (D066).
 */
(function () {
    'use strict';

    document.body.setAttribute('data-shell-hold', '');

    var pageContent = document.getElementById('shell-content');
    var notAvailableAlert = document.getElementById('admin-not-available');
    var errorAlert = document.getElementById('users-error');
    var usersTableBody = document.getElementById('users-table-body');
    var currentUserId = null;
    var resetResultPanel = document.getElementById('reset-link-result');
    var resetLinkText = document.getElementById('reset-link');
    var copyResetStatus = document.getElementById('copy-reset-link-status');
    var attentionRecipientSelect = document.getElementById('attention-recipient-select');
    var attentionRecipientStatus = document.getElementById('attention-recipient-status');
    /** The recipient's user ID as saved on the server ('' for nobody). */
    var savedAttentionRecipientId = '';

    /** The chat levels (D062), in the order the drop-down shows them. */
    var chatAccessLabels = [
        ['off', 'No chat'],
        ['own_workspaces', 'Own workspaces'],
        ['member_workspaces', 'All their workspaces'],
        ['global', 'All + shout out'],
    ];

    /**
     * A drop-down that changes how much chat an account may use. It saves on change, and goes
     * back to the saved level when saving fails.
     */
    function buildChatAccessSelect(user) {
        var chatSelect = MuninnApi.createElement('select', 'form-select form-select-sm w-auto');
        chatSelect.setAttribute('aria-label', 'Chat for ' + user.username);
        chatAccessLabels.forEach(function (levelAndLabel) {
            var levelOption = MuninnApi.createElement('option', null, 'Chat: ' + levelAndLabel[1]);
            levelOption.value = levelAndLabel[0];
            chatSelect.appendChild(levelOption);
        });
        chatSelect.value = user.chat_access;
        var savedLevel = user.chat_access;

        chatSelect.addEventListener('change', async function () {
            chatSelect.disabled = true;
            MuninnApi.showAlert(errorAlert, '');
            try {
                var changeResult = await MuninnApi.request('PATCH', '/api/v1/admin/users/' + encodeURIComponent(user.id) + '/chat-access', {
                    chat_access: chatSelect.value,
                });
                savedLevel = changeResult.chat_access;
            } catch (changeError) {
                chatSelect.value = savedLevel;
                MuninnApi.showAlert(errorAlert, (changeError.fields && changeError.fields.chat_access) || changeError.message);
            } finally {
                chatSelect.disabled = false;
            }
        });

        return chatSelect;
    }

    /** Builds one table row for an account. */
    function buildUserRow(user) {
        var tableRow = document.createElement('tr');

        var nameCell = MuninnApi.createElement('td');
        nameCell.appendChild(MuninnApi.createElement('div', 'fw-semibold text-break', user.display_name));
        nameCell.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', user.username + (user.is_system_admin ? ' · administrator' : '')));
        var isActive = user.status === 'active';
        // On phones the Status column is hidden, so the status is shown under the name instead.
        nameCell.appendChild(MuninnApi.createElement('div', 'small text-muted-brand d-sm-none', isActive ? 'Active' : 'Disabled'));
        tableRow.appendChild(nameCell);

        var statusCell = MuninnApi.createElement('td', 'd-none d-sm-table-cell');
        statusCell.appendChild(MuninnApi.createElement('span', 'badge ' + (isActive ? 'text-bg-success' : 'text-bg-secondary'), isActive ? 'active' : 'disabled'));
        tableRow.appendChild(statusCell);

        tableRow.appendChild(MuninnApi.createElement('td', 'd-none d-md-table-cell small', user.last_login_at ? MuninnApi.formatDateTime(user.last_login_at) : 'Never'));

        var actionCell = MuninnApi.createElement('td', 'text-end');
        if (user.id !== currentUserId) {
            var buttonGroup = MuninnApi.createElement('div', 'd-flex flex-wrap justify-content-end gap-2');
            // Administrator accounts never chat (D062), so they get no chat choice.
            if (!user.is_system_admin) {
                buttonGroup.appendChild(buildChatAccessSelect(user));
            }
            if (isActive) {
                var resetButton = MuninnApi.createElement('button', 'btn btn-sm btn-outline-secondary', 'Reset password');
                resetButton.type = 'button';
                resetButton.setAttribute('aria-label', 'Create a password reset link for ' + user.username);
                resetButton.addEventListener('click', function () {
                    createResetLink(user, resetButton);
                });
                buttonGroup.appendChild(resetButton);
            }
            var actionButton = MuninnApi.createElement('button', 'btn btn-sm ' + (isActive ? 'btn-outline-danger' : 'btn-outline-primary'), isActive ? 'Disable' : 'Enable');
            actionButton.type = 'button';
            actionButton.setAttribute('aria-label', (isActive ? 'Disable ' : 'Enable ') + user.username);
            actionButton.addEventListener('click', function () {
                setUserEnabled(user, !isActive, actionButton);
            });
            buttonGroup.appendChild(actionButton);
            actionCell.appendChild(buttonGroup);
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
        await loadAttentionRecipient(userData.users);
    }

    /**
     * Fills the "Tell this account when admin work is waiting" drop-down (D066): Nobody plus every
     * active everyday account, with the saved recipient selected. A recipient disabled later is
     * still listed, marked as disabled, so the page shows the truth.
     */
    async function loadAttentionRecipient(allUsers) {
        var recipientData = await MuninnApi.request('GET', '/api/v1/admin/attention-recipient');
        var savedRecipient = recipientData.recipient;
        savedAttentionRecipientId = savedRecipient ? savedRecipient.id : '';

        var nobodyOption = MuninnApi.createElement('option', null, 'Nobody');
        nobodyOption.value = '';
        attentionRecipientSelect.replaceChildren(nobodyOption);
        allUsers.forEach(function (user) {
            var isSavedRecipient = user.id === savedAttentionRecipientId;
            if (user.is_system_admin || (user.status !== 'active' && !isSavedRecipient)) {
                return;
            }
            var userLabel = user.display_name + ' (' + user.username + ')' + (user.status === 'active' ? '' : ' · disabled');
            var userOption = MuninnApi.createElement('option', null, userLabel);
            userOption.value = user.id;
            attentionRecipientSelect.appendChild(userOption);
        });
        attentionRecipientSelect.value = savedAttentionRecipientId;
    }

    // Saves on change, and goes back to the saved choice when saving fails.
    attentionRecipientSelect.addEventListener('change', async function () {
        attentionRecipientSelect.disabled = true;
        attentionRecipientStatus.textContent = 'Saving…';
        MuninnApi.showAlert(errorAlert, '');
        try {
            var changeResult = await MuninnApi.request('PATCH', '/api/v1/admin/attention-recipient', {
                user_id: attentionRecipientSelect.value === '' ? null : attentionRecipientSelect.value,
            });
            savedAttentionRecipientId = changeResult.recipient ? changeResult.recipient.id : '';
            attentionRecipientStatus.textContent = 'Saved.';
        } catch (changeError) {
            attentionRecipientSelect.value = savedAttentionRecipientId;
            attentionRecipientStatus.textContent = '';
            MuninnApi.showAlert(errorAlert, (changeError.fields && changeError.fields.user_id) || changeError.message);
        } finally {
            attentionRecipientSelect.disabled = false;
        }
    });

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

    async function createResetLink(user, resetButton) {
        if (!window.confirm('Create a password reset link for ' + user.username + '?')) {
            return;
        }
        resetButton.disabled = true;
        MuninnApi.showAlert(errorAlert, '');
        try {
            var resetData = await MuninnApi.request('POST', '/api/v1/admin/users/' + encodeURIComponent(user.id) + '/password-reset', {});
            document.getElementById('reset-link-username').textContent = resetData.username;
            document.getElementById('reset-link-expiry').textContent = MuninnApi.formatDateTime(resetData.expires_at);
            resetLinkText.textContent = resetData.reset_url;
            copyResetStatus.textContent = '';
            resetResultPanel.classList.remove('d-none');
            resetResultPanel.focus();
        } catch (resetError) {
            MuninnApi.showAlert(errorAlert, resetError.message);
        } finally {
            resetButton.disabled = false;
        }
    }

    document.getElementById('copy-reset-link').addEventListener('click', function () {
        MuninnApi.copyLinkText(resetLinkText, copyResetStatus);
    });

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
