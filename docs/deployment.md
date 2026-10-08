# Deployment

The step-by-step guide ships inside every release zip: see [`deploy/DEPLOY.md`](../deploy/DEPLOY.md).

Summary:

- Deployment is manual by design. GitHub builds a zip (`.github/workflows/release.yml`); nothing
  is pushed to any server automatically.
- A release zip is built automatically after every merge to main, once CI has passed on that
  commit: Actions → Release → the newest run → Artifacts, named `muninn-main-<date>-<commit>`.
  A version tag (`v*`) or Actions → Release → Run workflow still builds one on demand.
- **API** → NAS via `Deploy-Api.ps1`, then `php bin/migrate.php` over SSH. Web root is the
  API folder's `public/` subfolder, served as `https://api.dx.se` (CNAME of `fehre.synology.me`).
- **Frontend** → FTP the contents of `frontend/` to the `www.dx.se` web root.
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

## Resetting test data

To clear out test accounts and invitations and keep only the administrator account(s), run on
the NAS from the API folder (`/volume1/Muninn`):

```sh
sudo php84 bin/reset-data.php --dry-run   # shows what would be deleted, changes nothing
sudo php84 bin/reset-data.php             # asks you to type DELETE ALL USER DATA, then deletes
```

It deletes every non-admin account with its sessions, workspaces and notes (Trash and history
included), folders, tags, note images (database rows and the files in `storage/attachments/`), all
invitations and the sign-in attempt records. Admin accounts, the audit log and the schema stay.
There is no undo, so back up the database first if anything might be worth keeping (D046).

## Environment record

Fill this in at the first real deployment so a fresh deployment can be reproduced.

| Item | Value |
|---|---|
| NAS DSM version | _to record_ |
| Web Station PHP version and extensions | _to record_ |
| Web Station back-end (Apache/nginx) | _to record_ |
| MariaDB version | _to record_ |
| API folder on NAS | _to record_ |
| Log folder on NAS | _to record_ |
| www.dx.se PHP version | _to record_ |
| api.dx.se certificate | _to record (issuer, renewal)_ |
