/*
 * Password reset (D040): read the token from the URL fragment, check it, then set the new password.
 */
(function () {
    'use strict';

    var checkingMessage = document.getElementById('reset-checking');
    var invalidSection = document.getElementById('reset-invalid');
    var doneSection = document.getElementById('reset-done');
    var formSection = document.getElementById('reset-form-section');
    var resetForm = document.getElementById('reset-form');
    var errorAlert = document.getElementById('reset-error');
    var submitButton = document.getElementById('reset-submit');
    var passwordInput = document.getElementById('reset-password');
    var passwordRepeatInput = document.getElementById('reset-password-repeat');

    // The token lives in the fragment (#token=...), which is never sent to any server.
    var fragmentParameters = new URLSearchParams(window.location.hash.replace(/^#/, ''));
    var resetToken = fragmentParameters.get('token') || '';

    // Remove the token from the address bar and history right away.
    if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', window.location.pathname);
    }

    function showOnly(visibleSection) {
        [checkingMessage, invalidSection, doneSection, formSection].forEach(function (pageSection) {
            pageSection.classList.toggle('d-none', pageSection !== visibleSection);
        });
    }

    async function checkLink() {
        if (resetToken === '') {
            showOnly(invalidSection);
            return;
        }
        try {
            var resetDetails = await MuninnApi.request('POST', '/api/v1/password-resets/inspect', { token: resetToken });
            document.getElementById('reset-username').textContent = resetDetails.username;
            document.getElementById('reset-form-username').value = resetDetails.username;
            document.getElementById('reset-expiry').textContent = MuninnApi.formatDateTime(resetDetails.expires_at);
            showOnly(formSection);
            passwordInput.focus();
        } catch (inspectError) {
            if (inspectError.code === 'rate_limited' || inspectError.code === 'network_error') {
                checkingMessage.textContent = inspectError.message;
                return;
            }
            showOnly(invalidSection);
        }
    }

    resetForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        MuninnApi.showAlert(errorAlert, '');
        MuninnApi.showFieldErrors({ password: 'reset-password' }, {});

        // Catch typos before contacting the server.
        var passwordsMatch = passwordInput.value === passwordRepeatInput.value;
        passwordRepeatInput.classList.toggle('is-invalid', !passwordsMatch);
        passwordRepeatInput.setAttribute('aria-invalid', passwordsMatch ? 'false' : 'true');
        document.getElementById('reset-password-repeat-feedback').textContent = passwordsMatch ? '' : 'The passwords do not match.';
        if (!passwordsMatch) {
            passwordRepeatInput.focus();
            return;
        }

        submitButton.disabled = true;
        submitButton.textContent = 'Saving…';
        try {
            await MuninnApi.request('POST', '/api/v1/password-resets/complete', {
                token: resetToken,
                password: passwordInput.value,
            });
            showOnly(doneSection);
            doneSection.focus();
        } catch (completeError) {
            submitButton.disabled = false;
            submitButton.textContent = 'Save new password';
            if (completeError.code === 'password_reset_invalid') {
                showOnly(invalidSection);
                return;
            }
            MuninnApi.showFieldErrors({ password: 'reset-password' }, completeError.fields);
            MuninnApi.showAlert(errorAlert, completeError.code === 'validation_failed' ? 'Please correct the highlighted fields.' : completeError.message);
        }
    });

    checkLink();
})();
