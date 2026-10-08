# Deploying Muninn

This file ships inside every release zip. Nothing is deployed automatically (D041): you copy the
files into place yourself. The zip contains:

| Folder / file     | Goes to                                                  | How              |
|-------------------|----------------------------------------------------------|------------------|
| `api/`            | The API folder on the NAS, `/volume1/Muninn`             | `Deploy-Api.ps1` |
| `frontend/`       | The **contents** go to the frontend folder on www.dx.se  | FTP              |
| `DEPLOY.md`       | This guide                                               |                  |
| `Deploy-Api.ps1`  | Run from your Windows machine                            | PowerShell       |
| `VERSION`         | Informational                                            |                  |

The live installation (D022, D053):

| | |
|---|---|
| Frontend | `https://www.dx.se/muninn/` (web hosting with PHP 8, FTP) |
| API | `https://api.dx.se` → CNAME of `fehre.synology.me` (Synology NAS, Web Station) |
| API folder on the NAS | `/volume1/Muninn` (source, `vendor`, `config/config.php`, `storage`) |
| API document root | `/volume1/Muninn/public` (only `index.php` and `.htaccess`) |
| Database | MariaDB 10 on the NAS, database `muninn` |

On the NAS, run every command over SSH **from `/volume1/Muninn`** with Web Station's PHP 8.4
command-line binary: `sudo php84 bin/<script>.php`. (On a machine where `php` is PHP 8.2 or newer,
plain `php` works the same way.)

---

## First-time setup (once)

### 1. Database (NAS, MariaDB 10 package)

Create the database and two accounts. The app account can only read and write data; the
migration account may change the schema. Use your own strong passwords.

```sql
CREATE DATABASE muninn CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'muninn_app'@'localhost' IDENTIFIED BY '<app password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON muninn.* TO 'muninn_app'@'localhost';
CREATE USER 'muninn_migrate'@'localhost' IDENTIFIED BY '<migration password>';
GRANT ALL PRIVILEGES ON muninn.* TO 'muninn_migrate'@'localhost';
```

### 2. API folder and web server (NAS, Web Station)

1. Create the shared folder `Muninn` (`/volume1/Muninn`). Give the web server user (`http`) read
   access to it and write access to `/volume1/Muninn/storage` (log file, and note images in
   `storage/attachments/`, which the API creates on the first upload).
2. Copy the API there with `Deploy-Api.ps1` (see "Every release" below).
3. Web Station → **Script Language Settings**: a PHP profile with **PHP 8.2 or newer** (live:
   PHP 8.4) and the extensions `pdo_mysql`, `mbstring`, `openssl` and `sodium` (sodium enables
   Argon2id password hashing; without it bcrypt is used). In the profile's core settings:
   - `open_basedir` must include `/volume1/Muninn`, otherwise PHP cannot load the source;
   - `post_max_size` at least `12M`, so 10 MB images can be uploaded.
4. Web Station → **Web Service** → Create: name `muninn`, back-end **Apache 2.4** (the included
   `.htaccess` routes every request to `index.php`), that PHP profile, document root
   **`Muninn/public`**. Only that folder is reachable from the web; `src`, `config`, `vendor` and
   `storage` are outside the document root.
5. Web Station → **Web Portal** → Create: name-based, host name `api.dx.se`, HTTPS port 443, the
   `muninn` service.
6. Certificate: Control Panel → Security → Certificate → Add → Let's Encrypt for `api.dx.se`; then
   Settings → assign it to the `api.dx.se` portal.
7. DNS (at the dx.se DNS provider, one.com): `api  CNAME  fehre.synology.me.`

Alternative layout: `Deploy-Api.ps1` can also put `public/` in a web folder of its own
(`/volume1/web/muninn`) and the rest elsewhere (`/volume1/secrets/muninn`), D042. It then writes
`app-location.php` so `index.php` finds the application folder. Muninn's live NAS does not need
that, because the document root is the `public` subfolder itself.

### 3. API configuration (NAS)

Copy `config/config.example.php` to `config/config.php` in `/volume1/Muninn/config/` and fill in:

- `app.environment`: `production` (hides internal details everywhere; use `development` only
  while setting up);
- `database`: the `muninn_app` credentials; `migrations`: the `muninn_migrate` credentials;
- `cors.allowed_origins`: `['https://www.dx.se']`, the bare origin **without** a path, even
  though the frontend lives in `/muninn` (a path makes the API refuse to start);
- `frontend.base_url`: the full frontend address including the folder, `https://www.dx.se/muninn`
  (invitation and password reset links are built from it);
