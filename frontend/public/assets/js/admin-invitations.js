/*
 * Invitation administration: list, create (showing the one-time link) and revoke.
 * All data is inserted with textContent, never innerHTML, so notes cannot inject markup.
 */
(function () {
    'use strict';

    // Keep the page hidden until we know the user is an admin and the list has loaded.
    document.body.setAttribute('data-shell-hold', '');

    var pageContent = document.getElementById('shell-content');
    var notAvailableAlert = document.getElementById('admin-not-available');
    var createForm = document.getElementById('create-invitation-form');
    var createButton = document.getElementById('create-invitation-submit');
    var createErrorAlert = document.getElementById('create-error');
    var noteInput = document.getElementById('invitation-note');
    var expirySelect = document.getElementById('invitation-expiry');
    var resultPanel = document.getElementById('new-invitation-result');
    var resultLink = document.getElementById('new-invitation-link');
    var copyButton = document.getElementById('copy-invitation-link');
    var copyStatus = document.getElementById('copy-invitation-status');
    var listErrorAlert = document.getElementById('list-error');
    var emptyMessage = document.getElementById('invitations-empty');
    var invitationsTable = document.getElementById('invitations-table');
    var invitationsTableBody = document.getElementById('invitations-table-body');

    var statusBadgeClasses = {
        pending: 'text-bg-warning',
        accepted: 'text-bg-success',
        expired: 'text-bg-secondary',
        revoked: 'text-bg-dark',
    };

    /** Creates an element with optional class names and text. */
    function createElement(tagName, classNames, textContent) {
        var newElement = document.createElement(tagName);
        if (classNames) {
            newElement.className = classNames;
        }
        if (textContent !== undefined && textContent !== null) {
            newElement.textContent = textContent;
        }
        return newElement;
    }

    /** Builds one table row for an invitation. */
    function buildInvitationRow(invitation) {
        var tableRow = document.createElement('tr');

        var noteCell = createElement('td', null, invitation.note || '—');
        if (invitation.accepted_username) {
            noteCell.appendChild(createElement('div', 'small text-muted-brand', 'Used by ' + invitation.accepted_username));
        }
        // On phones the Expires column is hidden, so the date is shown under the note instead.
        noteCell.appendChild(createElement('div', 'small text-muted-brand d-sm-none', 'Expires ' + MuninnApi.formatDateTime(invitation.expires_at)));
        tableRow.appendChild(noteCell);

        var statusCell = document.createElement('td');
        statusCell.appendChild(createElement('span', 'badge ' + (statusBadgeClasses[invitation.status] || 'text-bg-light'), invitation.status));
        tableRow.appendChild(statusCell);

        tableRow.appendChild(createElement('td', 'd-none d-md-table-cell small', MuninnApi.formatDateTime(invitation.created_at)));
        tableRow.appendChild(createElement('td', 'd-none d-sm-table-cell small', MuninnApi.formatDateTime(invitation.expires_at)));

        var actionCell = createElement('td', 'text-end');
        if (invitation.status === 'pending') {
            var revokeButton = createElement('button', 'btn btn-outline-danger btn-sm', 'Revoke');
            revokeButton.type = 'button';
            revokeButton.setAttribute('aria-label', 'Revoke invitation' + (invitation.note ? ' for ' + invitation.note : ''));
            revokeButton.addEventListener('click', function () {
                revokeInvitation(invitation, revokeButton);
            });
            actionCell.appendChild(revokeButton);
        }
        tableRow.appendChild(actionCell);

        return tableRow;
    }

    async function loadInvitations() {
        listErrorAlert.classList.add('d-none');
        try {
            var listData = await MuninnApi.request('GET', '/api/v1/admin/invitations');
            invitationsTableBody.replaceChildren();
            listData.invitations.forEach(function (invitation) {
                invitationsTableBody.appendChild(buildInvitationRow(invitation));
            });
            var hasInvitations = listData.invitations.length > 0;
            invitationsTable.classList.toggle('d-none', !hasInvitations);
            emptyMessage.classList.toggle('d-none', hasInvitations);
        } catch (listError) {
            MuninnApi.showAlert(listErrorAlert, listError.message);
        }
    }

    async function revokeInvitation(invitation, revokeButton) {
        var confirmationText = 'Revoke this invitation? The link will stop working immediately.';
        if (!window.confirm(confirmationText)) {
            return;
        }
        revokeButton.disabled = true;
        try {
            await MuninnApi.request('DELETE', '/api/v1/admin/invitations/' + encodeURIComponent(invitation.id));
        } catch (revokeError) {
            MuninnApi.showAlert(listErrorAlert, revokeError.message);
        }
        await loadInvitations();
    }

    createForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        MuninnApi.showAlert(createErrorAlert, '');
        MuninnApi.showFieldErrors({ note: 'invitation-note', expires_in_hours: 'invitation-expiry' }, {});
        resultPanel.classList.add('d-none');

        createButton.disabled = true;
        try {
            var createdData = await MuninnApi.request('POST', '/api/v1/admin/invitations', {
                note: noteInput.value.trim(),
                expires_in_hours: parseInt(expirySelect.value, 10),
            });
            noteInput.value = '';
            resultLink.textContent = createdData.invitation_url;
            copyStatus.textContent = '';
            resultPanel.classList.remove('d-none');
            resultPanel.focus();
            await loadInvitations();
        } catch (createError) {
            MuninnApi.showFieldErrors({ note: 'invitation-note', expires_in_hours: 'invitation-expiry' }, createError.fields);
            MuninnApi.showAlert(createErrorAlert, createError.message);
        } finally {
            createButton.disabled = false;
        }
    });

    copyButton.addEventListener('click', function () {
        MuninnApi.copyLinkText(resultLink, copyStatus);
    });

    document.addEventListener('muninn:user-ready', async function (readyEvent) {
        if (!readyEvent.detail.is_system_admin) {
            notAvailableAlert.classList.remove('d-none');
            return;
        }
        await loadInvitations();
        pageContent.classList.remove('d-none');
    });
})();
