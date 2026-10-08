/*
 * Account page: change one's own password (D040) and invitation requests (D049).
 * All data is inserted with textContent, never innerHTML.
 */
(function () {
    'use strict';

    var passwordForm = document.getElementById('password-form');
    var passwordSubmit = document.getElementById('password-submit');
    var passwordErrorAlert = document.getElementById('password-error');
    var passwordSuccessAlert = document.getElementById('password-success');
    var currentPasswordInput = document.getElementById('current-password');
    var newPasswordInput = document.getElementById('new-password');
    var newPasswordRepeatInput = document.getElementById('new-password-repeat');

    var requestsCard = document.getElementById('requests-card');
    var requestForm = document.getElementById('request-form');
    var requestSubmit = document.getElementById('request-submit');
    var requestNoteInput = document.getElementById('request-note');
    var requestErrorAlert = document.getElementById('request-error');
    var requestsList = document.getElementById('requests-list');
    var requestsEmpty = document.getElementById('requests-empty');
    var linkResultPanel = document.getElementById('request-link-result');
    var linkText = document.getElementById('request-link');
    var copyLinkButton = document.getElementById('copy-request-link');
    var copyLinkStatus = document.getElementById('copy-request-link-status');

    var passwordFieldIds = { current_password: 'current-password', new_password: 'new-password' };

    /** What each request status means for the person who asked. */
    var statusDescriptions = {
        pending: 'Waiting for the administrator.',
        approved: 'Approved. Create the link and send it.',
        declined: 'Declined by the administrator.',
        cancelled: 'Cancelled by you.',
        completed: 'Done.',
    };
    var statusBadgeClasses = {
        pending: 'text-bg-warning',
        approved: 'text-bg-primary',
        declined: 'text-bg-secondary',
        cancelled: 'text-bg-secondary',
        completed: 'text-bg-success',
    };

    passwordForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        MuninnApi.showAlert(passwordErrorAlert, '');
        passwordSuccessAlert.classList.add('d-none');
        MuninnApi.showFieldErrors(passwordFieldIds, {});

        // Catch typos before contacting the server.
        var passwordsMatch = newPasswordInput.value === newPasswordRepeatInput.value;
        newPasswordRepeatInput.classList.toggle('is-invalid', !passwordsMatch);
        newPasswordRepeatInput.setAttribute('aria-invalid', passwordsMatch ? 'false' : 'true');
        document.getElementById('new-password-repeat-feedback').textContent = passwordsMatch ? '' : 'The passwords do not match.';
        if (!passwordsMatch) {
            newPasswordRepeatInput.focus();
            return;
        }

        passwordSubmit.disabled = true;
        try {
            await MuninnApi.request('POST', '/api/v1/auth/password', {
                current_password: currentPasswordInput.value,
                new_password: newPasswordInput.value,
            });
            passwordForm.reset();
            passwordSuccessAlert.classList.remove('d-none');
            passwordSuccessAlert.focus();
        } catch (changeError) {
            MuninnApi.showFieldErrors(passwordFieldIds, changeError.fields);
            MuninnApi.showAlert(passwordErrorAlert, changeError.code === 'validation_failed' ? 'Please correct the highlighted fields.' : changeError.message);
        } finally {
            passwordSubmit.disabled = false;
        }
    });

    /** Builds one list item for a request, with the actions its state allows. */
    function buildRequestItem(invitationRequest) {
        var listItem = MuninnApi.createElement('li', 'list-group-item px-0 d-flex flex-wrap align-items-center gap-2');

        var textBlock = MuninnApi.createElement('div', 'flex-grow-1 text-break');
        textBlock.appendChild(MuninnApi.createElement('div', 'fw-semibold', invitationRequest.note));
        var detailText = statusDescriptions[invitationRequest.status] || '';
        if (invitationRequest.status === 'completed' && invitationRequest.accepted_username) {
            detailText = 'Done: joined as ' + invitationRequest.accepted_username + '.';
        } else if (invitationRequest.status === 'approved' && invitationRequest.link) {
            var linkDescriptions = {
                pending: 'Link sent, valid until ' + MuninnApi.formatDateTime(invitationRequest.link.expires_at) + '.',
                expired: 'The link expired. Create a new one.',
                revoked: 'The link was revoked. Create a new one.',
            };
            detailText = linkDescriptions[invitationRequest.link.status] || detailText;
        }
        textBlock.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', detailText + ' Asked ' + MuninnApi.formatDateTime(invitationRequest.created_at) + '.'));
        listItem.appendChild(textBlock);

        listItem.appendChild(MuninnApi.createElement('span', 'badge ' + (statusBadgeClasses[invitationRequest.status] || 'text-bg-light'), invitationRequest.status));

        if (invitationRequest.status === 'approved') {
            var hasLiveLink = invitationRequest.link && invitationRequest.link.status === 'pending';
            var linkButton = MuninnApi.createElement('button', 'btn btn-primary btn-sm', hasLiveLink ? 'New link' : 'Create link');
            linkButton.type = 'button';
            linkButton.setAttribute('aria-label', (hasLiveLink ? 'Replace the invitation link for ' : 'Create the invitation link for ') + invitationRequest.note);
            linkButton.addEventListener('click', function () {
                createLink(invitationRequest, hasLiveLink, linkButton);
            });
            listItem.appendChild(linkButton);
        }
        if (invitationRequest.status === 'pending' || invitationRequest.status === 'approved') {
            var cancelButton = MuninnApi.createElement('button', 'btn btn-outline-danger btn-sm', 'Cancel');
            cancelButton.type = 'button';
            cancelButton.setAttribute('aria-label', 'Cancel the request for ' + invitationRequest.note);
            cancelButton.addEventListener('click', function () {
                cancelRequest(invitationRequest, cancelButton);
            });
            listItem.appendChild(cancelButton);
        }
        return listItem;
    }

    async function loadRequests() {
        var requestData = await MuninnApi.request('GET', '/api/v1/invitation-requests');
        requestsList.replaceChildren();
        requestData.invitation_requests.forEach(function (invitationRequest) {
            requestsList.appendChild(buildRequestItem(invitationRequest));
        });
        requestsEmpty.classList.toggle('d-none', requestData.invitation_requests.length > 0);
    }

    async function createLink(invitationRequest, replacesLiveLink, linkButton) {
        if (replacesLiveLink && !window.confirm('Create a new link? The link you sent before stops working.')) {
            return;
        }
        linkButton.disabled = true;
        MuninnApi.showAlert(requestErrorAlert, '');
        try {
            var linkData = await MuninnApi.request('POST', '/api/v1/invitation-requests/' + encodeURIComponent(invitationRequest.id) + '/link');
            linkText.textContent = linkData.invitation_url;
            copyLinkStatus.textContent = '';
            linkResultPanel.classList.remove('d-none');
            linkResultPanel.focus();
        } catch (linkError) {
            MuninnApi.showAlert(requestErrorAlert, linkError.message);
        }
        await refreshRequests();
    }

    async function cancelRequest(invitationRequest, cancelButton) {
        if (!window.confirm('Cancel this request? A link you already sent stops working.')) {
            return;
        }
        cancelButton.disabled = true;
        MuninnApi.showAlert(requestErrorAlert, '');
        try {
            await MuninnApi.request('DELETE', '/api/v1/invitation-requests/' + encodeURIComponent(invitationRequest.id));
        } catch (cancelError) {
            MuninnApi.showAlert(requestErrorAlert, cancelError.message);
        }
        await refreshRequests();
    }

    /** Reloads the list, showing any failure in the requests alert. */
    async function refreshRequests() {
        try {
            await loadRequests();
        } catch (loadError) {
            MuninnApi.showAlert(requestErrorAlert, loadError.message);
        }
    }

    requestForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        MuninnApi.showAlert(requestErrorAlert, '');
        MuninnApi.showFieldErrors({ note: 'request-note' }, {});
        requestSubmit.disabled = true;
        try {
            await MuninnApi.request('POST', '/api/v1/invitation-requests', { note: requestNoteInput.value.trim() });
            requestNoteInput.value = '';
            await refreshRequests();
        } catch (createError) {
            MuninnApi.showFieldErrors({ note: 'request-note' }, createError.fields);
            MuninnApi.showAlert(requestErrorAlert, createError.code === 'validation_failed' ? 'Please describe who should be invited.' : createError.message);
        } finally {
            requestSubmit.disabled = false;
        }
    });

    copyLinkButton.addEventListener('click', function () {
        MuninnApi.copyLinkText(linkText, copyLinkStatus);
    });

    document.addEventListener('muninn:user-ready', function (readyEvent) {
        var currentUser = readyEvent.detail;
        document.getElementById('account-username').textContent = currentUser.username;
        document.getElementById('password-form-username').value = currentUser.username;
        // Administrator accounts create invitations directly under Admin, so they get no requests.
        if (!currentUser.is_system_admin) {
            requestsCard.classList.remove('d-none');
            refreshRequests();
        }
    });
})();
