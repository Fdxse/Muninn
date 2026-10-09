# Deployment

The step-by-step guide ships inside every release zip: see [`deploy/DEPLOY.md`](../deploy/DEPLOY.md).

Summary:

- Deployment is manual by design. GitHub builds a zip (`.github/workflows/release.yml`); nothing
  is pushed to any server automatically.
- A release zip is built automatically after every merge to main, once CI has passed on that
  commit: Actions → Release → the newest run → Artifacts, named `muninn-main-<date>-<commit>`.
  A version tag (`v*`) or Actions → Release → Run workflow still builds one on demand.
- **API** → NAS folder `/volume1/Muninn` via `Deploy-Api.ps1`, then `sudo php84 bin/migrate.php`
  and `sudo php84 bin/check-setup.php` over SSH from that folder. Web Station serves its
  `public/` subfolder as `https://api.dx.se` (CNAME of `fehre.synology.me`), D053.
- **Frontend** → FTP the contents of `frontend/` to `https://www.dx.se/muninn/`.
- Server configuration (`api/config/config.php`, frontend `includes/config.php`) is created once
  on each server and never shipped in a zip.

## Upgrading to Week 3 (folders, tags, images)

1. Deploy as usual, then run the new migration on the NAS from `/volume1/Muninn`:
   `sudo php84 bin/migrate.php` (adds migration `0003_folders_tags_attachments`).
2. In Web Station, set the Muninn PHP profile's `post_max_size` to at least `12M`, so 10 MB
   images can be uploaded.
3. Images are stored in `/volume1/Muninn/storage/attachments/` (created on the first upload).
   The web server user needs write access to `/volume1/Muninn/storage`, which it already has for
   the log. That folder is outside the document root (`/volume1/Muninn/public`), so images are
   never served directly. Include it in NAS backups together with the database.
4. FTP the frontend with overwrite on: it adds `assets/vendor/marked-18.0.14/`,
   `assets/vendor/dompurify-3.4.16/` and two new scripts.

## Upgrading to Week 4 (search, history, Archive, Trash)

1. Deploy as usual, then run the new migration on the NAS from `/volume1/Muninn`:
   `sudo php84 bin/migrate.php` (adds migration `0004_archive_history`).
2. FTP the frontend with overwrite on: it adds `search.php`, `trash.php`, `history.php` and
   their scripts.
3. Nothing to schedule for Trash cleanup: the API itself deletes notes that have been in Trash
   for more than 30 days (`trash.retention_days` in `config/config.php`), with their history and
   image files, at most once an hour after answering a signed-in request (D039). Each cleanup
   that deletes something is recorded in the audit log. To look or clean up by hand, run
   `sudo php84 bin/purge-trash.php --dry-run` (only counts) or without `--dry-run`.

## Upgrading to Week 5 and 6 (account page, reset links, invitation requests, ship)

1. Deploy as usual, then on the NAS from `/volume1/Muninn`: `sudo php84 bin/migrate.php` (adds
   migration `0005_password_resets_invitation_requests`; Week 6 adds no migration).
2. FTP the frontend with overwrite on (adds `account.php`, `reset-password.php`,
   `admin/workspaces.php` and their scripts; Week 6 changes `assets/css/muninn.css`).
3. New in Week 6: `sudo php84 bin/check-setup.php` checks the installation, and
   `sudo php84 bin/seed-demo.php` creates demo data (see `docs/demo.md`).
4. Then work through the "Going live checklist" in `deploy/DEPLOY.md`.

## Upgrading to sub-folders, syntax highlighting and image removal

1. Deploy as usual, then on the NAS from `/volume1/Muninn`: `sudo php84 bin/migrate.php` (adds
   migration `0006_sub_folders`; existing folders become top-level folders, nothing else changes).
2. FTP the frontend with overwrite on: it adds `assets/vendor/highlightjs-11.12.0/` and changes
   `note.php`, `history.php`, `index.php`, `assets/css/muninn.css` and several scripts in
   `assets/js/`.

## Upgrading to Magic Links (D059)

1. Deploy as usual, then on the NAS from `/volume1/Muninn`: `sudo php84 bin/migrate.php` (adds
   migration `0007_magic_links`; no existing data changes).
2. FTP the frontend with overwrite on: it adds `link.php`, `magic-links.php`,
   `admin/magic-links.php` and their scripts, and changes `workspace.php`, `admin/workspaces.php`,
   `includes/page.php`, `assets/js/api-client.js`, `assets/js/markdown-renderer.js` and
   `assets/js/workspace-settings.js`.
3. Optional: the `magic_links` section in `config/config.php` (see `config/config.example.php`).
   The defaults are Europe/Stockholm for daily hours, 30 days by default and 365 at most.
4. Try it: as a workspace Owner open the workspace's settings, then "Manage Magic Links", create
   a read-only link and open it in a private browser window. Revoke it and reload the window: it
   should say the link does not work.

## Upgrading to the admin Overview page (D060)

1. Deploy as usual. No migration is needed.
2. FTP the frontend with overwrite on: it adds `admin/overview.php` and
   `assets/js/admin-overview.js`, and changes `index.php`, `includes/page.php`,
   `assets/css/muninn.css`, `assets/js/app-shell.js` and `assets/js/api-client.js`.
3. Archiving old audit log entries needs PHP's zip extension: in Web Station, open the PHP 8.4
   profile used by the `muninn` site and tick `zip` under extensions. The Overview page warns
   while it is off; everything else works without it.