- `logging.file_path`: `/volume1/Muninn/storage/api.log`;
- `security.trusted_proxies`: leave empty, unless Synology's reverse proxy is put in front of
  Web Station (then its IP address).
- `attachments` (optional): `storage_path` defaults to `storage/attachments` in the API folder;
  `max_upload_bytes` defaults to `10000000` (10 MB).
- `ntfy` (optional, D057): push notifications to the administrator. See "Administrator
  notifications (ntfy)" in `docs/deployment.md`.

Make `config.php` readable only by its owner and the web server (`sudo chmod 640 config/config.php`).
It is never in a release zip, so later deploys never overwrite it.

### 4. Database schema and first administrator (NAS, over SSH)

```sh
cd /volume1/Muninn
sudo php84 bin/migrate.php
sudo php84 bin/create-admin.php
sudo php84 bin/check-setup.php
```

`create-admin.php` asks for a username, display name and password (hidden). Use a dedicated
admin account and invite yourself a separate everyday account from the admin pages (D025).
`check-setup.php` reads the configuration, database, schema and folders and prints OK, WARN or
FAIL per item; fix every FAIL before going live. It changes nothing, so run it whenever in doubt.

### 5. Frontend (www.dx.se)

1. FTP the **contents** of `frontend/` into the frontend folder (`/muninn` on www.dx.se), with
   "overwrite existing files" switched on.
2. On the server, copy `includes/config.example.php` to `includes/config.php`. Its default
   `api_base_url` is already `https://api.dx.se`.
3. The host must run PHP 8 and serve HTTPS.

Check: `https://api.dx.se/api/v1/health` returns `{"data":{"status":"ok"}}`, and
`https://www.dx.se/muninn/` shows the sign-in page.

### 6. Trash cleanup (nothing to set up)

Notes stay in Trash for 30 days (`trash.retention_days`), then the API deletes them for good with
their history and images by itself, at most once an hour, after answering a signed-in request
(D039). No scheduled task is needed. To check or clean up by hand:
`sudo php84 bin/purge-trash.php --dry-run` (only counts) or without `--dry-run` (deletes).

### 7. Backups

Muninn has no backup tool of its own (a stretch goal). Back up both:

- the database: Hyper Backup's MariaDB support, or a scheduled task running
  `mysqldump --single-transaction muninn > /volume1/backup/muninn-$(date +%F).sql`;
- the images: `/volume1/Muninn/storage/attachments/`.

`config/config.php` is worth keeping too (it holds the database passwords, so store it safely).

---

## Every release

1. Download the zip: GitHub → Actions → **Release** → the newest run → Artifacts
   (`muninn-main-<date>-<commit>`, built after every merge to main), or a version tag's release.
   It holds one `muninn-<version>/` folder; pass the downloaded zip straight to `Deploy-Api.ps1`.
2. API: in PowerShell (replace `\\fehre\Muninn` with however the NAS share `Muninn` is reachable from your PC):
   ```powershell
   .\Deploy-Api.ps1 -ZipPath .\muninn-<version>.zip `
       -PublicTarget \\fehre\Muninn\public -AppTarget \\fehre\Muninn `
       -ServerAppPath /volume1/Muninn
   ```
   Add `-WhatIf` first for a dry run. The script never touches `config\config.php` or `storage\`.
3. Over SSH on the NAS: `cd /volume1/Muninn && sudo php84 bin/migrate.php` (safe when nothing is
   new), then `sudo php84 bin/check-setup.php`.
4. Frontend: FTP the contents of `frontend/` again with overwrite on. The zip has no
   `includes/config.php`, so your server copy is kept.
5. Open the app, sign in, open a note. `docs/device-checklist.md` has the longer manual pass.

## Going live checklist

Before showing Muninn to anyone else:

- [ ] `config.php`: `app.environment` is `production`, `session.cookie_secure` is `true`.
- [ ] `sudo php84 bin/check-setup.php` shows no FAIL.
- [ ] `sudo chmod 640 /volume1/Muninn/config/config.php`.
- [ ] Test accounts and demo data are gone: disable them on the admin Users page, or (before real
      use) clear everything but the admin accounts with `sudo php84 bin/reset-data.php`.
- [ ] A database backup runs (see step 7).
- [ ] The device checklist (`docs/device-checklist.md`) passes on an iPhone and one desktop browser.

## Demo data

For a demonstration, `sudo php84 bin/seed-demo.php` creates two demo accounts (`demo.anna`,
`demo.erik`) with random passwords that are printed once, a shared workspace and sample notes.
`--dry-run` only checks that it can run. It refuses to run twice and never changes existing data.
The demo script to follow is `docs/demo.md` in the repository. Afterwards, disable the two
accounts on the admin Users page.
