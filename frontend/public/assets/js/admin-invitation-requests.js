/*
 * Invitation requests on the admin Invitations page (D049): list them, approve or decline.
 * All data is inserted with textContent, never innerHTML, so request notes cannot inject markup.
 */
(function () {
    'use strict';

    var requestsList = document.getElementById('requests-list');
    var requestsEmpty = document.getElementById('requests-empty');
    var errorAlert = document.getElementById('requests-error');

    var statusBadgeClasses = {
        pending: 'text-bg-warning',
        approved: 'text-bg-primary',
        declined: 'text-bg-secondary',
        cancelled: 'text-bg-secondary',
        completed: 'text-bg-success',
    };

    /** Describes where an approved request stands, from the state of its link. */
    function linkDescription(invitationRequest) {
        if (invitationRequest.status === 'completed') {
            return 'Joined as ' + (invitationRequest.accepted_username || 'a new user') + '.';
        }
        if (invitationRequest.status !== 'approved') {
            return '';
        }
        if (!invitationRequest.link) {
            return 'No link created yet.';
        }
        var linkStates = { pending: 'Link sent.', expired: 'Link expired.', revoked: 'Link replaced or revoked.' };
        return linkStates[invitationRequest.link.status] || '';
    }

    /** Creates a small action button for a request. */
    function buildActionButton(labelText, buttonClasses, ariaLabel, clickAction) {
        var actionButton = MuninnApi.createElement('button', 'btn btn-sm ' + buttonClasses, labelText);
        actionButton.type = 'button';
        actionButton.setAttribute('aria-label', ariaLabel);
        actionButton.addEventListener('click', function () {
            clickAction(actionButton);
        });
        return actionButton;
    }

    /** Builds one list item for a request, with Approve/Decline where they apply. */
    function buildRequestItem(invitationRequest) {
        var listItem = MuninnApi.createElement('li', 'list-group-item d-flex flex-wrap align-items-center gap-2');

        var textBlock = MuninnApi.createElement('div', 'flex-grow-1 text-break');
        textBlock.appendChild(MuninnApi.createElement('div', 'fw-semibold', invitationRequest.note));
        var detailText = 'Asked by ' + invitationRequest.requested_by_display_name + ' (' + invitationRequest.requested_by + '), '
            + MuninnApi.formatDateTime(invitationRequest.created_at) + '. ' + linkDescription(invitationRequest);
        textBlock.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', detailText));
        listItem.appendChild(textBlock);

        listItem.appendChild(MuninnApi.createElement('span', 'badge ' + (statusBadgeClasses[invitationRequest.status] || 'text-bg-light'), invitationRequest.status));

        if (invitationRequest.status === 'pending') {
            listItem.appendChild(buildActionButton('Approve', 'btn-primary', 'Approve the request: ' + invitationRequest.note, function (clickedButton) {
                decide(invitationRequest, 'approve', clickedButton);
            }));
        }
        if (invitationRequest.status === 'pending' || invitationRequest.status === 'approved') {
            var declineLabel = invitationRequest.status === 'approved' ? 'Withdraw' : 'Decline';
            listItem.appendChild(buildActionButton(declineLabel, 'btn-outline-danger', declineLabel + ' the request: ' + invitationRequest.note, function (clickedButton) {
                decide(invitationRequest, 'decline', clickedButton);
            }));
        }
        return listItem;
    }

    async function loadRequests() {
        try {
            var requestData = await MuninnApi.request('GET', '/api/v1/admin/invitation-requests');
            requestsList.replaceChildren();
            requestData.invitation_requests.forEach(function (invitationRequest) {
                requestsList.appendChild(buildRequestItem(invitationRequest));
            });
            requestsEmpty.classList.toggle('d-none', requestData.invitation_requests.length > 0);
        } catch (loadError) {
            MuninnApi.showAlert(errorAlert, loadError.message);
        }
    }

    async function decide(invitationRequest, decision, clickedButton) {
        if (decision === 'decline' && invitationRequest.status === 'approved'
            && !window.confirm('Withdraw the approval? A link the user already sent stops working.')) {
            return;
        }
        clickedButton.disabled = true;
        MuninnApi.showAlert(errorAlert, '');
        try {
            await MuninnApi.request('POST', '/api/v1/admin/invitation-requests/' + encodeURIComponent(invitationRequest.id) + '/' + decision);
        } catch (decisionError) {
            MuninnApi.showAlert(errorAlert, decisionError.message);
        }
        await loadRequests();
    }

    document.addEventListener('muninn:user-ready', function (readyEvent) {
        if (readyEvent.detail.is_system_admin) {
            loadRequests();
        }
    });
})();
