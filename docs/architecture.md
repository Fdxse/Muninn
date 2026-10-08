# Muninn architecture (Week 4)

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
   └── Filesystem outside the web root (logs, storage/attachments)
```

`www.dx.se` and `api.dx.se` are different *origins* but the same *site* (`dx.se`). That is why
the session cookie works in every browser, including Safari on iOS (decision D022).

## Repository layout

| Path | Purpose |
|---|---|
| `api/public/` | API web root: `index.php` front controller and `.htaccess` only |
| `api/src/` | Application code, namespace `Muninn\Api\`, grouped by feature |
| `api/migrations/` | Numbered forward-only SQL migrations |
| `api/bin/` | CLI tools: `migrate.php`, `create-admin.php`, `reset-data.php` |
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

## Data model (migrations 0001–0003)

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
  concurrency, creator/updater, `trashed_at` for Trash (D045), `archived_at` for the Archive
  (D048), and an optional `folder_id`.
- `note_versions`: earlier states of a note (title, content, folder, tag names, who saved it
  and when), at most 100 per note (D009, D037). Removed with the note when it is purged.
- `folders`: flat, one level, per workspace (D033); unique name per workspace. Deleting one sets
  its notes' `folder_id` to NULL (`ON DELETE SET NULL`).
- `tags` and `note_tags`: tags per workspace (D034), unique name per workspace, linked to notes.
  Unused tags are removed when a note's tags change.
- `attachments`: image metadata (display filename, detected media type, size, dimensions,
  SHA-256) for one note. The file lives at `storage/attachments/<2 chars>/<uuid>.bin`.

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

Folders, tags and attachments follow the same pattern: the folder's, note's or attachment's
workspace is looked up first, `WorkspaceAuthorizer` checks the caller's membership of that
workspace, and the service scopes every query to it. An attachment is reachable only while its
note is active.

## Attachments

```text
editor (button, paste, drop) ─ POST /notes/{id}/attachments (raw bytes, CSRF header)
   → ImageInspector: magic bytes + getimagesizefromstring → PNG/JPEG/GIF/WebP only
   → row in attachments + file written atomically to storage/attachments (one transaction)
   → returns ![name](attachment:<id>) for the note
read view ─ <img src="https://api.dx.se/api/v1/attachments/<id>/content">
   → session cookie (same site, D022) → WorkspaceAuthorizer → file streamed with safe headers
```

## Trash lifecycle

```text
DELETE /notes/{id}          → trashed_at set; note, history and images kept (restorable)
POST /trash/{id}/restore    → trashed_at cleared (Editor+)
DELETE /trash/{id}          → NotePurger (Admin+): rows deleted in one transaction, then files
bin/purge-trash.php (daily) → NotePurger for notes trashed > trash.retention_days days ago
```

## Search

`SearchController` asks `WorkspaceAuthorizer::workspaceIdsWithPermission()` for the workspaces
the caller may read (the same rules as every other endpoint: memberships only, never for
administrators or disabled users), and `SearchService` queries only those, with every word
matched by `LIKE` in the title, content or tag names (D038). Snippets are cut in SQL around the
first hit, so large notes are never loaded whole.

## Frontend

PHP renders page shells and security headers only; it holds no session or credentials. Each
page loads `api-client.js` (fetch wrapper with CSRF handling) and its own script. Data is
inserted into the DOM with `textContent`, never `innerHTML`. A strict CSP allows scripts and
styles from the site itself only, images from the site and the API, and network calls to the
site and the API.

Note Markdown is rendered in the browser (D035) by `markdown-renderer.js` with two vendored
libraries, `assets/vendor/marked-18.0.14` (MIT) and `assets/vendor/dompurify-3.4.16`
(Apache-2.0 / MPL-2.0), loaded only on the note page:

1. marked parses GitHub-flavoured Markdown with raw HTML turned off (typed HTML shows as text).
2. Images render only for `attachment:<id>` references; other image URLs become plain links (D036).
3. DOMPurify sanitises the HTML; a hook adds `rel="noopener noreferrer nofollow"` to links and
   drops any image whose source is not the API's attachment URL. The result is inserted as a
   DOM fragment.

Checklist boxes in the read view are clickable for Editors: a tick rewrites that one `[ ]` in
the source (fenced code blocks are skipped) and saves with the note's revision. The editor
(`note-editor.js`) is a textarea with a formatting toolbar, Write/Preview switch, and image
upload by button, clipboard paste or drag and drop, all through the same upload endpoint.

The PWA is a manifest, the raven app icons and a service worker that caches nothing
(installability only, per CLAUDE.md).
