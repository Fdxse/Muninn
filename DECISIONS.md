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

**Status:** Accepted (2026-10-08, confirmed by the project owner). The "one level" part is
replaced by D055 (sub-folders); the rest still applies.

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

**Status:** Accepted (2026-10-08, confirmed by the project owner)

Search uses `LIKE` on the note title, content and tag names, not MariaDB `FULLTEXT`: every word
typed must occur somewhere, also inside a longer word, so Swedish compound words are found
("möte" finds "mötesanteckningar"). The `utf8mb4_unicode_ci` collation makes matching ignore case
and accents. Note volumes in Muninn are small enough that scanning is fast; `FULLTEXT` (relevance
ranking, whole words only, minimum word length) can be added later if needed. Search covers only
the workspaces `WorkspaceAuthorizer` says the caller may read, never Trash, and the Archive only on
request. At most 50 results; title matches first.

## D039 — Trash purge

**Status:** Accepted (2026-10-08, confirmed by the project owner)

Deleting a note for good removes the note row, its version history, its tag links, its attachment
rows and the attachment files, and tags no note uses any more. Files are deleted after the database
transaction commits. Two ways lead there, both only for notes already in Trash:

- Admins and Owners may delete one trashed note, or empty the whole Trash, right away
  (`WorkspacePermission::PurgeNotes`). Editors can trash and restore but never destroy data. In a
  personal workspace the user is the Owner, so they can always empty their own Trash.
- The API itself deletes notes trashed more than `trash.retention_days` (default 30, D012) days
  ago, so nothing has to be scheduled on the NAS. After answering a signed-in request it runs the
  cleanup at most once an hour (claimed atomically in `maintenance_runs`, so simultaneous requests
  never both run it), at most 500 notes per run, after the response has been sent where PHP-FPM
  allows it. Anonymous requests never trigger it. A failure is logged and never affects the
  user's request. On days nobody signs in, nothing is deleted until the next visit.
  `bin/purge-trash.php` runs the same cleanup by hand.

## D040 — Password reset links

**Status:** Accepted (2026-10-08, approved by the project owner)

There is no email and no self-service "forgot password". A system administrator creates a one-time
reset link for a user on the Users page and sends it personally, like an invitation (D026). The
token is 256-bit random, stored only as a SHA-256 hash in `password_resets`, travels in the URL
fragment (`/reset-password.php#token=…`), is valid for 24 hours by default (at most 72) and works
once. Creating a new link, disabling the account, or the user changing their own password revokes
older unused links. Using a link sets the new password and signs the account out on every device;
the user then signs in normally. The reset page shows the username so the person knows which
account they are resetting. Used, expired, revoked and unknown links give the same answer, and
wrong-token guesses are rate limited per IP like invitation tokens. An administrator cannot create
a link for their own account and cannot reset a disabled account.

Signed-in users change their own password with their current password
(`POST /api/v1/auth/password`); a wrong current password counts as a failed sign-in for rate
limiting, and every other session of the account is signed out.

## D041 — Manual, zip-based deployment

**Status:** Accepted (2026-10-01)

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

## D049 — Invitation requests

**Status:** Accepted (2026-10-08, approved by the project owner)

Any everyday user can ask for someone to be invited, with a short note on who and why (at most 5
open requests per user). A system administrator approves or declines each request on the
Invitations page. After approval, the user who asked creates the invitation link on their Account
page and sends it themselves; the link is an ordinary invitation (D026: hashed, single use, the
default invitation lifetime of 72 hours) whose creator is that user, so it also shows in the
administrator's invitation list. Creating a new link revokes the previous one, so a lost link can be
replaced. The administrator can still decline (withdraw) an approved request until its link has
been used, and the user can cancel; both revoke the link. Once the link is used, the request is
complete. An invitation stops working when the account that created it is disabled. Users only
ever see their own requests; administrator accounts invite directly and cannot file requests.
The invited person chooses their own username, as with every invitation.

## D050 — System administrators rescue shared workspaces without an Owner

**Status:** Accepted (2026-10-08, chosen by the project owner)

