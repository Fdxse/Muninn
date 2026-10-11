/*
 * Account page: show a 6-digit code that signs another device in as this account (D068).
 *
 * The code comes from the API once and is never shown again after the page is left. While it is
 * on screen, a countdown shows how long it still works, and the page asks the API every few
 * seconds whether it has been used, so the person sees "used" the moment the other device is in.
 */
(function () {
    'use strict';

    /** How often to ask whether the code has been used, in milliseconds. */
    var STATUS_CHECK_INTERVAL_MILLISECONDS = 3000;

    var codeCard = document.getElementById('device-code-card');
    var showButton = document.getElementById('device-code-show');
    var newCodeButton = document.getElementById('device-code-new');
    var cancelButton = document.getElementById('device-code-cancel');
    var codePanel = document.getElementById('device-code-panel');
    var digitsElement = document.getElementById('device-code-digits');
    var statusElement = document.getElementById('device-code-status');
    var errorAlert = document.getElementById('device-code-error');

    /** The code on screen: {id, expiresAtMilliseconds}, or null when none is shown. */
    var shownCode = null;
    var countdownTimer = null;
    var statusCheckTimer = null;

    /** "123456" → "123 456", easier to read and type. */
    function groupDigits(code) {
        return code.slice(0, 3) + ' ' + code.slice(3);
    }

    /** Remaining time as "4:05". */
    function formatRemaining(remainingMilliseconds) {
        var remainingSeconds = Math.max(0, Math.ceil(remainingMilliseconds / 1000));
        var minutes = Math.floor(remainingSeconds / 60);
        var seconds = remainingSeconds % 60;
        return minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
    }

    function stopTimers() {
        window.clearInterval(countdownTimer);
        window.clearInterval(statusCheckTimer);
        countdownTimer = null;
        statusCheckTimer = null;
    }

    /**
     * Ends the shown code: crosses it out, says why, and offers a new one.
     * Called when the code was used, expired, or was cancelled.
     */
    function finishCode(statusText) {
        stopTimers();
        shownCode = null;
        digitsElement.classList.add('muninn-device-code-done');
        statusElement.textContent = statusText;
        cancelButton.classList.add('d-none');
        newCodeButton.classList.remove('d-none');
    }

    function updateCountdown() {
        if (shownCode === null) {
            return;
        }
        var remainingMilliseconds = shownCode.expiresAtMilliseconds - Date.now();
        if (remainingMilliseconds <= 0) {
            finishCode('The code has expired.');
            return;
        }
        statusElement.textContent = 'Works for ' + formatRemaining(remainingMilliseconds) + '. Waiting for the other device…';
    }

    /** Asks the API whether the code on screen has been used (or ended some other way). */
    async function checkCodeStatus() {
        if (shownCode === null) {
            return;
        }
        var checkedCodeId = shownCode.id;
        try {
            var codeState = await MuninnApi.request('GET', '/api/v1/auth/device-codes/' + encodeURIComponent(checkedCodeId));
            // Ignore an answer about a code that is no longer on screen.
            if (shownCode === null || shownCode.id !== checkedCodeId) {
                return;
            }
            if (codeState.status === 'used') {
                finishCode('Used. Your other device is now signed in.');
            } else if (codeState.status === 'expired') {
                finishCode('The code has expired.');
            } else if (codeState.status === 'revoked') {
                finishCode('The code was cancelled.');
            }
        } catch (checkError) {
            // A missed check is harmless: the next one tries again, and the countdown keeps going.
        }
    }

    async function showNewCode() {
        MuninnApi.showAlert(errorAlert, '');
        showButton.disabled = true;
        newCodeButton.disabled = true;
        try {
            var createdCode = await MuninnApi.request('POST', '/api/v1/auth/device-codes');
            stopTimers();
            // Count down from this device's clock, so a wrong clock does not shorten the code.
            shownCode = {
                id: createdCode.id,
                expiresAtMilliseconds: Date.now() + createdCode.lifetime_seconds * 1000,
            };
            digitsElement.textContent = groupDigits(createdCode.code);
            digitsElement.setAttribute('aria-label', 'Code: ' + createdCode.code.split('').join(' '));
            digitsElement.classList.remove('muninn-device-code-done');
            cancelButton.classList.remove('d-none');
            newCodeButton.classList.add('d-none');
            showButton.classList.add('d-none');
            codePanel.classList.remove('d-none');

            updateCountdown();
            countdownTimer = window.setInterval(updateCountdown, 1000);
            statusCheckTimer = window.setInterval(checkCodeStatus, STATUS_CHECK_INTERVAL_MILLISECONDS);
        } catch (createError) {
            MuninnApi.showAlert(errorAlert, createError.message);
        } finally {
            showButton.disabled = false;
            newCodeButton.disabled = false;
        }
    }

    async function cancelCode() {
        if (shownCode === null) {
            return;
        }
        var cancelledCodeId = shownCode.id;
        cancelButton.disabled = true;
        try {
            await MuninnApi.request('DELETE', '/api/v1/auth/device-codes/' + encodeURIComponent(cancelledCodeId));
            finishCode('The code was cancelled.');
        } catch (cancelError) {
            MuninnApi.showAlert(errorAlert, cancelError.message);
        } finally {
            cancelButton.disabled = false;
        }
    }

    showButton.addEventListener('click', showNewCode);
    newCodeButton.addEventListener('click', showNewCode);
    cancelButton.addEventListener('click', cancelCode);

    // Phones pause timers in background tabs; check straight away when the page is back.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            updateCountdown();
            checkCodeStatus();
        }
    });

    document.addEventListener('muninn:user-ready', function (readyEvent) {
        // Administrator accounts sign in with their password only (D068).
        if (!readyEvent.detail.is_system_admin) {
            codeCard.classList.remove('d-none');
        }
    });
})();
