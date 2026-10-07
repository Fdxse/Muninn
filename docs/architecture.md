# Muninn architecture (Week 2)

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

## Data model (migrations 0001 and 0002)

- `users`: UUID id, lowercase unique username, display name, password hash
  (Argon2id or bcrypt), `is_system_admin`, `status` (active/disabled).
- `sessions`: SHA-256 of the cookie token, CSRF token, idle and absolute expiry, revocation.
- `invitations`: SHA-256 of the invitation token, creator, note, expiry, acceptance, revocation.
- `auth_attempts`: recent login and invitation attempts for rate limiting (pruned after 24 h).
- `audit_log`: security events with actor, target, IP and non-secret details.
- `workspaces`: name, kind (`personal`/`shared`); `personal_owner_user_id` is unique, so each
  user has exactly one personal workspace (created by the API on first use).
- `workspace_members`: (workspace, user) → role `owner`/`admin`/`editor`/`reader`. The only
  source of access to a workspace and its notes.
- `notes`: workspace (fixed, D032), title, Markdown `content`, `revision` for optimistic
  concurrency, creator/updater, and `trashed_at` for Trash (D045).

## Authorization (Week 2)

Every workspace and note handler first calls `WorkspaceAuthorizer::requireWorkspacePermission()`
with the signed-in user, the workspace ID and a `WorkspacePermission`. For a note, its workspace
is looked up first and the caller's membership of *that* workspace is required. The result is a
`WorkspaceMembership`, and the services (`WorkspaceService`, `NoteService`) only accept that
object and scope every query to its workspace. Non-members get 404 (indistinguishable from a
missing resource), members with too weak a role get 403, and system administrators never get
access (D025, D044). The role matrix (D029) is defined once in `WorkspaceRole` and
`WorkspacePermission`.

All timestamps are UTC `DATETIME`; the PDO connection pins `time_zone = '+00:00'`.

## Frontend

PHP renders page shells and security headers only; it holds no session or credentials. Each
page loads `api-client.js` (fetch wrapper with CSRF handling) and its own script. Data is
inserted into the DOM with `textContent`, never `innerHTML`. A strict CSP allows scripts and
styles from the site itself only, and network calls to the site and the API.

The PWA is a manifest, the raven app icons and a service worker that caches nothing
(installability only, per CLAUDE.md).
