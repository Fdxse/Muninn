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

    MuninnApi.loadCurrentUser()
        .then(function (currentUserData) {
            var currentUser = currentUserData.user;
            document.getElementById('nav-user-name').textContent = currentUser.display_name;
            var homeName = document.getElementById('home-display-name');
            if (homeName) {
                homeName.textContent = currentUser.display_name;
            }
            // Convenience only: the API itself refuses admin calls from non-admins.
            document.getElementById('nav-admin-item').classList.toggle('d-none', !currentUser.is_system_admin);
            document.getElementById('nav-admin-users-item').classList.toggle('d-none', !currentUser.is_system_admin);
            document.getElementById('nav-admin-workspaces-item').classList.toggle('d-none', !currentUser.is_system_admin);
            if (currentUser.is_system_admin) {
                refreshInvitationRequestsBadge();
            }
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
