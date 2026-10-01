# Muninn Architecture Decision Log

This file records accepted project decisions. Add new decisions rather than silently changing established ones.

## D001 — Split frontend and API hosting

**Status:** Accepted

Frontend is hosted externally on `dx.se`. The PHP REST API, database access, and file storage services are hosted through `fehre.synology.me` / NAS infrastructure.

## D002 — Frontend stack

**Status:** Accepted

Use PHP 8+, Bootstrap 5.3, and vanilla JavaScript. Build mobile-first. Do not introduce a major JavaScript frontend framework without explicit approval.

## D003 — Backend stack

**Status:** Accepted

Use PHP 8+ REST-style API with MariaDB/MySQL and NAS filesystem storage for attachments.

## D004 — Account creation

**Status:** Accepted

No public self-registration. Admins issue invitations. Existing users may propose/request a new user, but an admin decides whether an invitation is issued.

## D005 — Workspaces

**Status:** Accepted

Users may belong to multiple workspaces. Every user receives a personal workspace. Workspace roles use a reasonably granular Owner/Admin/Editor/Reader model.

## D006 — Notes V1 content

**Status:** Accepted

V1 supports Markdown, checklists, code blocks, links, and images. Use a unified note model where practical.

## D007 — Organization

**Status:** Accepted

Use folders, tags, and favorites.

## D008 — Attachments

**Status:** Accepted

Attachments live on NAS-backed file storage with database metadata. Clipboard image paste is a desired V1 capability.

## D009 — Version history

**Status:** Accepted

Keep the latest 100 historical note versions. Older versions are removed when the limit is exceeded. UI communicates usage, e.g. `34 / 100`.

## D010 — Concurrent editing

**Status:** Accepted

Use optimistic concurrency/conflict detection. Do not implement real-time collaborative editing in V1.

## D011 — Search

**Status:** Accepted

Provide global search over notes the current user is authorized to read. Server-side authorization is mandatory.

## D012 — Archive and trash

**Status:** Accepted

Support both Archive and Trash. Trash default retention is 30 days and should be configurable.

## D013 — Notifications

**Status:** Accepted

Use ntfy. Connection/server credentials are centrally configurable; users control supported notification preferences.

## D014 — PWA

**Status:** Accepted

Muninn is an installable PWA. Offline editing and synchronization are explicitly excluded from V1.

## D015 — Encryption model

**Status:** Accepted

V1 uses normal server-side access control. End-to-end/client-side encryption is not required. Strict authorization/data isolation remains mandatory.

## D016 — Administration

**Status:** Accepted

Provide an administration interface for users, invitations, requests, workspaces, permissions, configuration, storage, and security-relevant management.

## D017 — Export and backup

**Status:** Accepted

Support portable export for an individual user and complete system-level backup/export for administrators.

## D018 — Integration API

**Status:** Accepted

Support scoped API tokens for external integrations.

## D019 — Magic Links

**Status:** Accepted

Magic Links can target a note or folder. They support configured start/end validity and optional daily active time windows. Raw tokens are shown only at creation and only hashes are stored. Links may be reusable until revoked/expired. Delete permission is excluded from V1.

## D020 — Geotagging

**Status:** Accepted

Notes may optionally be geotagged. Coordinates can be captured by explicit browser geolocation, selected on an OpenStreetMap/Leaflet map, or entered manually. No background location tracking.

## D021 — Project name

**Status:** Accepted as working name

The project is called **Muninn**.


## Course Delivery Decisions

- The six-week Course MVP is the active delivery target.
- `COURSE-MVP.md` is authoritative for course scope and Definition of Done.
- Long-term features may remain documented but are stretch goals unless included in Course MVP.
- Magic Links and geotagging are preferred first stretch goals after MVP completion.
- Week 6 is primarily a shipping, testing, documentation, and deployment week rather than a feature-expansion week.

## D022 — API hostname and session transport

**Status:** Accepted (2026-10-01)

