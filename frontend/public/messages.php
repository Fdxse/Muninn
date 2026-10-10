<?php

/**
 * My messages (decision D065): the signed-in user's conversations with the administrator.
 *   messages.php            the list of conversations
 *   messages.php?id=<id>    one conversation, with a reply box while it is open
 *
 * A conversation starts with the "Contact admin" dialog in the top bar. The API only ever returns
 * the caller's own conversations; administrator accounts use admin/messages.php instead.
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require __DIR__ . '/includes/page.php';

renderPageStart('My messages');
renderAppNavbar();
?>
<main id="main-content" class="container py-4 px-3">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <div id="messages-not-available" class="alert alert-secondary d-none" role="alert">
        Administrator accounts read their messages on the Messages page.
    </div>

    <section id="shell-content" class="d-none">
        <!-- The list of conversations (no ?id). -->
        <div id="conversation-list-view" class="d-none">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <h1 class="h3 mb-0">My messages</h1>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#contact-admin-modal">
                    <i class="bi bi-pencil-square" aria-hidden="true"></i> New message
                </button>
            </div>
            <div id="conversation-list-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
            <div id="conversation-list" class="list-group shadow-sm mb-3"></div>
            <p id="conversation-list-empty" class="text-muted-brand d-none">
                You have not written to the administrator yet. Use New message (or Contact admin in the top bar).
            </p>
            <p class="small text-muted-brand" id="conversation-retention-note"></p>
        </div>

        <!-- One conversation (?id=). -->
        <div id="conversation-view" class="d-none muninn-chat">
            <a href="messages.php" class="small d-inline-block mb-2">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> My messages
            </a>
            <h1 class="h4 mb-1">Conversation with the administrator</h1>
            <p id="conversation-meta" class="small text-muted-brand mb-2"></p>
            <div id="conversation-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>

            <div class="card border-0 shadow-sm overflow-hidden">
                <div class="card-body muninn-message-thread" role="log" aria-live="polite" aria-label="Messages">
                    <div id="conversation-messages"></div>
                </div>

                <p id="conversation-closed-note" class="card-footer bg-white border-0 small text-muted-brand mb-0 d-none">
                    The administrator has closed this conversation. To ask something new, use Contact admin in the top bar.
                </p>
                <form id="reply-form" class="card-footer bg-white border-0 d-none" novalidate>
                    <label for="reply-input" class="visually-hidden">Your reply</label>
                    <div class="d-flex gap-2 align-items-end">
                        <textarea class="form-control" id="reply-input" rows="3" maxlength="1000"
                                  placeholder="Write a reply" aria-describedby="reply-feedback reply-hint"></textarea>
                        <button type="submit" class="btn btn-primary flex-shrink-0" id="reply-send-button">
                            <i class="bi bi-send" aria-hidden="true"></i>
                            <span class="visually-hidden">Send</span>
                        </button>
                    </div>
                    <div id="reply-feedback" class="small text-danger mt-1" role="alert"></div>
                    <div class="d-flex justify-content-between small text-muted-brand">
                        <span id="reply-hint">Plain text. Only you and the administrator see this conversation.</span>
                        <span id="reply-character-count" aria-live="off"></span>
                    </div>
                </form>
            </div>
        </div>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'message-thread.js', 'messages.js']);
