/*
 * Sign-in page behaviour: submit credentials to the API, then go to the home page.
 */
(function () {
    'use strict';

    var loginForm = document.getElementById('login-form');
    var usernameInput = document.getElementById('login-username');
    var passwordInput = document.getElementById('login-password');
    var submitButton = document.getElementById('login-submit');
    var errorAlert = document.getElementById('login-error');

    // Someone who is already signed in goes straight to the app.
    MuninnApi.loadCurrentUser()
        .then(function () { MuninnApi.goTo('index.php'); })
        .catch(function () { usernameInput.focus(); });

    loginForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        MuninnApi.showAlert(errorAlert, '');

        if (usernameInput.value.trim() === '' || passwordInput.value === '') {
            MuninnApi.showAlert(errorAlert, 'Enter your username and password.');
            return;
        }

        submitButton.disabled = true;
        submitButton.textContent = 'Signing in…';
        try {
            await MuninnApi.request('POST', '/api/v1/auth/login', {
                username: usernameInput.value.trim(),
                password: passwordInput.value,
            });
            MuninnApi.goTo('index.php');
        } catch (loginError) {
            passwordInput.value = '';
            MuninnApi.showAlert(errorAlert, loginError.message);
            submitButton.disabled = false;
            submitButton.textContent = 'Sign in';
        }
    });
})();
