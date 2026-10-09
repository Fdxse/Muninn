/*
 * Shared behaviour of signed-in pages: confirm the session, show the user in the navigation,
 * wire the sign-out button, and reveal the page. Signed-out visitors go to the sign-in page.
 *
 * Pages can listen for the "muninn:user-ready" event (detail = the user) to load their own data.
 */
(function () {
    'use strict';

    var loadingIndicator = document.getElementById('shell-loading');
    var pageContent = document.getElementById('shell-content');

    // Mark the navigation link of the current page, for screen readers and as a visual cue.
    document.querySelectorAll('.muninn-navbar .nav-link').forEach(function (navigationLink) {
        if (navigationLink.pathname === window.location.pathname) {
            navigationLink.setAttribute('aria-current', 'page');
            navigationLink.classList.add('active');
        }
    });

    document.getElementById('nav-logout-button').addEventListener('click', function () {
        MuninnApi.signOut();
    });

    /**
     * Shows how many invitation requests wait for the administrator's decision on the
     * "Invitations" link, or hides the badge when there are none. A failure only hides the
     * badge: it is a hint, and the Invitations page itself always lists every request.
     */
    async function refreshInvitationRequestsBadge() {
        var invitationsBadge = document.getElementById('nav-admin-invitations-badge');
        try {
            var countData = await MuninnApi.request('GET', '/api/v1/admin/invitation-requests/pending-count');
            var pendingCount = countData.pending_count;
            // Screen readers hear "Invitations 2 waiting for a decision" instead of a bare number.
            invitationsBadge.replaceChildren(
                document.createTextNode(String(pendingCount)),
                MuninnApi.createElement('span', 'visually-hidden', ' waiting for a decision')
            );
            invitationsBadge.classList.toggle('d-none', pendingCount === 0);
        } catch (countError) {
            invitationsBadge.classList.add('d-none');
        }
    }

    // The Invitations page announces approvals and declines, so the badge follows along.
    document.addEventListener('muninn:invitation-requests-changed', refreshInvitationRequestsBadge);

    /*
     * "Contact admin" (D058): everyday users write a short message that the API pushes to the
     * administrator's phone through ntfy. The dialog's markup comes from includes/page.php.
     */
    var contactAdminModal = document.getElementById('contact-admin-modal');
    var contactAdminForm = document.getElementById('contact-admin-form');
    var contactAdminMessageInput = document.getElementById('contact-admin-message');
    var contactAdminContactInput = document.getElementById('contact-admin-contact');
    var contactAdminSendButton = document.getElementById('contact-admin-send-button');
    var contactAdminErrorAlert = document.getElementById('contact-admin-error');
    var contactAdminSuccessAlert = document.getElementById('contact-admin-success');
    var contactAdminFieldIds = { message: 'contact-admin-message', contact: 'contact-admin-contact' };

    /** Explains, before anything is typed, when messages cannot be sent right now. */
    async function prepareContactAdminDialog() {
        MuninnApi.showAlert(contactAdminErrorAlert, '');
        contactAdminSuccessAlert.classList.add('d-none');
        MuninnApi.showFieldErrors(contactAdminFieldIds, {});
        contactAdminSendButton.disabled = false;
        try {
            var messageStatus = await MuninnApi.request('GET', '/api/v1/admin-messages');
            if (!messageStatus.enabled) {
                MuninnApi.showAlert(contactAdminErrorAlert, 'Messages to the administrator are not set up on this server yet.');
                contactAdminSendButton.disabled = true;
            } else if (messageStatus.remaining_this_hour === 0) {
                MuninnApi.showAlert(contactAdminErrorAlert, 'You have sent ' + messageStatus.max_per_hour + ' messages this hour. Please wait before sending another.');
                contactAdminSendButton.disabled = true;
            }
        } catch (statusError) {
            // Sending will report the real problem; nothing to block here.
        }
    }

    if (contactAdminModal) {
        contactAdminModal.addEventListener('show.bs.modal', prepareContactAdminDialog);
        contactAdminModal.addEventListener('shown.bs.modal', function () {
            if (!contactAdminSendButton.disabled) {
                contactAdminMessageInput.focus();
            }
        });

        contactAdminForm.addEventListener('submit', async function (submitEvent) {
            submitEvent.preventDefault();
            MuninnApi.showAlert(contactAdminErrorAlert, '');
            contactAdminSuccessAlert.classList.add('d-none');
            MuninnApi.showFieldErrors(contactAdminFieldIds, {});

            contactAdminSendButton.disabled = true;
            var keepButtonDisabled = false;
            try {
                var sendResult = await MuninnApi.request('POST', '/api/v1/admin-messages', {
                    message: contactAdminMessageInput.value,
                    contact: contactAdminContactInput.value,
                });
                // Keep the contact details: the same person usually wants the same answer route.
                contactAdminMessageInput.value = '';
                contactAdminSuccessAlert.textContent = 'Sent. The administrator has been notified.';
                contactAdminSuccessAlert.classList.remove('d-none');
                contactAdminSuccessAlert.focus();
                keepButtonDisabled = sendResult.remaining_this_hour === 0;
            } catch (sendError) {
                MuninnApi.showFieldErrors(contactAdminFieldIds, sendError.fields || {});
                MuninnApi.showAlert(
                    contactAdminErrorAlert,
                    sendError.code === 'validation_failed' ? 'Please correct the highlighted fields.'
                        : sendError.status === 429 ? 'You have sent the most messages allowed this hour. Please wait before sending another.'
                        : sendError.message
                );
                keepButtonDisabled = sendError.status === 429 || sendError.code === 'messages_unavailable';
            } finally {
                contactAdminSendButton.disabled = keepButtonDisabled;
            }
        });
    }

    MuninnApi.loadCurrentUser()
        .then(function (currentUserData) {
            var currentUser = currentUserData.user;
            document.getElementById('nav-user-name').textContent = currentUser.display_name;
            var homeName = document.getElementById('home-display-name');
            if (homeName) {
                homeName.textContent = currentUser.display_name;
            }
            // Convenience only: the API itself refuses admin calls from non-admins.
            document.getElementById('nav-admin-overview-item').classList.toggle('d-none', !currentUser.is_system_admin);
            document.getElementById('nav-admin-item').classList.toggle('d-none', !currentUser.is_system_admin);
            document.getElementById('nav-admin-users-item').classList.toggle('d-none', !currentUser.is_system_admin);
            document.getElementById('nav-admin-workspaces-item').classList.toggle('d-none', !currentUser.is_system_admin);
            if (currentUser.is_system_admin) {
                refreshInvitationRequestsBadge();
            }
            // The administrator is the one being contacted, so only everyday users see this.
            document.getElementById('nav-contact-admin-item').classList.toggle('d-none', currentUser.is_system_admin);
            // Administrator accounts have no workspaces (D025), so they get no Workspaces or Search link.
            document.getElementById('nav-workspaces-item').classList.toggle('d-none', currentUser.is_system_admin);
            document.getElementById('nav-search-item').classList.toggle('d-none', currentUser.is_system_admin);

            loadingIndicator.classList.add('d-none');
            document.dispatchEvent(new CustomEvent('muninn:user-ready', { detail: currentUser }));
            if (!document.body.hasAttribute('data-shell-hold')) {
                pageContent.classList.remove('d-none');
            }
        })
        .catch(function (sessionError) {
            if (sessionError.status === 401) {
                MuninnApi.goTo('login.php');
                return;
            }
            loadingIndicator.textContent = sessionError.message;
        });
})();
