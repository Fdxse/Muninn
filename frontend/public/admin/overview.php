<?php

/**
 * The administrator's overview (D060): what needs attention, users, content, activity heat maps,
 * sign-in attempts, database and disk, and the audit log archive. Everything is a count, a size
 * or a time grid for the whole installation; nothing names a note, folder, tag or Magic Link.
 * Non-admins get 404 from the API and this page then shows "not available".
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require dirname(__DIR__) . '/includes/page.php';

renderPageStart('Overview', '../');
renderAppNavbar('../');
?>
<main id="main-content" class="container py-4 px-3">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <div id="admin-not-available" class="alert alert-secondary d-none" role="alert">
        This page is not available.
    </div>

    <section id="shell-content" class="d-none" aria-labelledby="overview-heading">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
            <h1 id="overview-heading" class="h3 mb-0 flex-grow-1">Overview</h1>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="overview-refresh">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Refresh
            </button>
        </div>
        <p class="small text-muted-brand mb-3" id="overview-generated"></p>
        <div id="overview-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>

        <!-- Needs attention: only what is wrong or about to go wrong. -->
        <section class="mb-4" aria-labelledby="attention-heading">
            <h2 id="attention-heading" class="h5">Needs attention</h2>
            <ul id="overview-warnings" class="list-unstyled mb-0"></ul>
        </section>

        <!-- Headline numbers. -->
        <div class="row row-cols-2 row-cols-md-4 g-2 mb-4" id="overview-tiles"></div>

        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <section class="card h-100" aria-labelledby="users-card-heading">
                    <div class="card-body">
                        <h2 id="users-card-heading" class="h5">Users</h2>
                        <dl class="row small mb-0 muninn-overview-list" id="overview-users"></dl>
                    </div>
                </section>
            </div>

            <div class="col-12 col-lg-6">
                <section class="card h-100" aria-labelledby="content-card-heading">
                    <div class="card-body">
                        <h2 id="content-card-heading" class="h5">Content</h2>
                        <dl class="row small muninn-overview-list" id="overview-content"></dl>
                        <h3 class="h6 mb-2" id="weekly-notes-heading">Notes created per week</h3>
                        <div class="muninn-week-bars" id="overview-weekly-notes" role="img" aria-labelledby="weekly-notes-heading weekly-notes-summary"></div>
                        <p class="small text-muted-brand mb-0 mt-1" id="weekly-notes-summary"></p>
                    </div>
                </section>
            </div>

            <div class="col-12">
                <section class="card" aria-labelledby="activity-card-heading">
                    <div class="card-body">
                        <h2 id="activity-card-heading" class="h5">When people use Muninn</h2>
                        <p class="small text-muted-brand">
                            All accounts together, by weekday and hour (<span id="activity-timezone"></span>).
                            Magic Link visits count each time a link is opened in a browser.
                        </p>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <div class="btn-group btn-group-sm" role="group" aria-label="What to show">
                                <input type="radio" class="btn-check" name="activity-kind" id="activity-kind-sign-ins" value="sign_ins" checked>
                                <label class="btn btn-outline-secondary" for="activity-kind-sign-ins">Sign-ins</label>
                                <input type="radio" class="btn-check" name="activity-kind" id="activity-kind-links" value="magic_link_visits">
                                <label class="btn btn-outline-secondary" for="activity-kind-links">Magic Link visits</label>
                            </div>
                            <div class="btn-group btn-group-sm" role="group" aria-label="Period">
                                <input type="radio" class="btn-check" name="activity-period" id="activity-period-4" value="last_4_weeks" checked>
                                <label class="btn btn-outline-secondary" for="activity-period-4">4 weeks</label>
                                <input type="radio" class="btn-check" name="activity-period" id="activity-period-12" value="last_12_weeks">
                                <label class="btn btn-outline-secondary" for="activity-period-12">12 weeks</label>
                            </div>
                        </div>
                        <!-- Scrolls sideways on narrow phones instead of squeezing 24 hours into the screen. -->
                        <div class="muninn-heat-map-scroller">
                            <table class="muninn-heat-map" id="overview-heat-map">
                                <caption class="visually-hidden" id="heat-map-caption"></caption>
                            </table>
                        </div>
                        <div class="d-flex align-items-center gap-1 small text-muted-brand mt-2" aria-hidden="true">
                            <span>Fewer</span>
                            <span class="muninn-heat-swatch muninn-heat-0"></span>
                            <span class="muninn-heat-swatch muninn-heat-1"></span>
                            <span class="muninn-heat-swatch muninn-heat-2"></span>
                            <span class="muninn-heat-swatch muninn-heat-3"></span>
                            <span class="muninn-heat-swatch muninn-heat-4"></span>
                            <span>More</span>
                            <span class="ms-2" id="heat-map-total"></span>
                        </div>
                    </div>
                </section>
            </div>

            <div class="col-12">
                <section class="card" aria-labelledby="sign-in-card-heading">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <h2 id="sign-in-card-heading" class="h5 mb-0 flex-grow-1">Sign-in attempts</h2>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="reveal-usernames" aria-pressed="false">
                                <i class="bi bi-eye" aria-hidden="true"></i> <span>Reveal usernames</span>
                            </button>
                        </div>
                        <p class="small text-muted-brand">
                            Usernames typed at failed sign-ins are hidden, because people sometimes type their
                            password there by mistake. Revealing them is recorded in the audit log.
                        </p>
                        <div class="table-responsive">
                            <table class="table table-sm small mb-3" id="sign-in-totals">
                                <caption class="visually-hidden">Sign-in attempts per period</caption>
                            </table>
                        </div>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <h3 class="h6">Most tried usernames (30 days)</h3>
                                <ul class="list-group list-group-flush small" id="sign-in-top-usernames"></ul>
                            </div>
                            <div class="col-12 col-md-6">
                                <h3 class="h6">Most failures by IP address (30 days)</h3>
                                <ul class="list-group list-group-flush small" id="sign-in-top-addresses"></ul>
                            </div>
                        </div>
                        <h3 class="h6 mt-3">Latest failed sign-ins</h3>
                        <div class="table-responsive">
                            <table class="table table-sm small mb-0" id="sign-in-recent">
                                <caption class="visually-hidden">The latest failed and blocked sign-ins</caption>
                            </table>
                        </div>
                    </div>
                </section>
            </div>

            <div class="col-12 col-lg-6">
                <section class="card h-100" aria-labelledby="storage-card-heading">
                    <div class="card-body">
                        <h2 id="storage-card-heading" class="h5">Database and disk</h2>
                        <dl class="row small muninn-overview-list" id="overview-storage"></dl>
                        <h3 class="h6">Largest tables</h3>
                        <ul class="list-group list-group-flush small" id="overview-largest-tables"></ul>
                    </div>
                </section>
            </div>

            <div class="col-12 col-lg-6">
                <section class="card h-100" aria-labelledby="audit-card-heading">
                    <div class="card-body">
                        <h2 id="audit-card-heading" class="h5">Audit log</h2>
                        <p class="small text-muted-brand">
                            The audit log is kept forever. Entries older than <span id="audit-archive-months"></span>
                            months can be moved into a zip file on the NAS; they are deleted from the database only
                            after the zip has been checked.
                        </p>
                        <dl class="row small muninn-overview-list" id="overview-audit"></dl>
                        <div id="audit-archive-status" class="alert d-none small" role="status" tabindex="-1"></div>
                        <button type="button" class="btn btn-outline-primary btn-sm mb-3" id="audit-archive-button">
                            <i class="bi bi-file-earmark-zip" aria-hidden="true"></i> Archive old entries
                        </button>
                        <h3 class="h6">Archives on the NAS</h3>
                        <p class="small text-muted-brand d-none" id="audit-archives-empty">No archives yet.</p>
                        <ul class="list-group list-group-flush small" id="audit-archives"></ul>
                    </div>
                </section>
            </div>
        </div>

        <p class="small text-muted-brand mt-4 mb-0" id="overview-software"></p>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'admin-overview.js'], '../');