4. Optional: the `audit_log` section in `config/config.php` (see `config/config.example.php`).
   Archives go to `/volume1/Muninn/storage/audit-archives/` by default, which the API creates
   on the first archive. `sudo php84 bin/check-setup.php` reports the folder and the extension.
5. Try it: sign in as the administrator and choose Overview in the top bar.

## Upgrading to broadcast messages (D061)

1. Deploy as usual, then on the NAS from `/volume1/Muninn`: `sudo php84 bin/migrate.php` (adds
   migration `0008_broadcasts`; no existing data changes).
2. FTP the frontend with overwrite on: it adds `admin/broadcasts.php` and
   `assets/js/admin-broadcasts.js`, and changes `includes/page.php`, `assets/css/muninn.css` and
   `assets/js/app-shell.js`.
3. Votes are pushed to your phone through ntfy when ntfy is set up (see below). Nothing else to
   configure.
4. Try it: as the administrator choose Broadcasts in the top bar and create a one-time banner,
   then sign in as an everyday user in a private window. The banner shows once; reload and it is
   gone.

## Upgrading to chat (D062)

1. Deploy as usual, then on the NAS from `/volume1/Muninn`: `sudo php84 bin/migrate.php` (adds
   migration `0009_chat`: a `chat_access` column on `users`, where every existing account gets
   "all their workspaces", and the new `chat_messages` table; no existing data changes).
2. FTP the frontend with overwrite on: it adds `chat.php` and `assets/js/chat.js`, and changes
   `includes/page.php`, `admin/users.php`, `assets/css/muninn.css`, `assets/js/app-shell.js` and
   `assets/js/admin-users.js`.
3. Optional: `chat.retention_days` in `config/config.php` (default 90; see
   `config/config.example.php`). Old messages are deleted by the API itself, like Trash.
4. Try it: as the administrator, open Users and set someone's Chat to "All + shout out". Then,
   as two everyday users in different browsers, open Chat in the top bar and write in a shared
   workspace you both belong to. New messages appear within about 10 seconds.

## Upgrading to unread chat badges (D063)

1. Deploy as usual, then on the NAS from `/volume1/Muninn`: `sudo php84 bin/migrate.php` (adds
   migration `0010_chat_read_markers`; no existing data changes).
2. FTP the frontend with overwrite on: it changes `includes/page.php`, `assets/css/muninn.css`,
   `assets/js/app-shell.js` and `assets/js/chat.js`.
3. Everything already in a chat counts as unread once, until each user opens that chat.

## Administrator notifications (ntfy)

Muninn can push a notification to your phone when someone asks for an invitation and when
sign-ins are blocked after repeated wrong passwords (D057), and when a user writes to you with
"Contact admin" (D058). It uses the ntfy server on the NAS. Until ntfy is set up, the Contact
admin dialog tells users that messages are not set up yet.

1. Pick a topic name, e.g. `muninn-admin`, and subscribe to it in the ntfy app.
2. If your ntfy requires sign-in to publish, create a token for Muninn on the NAS
   (`ntfy token add <user>`) and give that user write access to the topic. Keep the token
   secret: it goes only into `config.php`, never in chat, email or the repository.
3. In `/volume1/Muninn/config/config.php`, add or fill in the `ntfy` section (see
   `config/config.example.php`): `'enabled' => true`, `server_url` (the local address such as
   `http://127.0.0.1:<port>`, so nothing leaves the NAS), `topic`, and `access_token` or `''`.
4. Run `sudo php84 bin/send-test-notification.php` from `/volume1/Muninn`. It says whether ntfy
   accepted the message, and the test should appear on your phone.

If ntfy is down, Muninn keeps working; the failure is written to `storage/api.log`.

## Resetting test data

To clear out test accounts and invitations and keep only the administrator account(s), run on
the NAS from the API folder (`/volume1/Muninn`):

```sh
sudo php84 bin/reset-data.php --dry-run   # shows what would be deleted, changes nothing
sudo php84 bin/reset-data.php             # asks you to type DELETE ALL USER DATA, then deletes
```

It deletes every non-admin account with its sessions, workspaces and notes (Trash and history
included), folders, tags, note images (database rows and the files in `storage/attachments/`),
Magic Links, all chat messages, all invitations, the sign-in attempt records, and those accounts' broadcast answers and votes. Admin
accounts, the broadcasts themselves, the audit log and the schema stay.
There is no undo, so back up the database first if anything might be worth keeping (D046).

## Environment record

Fill this in at the first real deployment so a fresh deployment can be reproduced.

| Item | Value |
|---|---|
| NAS DSM version | _to record_ |
| Web Station PHP version and extensions | PHP 8.4 profile; pdo_mysql, mbstring, openssl, sodium; `open_basedir` includes `/volume1/Muninn` |
| Web Station back-end | Apache 2.4, web service "muninn", document root `Muninn/public` |
| Web portal | Name-based, `api.dx.se`, HTTPS 443 |
| MariaDB version | _to record_ (MariaDB 10 package) |
| API folder on NAS | `/volume1/Muninn` (D053) |
| Log file on NAS | `/volume1/Muninn/storage/api.log` (check `logging.file_path`) |
| Images on NAS | `/volume1/Muninn/storage/attachments/` |
| Frontend | `https://www.dx.se/muninn/`, uploaded by FTP |
| www.dx.se PHP version | _to record_ |
| api.dx.se certificate | Let's Encrypt on the NAS (renewed by DSM) |
| DNS | one.com: `api` CNAME `fehre.synology.me` |
