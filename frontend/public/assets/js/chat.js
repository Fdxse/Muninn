/*
 * Chat (D062): the list of chats, and one conversation that stays up to date by asking the API
 * every few seconds for what changed (new and deleted messages) while the page is visible.
 *
 * Messages are plain text and are always inserted as text, never as HTML.
 */
(function () {
    'use strict';

    // The page shows itself once it knows what to show.
    document.body.setAttribute('data-shell-hold', '');

    /** How often an open, visible chat asks for new messages. */
    var POLL_INTERVAL_MILLISECONDS = 10000;
    /** How close to the bottom (in pixels) still counts as "reading the newest messages". */
    var NEAR_BOTTOM_PIXELS = 80;

    var pageContent = document.getElementById('shell-content');
    var notAvailableAlert = document.getElementById('chat-not-available');
    var channelListView = document.getElementById('channel-list-view');
    var conversationView = document.getElementById('conversation-view');

    var chatLog = document.getElementById('chat-log');
    var messageList = document.getElementById('chat-messages');
    var emptyNotice = document.getElementById('chat-empty');
    var loadEarlierButton = document.getElementById('load-earlier-button');
    var conversationError = document.getElementById('conversation-error');
    var chatForm = document.getElementById('chat-form');
    var chatInput = document.getElementById('chat-input');
    var sendButton = document.getElementById('chat-send-button');
    var inputFeedback = document.getElementById('chat-input-feedback');
    var characterCount = document.getElementById('chat-character-count');

    /** State of the open conversation. */
    var messagesPath = null;
    var pollCursor = null;
    var pollTimer = null;
    var pollInFlight = false;
    var conversationClosed = false;
    var messageMaxLength = 2000;
    /** Message ID → its element, so polls never add a message twice. */
    var messageElementsById = new Map();

    /* ---------- The list of chats ---------- */

    /** One row of the chat list. */
    function buildChannelRow(channelHref, iconName, channelName, detailText) {
        var channelLink = MuninnApi.createElement('a', 'list-group-item list-group-item-action d-flex align-items-center gap-3 py-3');
        channelLink.href = channelHref;
        var channelIcon = MuninnApi.createElement('i', 'bi ' + iconName + ' fs-5');
        channelIcon.setAttribute('aria-hidden', 'true');
        channelLink.appendChild(channelIcon);
        var textColumn = MuninnApi.createElement('div', 'flex-grow-1 text-break');
        textColumn.appendChild(MuninnApi.createElement('div', 'fw-semibold', channelName));
        textColumn.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', detailText));
        channelLink.appendChild(textColumn);
        return channelLink;
    }

    async function showChannelList() {
        var listError = document.getElementById('channel-list-error');
        var channelList = document.getElementById('channel-list');
        try {
            var overview = await MuninnApi.request('GET', '/api/v1/chat');
            channelList.replaceChildren();
            if (overview.global.can_read) {
                channelList.appendChild(buildChannelRow(
                    'chat.php?channel=global',
                    'bi-megaphone',
                    'Everyone',
                    overview.global.can_write ? 'Shout out to all users' : 'Read what is shouted out to all users'
                ));
            }
            overview.workspaces.forEach(function (workspaceChannel) {
                channelList.appendChild(buildChannelRow(
                    'chat.php?workspace=' + encodeURIComponent(workspaceChannel.workspace_id),
                    'bi-people',
                    workspaceChannel.name,
                    MuninnApi.roleLabel(workspaceChannel.your_role) + (workspaceChannel.can_write ? '' : ' · read only')
                ));
            });
            document.getElementById('channel-list-empty').classList.toggle('d-none', overview.workspaces.length > 0);
            document.getElementById('chat-retention-note').textContent =
                'Messages are deleted after ' + overview.limits.retention_days + ' days. Administrators cannot read them.';
        } catch (loadError) {
            MuninnApi.showAlert(listError, loadError.message);
        }
        channelListView.classList.remove('d-none');
        pageContent.classList.remove('d-none');
    }

    /* ---------- One conversation ---------- */

    /** Short time for today's messages, date and time for older ones. */
    function formatMessageTime(isoTimestamp) {
        var messageDate = new Date(isoTimestamp);
        var isToday = messageDate.toDateString() === new Date().toDateString();
        return isToday
            ? messageDate.toLocaleTimeString(undefined, { timeStyle: 'short' })
            : MuninnApi.formatDateTime(isoTimestamp);
    }

    function isNearBottom() {
        return chatLog.scrollHeight - chatLog.scrollTop - chatLog.clientHeight < NEAR_BOTTOM_PIXELS;
    }

    function scrollToBottom() {
        chatLog.scrollTop = chatLog.scrollHeight;
    }

    function updateEmptyNotice() {
        emptyNotice.classList.toggle('d-none', messageElementsById.size > 0);
    }

    /** Builds the element for one message. */
    function buildMessageElement(chatMessage) {
        var messageElement = MuninnApi.createElement('div', 'muninn-chat-message' + (chatMessage.is_own ? ' is-own' : ''));
        messageElement.dataset.messageId = chatMessage.id;

        var headerRow = MuninnApi.createElement('div', 'd-flex align-items-baseline gap-2 small');
        headerRow.appendChild(MuninnApi.createElement('span', 'fw-semibold text-break', chatMessage.is_own ? 'You' : chatMessage.author.display_name));
        var timeElement = MuninnApi.createElement('time', 'text-muted-brand', formatMessageTime(chatMessage.created_at));
        timeElement.dateTime = chatMessage.created_at;
        headerRow.appendChild(timeElement);

        if (chatMessage.can_delete) {
            var deleteButton = MuninnApi.createElement('button', 'btn btn-link btn-sm text-muted-brand p-0 ms-auto muninn-chat-delete');
            deleteButton.type = 'button';
            deleteButton.setAttribute('aria-label', chatMessage.is_own
                ? 'Delete your message'
                : 'Delete message from ' + chatMessage.author.display_name);
            var deleteIcon = MuninnApi.createElement('i', 'bi bi-trash');
            deleteIcon.setAttribute('aria-hidden', 'true');
            deleteButton.appendChild(deleteIcon);
            deleteButton.addEventListener('click', function () {
                deleteMessage(chatMessage, deleteButton);
            });
            headerRow.appendChild(deleteButton);
        }
        messageElement.appendChild(headerRow);

        messageElement.appendChild(MuninnApi.createElement('p', 'muninn-chat-body mb-0', chatMessage.body));

        return messageElement;
    }

    /** Adds a message at the end, unless it is already shown. */
    function appendMessage(chatMessage) {
        if (messageElementsById.has(chatMessage.id)) {
            return;
        }
        var messageElement = buildMessageElement(chatMessage);
        messageElementsById.set(chatMessage.id, messageElement);
        messageList.appendChild(messageElement);
    }

    /** Takes a deleted message off the screen. */
    function removeMessage(messageId) {
        var messageElement = messageElementsById.get(messageId);
        if (messageElement) {
            messageElement.remove();
            messageElementsById.delete(messageId);
        }
    }

    /** Replaces everything shown with the newest page from the API. */
    async function loadLatest() {
        var latestPage = await MuninnApi.request('GET', messagesPath);
        messageList.replaceChildren();
        messageElementsById.clear();
        latestPage.messages.forEach(appendMessage);
        loadEarlierButton.classList.toggle('d-none', !latestPage.has_older);
        pollCursor = latestPage.cursor;
        updateEmptyNotice();
        scrollToBottom();
        return latestPage;
    }

    /** Shows the page before the oldest message shown, keeping the reader's place. */
    async function loadEarlier() {
        var oldestElement = messageList.firstElementChild;
        if (!oldestElement) {
            return;
        }
        loadEarlierButton.disabled = true;
        try {
            var earlierPage = await MuninnApi.request('GET', messagesPath + '?before=' + encodeURIComponent(oldestElement.dataset.messageId));
            var heightBefore = chatLog.scrollHeight;
            var earlierFragment = document.createDocumentFragment();
            earlierPage.messages.forEach(function (chatMessage) {
                if (!messageElementsById.has(chatMessage.id)) {
                    var messageElement = buildMessageElement(chatMessage);
                    messageElementsById.set(chatMessage.id, messageElement);
                    earlierFragment.appendChild(messageElement);
                }
            });
            messageList.insertBefore(earlierFragment, oldestElement);
            // Keep the message the reader was looking at in the same place.
            chatLog.scrollTop += chatLog.scrollHeight - heightBefore;
            loadEarlierButton.classList.toggle('d-none', !earlierPage.has_older);
        } catch (loadError) {
            MuninnApi.showAlert(conversationError, loadError.message);
        } finally {
            loadEarlierButton.disabled = false;
        }
    }

    /** Stops polling and explains why, when the chat is no longer open to this user. */
    function closeConversation(explanation) {
        conversationClosed = true;
        window.clearTimeout(pollTimer);
        chatForm.classList.add('d-none');
        MuninnApi.showAlert(conversationError, explanation);
    }

    /** Asks for what changed since the last look, and shows it. */
    async function pollForChanges() {
        if (pollInFlight || conversationClosed || document.hidden || pollCursor === null) {
            return;
        }
        pollInFlight = true;
        try {
            var changes = await MuninnApi.request('GET', messagesPath + '?since=' + encodeURIComponent(pollCursor));
            var wasNearBottom = isNearBottom();
            if (changes.reset) {
                // Too much changed while this screen slept: start again from the newest page.
                await loadLatest();
                return;
            }
            changes.messages.forEach(function (chatMessage) {
                if (chatMessage.is_deleted) {
                    removeMessage(chatMessage.id);
                } else {
                    appendMessage(chatMessage);
                }
            });
            pollCursor = changes.cursor;
            updateEmptyNotice();
            if (wasNearBottom) {
                scrollToBottom();
            }
        } catch (pollError) {
            if (pollError.status === 401) {
                MuninnApi.goTo('login.php');
            } else if (pollError.status === 403 || pollError.status === 404) {
                closeConversation('You no longer have access to this chat.');
            }
            // Network trouble: simply try again on the next round.
        } finally {
            pollInFlight = false;
        }
    }

    /** Polls every few seconds; a hidden tab skips its rounds and catches up when shown again. */
    function schedulePoll() {
        window.clearTimeout(pollTimer);
        if (conversationClosed) {
            return;
        }
        pollTimer = window.setTimeout(async function () {
            await pollForChanges();
            schedulePoll();
        }, POLL_INTERVAL_MILLISECONDS);
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && messagesPath !== null) {
            pollForChanges().then(schedulePoll);
        }
    });

    async function deleteMessage(chatMessage, deleteButton) {
        var confirmText = chatMessage.is_own ? 'Delete your message?' : 'Delete this message from ' + chatMessage.author.display_name + '?';
        if (!window.confirm(confirmText)) {
            return;
        }
        deleteButton.disabled = true;
        try {
            await MuninnApi.request('DELETE', '/api/v1/chat/messages/' + encodeURIComponent(chatMessage.id));
            removeMessage(chatMessage.id);
            updateEmptyNotice();
        } catch (deleteError) {
            // Already deleted elsewhere: it is gone either way.
            if (deleteError.status === 404) {
                removeMessage(chatMessage.id);
                updateEmptyNotice();
                return;
            }
            MuninnApi.showAlert(conversationError, deleteError.message);
            deleteButton.disabled = false;
        }
    }

    function updateCharacterCount() {
        var typedLength = chatInput.value.length;
        // Only worth showing once the limit comes into view.
        characterCount.textContent = typedLength > messageMaxLength - 200 ? typedLength + ' / ' + messageMaxLength : '';
    }

    async function sendMessage() {
        var messageText = chatInput.value;
        if (messageText.trim() === '') {
            return;
        }
        inputFeedback.textContent = '';
        sendButton.disabled = true;
        try {
            var sendResult = await MuninnApi.request('POST', messagesPath, { body: messageText });
            chatInput.value = '';
            updateCharacterCount();
            appendMessage(sendResult.message);
            updateEmptyNotice();
            scrollToBottom();
        } catch (sendError) {
            if (sendError.status === 403 || sendError.status === 404) {
                closeConversation('You can no longer write in this chat.');
                return;
            }
            inputFeedback.textContent = (sendError.fields && sendError.fields.body) || sendError.message;
        } finally {
            sendButton.disabled = false;
            chatInput.focus();
        }
    }

    chatForm.addEventListener('submit', function (submitEvent) {
        submitEvent.preventDefault();
        sendMessage();
    });

    // Enter sends and Shift+Enter starts a new line, except on touch screens, where Enter is a new line.
    var hasTouchScreen = window.matchMedia('(pointer: coarse)').matches;
    chatInput.addEventListener('keydown', function (keyEvent) {
        if (keyEvent.key === 'Enter' && !keyEvent.shiftKey && !keyEvent.isComposing && !hasTouchScreen) {
            keyEvent.preventDefault();
            sendMessage();
        }
    });
    chatInput.addEventListener('input', updateCharacterCount);
    loadEarlierButton.addEventListener('click', loadEarlier);

    async function showConversation(channelPath) {
        messagesPath = channelPath;
        try {
            var latestPage;
            // The page must be visible to measure and scroll the message list.
            conversationView.classList.remove('d-none');
            pageContent.classList.remove('d-none');
            latestPage = await loadLatest();

            var channel = latestPage.channel;
            document.getElementById('conversation-heading').textContent = channel.kind === 'global' ? 'Everyone' : channel.name;
            document.title = (channel.kind === 'global' ? 'Everyone' : channel.name) + ' · Chat · Muninn';
            if (channel.can_write) {
                chatForm.classList.remove('d-none');
            } else {
                var readOnlyNote = document.getElementById('conversation-read-only');
                readOnlyNote.textContent = channel.kind === 'global'
                    ? 'Only users the administrator allows can write here.'
                    : 'Readers can read this chat but not write in it.';
                readOnlyNote.classList.remove('d-none');
            }
            schedulePoll();
        } catch (loadError) {
            conversationClosed = true;
            MuninnApi.showAlert(conversationError, loadError.status === 404 || loadError.status === 403
                ? 'This chat is not available.'
                : loadError.message);
        }
    }

    document.addEventListener('muninn:user-ready', async function (readyEvent) {
        var currentUser = readyEvent.detail;
        if (currentUser.is_system_admin || currentUser.chat_access === 'off') {
            notAvailableAlert.classList.remove('d-none');
            return;
        }

        var workspaceId = MuninnApi.queryParameter('workspace');
        if (MuninnApi.queryParameter('channel') === 'global') {
            await showConversation('/api/v1/chat/global/messages');
        } else if (workspaceId) {
            await showConversation('/api/v1/workspaces/' + encodeURIComponent(workspaceId) + '/chat/messages');
        } else {
            await showChannelList();
            return;
        }

        // The limits come with the chat list; fetch them once for the hint under the input.
        try {
            var overview = await MuninnApi.request('GET', '/api/v1/chat');
            messageMaxLength = overview.limits.message_max_length;
            chatInput.maxLength = messageMaxLength;
            document.getElementById('chat-retention-days').textContent = String(overview.limits.retention_days);
        } catch (overviewError) {
            // The hint keeps its default text.
        }
    });
})();
