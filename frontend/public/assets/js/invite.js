/*
 * Invitation acceptance: read the token from the URL fragment, check it, then create the account.
 */
(function () {
    'use strict';

    var checkingMessage = document.getElementById('invite-checking');
    var invalidSection = document.getElementById('invite-invalid');
    var formSection = document.getElementById('invite-form-section');
    var expiryText = document.getElementById('invite-expiry');
    var inviteForm = document.getElementById('invite-form');
    var errorAlert = document.getElementById('invite-error');
    var submitButton = document.getElementById('invite-submit');
    var passwordInput = document.getElementById('invite-password');
    var passwordRepeatInput = document.getElementById('invite-password-repeat');

    var fieldInputIds = {
        username: 'invite-username',
        display_name: 'invite-display-name',
        password: 'invite-password',
    };

    // The token lives in the fragment (#token=...), which is never sent to any server.
    var fragmentParameters = new URLSearchParams(window.location.hash.replace(/^#/, ''));
    var invitationToken = fragmentParameters.get('token') || '';

    // Remove the token from the address bar and history right away.
    if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', window.location.pathname);
    }

    function showInvalid() {
        checkingMessage.classList.add('d-none');
        formSection.classList.add('d-none');
        invalidSection.classList.remove('d-none');
    }

    async function checkInvitation() {
        if (invitationToken === '') {
            showInvalid();
            return;
        }
        try {
            var invitationDetails = await MuninnApi.request('POST', '/api/v1/invitations/inspect', { token: invitationToken });
            expiryText.textContent = MuninnApi.formatDateTime(invitationDetails.expires_at);
            checkingMessage.classList.add('d-none');
            formSection.classList.remove('d-none');
            document.getElementById('invite-username').focus();
        } catch (inspectError) {
            if (inspectError.code === 'rate_limited' || inspectError.code === 'network_error') {
                checkingMessage.textContent = inspectError.message;
                return;
            }
            showInvalid();
        }
    }

    inviteForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        MuninnApi.showAlert(errorAlert, '');
        MuninnApi.showFieldErrors(fieldInputIds, {});

        // Catch typos before contacting the server.
        var passwordsMatch = passwordInput.value === passwordRepeatInput.value;
        passwordRepeatInput.classList.toggle('is-invalid', !passwordsMatch);
        document.getElementById('invite-password-repeat-feedback').textContent = passwordsMatch ? '' : 'The passwords do not match.';
        if (!passwordsMatch) {
            passwordRepeatInput.focus();
            return;
        }

        submitButton.disabled = true;
        submitButton.textContent = 'Creating account…';
        try {
            await MuninnApi.request('POST', '/api/v1/invitations/accept', {
                token: invitationToken,
                username: document.getElementById('invite-username').value.trim(),
                display_name: document.getElementById('invite-display-name').value.trim(),
                password: passwordInput.value,
            });
            MuninnApi.goTo('index.php');
        } catch (acceptError) {
            submitButton.disabled = false;
            submitButton.textContent = 'Create account';
            if (acceptError.code === 'invitation_invalid') {
                showInvalid();
                return;
            }
            MuninnApi.showFieldErrors(fieldInputIds, acceptError.fields);
            MuninnApi.showAlert(errorAlert, acceptError.code === 'validation_failed' ? 'Please correct the highlighted fields.' : acceptError.message);
        }
    });

    checkInvitation();
})();