D025 says administrators manage memberships without note access. The admin Workspaces page lists
every shared workspace with its Owners, member count and whether it has an active Owner (never
note titles, counts or content). An administrator can manage the members only of a workspace
with no active Owner left, typically because its only Owner was disabled: add members, change
roles and remove members with the same rules as an Owner, including "a workspace keeps at least
one Owner". As soon as the workspace has an active Owner again, its member endpoints answer 404 to
administrators and its Owners take over. So an administrator who also has an everyday account
cannot add that account to a workspace that has a working Owner. Administrators still cannot be
members themselves (D044), the note, search and attachment endpoints still refuse them, and
personal workspaces are never listed or manageable. Every change is audited with `by_system_admin`.

## D051 — Note previews show plain text, not raw Markdown

**Status:** Accepted (2026-10-08, asked for by the project owner)

The one-line preview under each note in the note list and the Trash, and the search result
snippet, are plain text made by the API (`Notes\MarkdownExcerpt`): heading, quote and list
markers, emphasis, code fences, link addresses, images and HTML tags are removed, checklist boxes
become ☐ and ☑, and a first line that repeats the note's title is left out. It is a small set of
line-by-line rules, not a Markdown parser, and the frontend still shows the result with
`textContent`. The API reads the first 600 characters for this and returns at most 160.
Rendering formatted Markdown in the list was not chosen: it would load marked and DOMPurify on
every list page for a single truncated line.

## D052 — Demo data from the command line

**Status:** Accepted (2026-10-08, minor implementation choice for the Week 6 demo)

`bin/seed-demo.php` creates two everyday accounts (`demo.anna`, `demo.erik`) with random
24-character passwords printed once, a shared workspace where Anna is Owner and Erik Editor, and
sample notes through the same services the API uses. It refuses to run when either username
exists, so it never changes existing data, and offers `--dry-run`. There is no web endpoint for
it and no "remove demo" command: the demo accounts are disabled on the admin Users page like any
other account, or a test server is cleared with `bin/reset-data.php` (D046).

## D053 — The live NAS keeps the API in one folder with `public/` as document root

**Status:** Accepted (2026-10-08, records the live setup from 2026-10-07)

On the NAS the whole API lives in `/volume1/Muninn` and the Web Station web service's document
root is `/volume1/Muninn/public`. Source, `vendor`, configuration and storage are therefore
outside the document root, which is what D042 is for, without a second folder.
`Deploy-Api.ps1` supports this by pointing `-PublicTarget` at the `public` subfolder and
`-AppTarget` at the folder itself; the `app-location.php` it writes then simply names the parent
folder, which `index.php` would use anyway. D042's split layout stays supported for hosts
whose document root cannot be a subfolder. On the NAS, command-line scripts run as
`sudo php84 bin/<script>.php` from `/volume1/Muninn`, and `bin/check-setup.php` reports anything
that would break or weaken the installation (it changes nothing).

## D054 — Syntax highlighting with a custom highlight.js build

**Status:** Accepted (2026-10-08, library approved by the project owner)

