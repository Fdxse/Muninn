# Deploying Muninn

This file ships inside every release zip. Nothing is deployed automatically: you copy files
into place yourself. The zip contains:

| Folder / file     | Goes to                                   | How                        |
|-------------------|-------------------------------------------|----------------------------|
| `api/`            | NAS, e.g. `\\NAS\web\muninn-api`          | `Deploy-Api.ps1`           |
| `frontend/`       | The **contents** go to the www.dx.se web root | FTP                     |
| `Deploy-Api.ps1`  | Run from your Windows machine             | PowerShell                 |
| `VERSION`         | Informational                             |                            |

Hostnames (decision D022): the frontend is `https://www.dx.se`, the API is `https://api.dx.se`,
which is a DNS alias (CNAME) of `fehre.synology.me`. Until `api.dx.se` exists you can test on
`https://fehre.synology.me`, but sign-in will then fail on iPhones (third-party cookies).

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

1. Create a folder for the API, e.g. `/volume1/web/muninn-api`, and a storage folder **outside**
   any web root, e.g. `/volume1/muninn-data/logs`.
2. Run `Deploy-Api.ps1` once (see "Every release" below) to copy the files.
3. In Web Station, create a PHP profile with **PHP 8.2 or newer** and enable the extensions
   `pdo_mysql`, `mbstring`, `openssl`, and `sodium` (sodium enables Argon2id password hashing).
   If the profile uses `open_basedir`, add both the API folder and the storage folder.
4. Create a web service / virtual host for `api.dx.se` with:
   - document root: `<API folder>/public` (only this folder is exposed),
   - back-end server: **Apache 2.4** (the included `.htaccess` routes requests to `index.php`),
   - HTTPS on, with the certificate below.
   If you must use nginx, add: `location / { try_files $uri /index.php$is_args$args; }`
5. Certificate: Control Panel → Security → Certificate → Add → Let's Encrypt for `api.dx.se`,
   then assign it to the `api.dx.se` service.
6. DNS (at the dx.se DNS provider): `api.dx.se  CNAME  fehre.synology.me.`

### 3. API configuration (NAS)

Copy `config/config.example.php` to `config/config.php` in the API folder and fill in:

- `database`: `muninn_app` credentials; `migrations`: `muninn_migrate` credentials;
- `cors.allowed_origins`: `['https://www.dx.se']`;
- `frontend.base_url`: `https://www.dx.se`;
- `logging.file_path`: e.g. `/volume1/muninn-data/logs/api.log`;
- `security.trusted_proxies`: the reverse proxy's IP **only if** you put Synology's reverse
  proxy in front of Web Station; otherwise leave it empty.

Make `config.php` readable only by the web server user. It is never in a release zip, so
later deploys never overwrite it.

### 4. Database schema and first administrator (NAS, over SSH)

```sh
cd /volume1/web/muninn-api
php bin/migrate.php
php bin/create-admin.php
```

`create-admin.php` asks for a username, display name and password (hidden). Use a dedicated
admin account; invite yourself a separate everyday account from the admin page (decision D025).

### 5. Frontend (www.dx.se)

1. FTP the **contents** of `frontend/` into the www.dx.se web root.
2. On the server, copy `includes/config.example.php` to `includes/config.php`.
   Its default `api_base_url` is already `https://api.dx.se`.
3. Make sure the host runs PHP 8 and serves HTTPS.

---

## Every release

1. Download `muninn-<version>.zip` from the GitHub release (or the workflow run's artifacts).
2. API: in PowerShell, run
   ```powershell
   .\Deploy-Api.ps1 -ZipPath .\muninn-<version>.zip -TargetPath \\NAS\web\muninn-api
   ```
   Add `-WhatIf` first if you want a dry run.
3. Over SSH on the NAS: `php bin/migrate.php` (safe to run when nothing is new).
4. Frontend: FTP the contents of `frontend/` to the web root again. Do **not** upload over
   `includes/config.php` (the zip does not contain one, so a normal upload is safe).
5. Check:
   - `https://api.dx.se/api/v1/health` shows `{"data":{"status":"ok"}}`;
   - `https://www.dx.se/login.php` loads and you can sign in.

## Troubleshooting

- **Sign-in works on a computer but not on an iPhone:** the frontend is calling
  `fehre.synology.me` instead of `api.dx.se`; check `includes/config.php` on www.dx.se.
- **Health check returns a 500 "temporarily unavailable":** the API cannot read
  `config/config.php` or reach the database; see the web server's PHP error log on the NAS.
- **Every sign-in attempt says "Too many attempts":** behind a reverse proxy, set
  `security.trusted_proxies` so the real client IP is used.
