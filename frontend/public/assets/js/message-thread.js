/*
 * Shared by "My messages" (messages.js) and the administrator's inbox (admin-messages.js), D065:
 * drawing a conversation's messages and wiring its reply box.
 *
 * Messages are plain text and are always inserted as text, never as HTML.
 */
(function () {
    'use strict';

    /** Short time for today's messages, date and time for older ones. */
    function formatMessageTime(isoTimestamp) {
        var messageDate = new Date(isoTimestamp);
        var isToday = messageDate.toDateString() === new Date().toDateString();
        return isToday
            ? messageDate.toLocaleTimeString(undefined, { timeStyle: 'short' })
            : MuninnApi.formatDateTime(isoTimestamp);
    }

    /**
     * Replaces the contents of listElement with one bubble per message, oldest first. The
     * viewer's own messages sit on the right, like in chat.
     */
    function renderMessages(listElement, messages) {
        listElement.replaceChildren();
        messages.forEach(function (threadMessage) {
            var messageElement = MuninnApi.createElement('div', 'muninn-chat-message' + (threadMessage.is_own ? ' is-own' : ''));

            var headerRow = MuninnApi.createElement('div', 'd-flex align-items-baseline gap-2 small');
            headerRow.appendChild(MuninnApi.createElement('span', 'fw-semibold text-break', threadMessage.is_own ? 'You' : threadMessage.author_name));
            var timeElement = MuninnApi.createElement('time', 'text-muted-brand', formatMessageTime(threadMessage.created_at));
            timeElement.dateTime = threadMessage.created_at;
            headerRow.appendChild(timeElement);
            messageElement.appendChild(headerRow);

            messageElement.appendChild(MuninnApi.createElement('div', 'muninn-chat-body', threadMessage.body));
            listElement.appendChild(messageElement);
        });
    }

    /**
     * Wires a reply box: a live character count, Enter adds a line (the Send button sends), and
     * sending calls sendReply(text). sendReply resolves when the reply is stored and rejects with
     * the API error otherwise; the box is cleared only after success.
     *
     * @param {Object} replyParts {form, input, button, feedback, counter}
     * @param {number} maximumLength Longest message in characters.
     * @param {function(string): Promise} sendReply
     */
    function wireReplyForm(replyParts, maximumLength, sendReply) {
        replyParts.input.maxLength = maximumLength;

        function updateCounter() {
            var typedLength = replyParts.input.value.length;
            // Only shown near the limit, so it does not distract while typing short answers.
            replyParts.counter.textContent = typedLength > maximumLength * 0.8 ? typedLength + ' / ' + maximumLength : '';
        }
        replyParts.input.addEventListener('input', updateCounter);

        replyParts.form.addEventListener('submit', async function (submitEvent) {
            submitEvent.preventDefault();
            var replyText = replyParts.input.value.trim();
            replyParts.feedback.textContent = '';
            if (replyText === '') {
                replyParts.feedback.textContent = 'Write a message.';
                replyParts.input.focus();
                return;
            }
            replyParts.button.disabled = true;
            try {
                await sendReply(replyText);
                replyParts.input.value = '';
                updateCounter();
            } catch (sendError) {
                replyParts.feedback.textContent = sendError.status === 429
                    ? 'You have sent the most messages allowed this hour. Please wait a while.'
                    : (sendError.fields && sendError.fields.message) || sendError.message;
            } finally {
                replyParts.button.disabled = false;
            }
        });
    }

    window.MuninnMessageThread = {
        formatMessageTime: formatMessageTime,
        renderMessages: renderMessages,
        wireReplyForm: wireReplyForm,
    };
})();
