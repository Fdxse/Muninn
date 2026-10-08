<?php

/**
 * Magic Links of one workspace (D059): create a link that opens the workspace, a folder or a
 * note without signing in, see every link with its state, and revoke links.
 *   magic-links.php?workspace=<workspace id>
 *
 * Shown to workspace Admins and Owners; the API decides every action (D029, D059).
 */

declare(strict_types=1);

define('MUNINN_FRONTEND', true);
require __DIR__ . '/includes/page.php';

renderPageStart('Magic Links');
renderAppNavbar();
?>
<main id="main-content" class="container py-4 px-3">
    <div id="shell-loading" role="status" class="text-muted-brand">Loading…</div>

    <div id="links-not-available" class="alert alert-secondary d-none" role="alert">
        Magic Links are managed by the workspace's Admins and Owners. <a href="workspaces.php" class="alert-link">Back to workspaces</a>
    </div>

    <section id="shell-content" class="d-none" aria-labelledby="links-heading">
        <a href="workspaces.php" class="small d-inline-block mb-2" id="back-to-workspace-link">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Workspace settings
        </a>
        <h1 id="links-heading" class="h3 mb-1">Magic Links</h1>
        <p class="small text-muted-brand mb-4">
            <span id="links-workspace-name" class="fw-semibold"></span>.
            A Magic Link opens this workspace, a folder or a note without signing in. Anyone who has
            the link can use it until it expires or you revoke it, so share it only with people you trust.
        </p>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5 mb-3">New link</h2>
                <div id="create-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
                <form id="create-link-form" class="row g-3" novalidate>
                    <div class="col-12 col-md-6">
                        <label for="link-label" class="form-label">Name</label>
                        <input type="text" class="form-control" id="link-label" maxlength="100" placeholder="e.g. Kitchen tablet"
                               aria-describedby="link-label-help link-label-feedback" required>
                        <div id="link-label-help" class="form-text">Helps you recognise the link in the list below.</div>
                        <div id="link-label-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="link-target-type" class="form-label">Opens</label>
                        <select class="form-select" id="link-target-type" aria-describedby="link-target-type-feedback">
                            <option value="workspace">The whole workspace</option>
                            <option value="folder">One folder (with its sub-folders)</option>
                            <option value="note">One note</option>
                        </select>
                        <div id="link-target-type-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="col-12 d-none" id="link-target-choice">
                        <label for="link-target-id" class="form-label" id="link-target-id-label">Folder</label>
                        <select class="form-select" id="link-target-id" aria-describedby="link-target-id-feedback"></select>
                        <div id="link-target-id-feedback" class="invalid-feedback"></div>
                    </div>
                    <fieldset class="col-12">
                        <legend class="form-label fs-6 mb-1">Access</legend>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="link-permission" id="link-permission-read" value="read" checked>
                            <label class="form-check-label" for="link-permission-read">Read only</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="link-permission" id="link-permission-write" value="write">
                            <label class="form-check-label" for="link-permission-write">Read and write <span class="small text-muted-brand">(edit notes and add new ones; never delete)</span></label>
                        </div>
                        <div id="link-permission-feedback" class="invalid-feedback d-block"></div>
                    </fieldset>
                    <div class="col-12 col-sm-6">
                        <label for="link-valid-from" class="form-label">Works from <span class="small text-muted-brand">(empty = now)</span></label>
                        <input type="datetime-local" class="form-control" id="link-valid-from" aria-describedby="link-valid-from-feedback">
                        <div id="link-valid-from-feedback" class="invalid-feedback"></div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label for="link-valid-until" class="form-label">Works until</label>
                        <input type="datetime-local" class="form-control" id="link-valid-until" aria-describedby="link-valid-until-help link-valid-until-feedback" required>
                        <div id="link-valid-until-help" class="form-text">At most <span id="max-valid-days">365</span> days from today. Times are in your device's time zone.</div>
                        <div id="link-valid-until-feedback" class="invalid-feedback"></div>
                    </div>
                    <fieldset class="col-12">
                        <legend class="form-label fs-6 mb-1">Only between <span class="small text-muted-brand">(optional, every day)</span></legend>
                        <div class="d-flex align-items-center gap-2">
                            <label for="link-daily-start" class="visually-hidden">Daily start time</label>
                            <input type="time" class="form-control w-auto" id="link-daily-start" aria-describedby="link-daily-help link-daily-start-feedback">
                            <span aria-hidden="true">–</span>
                            <label for="link-daily-end" class="visually-hidden">Daily end time</label>
                            <input type="time" class="form-control w-auto" id="link-daily-end" aria-describedby="link-daily-help link-daily-end-feedback">
                        </div>
                        <div id="link-daily-help" class="form-text">In <span id="link-timezone">Europe/Stockholm</span> time. 22:00–06:00 covers the night. Leave empty for all day.</div>
                        <div id="link-daily-start-feedback" class="invalid-feedback d-block"></div>
                        <div id="link-daily-end-feedback" class="invalid-feedback d-block"></div>
                    </fieldset>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary" id="create-link-submit">
                            <i class="bi bi-link-45deg" aria-hidden="true"></i> Create link
                        </button>
                    </div>
                </form>

                <div id="new-link-result" class="alert alert-warning mt-3 mb-0 d-none" role="status" tabindex="-1">
                    <p class="fw-semibold mb-1">Copy this link now. It is shown only once.</p>
                    <p class="small mb-2">Anyone with the link can use it until it expires or is revoked. Bookmark it on the device that should use it.</p>
                    <p class="muninn-one-time-link small bg-white rounded-2 p-2 mb-2" id="new-link-url"></p>
                    <button type="button" class="btn btn-outline-dark btn-sm" id="copy-link-button">
                        <i class="bi bi-clipboard" aria-hidden="true"></i> Copy link
                    </button>
                    <span class="small ms-2" id="copy-link-status" role="status"></span>
                </div>
            </div>
        </div>

        <h2 class="h5 mb-3">Links</h2>
        <div id="list-error" class="alert alert-danger d-none" role="alert" tabindex="-1"></div>
        <p id="links-empty" class="text-muted-brand d-none">No links yet.</p>
        <ul id="links-list" class="list-group mb-4"></ul>
    </section>
</main>
<?php
renderPageEnd(['app-shell.js', 'magic-links.js']);
