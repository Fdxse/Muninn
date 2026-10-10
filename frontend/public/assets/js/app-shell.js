/*
 * Shared behaviour of signed-in pages: confirm the session, show the user in the navigation,
 * wire the sign-out button, and reveal the page. Signed-out visitors go to the sign-in page.
 *
 * Pages can listen for the "muninn:user-ready" event (detail = the user) to load their own data.
 */
(function () {
    'use strict';

    var loadingIndicator = document.getElementById('shell-loading');
    var pageContent = document.getElementById('shell-content');

    // Mark the navigation link of the current page, for screen readers and as a visual cue.
    document.querySelectorAll('.muninn-navbar .nav-link').forEach(function (navigationLink) {
        if (navigationLink.pathname === window.location.pathname) {
            navigationLink.setAttribute('aria-current', 'page');
            navigationLink.classList.add('active');
        }
    });

    document.getElementById('nav-logout-button').addEventListener('click', function () {
        MuninnApi.signOut();
    });

    /**
     * Shows how many invitation requests wait for the administrator's decision on the
     * "Invitations" link, or hides the badge when there are none. A failure only hides the
     * badge: it is a hint, and the Invitations page itself always lists every request.
     */
    async function refreshInvitationRequestsBadge() {
        var invitationsBadge = document.getElementById('nav-admin-invitations-badge');
        try {
            var countData = await MuninnApi.request('GET', '/api/v1/admin/invitation-requests/pending-count');
            var pendingCount = countData.pending_count;
            // Screen readers hear "Invitations 2 waiting for a decision" instead of a bare number.
            invitationsBadge.replaceChildren(
                document.createTextNode(String(pendingCount)),
                MuninnApi.createElement('span', 'visually-hidden', ' waiting for a decision')
            );
            invitationsBadge.classList.toggle('d-none', pendingCount === 0);
        } catch (countError) {
            invitationsBadge.classList.add('d-none');
        }
    }

    /**
     * Shows how many requests to join a Shared Workspace wait for a decision (D067) on the admin
     * "Workspaces" link, or hides the badge at zero. Like the Invitations badge it is only a hint.
     */
    async function refreshJoinRequestsBadge() {
        var workspacesBadge = document.getElementById('nav-admin-workspaces-badge');
        try {
            var countData = await MuninnApi.request('GET', '/api/v1/admin/workspace-join-requests/pending-count');
            showCount(workspacesBadge, countData.pending_count, ' waiting to join');
        } catch (countError) {
            workspacesBadge.classList.add('d-none');
        }
    }

    /*
     * Unread chat messages (D063): a count on the Chat link, refreshed every minute while the page
     * is visible, when it becomes visible again, and whenever the chat page has shown messages.
     */
    var CHAT_BADGE_INTERVAL_MILLISECONDS = 60000;
    var chatBadgeTimer = null;

    /** Shows the unread count on the Chat link (99+ above 99), or hides it at 0 or on failure. */
    async function refreshChatBadge() {
        var chatBadge = document.getElementById('nav-chat-badge');
        try {
            var unreadData = await MuninnApi.request('GET', '/api/v1/chat/unread');
            var unreadCount = unreadData.total_unread;
            chatBadge.replaceChildren(
                document.createTextNode(unreadCount > 99 ? '99+' : String(unreadCount)),
                MuninnApi.createElement('span', 'visually-hidden', unreadCount === 1 ? ' unread message' : ' unread messages')
            );
            chatBadge.classList.toggle('d-none', unreadCount === 0);
        } catch (unreadError) {
            chatBadge.classList.add('d-none');
        }
    }

    /** Starts the once-a-minute refresh; a hidden tab skips it and refreshes when shown again. */
    function startChatBadge() {
        refreshChatBadge();
        chatBadgeTimer = window.setInterval(function () {
            if (!document.hidden) {
                refreshChatBadge();
            }
        }, CHAT_BADGE_INTERVAL_MILLISECONDS);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                refreshChatBadge();
            }
        });
        // The chat page marks messages as seen when it shows them.
        document.addEventListener('muninn:chat-read', refreshChatBadge);
    }

    /*
     * Messages between users and the administrator (D065). Everyday users see a count of unread
     * answers on the "Contact admin" button and in its dialog; administrators see a count of
     * conversations with something new on the Messages link. Refreshed once a minute while the
     * page is visible, like the chat badge, and whenever a messages page has opened a conversation.
     */
    var MESSAGES_BADGE_INTERVAL_MILLISECONDS = 60000;
    var messagesBadgeTimer = null;
    /** The API path that counts unread conversations for this account; set once the user is known. */
    var messagesUnreadPath = null;

    /** Fills a badge with a count (99+ above 99) and a screen-reader text, or hides it at 0. */
    function showCount(badgeElement, unreadCount, screenReaderText) {
        if (!badgeElement) {
            return;
        }
        badgeElement.replaceChildren(
            document.createTextNode(unreadCount > 99 ? '99+' : String(unreadCount)),
            MuninnApi.createElement('span', 'visually-hidden', screenReaderText)
        );
        badgeElement.classList.toggle('d-none', unreadCount === 0);
    }

    /** Asks the API how many conversations have something unread and updates the badges. */
    async function refreshMessagesBadge() {
        var badgeIds = ['nav-contact-admin-badge', 'contact-admin-unread-badge', 'nav-admin-messages-badge'];
        try {
            var unreadData = await MuninnApi.request('GET', messagesUnreadPath);
            var unreadCount = unreadData.unread_count;
            var screenReaderText = unreadCount === 1 ? ' unread conversation' : ' unread conversations';
            badgeIds.forEach(function (badgeId) {
                showCount(document.getElementById(badgeId), unreadCount, screenReaderText);
            });
        } catch (unreadError) {
            // A hint only: the messages pages always show everything.
            badgeIds.forEach(function (badgeId) {
                var badgeElement = document.getElementById(badgeId);
                if (badgeElement) {
                    badgeElement.classList.add('d-none');
                }
            });
        }
    }

    /** Starts the once-a-minute refresh for the given count endpoint. */
    function startMessagesBadge(unreadPath) {
        messagesUnreadPath = unreadPath;
        refreshMessagesBadge();
        messagesBadgeTimer = window.setInterval(function () {
            if (!document.hidden) {
                refreshMessagesBadge();
            }
        }, MESSAGES_BADGE_INTERVAL_MILLISECONDS);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                refreshMessagesBadge();
            }
        });
        // The messages pages mark a conversation as read when they open it.
        document.addEventListener('muninn:messages-read', refreshMessagesBadge);
    }

    /*
     * "Something is waiting on the admin side" (D066). The administrator picks one everyday
     * account; while admin work waits, that account sees a gold shield in the top bar. Checked once
     * a minute while the page is visible and when the tab becomes visible again. Every other
     * account is told it is not the recipient, and stops asking until the next page load.
     */
    var ADMIN_ATTENTION_INTERVAL_MILLISECONDS = 60000;
    var adminAttentionTimer = null;

    /** Shows or hides the shield; a failure hides it, since the icon is only a hint. */
    async function refreshAdminAttention() {
        var attentionItem = document.getElementById('nav-admin-attention-item');
        try {
            var attentionData = await MuninnApi.request('GET', '/api/v1/admin-attention');
            attentionItem.classList.toggle('d-none', !attentionData.needs_attention);
            if (!attentionData.is_recipient && adminAttentionTimer !== null) {
                window.clearInterval(adminAttentionTimer);
                adminAttentionTimer = null;
                document.removeEventListener('visibilitychange', refreshAdminAttentionWhenShown);
            }
        } catch (attentionError) {
            attentionItem.classList.add('d-none');
        }
    }

    function refreshAdminAttentionWhenShown() {
        if (!document.hidden) {
            refreshAdminAttention();
        }
    }

    /** Starts the once-a-minute check; a hidden tab skips it and checks when shown again. */
    function startAdminAttention() {
        adminAttentionTimer = window.setInterval(refreshAdminAttentionWhenShown, ADMIN_ATTENTION_INTERVAL_MILLISECONDS);
        document.addEventListener('visibilitychange', refreshAdminAttentionWhenShown);
        refreshAdminAttention();
    }

    // "Sign out to switch" in the shield's dialog: the administrator account signs in separately.
    var adminAttentionSignOutButton = document.getElementById('admin-attention-sign-out-button');
    if (adminAttentionSignOutButton) {
        adminAttentionSignOutButton.addEventListener('click', function () {
            MuninnApi.signOut();
        });
    }

    // The Invitations page announces approvals and declines, so the badge follows along.
    document.addEventListener('muninn:invitation-requests-changed', refreshInvitationRequestsBadge);
    // The admin Workspaces page announces join request decisions (D067).
    document.addEventListener('muninn:workspace-join-requests-changed', refreshJoinRequestsBadge);

    /*
     * "Contact admin" (D058): everyday users write a short message to the administrator. It starts
     * a conversation the user follows under My messages (D065), and ntfy pushes it to the
     * administrator's phone. The dialog's markup comes from includes/page.php.
     */
    var contactAdminModal = document.getElementById('contact-admin-modal');
    var contactAdminForm = document.getElementById('contact-admin-form');
    var contactAdminMessageInput = document.getElementById('contact-admin-message');
    var contactAdminContactInput = document.getElementById('contact-admin-contact');
    var contactAdminSendButton = document.getElementById('contact-admin-send-button');
    var contactAdminErrorAlert = document.getElementById('contact-admin-error');
    var contactAdminSuccessAlert = document.getElementById('contact-admin-success');
    var contactAdminFieldIds = { message: 'contact-admin-message', contact: 'contact-admin-contact' };

    /**
     * Shows new answers, and explains before anything is typed when the hourly limit is reached.
     */
    async function prepareContactAdminDialog() {
        MuninnApi.showAlert(contactAdminErrorAlert, '');
        contactAdminSuccessAlert.classList.add('d-none');
        MuninnApi.showFieldErrors(contactAdminFieldIds, {});
        contactAdminSendButton.disabled = false;
        try {
            var messageStatus = await MuninnApi.request('GET', '/api/v1/admin-messages');
            showCount(
                document.getElementById('contact-admin-unread-badge'),
                messageStatus.unread_count,
                messageStatus.unread_count === 1 ? ' new answer' : ' new answers'
            );
            if (messageStatus.remaining_this_hour === 0) {
                MuninnApi.showAlert(contactAdminErrorAlert, 'You have sent ' + messageStatus.max_per_hour + ' messages this hour. Please wait before sending another.');
                contactAdminSendButton.disabled = true;
            }
        } catch (statusError) {
            // Sending will report the real problem; nothing to block here.
        }
    }

    if (contactAdminModal) {
        contactAdminModal.addEventListener('show.bs.modal', prepareContactAdminDialog);
        contactAdminModal.addEventListener('shown.bs.modal', function () {
            if (!contactAdminSendButton.disabled) {
                contactAdminMessageInput.focus();
            }
        });

        contactAdminForm.addEventListener('submit', async function (submitEvent) {
            submitEvent.preventDefault();
            MuninnApi.showAlert(contactAdminErrorAlert, '');
            contactAdminSuccessAlert.classList.add('d-none');
            MuninnApi.showFieldErrors(contactAdminFieldIds, {});

            contactAdminSendButton.disabled = true;
            var keepButtonDisabled = false;
            try {
                var sendResult = await MuninnApi.request('POST', '/api/v1/admin-messages', {
                    message: contactAdminMessageInput.value,
                    contact: contactAdminContactInput.value,
                });
                // Keep the contact details: the same person usually wants the same answer route.
                contactAdminMessageInput.value = '';
                // "Sent." plus a link to the new conversation, where the answer will appear.
                var conversationLink = MuninnApi.createElement('a', 'alert-link', 'My messages');
                conversationLink.href = document.getElementById('contact-admin-my-messages-link').getAttribute('href')
                    + '?id=' + encodeURIComponent(sendResult.conversation_id);
                contactAdminSuccessAlert.replaceChildren(
                    document.createTextNode('Sent. The administrator will answer under '),
                    conversationLink,
                    document.createTextNode('.')
                );
                contactAdminSuccessAlert.classList.remove('d-none');
                // The My messages page lists the new conversation straight away.
                document.dispatchEvent(new CustomEvent('muninn:admin-message-sent', { detail: sendResult }));
                contactAdminSuccessAlert.focus();
                keepButtonDisabled = sendResult.remaining_this_hour === 0;
            } catch (sendError) {
                MuninnApi.showFieldErrors(contactAdminFieldIds, sendError.fields || {});
                MuninnApi.showAlert(
                    contactAdminErrorAlert,
                    sendError.code === 'validation_failed' ? 'Please correct the highlighted fields.'
                        : sendError.status === 429 ? 'You have sent the most messages allowed this hour. Please wait before sending another.'
                        : sendError.message
                );
                keepButtonDisabled = sendError.status === 429;
            } finally {
                contactAdminSendButton.disabled = keepButtonDisabled;
            }
        });
    }


    /*
     * Broadcast messages from the administrator (D061), shown under the top bar:
     *   once   shown on one page, then the API is told it was seen and it never comes back;
     *   sticky shown on every page until the user closes it with its X;
     *   vote   shown until the user has voted.
     * The text is the administrator's plain text and is always inserted as text, never as HTML.
     */
    var broadcastArea = document.getElementById('broadcast-area');

    /** Hides the area again once its last message is gone. */
    function removeBroadcastElement(broadcastElement) {
        broadcastElement.remove();
        broadcastArea.classList.toggle('d-none', broadcastArea.childElementCount === 0);
    }

    /** An X button that runs onClose. */
    function createCloseButton(onClose) {
        var closeButton = MuninnApi.createElement('button', 'btn-close flex-shrink-0');
        closeButton.type = 'button';
        closeButton.setAttribute('aria-label', 'Close this message');
        closeButton.addEventListener('click', onClose);
        return closeButton;
    }

    /** A one-time or sticky banner. */
    function buildBroadcastBanner(broadcast) {
        var isSticky = broadcast.kind === 'sticky';
        var bannerElement = MuninnApi.createElement('div', 'alert ' + (isSticky ? 'alert-warning' : 'alert-info') + ' d-flex align-items-start gap-2 mb-2');
        bannerElement.setAttribute('role', 'status');
        bannerElement.appendChild(MuninnApi.createElement('i', 'bi ' + (isSticky ? 'bi-exclamation-triangle' : 'bi-megaphone') + ' flex-shrink-0 mt-1'));
        bannerElement.lastChild.setAttribute('aria-hidden', 'true');

        var textColumn = MuninnApi.createElement('div', 'flex-grow-1');
        textColumn.appendChild(MuninnApi.createElement('p', 'muninn-broadcast-message mb-0', broadcast.message));
        var closeErrorText = MuninnApi.createElement('p', 'small text-danger mb-0 d-none');
        textColumn.appendChild(closeErrorText);
        bannerElement.appendChild(textColumn);

        bannerElement.appendChild(createCloseButton(async function (clickEvent) {
            if (!isSticky) {
                // Already marked as seen when shown; closing only tidies this page.
                removeBroadcastElement(bannerElement);
                return;
            }
            // Kept in a variable: currentTarget is cleared once the click has been handled.
            var dismissButton = clickEvent.currentTarget;
            dismissButton.disabled = true;
            try {
                await MuninnApi.request('POST', '/api/v1/broadcasts/' + encodeURIComponent(broadcast.id) + '/dismiss');
                removeBroadcastElement(bannerElement);
            } catch (dismissError) {
                // A 404 means it ended meanwhile, so it would not come back anyway.
                if (dismissError.status === 404) {
                    removeBroadcastElement(bannerElement);
                    return;
                }
                closeErrorText.textContent = 'Could not close the message: ' + dismissError.message;
                closeErrorText.classList.remove('d-none');
                dismissButton.disabled = false;
            }
        }));

        return bannerElement;
    }

    /** A vote: the question, one radio button or checkbox per answer, and a Vote button. */
    function buildBroadcastVote(broadcast) {
        var voteCard = MuninnApi.createElement('div', 'card border-0 shadow-sm mb-2 muninn-broadcast-vote');
        var voteForm = MuninnApi.createElement('form', 'card-body');
        voteForm.noValidate = true;
        var questionId = 'broadcast-question-' + broadcast.id;
        var answerFieldset = MuninnApi.createElement('fieldset');
        answerFieldset.setAttribute('aria-describedby', questionId + '-hint');

        var questionLegend = MuninnApi.createElement('legend', 'fs-6 fw-semibold d-flex gap-2 mb-1');
        var questionIcon = MuninnApi.createElement('i', 'bi bi-check2-square');
        questionIcon.setAttribute('aria-hidden', 'true');
        questionLegend.appendChild(questionIcon);
        questionLegend.appendChild(MuninnApi.createElement('span', 'muninn-broadcast-message', broadcast.message));
        answerFieldset.appendChild(questionLegend);
        answerFieldset.appendChild(MuninnApi.createElement(
            'p',
            'small text-muted-brand mb-2',
            broadcast.allows_multiple_choices ? 'Choose one or more answers.' : 'Choose one answer.'
        ));
        answerFieldset.lastChild.id = questionId + '-hint';

        var answerInputs = [];
        broadcast.options.forEach(function (option, optionIndex) {
            var answerRow = MuninnApi.createElement('div', 'form-check');
            var answerInput = MuninnApi.createElement('input', 'form-check-input');
            answerInput.type = broadcast.allows_multiple_choices ? 'checkbox' : 'radio';
            answerInput.name = questionId;
            answerInput.value = option.id;
            answerInput.id = questionId + '-answer-' + optionIndex;
            var answerLabel = MuninnApi.createElement('label', 'form-check-label flex-grow-1', option.label);
            answerLabel.htmlFor = answerInput.id;
            answerRow.appendChild(answerInput);
            answerRow.appendChild(answerLabel);
            answerFieldset.appendChild(answerRow);
            answerInputs.push(answerInput);
        });
        voteForm.appendChild(answerFieldset);

        var voteErrorText = MuninnApi.createElement('p', 'small text-danger mb-2 d-none');
        voteErrorText.setAttribute('role', 'alert');
        voteForm.appendChild(voteErrorText);
        var voteButton = MuninnApi.createElement('button', 'btn btn-primary', 'Vote');
        voteButton.type = 'submit';
        voteButton.disabled = true;
        voteForm.appendChild(voteButton);

        // The button only works once something is chosen.
        answerFieldset.addEventListener('change', function () {
            voteButton.disabled = !answerInputs.some(function (answerInput) { return answerInput.checked; });
        });

        voteForm.addEventListener('submit', async function (submitEvent) {
            submitEvent.preventDefault();
            var chosenOptionIds = answerInputs
                .filter(function (answerInput) { return answerInput.checked; })
                .map(function (answerInput) { return answerInput.value; });
            if (chosenOptionIds.length === 0) {
                return;
            }
            voteButton.disabled = true;
            voteErrorText.classList.add('d-none');
            try {
                await MuninnApi.request('POST', '/api/v1/broadcasts/' + encodeURIComponent(broadcast.id) + '/vote', { option_ids: chosenOptionIds });
                showVoteThanks(voteCard);
            } catch (voteError) {
                // Already voted (another tab) or the vote has ended: either way it is done here.
                if (voteError.status === 409 || voteError.status === 404) {
                    removeBroadcastElement(voteCard);
                    return;
                }
                voteErrorText.textContent = voteError.message;
                voteErrorText.classList.remove('d-none');
                voteButton.disabled = false;
            }
        });

        voteCard.appendChild(voteForm);
        return voteCard;
    }

    /** Replaces a vote with a short thank-you the user can close. */
    function showVoteThanks(voteCard) {
        var thanksElement = MuninnApi.createElement('div', 'alert alert-success d-flex align-items-start gap-2 mb-2');
        thanksElement.setAttribute('role', 'status');
        thanksElement.appendChild(MuninnApi.createElement('span', 'flex-grow-1', 'Thank you for voting.'));
        thanksElement.appendChild(createCloseButton(function () {
            removeBroadcastElement(thanksElement);
        }));
        voteCard.replaceWith(thanksElement);
    }

    /**
     * Loads and shows the broadcasts for this user. A failure shows nothing: the messages are
     * extras, and the page itself must keep working.
     */
    async function showBroadcasts() {
        if (!broadcastArea) {
            return;
        }
        var broadcastData;
        try {
            broadcastData = await MuninnApi.request('GET', '/api/v1/broadcasts');
        } catch (loadError) {
            return;
        }
        broadcastData.broadcasts.forEach(function (broadcast) {
            if (broadcast.kind === 'vote') {
                broadcastArea.appendChild(buildBroadcastVote(broadcast));
                return;
            }
            broadcastArea.appendChild(buildBroadcastBanner(broadcast));
            if (broadcast.kind === 'once') {
                // Shown now, so it will not be shown again; if this fails it simply shows once more.
                MuninnApi.request('POST', '/api/v1/broadcasts/' + encodeURIComponent(broadcast.id) + '/seen').catch(function () {});
            }
        });
        broadcastArea.classList.toggle('d-none', broadcastArea.childElementCount === 0);
    }

    MuninnApi.loadCurrentUser()
        .then(function (currentUserData) {
            var currentUser = currentUserData.user;
            document.getElementById('nav-user-name').textContent = currentUser.display_name;
            var homeName = document.getElementById('home-display-name');
            if (homeName) {
                homeName.textContent = currentUser.display_name;
            }
            // Convenience only: the API itself refuses admin calls from non-admins.
            document.getElementById('nav-admin-overview-item').classList.toggle('d-none', !currentUser.is_system_admin);
            document.getElementById('nav-admin-item').classList.toggle('d-none', !currentUser.is_system_admin);
            document.getElementById('nav-admin-users-item').classList.toggle('d-none', !currentUser.is_system_admin);
            document.getElementById('nav-admin-workspaces-item').classList.toggle('d-none', !currentUser.is_system_admin);
            document.getElementById('nav-admin-broadcasts-item').classList.toggle('d-none', !currentUser.is_system_admin);
            document.getElementById('nav-admin-messages-item').classList.toggle('d-none', !currentUser.is_system_admin);
            if (currentUser.is_system_admin) {
                refreshInvitationRequestsBadge();
                refreshJoinRequestsBadge();
            }
            // Unread conversations with the administrator (D065): the inbox for administrators,
            // the user's own answers for everyone else.
            if (messagesBadgeTimer === null) {
                startMessagesBadge(currentUser.is_system_admin ? '/api/v1/admin/conversations/unread' : '/api/v1/admin-messages/unread');
            }
            // Administrators see the admin pages themselves, so only everyday accounts ask (D066).
            if (!currentUser.is_system_admin && adminAttentionTimer === null) {
                startAdminAttention();
            }
            // The administrator is the one being contacted, so only everyday users see this.
            document.getElementById('nav-contact-admin-item').classList.toggle('d-none', currentUser.is_system_admin);
            // Administrator accounts have no workspaces (D025), so they get no Workspaces or Search link.
            document.getElementById('nav-workspaces-item').classList.toggle('d-none', currentUser.is_system_admin);
            document.getElementById('nav-search-item').classList.toggle('d-none', currentUser.is_system_admin);
            // Chat (D062): administrators never chat, and the administrator can switch it off per account.
            var mayUseChat = !currentUser.is_system_admin && currentUser.chat_access !== 'off';
            document.getElementById('nav-chat-item').classList.toggle('d-none', !mayUseChat);
            if (mayUseChat && chatBadgeTimer === null) {
                startChatBadge();
            }

            loadingIndicator.classList.add('d-none');
            document.dispatchEvent(new CustomEvent('muninn:user-ready', { detail: currentUser }));
            if (!document.body.hasAttribute('data-shell-hold')) {
                pageContent.classList.remove('d-none');
            }
            // The administrator writes the broadcasts; they are for everyday users.
            if (!currentUser.is_system_admin) {
                showBroadcasts();
            }
        })
        .catch(function (sessionError) {
            if (sessionError.status === 401) {
                MuninnApi.goTo('login.php');
                return;
            }
            loadingIndicator.textContent = sessionError.message;
        });
})();
