/*
 * Sign-in page behaviour: submit credentials, or a 6-digit code from a signed-in device (D068),
 * to the API, then go to the home page.
 */
(function () {
    'use strict';

    var loginForm = document.getElementById('login-form');
    var usernameInput = document.getElementById('login-username');
    var passwordInput = document.getElementById('login-password');
    var submitButton = document.getElementById('login-submit');
    var errorAlert = document.getElementById('login-error');

    var passwordSection = document.getElementById('password-sign-in');
    var codeSection = document.getElementById('code-sign-in');
    var codeForm = document.getElementById('code-form');
    var codeInput = document.getElementById('sign-in-code');
    var codeSubmitButton = document.getElementById('code-submit');
    var codeErrorAlert = document.getElementById('code-error');

    // Someone who is already signed in goes straight to the app.
    MuninnApi.loadCurrentUser()
        .then(function () { MuninnApi.goTo('index.php'); })
        .catch(function () { usernameInput.focus(); });

    /** Switches between the password form and the code form, moving focus to the one shown. */
    function showCodeForm(codeFormWanted) {
        passwordSection.classList.toggle('d-none', codeFormWanted);
        codeSection.classList.toggle('d-none', !codeFormWanted);
        MuninnApi.showAlert(errorAlert, '');
        MuninnApi.showAlert(codeErrorAlert, '');
        if (codeFormWanted) {
            codeInput.focus();
        } else {
            usernameInput.focus();
        }
    }

    document.getElementById('use-code-button').addEventListener('click', function () { showCodeForm(true); });
    document.getElementById('use-password-button').addEventListener('click', function () { showCodeForm(false); });

    codeForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        MuninnApi.showAlert(codeErrorAlert, '');

        // Spaces and dashes between digit groups are fine; the API ignores them too.
        var typedDigits = codeInput.value.replace(/[\s-]/g, '');
        if (!/^[0-9]{6}$/.test(typedDigits)) {
            MuninnApi.showAlert(codeErrorAlert, 'Type the 6 digits shown on your signed-in device.');
            codeInput.focus();
            return;
        }

        codeSubmitButton.disabled = true;
        codeSubmitButton.textContent = 'Signing in…';
        try {
            await MuninnApi.request('POST', '/api/v1/auth/device-code-login', { code: typedDigits });
            MuninnApi.goTo('index.php');
        } catch (codeError) {
            codeInput.value = '';
            MuninnApi.showAlert(codeErrorAlert, codeError.message);
            codeSubmitButton.disabled = false;
            codeSubmitButton.textContent = 'Sign in';
        }
    });

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
