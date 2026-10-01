# Deployment

The step-by-step guide ships inside every release zip: see [`deploy/DEPLOY.md`](../deploy/DEPLOY.md).

Summary:

- Deployment is manual by design. GitHub builds a zip (`.github/workflows/release.yml`); nothing
  is pushed to any server automatically.
- **API** → NAS via `Deploy-Api.ps1`, then `php bin/migrate.php` over SSH. Web root is the
  API folder's `public/` subfolder, served as `https://api.dx.se` (CNAME of `fehre.synology.me`).
- **Frontend** → FTP the contents of `frontend/` to the `www.dx.se` web root.
- Server configuration (`api/config/config.php`, frontend `includes/config.php`) is created once
  on each server and never shipped in a zip.

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
