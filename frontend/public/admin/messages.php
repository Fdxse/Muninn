<?php

/**
 * The administrator's inbox (decision D065): every "Contact admin" message starts a conversation
 * here, and the administrator answers, closes or reopens it.
 *   admin/messages.php            the inbox (Open, Closed or All)
 *   admin/messages.php?id=<id>    one conversation (ntfy notifications link straight here)
 *
 * Non-admins get 403/404 from the API and this page then shows "not available".
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require dirname(__DIR__) . '/includes/page.php';

renderPageStart('Messages', '../');
renderAppNavbar('../');
?>
<main id="main-content" class="container py-4 px-3">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <div id="admin-not-available" class="alert alert-secondary d-none" role="alert">
        This page is not available.
    </div>

    <section id="shell-content" class="d-none">
        <!-- The inbox (no ?id). -->
        <div id="inbox-view" class="d-none">
            <h1 class="h3 mb-3">Messages</h1>
            <p class="small text-muted-brand">
                Messages users sent with Contact admin. They see your answers under My messages, signed
                "Administrator". Close a conversation when it is done; the user can then read it but not reply.
            </p>

            <div class="btn-group mb-3" role="group" aria-label="Show conversations">
                <input type="radio" class="btn-check" name="inbox-filter" id="inbox-filter-open" value="open" autocomplete="off" checked>
                <label class="btn btn-outline-primary" for="inbox-filter-open">Open</label>
                <input type="radio" class="btn-check" name="inbox-filter" id="inbox-filter-closed" value="closed" autocomplete="off">
                <label class="btn btn-outline-primary" for="inbox-filter-closed">Closed</label>
                <input type="radio" class="btn-check" name="inbox-filter" id="inbox-filter-all" value="all" autocomplete="off">
                <label class="btn btn-outline-primary" for="inbox-filter-all">All</label>
            </div>

            <div id="inbox-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
            <div id="inbox-list" class="list-group shadow-sm mb-3" aria-live="polite"></div>
            <p id="inbox-empty" class="text-muted-brand d-none">No conversations here.</p>
            <p class="small text-muted-brand" id="inbox-retention-note"></p>
        </div>

        <!-- One conversation (?id=). -->
        <div id="conversation-view" class="d-none muninn-chat">
            <a href="messages.php" class="small d-inline-block mb-2">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All messages
            </a>
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-1">
                <h1 id="conversation-heading" class="h4 mb-0 text-break"></h1>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="conversation-close-button">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Close conversation
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="conversation-reopen-button">
                        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reopen
                    </button>
                </div>
            </div>
            <p id="conversation-meta" class="small text-muted-brand mb-2"></p>
            <div id="conversation-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>

            <div class="card border-0 shadow-sm overflow-hidden">
                <div class="card-body muninn-message-thread" role="log" aria-live="polite" aria-labelledby="conversation-heading">
                    <div id="conversation-messages"></div>
                </div>

                <form id="reply-form" class="card-footer bg-white border-0" novalidate>
                    <label for="reply-input" class="visually-hidden">Your answer</label>
                    <div class="d-flex gap-2 align-items-end">
                        <textarea class="form-control" id="reply-input" rows="3" maxlength="1000"
                                  placeholder="Write an answer" aria-describedby="reply-feedback reply-hint"></textarea>
                        <button type="submit" class="btn btn-primary flex-shrink-0" id="reply-send-button">
                            <i class="bi bi-send" aria-hidden="true"></i>
                            <span class="visually-hidden">Send</span>
                        </button>
                    </div>
                    <div id="reply-feedback" class="small text-danger mt-1" role="alert"></div>
                    <div class="d-flex justify-content-between small text-muted-brand">
                        <span id="reply-hint">Plain text. The user sees it as from "Administrator". Answering a closed conversation reopens it.</span>
                        <span id="reply-character-count" aria-live="off"></span>
                    </div>
                </form>
            </div>
        </div>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'message-thread.js', 'admin-messages.js'], '../');
