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

    document.getElementById('nav-logout-button').addEventListener('click', function () {
        MuninnApi.signOut();
    });

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
