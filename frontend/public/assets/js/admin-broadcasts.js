/*
 * Broadcast messages (D061): the administrator creates, edits, ends and deletes one-time
 * banners, sticky banners and votes, and reads who has seen or answered them.
 *
 * Times are entered in the browser's own time zone (datetime-local fields) and sent to the
 * API as UTC with an explicit "Z", so the server never has to guess a time zone.
 */
(function () {
    'use strict';

    document.body.setAttribute('data-shell-hold', '');

    var maximumAnswerCount = 5;
    var kindLabels = { once: 'One-time banner', sticky: 'Sticky banner', vote: 'Vote' };
    var statusLabels = { scheduled: 'Scheduled', showing: 'Showing now', ended: 'Ended' };
    var statusBadgeClasses = { scheduled: 'text-bg-secondary', showing: 'text-bg-success', ended: 'text-bg-light border' };

    var pageContent = document.getElementById('shell-content');
    var notAvailableAlert = document.getElementById('admin-not-available');
    var broadcastForm = document.getElementById('broadcast-form');
    var formHeading = document.getElementById('broadcast-form-heading');
    var formErrorAlert = document.getElementById('broadcast-form-error');
    var kindFieldset = document.getElementById('broadcast-kind-fieldset');
    var messageInput = document.getElementById('broadcast-message');
    var messageLabel = document.getElementById('broadcast-message-label');
    var startsAtInput = document.getElementById('broadcast-starts-at');
    var endsAtInput = document.getElementById('broadcast-ends-at');
    var voteFieldset = document.getElementById('broadcast-vote-fieldset');
    var answersContainer = document.getElementById('broadcast-options');
    var multipleChoicesInput = document.getElementById('broadcast-multiple-choices');
    var submitButton = document.getElementById('broadcast-submit');
    var cancelEditButton = document.getElementById('broadcast-cancel-edit');
    var listErrorAlert = document.getElementById('broadcast-list-error');
    var listEmptyText = document.getElementById('broadcast-list-empty');
    var broadcastList = document.getElementById('broadcast-list');
    var kindFeedback = document.getElementById('broadcast-kind-feedback');
    // The answers' feedback element is "broadcast-options-feedback", next to the answer boxes.
    var formFieldIds = {
        message: 'broadcast-message',
        starts_at: 'broadcast-starts-at',
        ends_at: 'broadcast-ends-at',
        options: 'broadcast-options',
    };

    /** The broadcast being edited, or null while the form creates a new one. */
    var broadcastBeingEdited = null;

    /** Builds the answer boxes once; they are reused for every new vote. */
    var answerInputs = [];
    for (var answerNumber = 1; answerNumber <= maximumAnswerCount; answerNumber++) {
        var answerInput = MuninnApi.createElement('input', 'form-control');
        answerInput.type = 'text';
        answerInput.maxLength = 100;
        answerInput.id = 'broadcast-answer-' + answerNumber;
        answerInput.setAttribute('aria-label', 'Answer ' + answerNumber);
        answerInput.placeholder = 'Answer ' + answerNumber + (answerNumber > 2 ? ' (optional)' : '');
        answersContainer.appendChild(answerInput);
        answerInputs.push(answerInput);
    }

    /** Two-digit form of a number, for datetime-local values. */
    function twoDigits(numberValue) {
        return String(numberValue).padStart(2, '0');
    }

    /** Turns a Date into a datetime-local value ("2026-10-09T18:30") in the browser's time zone. */
    function toLocalInputValue(dateValue) {
        return dateValue.getFullYear() + '-' + twoDigits(dateValue.getMonth() + 1) + '-' + twoDigits(dateValue.getDate())
            + 'T' + twoDigits(dateValue.getHours()) + ':' + twoDigits(dateValue.getMinutes());
    }

    /**
     * Turns a datetime-local value into UTC ISO 8601 ("...Z"), or '' when empty or invalid
     * (the API then says which field is missing). Such values are read as local time.
     */
    function toUtcIso(localInputValue) {
        if (!localInputValue) {
            return '';
        }
        var parsedDate = new Date(localInputValue);
        return isNaN(parsedDate.getTime()) ? '' : parsedDate.toISOString();
    }

    function selectedKind() {
        var checkedKindInput = broadcastForm.querySelector('input[name="broadcast-kind"]:checked');
        return checkedKindInput ? checkedKindInput.value : '';
    }

    /** Shows the answer boxes for votes only, and names the text "Question" for them. */
    function updateFormForKind() {
        var isVote = selectedKind() === 'vote';
        voteFieldset.classList.toggle('d-none', !isVote);
        messageLabel.textContent = isVote ? 'Question' : 'Message';
    }

    /** Empties the form for a new broadcast: from now, until the same time tomorrow. */
    function resetForm() {
        broadcastBeingEdited = null;
        broadcastForm.reset();
        var nowDate = new Date();
        startsAtInput.value = toLocalInputValue(nowDate);
        endsAtInput.value = toLocalInputValue(new Date(nowDate.getTime() + 24 * 60 * 60 * 1000));
        kindFieldset.disabled = false;
        voteFieldset.disabled = false;
        formHeading.textContent = 'New broadcast';
        submitButton.textContent = 'Create';
        cancelEditButton.classList.add('d-none');
        MuninnApi.showFieldErrors(formFieldIds, {});
        kindFeedback.textContent = '';
        MuninnApi.showAlert(formErrorAlert, '');
        updateFormForKind();
    }

    /** Fills the form with a broadcast to edit. Its type and answers stay fixed (D061). */
    function startEditing(broadcast) {
        resetForm();
        broadcastBeingEdited = broadcast;
        broadcastForm.querySelector('input[name="broadcast-kind"][value="' + broadcast.kind + '"]').checked = true;
        messageInput.value = broadcast.message;
        startsAtInput.value = toLocalInputValue(new Date(broadcast.starts_at));
        endsAtInput.value = toLocalInputValue(new Date(broadcast.ends_at));
        answerInputs.forEach(function (answerInput, answerIndex) {
            answerInput.value = broadcast.options[answerIndex] ? broadcast.options[answerIndex].label : '';
        });
        multipleChoicesInput.checked = broadcast.allows_multiple_choices;
        kindFieldset.disabled = true;
        voteFieldset.disabled = true;
        formHeading.textContent = 'Edit broadcast';
        submitButton.textContent = 'Save';
        cancelEditButton.classList.remove('d-none');
        updateFormForKind();
        formHeading.scrollIntoView({ behavior: 'smooth', block: 'start' });
        messageInput.focus();
    }

    /** "Show from … until …" in the viewer's time zone. */
    function scheduleText(broadcast) {
        return MuninnApi.formatDateTime(broadcast.starts_at) + ' – ' + MuninnApi.formatDateTime(broadcast.ends_at);
    }

    /** How many users are done with it, in the words that fit its type. */
    function progressText(broadcast) {
        var userWord = broadcast.done_count === 1 ? ' user' : ' users';
        if (broadcast.kind === 'once') {
            return 'Seen by ' + broadcast.done_count + userWord + '.';
        }
        if (broadcast.kind === 'sticky') {
            return 'Closed by ' + broadcast.done_count + userWord + '.';
        }
        return broadcast.done_count + userWord + ' voted.';
    }

    /** The results of a vote: one bar per answer, with who chose it. */
    function buildVoteResults(broadcast) {
        var resultsList = MuninnApi.createElement('ul', 'list-unstyled mb-0 mt-2');
        var largestVoteCount = Math.max(1, ...broadcast.options.map(function (option) { return option.vote_count; }));
        broadcast.options.forEach(function (option) {
            var resultItem = MuninnApi.createElement('li', 'mb-2');
            var resultHeader = MuninnApi.createElement('div', 'd-flex justify-content-between gap-2 small');
            resultHeader.appendChild(MuninnApi.createElement('span', 'text-break', option.label));
            resultHeader.appendChild(MuninnApi.createElement('span', 'fw-semibold flex-shrink-0', option.vote_count + (option.vote_count === 1 ? ' vote' : ' votes')));
            resultItem.appendChild(resultHeader);

            var resultBar = MuninnApi.createElement('div', 'muninn-result-bar');
            resultBar.setAttribute('aria-hidden', 'true');
            var resultBarFill = MuninnApi.createElement('div', 'muninn-result-bar-fill');
            resultBarFill.style.width = Math.round(100 * option.vote_count / largestVoteCount) + '%';
            resultBar.appendChild(resultBarFill);
            resultItem.appendChild(resultBar);

            if (option.voters.length > 0) {
                var voterNames = option.voters.map(function (voter) { return voter.display_name + ' (' + voter.username + ')'; });
                resultItem.appendChild(MuninnApi.createElement('div', 'small text-muted-brand text-break', voterNames.join(', ')));
            }
            resultsList.appendChild(resultItem);
        });
        return resultsList;
    }

    /** One broadcast in the list, with its actions. */
    function buildBroadcastCard(broadcast) {
        var broadcastCard = MuninnApi.createElement('article', 'card border-0 shadow-sm');
        var cardBody = MuninnApi.createElement('div', 'card-body');

        var badgeRow = MuninnApi.createElement('div', 'd-flex flex-wrap gap-2 mb-2');
        badgeRow.appendChild(MuninnApi.createElement('span', 'badge text-bg-primary', kindLabels[broadcast.kind] || broadcast.kind));
        badgeRow.appendChild(MuninnApi.createElement('span', 'badge ' + statusBadgeClasses[broadcast.status], statusLabels[broadcast.status] || broadcast.status));
        if (broadcast.kind === 'vote') {
            badgeRow.appendChild(MuninnApi.createElement('span', 'badge text-bg-light border', broadcast.allows_multiple_choices ? 'Several answers' : 'One answer'));
        }
        cardBody.appendChild(badgeRow);

        cardBody.appendChild(MuninnApi.createElement('p', 'muninn-broadcast-message mb-2', broadcast.message));
        cardBody.appendChild(MuninnApi.createElement('p', 'small text-muted-brand mb-1', scheduleText(broadcast)));
        cardBody.appendChild(MuninnApi.createElement('p', 'small mb-0', progressText(broadcast)));
        if (broadcast.kind === 'vote') {
            cardBody.appendChild(buildVoteResults(broadcast));
        }

        var actionRow = MuninnApi.createElement('div', 'd-flex flex-wrap gap-2 mt-3');
        var editButton = MuninnApi.createElement('button', 'btn btn-sm btn-outline-secondary', 'Edit');
        editButton.type = 'button';
        editButton.addEventListener('click', function () {
            startEditing(broadcast);
        });
        actionRow.appendChild(editButton);

        if (broadcast.status === 'showing') {
            var endButton = MuninnApi.createElement('button', 'btn btn-sm btn-outline-secondary', 'End now');
            endButton.type = 'button';
            endButton.addEventListener('click', function () {
                endBroadcastNow(broadcast, endButton);
            });
            actionRow.appendChild(endButton);
        }

        var deleteButton = MuninnApi.createElement('button', 'btn btn-sm btn-outline-danger', 'Delete');
        deleteButton.type = 'button';
        deleteButton.addEventListener('click', function () {
            deleteBroadcast(broadcast, deleteButton);
        });
        actionRow.appendChild(deleteButton);
        cardBody.appendChild(actionRow);

        broadcastCard.appendChild(cardBody);
        return broadcastCard;
    }

    async function loadBroadcasts() {
        var broadcastData = await MuninnApi.request('GET', '/api/v1/admin/broadcasts');
        broadcastList.replaceChildren();
        broadcastData.broadcasts.forEach(function (broadcast) {
            broadcastList.appendChild(buildBroadcastCard(broadcast));
        });
        listEmptyText.classList.toggle('d-none', broadcastData.broadcasts.length > 0);
    }

    /** Reloads the list and reports a failure above it. */
    async function reloadBroadcasts() {
        try {
            await loadBroadcasts();
        } catch (loadError) {
            MuninnApi.showAlert(listErrorAlert, loadError.message);
        }
    }

    /** Ends a showing broadcast by moving its end to this moment. */
    async function endBroadcastNow(broadcast, endButton) {
        if (!window.confirm('End this broadcast now? Users stop seeing it at once.')) {
            return;
        }
        endButton.disabled = true;
        MuninnApi.showAlert(listErrorAlert, '');
        try {
            await MuninnApi.request('PATCH', '/api/v1/admin/broadcasts/' + encodeURIComponent(broadcast.id), {
                message: broadcast.message,
                starts_at: broadcast.starts_at,
                ends_at: new Date().toISOString(),
            });
            await reloadBroadcasts();
        } catch (endError) {
            MuninnApi.showAlert(listErrorAlert, endError.message);
            endButton.disabled = false;
        }
    }

    async function deleteBroadcast(broadcast, deleteButton) {
        var confirmText = broadcast.kind === 'vote'
            ? 'Delete this vote and all its answers? This cannot be undone.'
            : 'Delete this broadcast? This cannot be undone.';
        if (!window.confirm(confirmText)) {
            return;
        }
        deleteButton.disabled = true;
        MuninnApi.showAlert(listErrorAlert, '');
        try {
            await MuninnApi.request('DELETE', '/api/v1/admin/broadcasts/' + encodeURIComponent(broadcast.id));
            if (broadcastBeingEdited && broadcastBeingEdited.id === broadcast.id) {
                resetForm();
            }
            await reloadBroadcasts();
        } catch (deleteError) {
            MuninnApi.showAlert(listErrorAlert, deleteError.message);
            deleteButton.disabled = false;
        }
    }

    broadcastForm.addEventListener('change', function (changeEvent) {
        if (changeEvent.target.name === 'broadcast-kind') {
            updateFormForKind();
        }
    });

    cancelEditButton.addEventListener('click', resetForm);

    broadcastForm.addEventListener('submit', async function (submitEvent) {
        submitEvent.preventDefault();
        MuninnApi.showFieldErrors(formFieldIds, {});
        kindFeedback.textContent = '';
        MuninnApi.showAlert(formErrorAlert, '');

        var requestBody = {
            message: messageInput.value,
            starts_at: toUtcIso(startsAtInput.value),
            ends_at: toUtcIso(endsAtInput.value),
        };
        var isEditing = broadcastBeingEdited !== null;
        if (!isEditing) {
            requestBody.kind = selectedKind();
            if (requestBody.kind === 'vote') {
                requestBody.allows_multiple_choices = multipleChoicesInput.checked;
                requestBody.options = answerInputs.map(function (answerInput) { return answerInput.value; });
            }
        }

        submitButton.disabled = true;
        try {
            if (isEditing) {
                await MuninnApi.request('PATCH', '/api/v1/admin/broadcasts/' + encodeURIComponent(broadcastBeingEdited.id), requestBody);
            } else {
                await MuninnApi.request('POST', '/api/v1/admin/broadcasts', requestBody);
            }
            resetForm();
            await reloadBroadcasts();
        } catch (saveError) {
            var fieldErrors = saveError.fields || {};
            MuninnApi.showFieldErrors(formFieldIds, fieldErrors);
            // The type is a group of radio buttons, so its message is shown separately.
            kindFeedback.textContent = fieldErrors.kind || '';
            MuninnApi.showAlert(formErrorAlert, saveError.code === 'validation_failed' ? 'Please correct the highlighted fields.' : saveError.message);
        } finally {
            submitButton.disabled = false;
        }
    });

    document.addEventListener('muninn:user-ready', async function (readyEvent) {
        if (!readyEvent.detail.is_system_admin) {
            notAvailableAlert.classList.remove('d-none');
            return;
        }
        resetForm();
        await reloadBroadcasts();
        pageContent.classList.remove('d-none');
    });
})();
