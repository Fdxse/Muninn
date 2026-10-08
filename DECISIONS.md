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

## D029 — Workspace role permissions

**Status:** Accepted (2026-10-07, confirmed by the project owner)

Reader: read notes (later also history and search). Editor: also create, edit and delete
(trash) notes. Admin: also add, change and remove Editors and Readers. Owner: also manage Admins
and Owners, rename and delete the workspace. A workspace always keeps at least one Owner; any
member may leave unless they are the last Owner. The matrix lives only in
`Workspaces\WorkspaceRole` and `Workspaces\WorkspacePermission`, and every workspace and note
endpoint checks it through `Workspaces\WorkspaceAuthorizer`. Callers who are not members get
404; members with too weak a role get 403.

## D030 — Who creates shared workspaces

**Status:** Accepted (2026-10-07, confirmed by the project owner)

Any active everyday user may create a shared workspace and becomes its Owner. Members are added
by username; unknown, disabled and administrator accounts all get the same answer. Every user
also gets one personal workspace, created automatically, that cannot be shared or deleted.

## D031 — Disabling users

**Status:** Accepted (2026-10-07, confirmed by the project owner)

Accounts are disabled, never deleted, in the MVP. Disabling revokes all of the user's sessions
and blocks sign-in; their workspaces, memberships and notes stay untouched. An administrator
cannot disable their own account. Hard delete is deferred together with export.

## D032 — Notes do not move between workspaces

**Status:** Accepted (2026-10-07, confirmed by the project owner)

A note's workspace is fixed at creation in the MVP. This keeps attachments and version history
authorized by a single, unchanging workspace.

## D033 — Folders are flat

**Status:** Accepted (2026-10-08, confirmed by the project owner)

Folders are one level deep and belong to one workspace. A note is in at most one folder or in
"No folder". Deleting a folder never deletes notes: they move to "No folder"
(`ON DELETE SET NULL`). Folder names are unique per workspace, ignoring case. Folders are managed
by whoever may edit notes (Editor and up), since they only organise notes.

## D034 — Tags belong to a workspace

**Status:** Accepted (2026-10-08, confirmed by the project owner)

Tags are never global: each tag belongs to one workspace, so a tag name typed in one workspace
can never be seen from another. Tags are set through the note (a list of names), created on
first use and removed when no note, Trash included, uses them. Names are unique per workspace
ignoring case; the first spelling is kept.

## D035 — Markdown rendered in the browser

**Status:** Accepted (2026-10-08, approved by the project owner)

Notes are edited in a plain textarea with a small formatting toolbar and a Write/Preview switch
(mobile-friendly, no rich-text editor). Markdown is rendered in the browser with **marked** (MIT)
and sanitised with **DOMPurify** (Apache-2.0/MPL-2.0), both vendored under
`frontend/public/assets/vendor/` (no CDN), with raw HTML in Markdown disabled and the existing
strict CSP. There is no server-side Markdown library.

## D036 — Only Muninn attachments render as images

**Status:** Accepted (2026-10-08, confirmed by the project owner)

Rendered notes show images only when they reference a Muninn attachment
(`![alt](attachment:<id>)`). Any other image URL is shown as a link instead, so opening a note
never contacts another server (no tracking pixels, no mixed content).

## D037 — When a history version is kept

**Status:** Accepted (2026-10-08, confirmed by the project owner)

Every save keeps the note state it replaces (title, content, folder and tag names) in
`note_versions`, unless the save changes nothing. Saves by the same person within 10 minutes of
the last kept version count as one change: only the state from before that burst is kept. A save
by someone else always keeps the previous state, so merging never loses another person's work.
At most 100 earlier versions are kept per note (D009); the oldest go first. Restoring a version is
an ordinary save that needs the current revision, and the state it replaces is always kept, so a
restore can be undone. Archiving, trashing and restoring from Trash are not edits and add no
version. Readers may see history; Editors and up may restore.

## D038 — Search matches parts of words

**Status:** Proposed (2026-10-08), built on this default while the project owner decides

Search uses `LIKE` on the note title, content and tag names, not MariaDB `FULLTEXT`: every word
typed must occur somewhere, also inside a longer word, so Swedish compound words are found
("möte" finds "mötesanteckningar"). The `utf8mb4_unicode_ci` collation makes matching ignore case
and accents. Note volumes in Muninn are small enough that scanning is fast; `FULLTEXT` (relevance
ranking, whole words only, minimum word length) can be added later if needed. Search covers only
the workspaces `WorkspaceAuthorizer` says the caller may read, never Trash, and the Archive only on
request. At most 50 results; title matches first.

## D039 — Trash purge

**Status:** Proposed (2026-10-08), built on this default while the project owner decides

Deleting a note for good removes the note row, its version history, its tag links, its attachment
rows and the attachment files, and tags no note uses any more. Files are deleted after the database
transaction commits. Two ways lead there, both only for notes already in Trash:

