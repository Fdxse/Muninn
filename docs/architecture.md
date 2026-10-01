# Muninn architecture (Week 1)

## Overview

```text
Browser / installed PWA
   │  HTTPS, HTML + static assets
   ▼
www.dx.se ─ frontend/public (PHP page shell, vanilla JS, Bootstrap 5.3)
   │
   │  Browser calls the API directly: HTTPS + JSON, credentials: "include"
   ▼
api.dx.se ─ CNAME of fehre.synology.me (NAS, Web Station)
   │  api/public/index.php → Application pipeline
   ├── MariaDB 10.x (users, sessions, invitations, auth_attempts, audit_log)
   └── Filesystem outside the web root (logs now; attachments from Week 3)
```

`www.dx.se` and `api.dx.se` are different *origins* but the same *site* (`dx.se`). That is why
the session cookie works in every browser, including Safari on iOS (decision D022).

## Repository layout

| Path | Purpose |
|---|---|
| `api/public/` | API web root: `index.php` front controller and `.htaccess` only |
| `api/src/` | Application code, namespace `Muninn\Api\`, grouped by feature |
| `api/migrations/` | Numbered forward-only SQL migrations |
| `api/bin/` | CLI tools: `migrate.php`, `create-admin.php` |
| `api/config/` | `config.example.php` (committed) and `config.php` (server only, git-ignored) |
| `api/tests/` | PHPUnit unit and integration tests |
| `frontend/public/` | Everything uploaded to www.dx.se |
| `frontend/public/includes/` | PHP helpers and config, blocked from direct access |
| `frontend/tools/` | Developer scripts (placeholder icon generator) |
| `deploy/` | Release zip builder, `Deploy-Api.ps1`, `DEPLOY.md` |
| `docs/` | Architecture, conventions, setup, security checklist, limitations |

## API request pipeline

`Application::handle()` runs every request through the same steps, so no endpoint can skip a
security check:

1. CORS preflight answered from the allowlist.
2. Route matched (404 / 405 otherwise).
3. State-changing requests from a foreign `Origin` refused (403).
4. Session resolved from the cookie for non-public routes (401 otherwise).
5. System-admin routes hidden from non-admins (404).
6. CSRF token checked on every authenticated state change (403).
7. Handler runs; any exception becomes a JSON error (internals only in the log).
8. CORS and security headers added; `X-Request-Id` on every response.

Each route declares its access level (`public`, `user`, `system_admin`) where it is registered
in `Application::registerRoutes()`.

## Data model (migration 0001)

- `users`: UUID id, lowercase unique username, display name, password hash
  (Argon2id or bcrypt), `is_system_admin`, `status` (active/disabled).
- `sessions`: SHA-256 of the cookie token, CSRF token, idle and absolute expiry, revocation.
- `invitations`: SHA-256 of the invitation token, creator, note, expiry, acceptance, revocation.
- `auth_attempts`: recent login and invitation attempts for rate limiting (pruned after 24 h).
- `audit_log`: security events with actor, target, IP and non-secret details.

All timestamps are UTC `DATETIME`; the PDO connection pins `time_zone = '+00:00'`.

## Frontend

PHP renders page shells and security headers only; it holds no session or credentials. Each
page loads `api-client.js` (fetch wrapper with CSRF handling) and its own script. Data is
inserted into the DOM with `textContent`, never `innerHTML`. A strict CSP allows scripts and
styles from the site itself only, and network calls to the site and the API.

The PWA is a manifest, placeholder icons and a service worker that caches nothing
(installability only, per CLAUDE.md).
