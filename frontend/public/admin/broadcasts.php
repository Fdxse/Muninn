<?php

/**
 * Broadcast messages for system administrators (D061): schedule one-time banners, sticky
 * banners and votes for every signed-in user, see who has seen or answered them, and read the
 * vote results. Non-admins get 404 from the API and this page then shows "not available".
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require dirname(__DIR__) . '/includes/page.php';

renderPageStart('Broadcasts', '../');
renderAppNavbar('../');
?>
<main id="main-content" class="container py-4 px-3">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <div id="admin-not-available" class="alert alert-secondary d-none" role="alert">
        This page is not available.
    </div>

    <section id="shell-content" class="d-none" aria-labelledby="broadcasts-heading">
        <h1 id="broadcasts-heading" class="h3 mb-3">Broadcasts</h1>
        <p class="small text-muted-brand">
            Messages to everyone who signs in, shown under the top bar between the start and the end you choose.
            Times are in your device's time zone.
        </p>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5 mb-3" id="broadcast-form-heading">New broadcast</h2>
                <div id="broadcast-form-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
                <form id="broadcast-form" class="row g-3" novalidate aria-labelledby="broadcast-form-heading">
                    <fieldset class="col-12" id="broadcast-kind-fieldset">
                        <legend class="form-label fs-6 mb-2">Type</legend>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="broadcast-kind" id="broadcast-kind-once" value="once" checked>
                            <label class="form-check-label" for="broadcast-kind-once">
                                One-time banner <span class="small text-muted-brand">(each user sees it once)</span>
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="broadcast-kind" id="broadcast-kind-sticky" value="sticky">
                            <label class="form-check-label" for="broadcast-kind-sticky">
                                Sticky banner <span class="small text-muted-brand">(on every page until the user closes it with its X)</span>
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="broadcast-kind" id="broadcast-kind-vote" value="vote">
                            <label class="form-check-label" for="broadcast-kind-vote">
                                Vote <span class="small text-muted-brand">(shown until the user has voted; you get each vote on ntfy)</span>
                            </label>
                        </div>
                        <div id="broadcast-kind-feedback" class="invalid-feedback d-block"></div>
                    </fieldset>

                    <div class="col-12">
                        <label for="broadcast-message" class="form-label" id="broadcast-message-label">Message</label>
                        <textarea class="form-control" id="broadcast-message" rows="3" maxlength="1000" required
                                  aria-describedby="broadcast-message-feedback"></textarea>
                        <div id="broadcast-message-feedback" class="invalid-feedback"></div>
                    </div>

                    <div class="col-12 col-sm-6">
                        <label for="broadcast-starts-at" class="form-label">Show from</label>
                        <input type="datetime-local" class="form-control" id="broadcast-starts-at" required aria-describedby="broadcast-starts-at-feedback">
                        <div id="broadcast-starts-at-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label for="broadcast-ends-at" class="form-label">Show until</label>
                        <input type="datetime-local" class="form-control" id="broadcast-ends-at" required aria-describedby="broadcast-ends-at-feedback">
                        <div id="broadcast-ends-at-feedback" class="invalid-feedback"></div>
                    </div>

                    <fieldset class="col-12 d-none" id="broadcast-vote-fieldset">
                        <legend class="form-label fs-6 mb-1">Answers</legend>
                        <p class="small text-muted-brand mb-2">Write 2 to 5 answers. Empty boxes are left out. Answers cannot be changed later.</p>
                        <div id="broadcast-options" class="d-grid gap-2 mb-2"></div>
                        <div id="broadcast-options-feedback" class="invalid-feedback d-block mb-2"></div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="broadcast-multiple-choices">
                            <label class="form-check-label" for="broadcast-multiple-choices">Users may choose several answers</label>
                        </div>
                    </fieldset>

                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary" id="broadcast-submit">Create</button>
                        <button type="button" class="btn btn-outline-secondary d-none" id="broadcast-cancel-edit">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <h2 class="h5 mb-3">All broadcasts</h2>
        <div id="broadcast-list-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
        <p id="broadcast-list-empty" class="text-muted-brand d-none">No broadcasts yet.</p>
        <div id="broadcast-list" class="d-grid gap-3"></div>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'admin-broadcasts.js'], '../');