Code blocks in notes are coloured by highlight.js 11.12.0 (BSD-3-Clause), vendored in
`assets/vendor/highlightjs-11.12.0` like marked and DOMPurify (no CDN). It is a custom build from
the official build tool with 12 languages (PHP, JavaScript, SQL, HTML/XML, CSS, JSON, Bash,
PowerShell, Python, YAML, Markdown, C#): about 79 KB, 27 KB gzipped, against about 129 KB for
the stock "common" build. It loads only on the note and history pages. Highlighting happens in
marked's code renderer, before DOMPurify, so its output is sanitised like everything else. A
block's language comes from its fence (```` ```php ````); a block without one is coloured only
when automatic detection is confident, and an unknown language stays plain. The colours are
Muninn palette tokens in `muninn.css` rather than a stock theme, each at least 4.5:1 on white.

## D055 — Sub-folders, at most 3 levels deep

**Status:** Accepted (2026-10-08, confirmed by the project owner)

A folder may sit inside another folder of the same workspace, at most 3 levels deep (a top-level
folder is level 1). Migration 0006 adds `folders.parent_folder_id`; existing folders become
top-level folders. Folder names stay unique per workspace (D033), so a name always identifies
one folder in pickers and moving a folder can never clash with a sibling's name.

- Folders can be created inside a folder and moved (`parent_id` on POST and PATCH). The API
  refuses a parent from another workspace (same 422 as an unknown ID), a move into the folder
  itself or one of its sub-folders, and anything that would put a folder deeper than level 3.
- Every tree change locks the workspace row first, so concurrent moves cannot build a loop.
- Deleting a folder moves its notes (Archive and Trash included) and its sub-folders up one
  level, into its parent; for a top-level folder that is "No folder" and the top level, as
  before. Nothing is deleted with a folder.
- Choosing a folder in the note list also lists the notes in its sub-folders. The list returns
  `note_count` (notes directly in the folder) and `total_note_count` (sub-folders included).

## D056 — Removing one image from a note

**Status:** Accepted (2026-10-08, confirmed by the project owner)

`DELETE /api/v1/attachments/{id}` removes one image for good: its row, then its file, and writes
an `attachment.deleted` audit entry. It needs note-editing rights (Editor and up) on an active
note; trashed notes keep their images for a restore. The API does not change the note's text:
the editor's "Images on this note" list removes the image's Markdown from the text it is editing
and the user saves as usual, so the note's revision and history work exactly as for any edit.
An image that no longer exists (a removed image in an unsaved note, or in an old version) is
shown as its name followed by "(image removed)" instead of a broken image.

## D057 — Administrator push notifications through the NAS's own ntfy

**Status:** Accepted (2026-10-08, confirmed by the project owner, who runs ntfy on the NAS)

The API sends push notifications to the administrator through the ntfy server on the NAS. The
first version sends two kinds of message and nothing else:

- **New invitation request** (D049): who asked, with a link to Admin > Invitations. The
  request's note (who should be invited and why) stays in Muninn.
- **Sign-ins blocked** by the rate limiter: the username and address that reached the limit, or
  the address alone when it is blocked for every username. Sent once, by the failure that
  causes the block, and at most 10 times an hour across all addresses, so password guessing
  from many addresses cannot flood the phone. Passwords never appear.

Settings (`ntfy` in `config.php`: enabled, server address, topic, optional access token,
timeout) are server-side only and never reach the frontend; they are validated at startup.
The recommended address is the local one (`http://127.0.0.1:<port>`), so messages and the token
never leave the NAS. Messages are queued while a request is answered and sent after the
response (like the Trash cleanup, D039), so a slow or stopped ntfy never delays or breaks a
request; failed sends are logged with the HTTP status but never the token. Each queued message
is recorded in the audit log (`notification.admin_queued`), which also counts the hourly cap.
It is sent as ntfy JSON with PHP's curl extension, or PHP's HTTP stream when curl is missing,
so no library is added. `bin/send-test-notification.php` checks the settings on the server.

Not in this version: per-user notification preferences (PROJECT.md), notifications to
everyday users, and editing ntfy settings in the admin pages.

## D058 — "Contact admin": users message the administrator through ntfy

**Status:** Proposed (2026-10-08, asked for by the project owner; awaiting review in the PR)

Every everyday user has a "Contact admin" button in the top bar. It opens a dialog with a
message (up to 1000 characters, line breaks kept) and an optional "How can the admin reach
you?" field. `POST /api/v1/admin-messages` pushes it to the administrator's ntfy topic (D057)
with the sender's display name and username in the title.

- **Sent straight away, not after the response** (unlike D057's alerts): the user is told
  whether ntfy accepted it (`503 delivery_failed` otherwise), so nobody believes a lost
  message arrived.
- **Not stored.** Muninn keeps only an audit entry (`admin_message.sent`) without the text or
  the contact details. Accounts have no email address, so the optional contact field is how
  the administrator answers, outside Muninn.
- **At most 5 messages per user per hour** (attempts count, delivered or not), counted from
  the audit log; more get `429`. The button cannot be used to flood the administrator's phone.
- Administrator accounts get no button and a `403` from the API: they are the recipient.
- When ntfy is off the dialog says messages are not set up and the API answers
  `503 messages_unavailable`.
- Control characters are removed from the text before it is sent; the message is JSON, so it
  cannot inject ntfy headers.

Not in this version: answering inside Muninn (an inbox for the administrator and replies the
user sees on their next visit). That would need a table and pages of its own.

## D059 — Magic Links: first version

**Status:** Proposed (2026-10-08). The project owner chose to build it with whole-workspace links
and the defaults below; awaiting review in the PR.

Builds on D019 (Accepted) and adds a third target: a whole workspace.

- **Targets.** A whole workspace, a folder (including its sub-folders, D055), or one note. A link
  reaches nothing outside its target: other folders, notes, history, Trash, search, tags,
  members and other workspaces all answer 404 as if they did not exist. Personal workspaces may
  have links too (their Owner decides).
- **Who creates them.** Workspace Admins and Owners only (the same role as managing members,
  since a link lets people in much like a member). A link works only while its creator is an
  active account and still an Admin or Owner of the workspace. System administrators have an
  overview (`admin/magic-links.php`) and can revoke any link, but cannot create links, and the
  overview shows no link names, folder names or note titles (like D050).
- **Access.** Read by default. Write = edit the title and content of reachable notes, create
  notes (workspace and folder links), and add images through the normal upload checks. Never
  delete, trash, archive, move, change tags, restore history or manage anything (D019).
  Visitors never see member names. Saves go into the note's history like any other save and are
  recorded under the link's creator (the database needs an account), with the link's ID in the
  audit log (`magic_link.note_updated`, `magic_link.note_created`).
- **Validity.** Every link has a start (default now) and an end (default 30 days, at most 365
  days from today; configurable as `magic_links.default_valid_days` / `max_valid_days`). There
  are no links that never expire. Optional daily window (e.g. 07:00-18:00, or 22:00-06:00 over
  midnight), start included and end excluded, evaluated in `magic_links.timezone` (default
  `Europe/Stockholm`), never the server's own zone. Stored timestamps stay UTC; the API refuses
  timestamps without an explicit offset instead of guessing.
- **Revoking.** Any Admin or Owner of the workspace can revoke a link; it stops at once, also in
  browsers that have it open. A link also stops when its folder is deleted or its note is
  trashed. Revoked and expired links stay listed.
- **Token.** 256-bit random, base64url. Only its SHA-256 hash is stored and looked up through a
  unique index (the token is random and long, so a slow password hash adds nothing). The full
  link is shown once at creation and never again, and never logged. Hashing does not make it
  single-use: the link is reusable until it expires or is revoked.
- **How the browser uses it.** The link is `<frontend>/link.php#token=<token>`. The token sits
  after `#`, so it never reaches web server logs or `Referer` headers, and it stays in the
  address bar so the page can be bookmarked. The page posts it to `POST /api/v1/link/open`,
  which sets a separate `__Host-muninn_link` cookie (same flags as D022) for at most
  `magic_links.visit_hours` (default 12) and never beyond the link's end. The visit cookie works
  only on the `/api/v1/link/` endpoints and the sign-in cookie never works there. Every request
  checks the link again: revoked, dates, daily window, creator, target. State changes need the
  visit's own CSRF token (D023).
- **Rate limit.** Unknown tokens count towards the same per-IP limit as invitation tokens.
  Unknown, revoked, expired and not-yet-valid links give the same 404; only a working link
  outside its hours says so (403 with its hours), which only a token holder can learn.
- **Audit.** Created, revoked and every opening are audited; the list shows when a link was
  last opened and how many times.
- **Database.** Migration `0007_magic_links`: `magic_links` and `magic_link_sessions` (hashed
  visit tokens). `bin/reset-data.php` removes both.

## D060 — Administrator Overview page and audit log archives

**Status:** Proposed (2026-10-09). The project owner asked for the page and chose the two
options below; awaiting review in the PR.

A new admin page, Overview (`admin/overview.php`, first in the administrator's top bar), shows
what an administrator needs to spot problems early. It is served by
`GET /api/v1/admin/overview` (system administrators only, 404 for everyone else).

- **What it shows.** A "Needs attention" list that is empty when all is well (low disk space,
  pending migrations, development mode, blocked or many failed sign-ins, Trash cleanup behind,
  errors in the application log, a large log file, zip extension missing; plus notices for
  archivable audit entries, waiting invitation requests and Magic Links about to expire). Then
  users (active, disabled, signed in recently, never or not for 90 days, open sessions), content
  totals and notes created per week, weekday × hour heat maps of sign-ins and Magic Link visits
  over 4 or 12 weeks, sign-in attempts, database size and largest tables, disk space, and the
  audit log with its archives.
- **No new tracking.** Everything comes from data Muninn already had: the audit log
  (`auth.login_*`, `magic_link.opened`), the tables themselves, `information_schema`, the disk
  and the log file. A Magic Link "visit" is one opening in a browser (one per visit cookie),
  not every page view.
- **Privacy.** Counts, sizes and time grids for the whole installation only. Nothing names a
  note, folder, tag or Magic Link (D025, D050, D059), there is no per-user heat map or timeline,
  and the users page's "last sign-in" stays the only per-person activity.
- **Time zone.** Heat maps and weeks use `magic_links.timezone` (default `Europe/Stockholm`),
  never the server's zone. The database groups per UTC hour and PHP moves each hour into the
  display zone, so MariaDB's time zone tables are not needed.
- **Typed usernames are kept, shown masked.** Failed sign-ins keep the username as it was
  submitted (lower-cased and trimmed, as the sign-in check uses it), because a pattern such as
  many tries of `admin` is worth seeing. People sometimes type a password into the username
  field, so the page shows them masked (`a•••n`, always three dots so the length stays hidden)
  with a mark when the name belongs to a real account. "Reveal usernames" asks the API again
  with `?reveal=true`, and every reveal is recorded in the audit log
  (`admin.sign_in_usernames_revealed`).
- **The audit log is kept forever**, with an "Archive old entries" button. Entries older than
  `audit_log.archive_after_months` (default 13) are written as JSON lines into
  `audit-log-<UTC date>-<time>.zip` (with a `manifest.json`) in `audit_log.archive_path`
  (default `storage/audit-archives`, outside the web root and inside the NAS backup). The zip is
  opened again and read back; only when it holds every exported entry are those entries deleted,
  in one transaction that is rolled back if the count differs. Any failure leaves the database
  untouched. JSON lines rather than CSV keeps every value exactly as stored and cannot carry
  spreadsheet formulas from typed usernames. Archives are listed on the page and downloaded
  through the API only (`audit_log.archived` and `audit_log.archive_downloaded` are audited);
  they are never public files and the API never deletes them. A MariaDB named lock stops two
  archives running at once. Needs PHP's zip extension; the page and `check-setup` say when it
  is missing.
- **No chart library.** The heat map is an HTML table with CSS shades of the brand slate, with
  the counts readable by screen readers and on hover; the weekly bars are plain CSS.

Not in this version: push notifications when a warning appears, per-workspace numbers, and
deleting archives from the page.


## D061 — Broadcast messages from the administrator

**Status:** Proposed (2026-10-09). The project owner asked for three kinds of broadcast and for
every vote to reach him on ntfy; awaiting review in the PR.

The system administrator writes messages that every signed-in everyday user sees under the top
bar, on a new admin page, Broadcasts (`admin/broadcasts.php`).

- **Three kinds.** A *one-time banner* is shown once per user and then never again. A *sticky
  banner* is shown on every page until the user closes it with its X. A *vote* is a question with
  2-5 answers, single or multiple choice, shown until the user has voted. A vote cannot be changed
  once given, and users never see the results.
- **Schedule.** Every broadcast has a start and an end (both required, stored in UTC), so it can
  be set up in advance; outside that period it is not shown and cannot be answered (404, like an
  unknown one). The admin page enters times in the browser's own time zone and sends them as UTC;
  the API refuses times without an explicit offset (as D059). "End now" moves the end to the
  present.
- **Per user, on every device.** Being done with a broadcast is one row in `broadcast_receipts`
  (user + broadcast): a one-time banner gets it when the page has shown it, a sticky one when its
  X is pressed, a vote when it is cast. So a closed banner stays closed on the phone and the
  computer alike. A user signed in before the start sees it on the next page they open; "when
  people log in" means "the first page they see while it runs".
- **Audience.** Everyday users only. Administrator accounts write the broadcasts and are not shown
  them (D025). Magic Link visitors (D059) are not signed in and see none.
- **Plain text.** Messages and answers are plain text with line breaks, inserted as text in the
  browser, never as HTML or Markdown. Message up to 1000 bytes, answers up to 100 characters.
- **Fixed answers.** The kind and the answers of a vote cannot be edited, so every vote keeps
  meaning what the voter saw; only the text and the schedule can change. Deleting a broadcast
  deletes its receipts and votes.
- **Results and privacy.** The admin page shows, per broadcast, how many users have seen, closed
  or voted, and for votes the count per answer and who chose it. Each vote is also pushed to the
  administrator through ntfy (D057) after the response, with the voter's name, the chosen answers
  and the question; each user votes once, so this cannot flood. The ntfy message is audited as
  `notification.admin_queued` like the others; creating, editing and deleting are audited as
  `broadcast.created`, `broadcast.updated` and `broadcast.deleted`.
- **Database.** Migration `0008_broadcasts`: `broadcasts`, `broadcast_options`,
  `broadcast_receipts`, `broadcast_votes`. `bin/reset-data.php` removes the receipts and votes of
  the accounts it deletes and keeps the broadcasts.

Not in this version: colours or levels per banner, Markdown or links in messages, targeting
some users only, and changing a vote.

## D062 — Chat

**Status:** Proposed (2026-10-09). The project owner asked for chat with four levels per user and
chose a 90-day retention, a separate global channel, and no chat access for administrators;
awaiting review in the PR.

Everyday users chat on a new page, Chat (`chat.php`, in the top bar). There is one channel per
**shared** workspace and one **global** channel called "Everyone". There are no private
one-to-one messages in this version.

- **Chat level per account.** The system administrator sets it on the Users page
  (`users.chat_access`):
  1. `off`: no chat at all, not even reading the global channel;
  2. `own_workspaces`: chat only in shared workspaces where the user is Owner;
  3. `member_workspaces` (the default, also for existing accounts): chat in every shared
     workspace they are a member of;
  4. `global`: level 3, plus writing in the global channel ("shout out").
  Every level except `off` may read the global channel. A change applies on the user's next
  request.
- **The workspace role still applies.** The level only ever takes access away. In a workspace
  chat every member reads, Editors and up write, and Admins and Owners may delete anyone's
  message. Access is checked through `WorkspaceAuthorizer` on every request, so a non-member gets
  404 exactly as for notes, and a removed member loses the chat at once. Personal workspaces have
  no chat (403). All rules live in one class, `Chat\ChatPolicy`.
- **Administrators cannot read chat.** Administrator accounts get no chat at all, not even the
  global channel (as D025 for notes). They set levels but see no messages, counts or excerpts.
  As a consequence nobody moderates the global channel: authors delete their own messages there,
  and the administrator controls who may write in it by giving out the `global` level.
- **Polling, not websockets.** The NAS serves plain PHP requests, so an open chat asks
  `?since=<cursor>` every 10 seconds while the tab is visible (hidden tabs skip their rounds and
  catch up when shown). The cursor is the database clock with microseconds; each poll looks five
  seconds behind it so a message committed a moment late is never skipped, and the browser
  ignores messages it already shows. A poll returns new messages and deleted ones (ID only); if
  more than 200 things changed, it tells the browser to reload the channel instead. Polling keeps
  the session active while a chat is open.
- **Messages.** Plain text with line breaks, 1-2000 characters, inserted as text in the browser
  (never HTML or Markdown). No editing; deleting removes the text at once and keeps an empty
  marker until the retention cleanup, so other screens learn about the deletion. At most 10
  messages per user per minute (429 `chat_rate_limited`), counted from the messages themselves.
- **Retention: 90 days.** `chat.retention_days` (default 90). The API deletes older messages by
  itself after a signed-in request, at most once an hour, like the Trash cleanup (D039); each run
  is audited as `chat.expired_deleted` with a count. Deleting a workspace deletes its chat.
- **Audit and privacy.** Message text never goes to the audit log or the application log.
  Audited: `user.chat_access_changed` (from, to), `chat.message_removed_by_moderator` (message,
  workspace and author IDs) and `chat.expired_deleted`. A user deleting their own message is not
  audited.
- **Magic Link visitors** (D059) are not signed in and never see chat.
- **Database.** Migration `0009_chat`: `users.chat_access` and `chat_messages`.
  `bin/reset-data.php` deletes all chat messages (administrators never write any).

Not in this version: private messages, unread counts or notifications (for example ntfy on
mentions), editing messages, attachments or links, Markdown, and moderation of the global
channel by the administrator.
