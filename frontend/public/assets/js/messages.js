/*
 * My messages (D065): the signed-in user's conversations with the administrator, and one
 * conversation with a reply box while it is open. The page is drawn by messages.php.
 */
(function () {
    'use strict';

    // The page shows itself once it knows what to show.
    document.body.setAttribute('data-shell-hold', '');

    var pageContent = document.getElementById('shell-content');
    var listView = document.getElementById('conversation-list-view');
    var conversationView = document.getElementById('conversation-view');
    var conversationError = document.getElementById('conversation-error');
    var messageList = document.getElementById('conversation-messages');
    var replyForm = document.getElementById('reply-form');
    var closedNote = document.getElementById('conversation-closed-note');

    /** The conversation on screen (?id=), or null on the list. */
    var openConversationId = MuninnApi.queryParameter('id');

    /* ---------- The list ---------- */

    /** "Answered", "Waiting for the administrator" or "Closed", for a list row. */
    function statusText(conversation) {
        if (conversation.status === 'closed') {
            return 'Closed';
        }
        return conversation.last_message_by === 'admin' ? 'Answered' : 'Waiting for the administrator';
    }

    /** One row of the list: the start of the first message, its state and the newest activity. */
    function buildConversationRow(conversation) {
        var rowLink = MuninnApi.createElement('a', 'list-group-item list-group-item-action d-flex align-items-start gap-3 py-3');
        rowLink.href = 'messages.php?id=' + encodeURIComponent(conversation.id);
        var rowIcon = MuninnApi.createElement('i', 'bi ' + (conversation.status === 'closed' ? 'bi-archive' : 'bi-envelope') + ' fs-5');
        rowIcon.setAttribute('aria-hidden', 'true');
        rowLink.appendChild(rowIcon);

        var textColumn = MuninnApi.createElement('div', 'flex-grow-1 text-break');
        textColumn.appendChild(MuninnApi.createElement('div', conversation.unread ? 'fw-semibold' : '', conversation.excerpt));
        textColumn.appendChild(MuninnApi.createElement(
            'div',
            'small text-muted-brand',
            statusText(conversation) + ' · ' + MuninnApi.formatDateTime(conversation.last_message_at)
        ));
        rowLink.appendChild(textColumn);

        if (conversation.unread) {
            var newBadge = MuninnApi.createElement('span', 'badge rounded-pill muninn-unread-badge', 'New');
            newBadge.appendChild(MuninnApi.createElement('span', 'visually-hidden', ' answer from the administrator'));
            rowLink.appendChild(newBadge);
        }
        return rowLink;
    }

    async function showConversationList() {
        var listError = document.getElementById('conversation-list-error');
        var conversationList = document.getElementById('conversation-list');
        MuninnApi.showAlert(listError, '');
        try {
            var listData = await MuninnApi.request('GET', '/api/v1/admin-messages/conversations');
            conversationList.replaceChildren();
            listData.conversations.forEach(function (conversation) {
                conversationList.appendChild(buildConversationRow(conversation));
            });
            conversationList.classList.toggle('d-none', listData.conversations.length === 0);
            document.getElementById('conversation-list-empty').classList.toggle('d-none', listData.conversations.length > 0);
            document.getElementById('conversation-retention-note').textContent =
                'Conversations are deleted ' + listData.retention_days + ' days after their newest message.';
        } catch (loadError) {
            MuninnApi.showAlert(listError, loadError.message);
        }
        listView.classList.remove('d-none');
        pageContent.classList.remove('d-none');
    }

    /* ---------- One conversation ---------- */

    /** Draws a conversation from the API's {conversation, messages} answer. */
    function showConversationData(conversationData) {
        var conversation = conversationData.conversation;
        var metaParts = ['Started ' + MuninnApi.formatDateTime(conversation.created_at)];
        if (conversation.contact_details !== '') {
            metaParts.push('you can also be reached at ' + conversation.contact_details);
        }
        document.getElementById('conversation-meta').textContent = metaParts.join(' · ');

        MuninnMessageThread.renderMessages(messageList, conversationData.messages);
        replyForm.classList.toggle('d-none', !conversation.can_reply);
        closedNote.classList.toggle('d-none', conversation.can_reply);
        // The badge in the top bar follows: opening the conversation marked it read.
        document.dispatchEvent(new CustomEvent('muninn:messages-read'));
    }

    /** Sends a reply and redraws the conversation with it. */
    async function sendReply(replyText) {
        try {
            var updatedData = await MuninnApi.request(
                'POST',
                '/api/v1/admin-messages/conversations/' + encodeURIComponent(openConversationId) + '/messages',
                { message: replyText }
            );
            showConversationData(updatedData);
        } catch (replyError) {
            // Closed meanwhile: show it as closed instead of only an error.
            if (replyError.code === 'conversation_closed') {
                replyForm.classList.add('d-none');
                closedNote.classList.remove('d-none');
            }
            throw replyError;
        }
    }

    async function showConversation() {
        conversationView.classList.remove('d-none');
        pageContent.classList.remove('d-none');
        var conversationData;
        try {
            conversationData = await MuninnApi.request('GET', '/api/v1/admin-messages/conversations/' + encodeURIComponent(openConversationId));
        } catch (loadError) {
            MuninnApi.showAlert(conversationError, loadError.status === 404 ? 'This conversation does not exist.' : loadError.message);
            return;
        }
        showConversationData(conversationData);
        MuninnMessageThread.wireReplyForm(
            {
                form: replyForm,
                input: document.getElementById('reply-input'),
                button: document.getElementById('reply-send-button'),
                feedback: document.getElementById('reply-feedback'),
                counter: document.getElementById('reply-character-count'),
            },
            conversationData.limits.message_max_length,
            sendReply
        );
        // The newest message is at the bottom; bring it and the reply box into view.
        conversationView.scrollIntoView({ block: 'end' });
    }

    document.addEventListener('muninn:user-ready', function (readyEvent) {
        if (readyEvent.detail.is_system_admin) {
            document.getElementById('messages-not-available').classList.remove('d-none');
            return;
        }
        if (openConversationId) {
            showConversation();
        } else {
            showConversationList();
            // A message sent from the dialog on this page appears in the list at once.
            document.addEventListener('muninn:admin-message-sent', showConversationList);
        }
    });
})();