The API stays on the NAS but browsers reach it as `https://api.dx.se`, a DNS CNAME of
`fehre.synology.me` with its own certificate. The frontend is `https://www.dx.se`. Because both
share the `dx.se` site, the session cookie is first-party, which is required for Safari/iOS
(it blocks third-party cookies) and lets `<img>` tags load authorized attachments later.

Sessions are opaque 256-bit random tokens in a `__Host-muninn_session` cookie
(`Secure; HttpOnly; SameSite=Lax; Path=/`), stored server-side only as SHA-256 hashes, with
idle (7 days) and absolute (30 days) expiry. Rejected: bearer tokens in browser storage
(readable by any XSS, cannot authorize image loads). Fallback if DNS is impossible: the
www.dx.se PHP proxies API calls.

## D023 — CSRF defence

**Status:** Accepted (2026-10-01)

Per-session synchronizer token, returned by login and `GET /auth/me`, required in the
`X-CSRF-Token` header on every authenticated state-changing request. Additionally: exact-match
CORS allowlist, refusal of state-changing requests from foreign `Origin`s, and refusal of
non-JSON bodies. The CSRF token is stored raw in the `sessions` row because the API must return
it after a page reload; it is useless without the HttpOnly session cookie.

## D024 — Identifiers

**Status:** Accepted (2026-10-01)

User-visible resources use random UUIDv4 strings (`CHAR(36)`) as primary keys, so IDs reveal
neither counts nor order. Purely internal tables (e.g. `auth_attempts`) may use auto-increment
keys because their IDs are never exposed.

## D025 — System administrator vs workspace roles

**Status:** Accepted (2026-10-01)

`users.is_system_admin` is separate from workspace roles. System administrators manage users,
invitations and memberships but do not gain access to note content in workspaces they are not
members of. The administrator uses a dedicated admin account, separate from their everyday
account.

## D026 — Invitation delivery

**Status:** Accepted (2026-10-01)

No email in the MVP. An administrator creates an invitation (optional note, default 72-hour
expiry, maximum 30 days), copies the one-time link and sends it personally. The token travels in
the URL fragment (`/invite.php#token=…`) so it never reaches server logs or `Referer` headers.
The invitee chooses their username, display name and password. Used, expired, revoked and
unknown invitations return the same response.

## D027 — Password policy and hashing

**Status:** Accepted (2026-10-01)

`password_hash()` with Argon2id when available, otherwise bcrypt. Minimum 12 characters, no
composition rules. With bcrypt the maximum is 72 bytes (bcrypt silently ignores the rest).
Hashes are upgraded transparently at sign-in.

## D028 — Administrator bootstrap

**Status:** Accepted (2026-10-01)

Administrators are created only with `php bin/create-admin.php` over SSH, with a hidden password
prompt. There is no web setup endpoint.

## D041 — Manual, zip-based deployment

**Status:** Accepted (2026-10-01)

(D029–D040 are reserved for the decisions proposed for Weeks 2–5 in the Week 1 plan.)

Nothing deploys automatically. A GitHub Actions workflow builds `muninn-<version>.zip` (API with
production autoloader, frontend, `DEPLOY.md`, `Deploy-Api.ps1`). The frontend is uploaded by
FTP; the API is copied to the NAS with `Deploy-Api.ps1`. Server configuration files are never
part of the zip.

## D042 — API web folder separate from the application folder

**Status:** Accepted (2026-10-01)

On the NAS only the API's `public/` contents (`index.php`, `.htaccess`) sit in the web root
(`/volume1/web/muninn`). Source, vendor, migrations, config and storage live outside any web
root (`/volume1/secrets/muninn`). `Deploy-Api.ps1` writes `app-location.php` next to
`index.php` with the application folder's path; `.htaccess` refuses to serve that file, and
`index.php` falls back to the parent folder when it is absent (the repository and dev layout).

The API can be served from a host root (`api.dx.se`) or a sub-folder (`fehre.synology.me/muninn/`).
The request path is made relative to the folder of `SCRIPT_NAME` (set by the web server, never by
the client), so routes always start with `/api/v1/`.