- Admins and Owners may delete one trashed note, or empty the whole Trash, right away
  (`WorkspacePermission::PurgeNotes`). Editors can trash and restore but never destroy data. In a
  personal workspace the user is the Owner, so they can always empty their own Trash.
- `bin/purge-trash.php`, run daily by the Synology Task Scheduler, deletes notes trashed more than
  `trash.retention_days` (default 30, D012) days ago. Without the task, notes simply stay in Trash.

## D041 — Manual, zip-based deployment

**Status:** Accepted (2026-10-01)

(D040 is reserved for password reset, proposed for Week 5 in the Week 1 plan.)

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

## D044 — Administrator accounts have no workspaces

**Status:** Accepted (2026-10-07)

Following D025, system administrator accounts get no personal workspace, cannot create shared
workspaces and cannot be added as members. `WorkspaceAuthorizer` refuses administrators even if
a membership row exists, so an administrator account can never read notes.

## D045 — Deleting a note moves it to Trash from the start

**Status:** Accepted (2026-10-07)

`DELETE /api/v1/notes/{id}` sets `notes.trashed_at` instead of removing the row, so no Week 2
delete is permanent and Editors never destroy data (D029 reserves permanent deletion). Trashed
notes are invisible through the API until Week 4 adds the Trash view, restore and purge. A
shared workspace can only be deleted when it holds no notes at all, Trash included.

The `notes.revision` column for optimistic concurrency (D010) is part of the Week 2 schema:
every update must name the revision it was based on and a stale one is refused with 409.

## D046 — Resetting test data from the command line

**Status:** Accepted (2026-10-07, confirmed by the project owner)

`bin/reset-data.php` resets an installation to "system administrators only": it deletes every
non-admin account with its sessions, plus all workspaces, memberships, notes (Trash included),
invitations and sign-in attempt records, in one transaction. Administrator accounts and their
sessions, the audit log and the schema are kept, and the reset itself is audited as
`system.data_reset`. It refuses to run when no administrator exists, always shows row counts
first, offers `--dry-run`, and deletes only after the exact phrase `DELETE ALL USER DATA` is typed.

This is a server-operator tool for clearing out test data, not account deletion: D031 still
holds for the web interface (accounts are disabled, never deleted), and there is deliberately no
API endpoint, so a stolen admin session can never wipe the system. Since Week 3 it also deletes
folders, tags, attachments and the attachment files, and since Week 4 the note history.

## D043 — Brand colour palette

**Status:** Accepted (2026-10-07)

The colour values on the brand sheet (`assets/branding/reference/muninn-brand-sheet.png`) are the
production palette: `#0B1A2B` primary, `#2F4B6E` secondary, `#D4AF7C` accent gold, `#E6E9EE`
page background, `#1F2937`/`#6B7280` text, `#10B981`/`#EF4444`/`#F59E0B` status. They live as CSS
variables in `frontend/public/assets/css/muninn.css` and are mapped onto Bootstrap 5.3's variables.

A few derived shades are added where the sheet colours alone would fail WCAG AA contrast: darker
gold for gold text on light backgrounds, a darker gray for muted text on the `#E6E9EE` page, pale
tints with dark text for alerts, and navy text on the bright status badges.

Headings use Merriweather and body text uses Inter, as on the sheet (approved by the project
owner 2026-10-07). Both are self-hosted under `frontend/public/assets/vendor/fonts/` (variable
weight, Latin subset, SIL Open Font License) so the CSP stays `font-src 'self'` and no request
goes to Google.

The production image set (logos, app icons, favicons, splash, hero and UI icons) was supplied by
the project owner on 2026-10-07 and is kept unchanged in `assets/branding/production/`. The
frontend uses copies of the app icons and favicons, a 64px navigation-bar symbol and an 800px
WebP of the dark horizontal logo; the full-size originals are not deployed.

## D047 — Image attachments

**Status:** Accepted (2026-10-08, confirmed by the project owner)

Attachments are images (PNG, JPEG, GIF, WebP; never SVG) of at most 10 MB (configurable),
belonging to one note. The type is detected from the file's bytes and checked with
`getimagesizefromstring()`, so no PHP image extension is needed. Uploads are the raw file as the
request body (with the CSRF header), which lets clipboard paste and the file picker share one
endpoint. Files are stored outside the web root as `storage/attachments/<2>/<uuid>.bin` and
served only through `GET /api/v1/attachments/{id}/content` after the note's workspace membership
is checked; attachments of trashed notes are unavailable. A new note is saved automatically when
its first image is added, so the image has a note to belong to. Removing single attachments is
left out of the MVP; files go with their note when Trash purge arrives (D039).

## D048 — Archive

**Status:** Accepted (2026-10-08, minor implementation choice)

Archiving sets `notes.archived_at`. Archived notes stay readable and editable by the same roles,
keep their attachments, and appear in search when the user ticks "Include archived notes"; they
leave the workspace's normal note list and the folder and tag counts, and are shown in the
workspace's Archive view instead. Archiving is done by Editors and up, is not an edit (no new
revision or history version), and a trashed note that is restored returns to the Archive if it was
archived.
