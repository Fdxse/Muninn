/*
 * The administrator's inbox (D065): conversations started with "Contact admin", one conversation
 * with an answer box, and closing or reopening it. The page is drawn by admin/messages.php.
 */
(function () {
    'use strict';

    // The page shows itself once it knows what to show.
    document.body.setAttribute('data-shell-hold', '');

    /** The chosen filter is remembered for this browser tab, so "Back" returns to the same list. */
    var FILTER_STORAGE_KEY = 'muninn.adminMessagesFilter';

    var pageContent = document.getElementById('shell-content');
    var notAvailableAlert = document.getElementById('admin-not-available');
    var inboxView = document.getElementById('inbox-view');
    var conversationView = document.getElementById('conversation-view');
    var conversationError = document.getElementById('conversation-error');
    var messageList = document.getElementById('conversation-messages');
    var closeButton = document.getElementById('conversation-close-button');
    var reopenButton = document.getElementById('conversation-reopen-button');

    /** The conversation on screen (?id=), or null on the inbox. */
    var openConversationId = MuninnApi.queryParameter('id');

    /* ---------- The inbox ---------- */

    function rememberedFilter() {
        try {
            var storedFilter = window.sessionStorage.getItem(FILTER_STORAGE_KEY);
            return ['open', 'closed', 'all'].indexOf(storedFilter) >= 0 ? storedFilter : 'open';
        } catch (storageError) {
            return 'open';
        }
    }

    function rememberFilter(filterValue) {
        try {
            window.sessionStorage.setItem(FILTER_STORAGE_KEY, filterValue);
        } catch (storageError) {
            // Only a convenience.
        }
    }

    /** "Waiting for you", "Answered" or "Closed". */
    function statusText(conversation) {
        if (conversation.status === 'closed') {
            return 'Closed';
        }
        return conversation.last_message_by === 'user' ? 'Waiting for you' : 'Answered';
    }

    /** "Alice (alice)", with a note when the account has been disabled. */
    function userLabel(conversationUser) {
        return conversationUser.display_name + ' (' + conversationUser.username + ')'
            + (conversationUser.status === 'active' ? '' : ' · account disabled');
    }

    /** One inbox row: who wrote, the start of the first message, its state and newest activity. */
    function buildInboxRow(conversation) {
        var rowLink = MuninnApi.createElement('a', 'list-group-item list-group-item-action d-flex align-items-start gap-3 py-3');
        rowLink.href = 'messages.php?id=' + encodeURIComponent(conversation.id);
        var waitsForAnswer = conversation.status === 'open' && conversation.last_message_by === 'user';
        var rowIcon = MuninnApi.createElement('i', 'bi ' + (conversation.status === 'closed' ? 'bi-archive' : waitsForAnswer ? 'bi-envelope-exclamation' : 'bi-envelope-check') + ' fs-5');
        rowIcon.setAttribute('aria-hidden', 'true');
        rowLink.appendChild(rowIcon);

        var textColumn = MuninnApi.createElement('div', 'flex-grow-1 text-break');
        textColumn.appendChild(MuninnApi.createElement('div', 'fw-semibold', userLabel(conversation.user)));
        textColumn.appendChild(MuninnApi.createElement('div', conversation.unread ? 'fw-semibold' : '', conversation.excerpt));
        textColumn.appendChild(MuninnApi.createElement(
            'div',
            'small text-muted-brand',
            statusText(conversation) + ' · ' + conversation.message_count + (conversation.message_count === 1 ? ' message' : ' messages')
                + ' · ' + MuninnApi.formatDateTime(conversation.last_message_at)
        ));
        rowLink.appendChild(textColumn);

        if (conversation.unread) {
            var newBadge = MuninnApi.createElement('span', 'badge rounded-pill muninn-unread-badge', 'New');
            newBadge.appendChild(MuninnApi.createElement('span', 'visually-hidden', ' message from the user'));
            rowLink.appendChild(newBadge);
        }
        return rowLink;
    }

    async function loadInbox(filterValue) {
        var inboxError = document.getElementById('inbox-error');
        var inboxList = document.getElementById('inbox-list');
        MuninnApi.showAlert(inboxError, '');
        try {
            var inboxData = await MuninnApi.request('GET', '/api/v1/admin/conversations?status=' + encodeURIComponent(filterValue));
            inboxList.replaceChildren();
            inboxData.conversations.forEach(function (conversation) {
                inboxList.appendChild(buildInboxRow(conversation));
            });
            inboxList.classList.toggle('d-none', inboxData.conversations.length === 0);
            document.getElementById('inbox-empty').classList.toggle('d-none', inboxData.conversations.length > 0);
            document.getElementById('inbox-retention-note').textContent =
                'Conversations are deleted ' + inboxData.retention_days + ' days after their newest message.';
        } catch (loadError) {
            MuninnApi.showAlert(inboxError, loadError.message);
        }
    }

    function showInbox() {
        var startFilter = rememberedFilter();
        document.getElementById('inbox-filter-' + startFilter).checked = true;
        document.querySelectorAll('input[name="inbox-filter"]').forEach(function (filterInput) {
            filterInput.addEventListener('change', function () {
                rememberFilter(filterInput.value);
                loadInbox(filterInput.value);
            });
        });
        inboxView.classList.remove('d-none');
        pageContent.classList.remove('d-none');
        loadInbox(startFilter);
    }

    /* ---------- One conversation ---------- */

    /** Draws a conversation from the API's {conversation, messages} answer. */
    function showConversationData(conversationData) {
        var conversation = conversationData.conversation;
        document.getElementById('conversation-heading').textContent = userLabel(conversation.user);

        var metaParts = [statusText(conversation), 'started ' + MuninnApi.formatDateTime(conversation.created_at)];
        metaParts.push(conversation.contact_details === ''
            ? 'no other contact details given'
            : 'can also be reached at ' + conversation.contact_details);
        document.getElementById('conversation-meta').textContent = metaParts.join(' · ');

        MuninnMessageThread.renderMessages(messageList, conversationData.messages);
        var isOpen = conversation.status === 'open';
        closeButton.classList.toggle('d-none', !isOpen);
        reopenButton.classList.toggle('d-none', isOpen);
        // The Messages badge follows: opening the conversation marked it read.
        document.dispatchEvent(new CustomEvent('muninn:messages-read'));
    }

    /** Closes or reopens the conversation ('close' or 'reopen'). */
    async function changeStatus(actionName, actionButton) {
        actionButton.disabled = true;
        MuninnApi.showAlert(conversationError, '');
        try {
            var updatedData = await MuninnApi.request(
                'POST',
                '/api/v1/admin/conversations/' + encodeURIComponent(openConversationId) + '/' + actionName
            );
            showConversationData(updatedData);
            // Keep keyboard focus on the button that is now shown.
            (actionName === 'close' ? reopenButton : closeButton).focus();
        } catch (changeError) {
            MuninnApi.showAlert(conversationError, changeError.message);
        } finally {
            actionButton.disabled = false;
        }
    }

    async function showConversation() {
        conversationView.classList.remove('d-none');
        pageContent.classList.remove('d-none');
        var conversationData;
        try {
            conversationData = await MuninnApi.request('GET', '/api/v1/admin/conversations/' + encodeURIComponent(openConversationId));
        } catch (loadError) {
            MuninnApi.showAlert(conversationError, loadError.status === 404 ? 'This conversation does not exist (it may have been deleted after a year).' : loadError.message);
            document.getElementById('reply-form').classList.add('d-none');
            return;
        }
        showConversationData(conversationData);

        closeButton.addEventListener('click', function () { changeStatus('close', closeButton); });
        reopenButton.addEventListener('click', function () { changeStatus('reopen', reopenButton); });
        MuninnMessageThread.wireReplyForm(
            {
                form: document.getElementById('reply-form'),
                input: document.getElementById('reply-input'),
                button: document.getElementById('reply-send-button'),
                feedback: document.getElementById('reply-feedback'),
                counter: document.getElementById('reply-character-count'),
            },
            conversationData.limits.message_max_length,
            async function (replyText) {
                var updatedData = await MuninnApi.request(
                    'POST',
                    '/api/v1/admin/conversations/' + encodeURIComponent(openConversationId) + '/messages',
                    { message: replyText }
                );
                showConversationData(updatedData);
            }
        );
        // The newest message is at the bottom; bring it and the answer box into view.
        conversationView.scrollIntoView({ block: 'end' });
    }

    document.addEventListener('muninn:user-ready', function (readyEvent) {
        if (!readyEvent.detail.is_system_admin) {
            notAvailableAlert.classList.remove('d-none');
            return;
        }
        if (openConversationId) {
            showConversation();
        } else {
            showInbox();
        }
    });
})();
