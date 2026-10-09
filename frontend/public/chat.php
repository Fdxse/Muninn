<?php

/**
 * Chat (decision D062).
 *   chat.php                        the chats the user may use
 *   chat.php?channel=global         the global channel ("Everyone")
 *   chat.php?workspace=<id>         the chat of one shared workspace
 *
 * The page checks for new messages every few seconds while it is open and visible. The API
 * decides who may read and write each chat; the page only hides what would be refused anyway.
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require __DIR__ . '/includes/page.php';

renderPageStart('Chat');
renderAppNavbar();
?>
<main id="main-content" class="container py-4 px-3">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <div id="chat-not-available" class="alert alert-secondary d-none" role="alert">
        Chat is not available for your account.
    </div>

    <section id="shell-content" class="d-none">
        <!-- The list of chats (no channel chosen). -->
        <div id="channel-list-view" class="d-none">
            <h1 class="h3 mb-3">Chat</h1>
            <div id="channel-list-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
            <div id="channel-list" class="list-group shadow-sm mb-3"></div>
            <p id="channel-list-empty" class="text-muted-brand d-none">
                No workspace chats yet. A shared workspace gets a chat as soon as it has members.
            </p>
            <p class="small text-muted-brand" id="chat-retention-note"></p>
        </div>

        <!-- One conversation. -->
        <div id="conversation-view" class="d-none muninn-chat">
            <a href="chat.php" class="small d-inline-block mb-2">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All chats
            </a>
            <h1 id="conversation-heading" class="h4 mb-1 text-break"></h1>
            <p id="conversation-read-only" class="small text-muted-brand mb-2 d-none"></p>
            <div id="conversation-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>

            <div class="card border-0 shadow-sm overflow-hidden">
                <div id="chat-log" class="card-body muninn-chat-log" role="log" aria-live="polite" aria-relevant="additions" aria-labelledby="conversation-heading" tabindex="0">
                    <div class="text-center mb-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="load-earlier-button">
                            Show earlier messages
                        </button>
                    </div>
                    <p id="chat-empty" class="text-muted-brand text-center small my-4 d-none">No messages yet. Say hello!</p>
                    <div id="chat-messages"></div>
                </div>

                <form id="chat-form" class="card-footer bg-white border-0 d-none" novalidate>
                    <label for="chat-input" class="visually-hidden">Message</label>
                    <div class="d-flex gap-2 align-items-end">
                        <textarea class="form-control" id="chat-input" rows="2" maxlength="2000"
                                  placeholder="Write a message" aria-describedby="chat-input-feedback chat-input-hint"></textarea>
                        <button type="submit" class="btn btn-primary flex-shrink-0" id="chat-send-button">
                            <i class="bi bi-send" aria-hidden="true"></i>
                            <span class="visually-hidden">Send</span>
                        </button>
                    </div>
                    <div id="chat-input-feedback" class="small text-danger mt-1" role="alert"></div>
                    <div class="d-flex justify-content-between small text-muted-brand">
                        <span id="chat-input-hint">Plain text. Messages are deleted after <span id="chat-retention-days">90</span> days.</span>
                        <span id="chat-character-count" aria-live="off"></span>
                    </div>
                </form>
            </div>
        </div>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'chat.js']);
