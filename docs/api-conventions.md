# API conventions

Base path: `/api/v1/`. All bodies are JSON (`Content-Type: application/json`).

## Envelope

Success:

```json
{ "data": { "...": "..." } }
```

Error:

```json
{ "error": { "code": "validation_failed", "message": "Some fields are invalid.", "fields": { "username": "..." } } }
```

`code` is stable and meant for programs; `message` is meant for people. `fields` appears only
on validation errors. Every response has an `X-Request-Id` header; 500 responses also put it
in the message so a user can quote it, and the same ID is in the server log.

## Status codes

| Status | Used for | Example codes |
|---|---|---|
| 200 | Successful read or action with a body | |
| 201 | Created | invitation created, invitation accepted |
| 204 | Success without a body | logout, revoke |
| 400 | Malformed request | `malformed_json` |
| 401 | Not signed in, or failed login; a Magic Link visit that no longer works | `unauthenticated`, `invalid_credentials`, `link_unavailable` |
| 403 | Signed in but the request is not acceptable | `csrf_failed`, `origin_not_allowed`, `insufficient_role`, `admin_account`, `link_read_only`, `link_cannot_create`, `link_outside_hours` |
| 404 | Not found **or not yours to know about** | `not_found`, `invitation_invalid`, `link_unavailable` |
| 405 | Wrong method (with `Allow` header) | `method_not_allowed` |
| 409 | State conflict | `invitation_not_pending`, `revision_conflict`, `last_owner`, `already_member`, `personal_workspace`, `workspace_not_empty`, `cannot_disable_self`, `folder_name_taken`, `folder_limit`, `attachment_limit` |
| 413 | Upload too large | `file_too_large` |
| 415 | Body is not JSON (or, for uploads, not an image) | `unsupported_media_type` |
| 422 | Semantically invalid input | `validation_failed` |
| 429 | Rate limited (with `Retry-After`) | `rate_limited` |
| 500 | Internal error, details only in the log | `internal_error` |
| 503 | Fresh install: `config/config.php` does not exist yet | `not_set_up` |

Admin endpoints answer **404** to signed-in non-admins so they are not advertised. Used,
expired, revoked and unknown invitation tokens all give the same 404 body.

## Authentication and CSRF

- `POST /auth/login` sets the session cookie (`__Host-muninn_session`, `Secure; HttpOnly;
  SameSite=Lax; Path=/`) and returns `csrf_token`.
- `GET /auth/me` returns the user and the `csrf_token` (used after a page reload).
- Every authenticated `POST`/`PUT`/`PATCH`/`DELETE` must send `X-CSRF-Token: <csrf_token>`.
- Browsers must call with `credentials: "include"`. Only origins in `cors.allowed_origins`
  receive CORS headers; the value is the exact origin, never `*`.
- Non-JSON bodies are refused (415), so classic cross-site HTML forms cannot reach handlers.

## Endpoints

| Method | Path | Access | Purpose |
|---|---|---|---|
| GET | `/api/v1/health` | public | `{"status":"ok"}` only |
| POST | `/api/v1/auth/login` | public | `{username, password}` → user + `csrf_token` |
| POST | `/api/v1/auth/logout` | user | revoke the session (204) |
| GET | `/api/v1/auth/me` | user | user + `csrf_token` |
| POST | `/api/v1/auth/password` | user | `{current_password, new_password}` → 204; other sessions signed out (D040) |
| POST | `/api/v1/password-resets/inspect` | public | `{token}` → `{valid, username, expires_at}` or 404 `password_reset_invalid` |
| POST | `/api/v1/password-resets/complete` | public | `{token, password}` → 204; signed out everywhere (D040) |
| POST | `/api/v1/invitations/inspect` | public | `{token}` → `{valid, expires_at}` or 404 |
| POST | `/api/v1/invitations/accept` | public | `{token, username, display_name, password}` → 201, signed in |
| GET | `/api/v1/admin/invitations` | system admin | list (never tokens) |
| POST | `/api/v1/admin/invitations` | system admin | `{note?, expires_in_hours?}` → 201 with one-time `invitation_url` |
| DELETE | `/api/v1/admin/invitations/{id}` | system admin | revoke a pending invitation (204) |
| GET | `/api/v1/admin/users` | system admin | list accounts (never password hashes) |
| POST | `/api/v1/admin/users/{id}/disable` | system admin | disable and sign out everywhere (204) |
| POST | `/api/v1/admin/users/{id}/enable` | system admin | re-enable (204) |
| POST | `/api/v1/admin/users/{id}/password-reset` | system admin | `{expires_in_hours?}` (1-72, default 24) → 201 with one-time `reset_url` (D040) |
| GET | `/api/v1/invitation-requests` | user | the caller's own requests, with `status` and `link` state (D049) |
| POST | `/api/v1/invitation-requests` | user | `{note}` → 201; at most 5 open → 409 `too_many_open_requests` |
| DELETE | `/api/v1/invitation-requests/{id}` | user (own) | cancel; revokes its link (204) |
| POST | `/api/v1/invitation-requests/{id}/link` | user (own, approved) | → 201 with one-time `invitation_url`; replaces an earlier link |
| GET | `/api/v1/admin-messages` | user (not admin) | `{max_per_hour, remaining_this_hour, unread_count}` for the Contact admin dialog (D058, D065) |
| POST | `/api/v1/admin-messages` | user (not admin) | `{message, contact?}` → 201 `{sent, conversation_id, notified, remaining_this_hour}`: the message starts a conversation in the administrator's inbox (D065); `notified` says whether ntfy accepted the phone alert (ntfy off or down still stores it); 429 after 5 an hour |
| GET | `/api/v1/admin-messages/unread` | user (not admin) | `{unread_count}`: own conversations with an administrator answer not opened yet (D065) |
| GET | `/api/v1/admin-messages/conversations` | user (not admin) | own conversations, newest first: `id`, `status` (`open`\|`closed`), `excerpt`, `contact_details`, `message_count`, `last_message_by` (`user`\|`admin`), `unread`, `created_at`, `last_message_at`; plus `retention_days` |
| GET | `/api/v1/admin-messages/conversations/{id}` | owner only | `{conversation (+ can_reply), messages, limits}`; each message `id`, `body`, `created_at`, `from` (`user`\|`admin`), `author_name` (administrators always "Administrator"), `is_own`. Marks it read. Anyone else's → 404 |
| POST | `/api/v1/admin-messages/conversations/{id}/messages` | owner only | `{message}` (1-1000 characters) → 201 with the conversation; closed → 409 `conversation_closed`; 200 messages → 409 `conversation_full`; more than 20 replies an hour → 429. ntfy alert after the response |
| GET | `/api/v1/admin/conversations` | system admin | the inbox, `?status=open` (default) \| `closed` \| `all`: like the user's list plus `user` {`id`, `username`, `display_name`, `status`} and `closed_at`; plus `unread_count`, `retention_days`; other values → 400 `invalid_status` |
| GET | `/api/v1/admin/conversations/unread` | system admin | `{unread_count}`: conversations with a user message no administrator has opened |
| GET | `/api/v1/admin/conversations/{id}` | system admin | same shape as the user's view; `author_name` shows which administrator answered. Marks it read for all administrators |
| POST | `/api/v1/admin/conversations/{id}/messages` | system admin | `{message}` → 201; reopens a closed conversation; audited as `admin_conversation.admin_replied` (no text) |
| POST | `/api/v1/admin/conversations/{id}/close` | system admin | the user can read but not reply; audited as `admin_conversation.closed` |
| POST | `/api/v1/admin/conversations/{id}/reopen` | system admin | audited as `admin_conversation.reopened` |
| GET | `/api/v1/admin/invitation-requests` | system admin | every request, pending first |
| GET | `/api/v1/admin/invitation-requests/pending-count` | system admin | `{pending_count}`: requests awaiting a decision (nav badge) |
| POST | `/api/v1/admin/invitation-requests/{id}/approve` | system admin | pending → approved |
| POST | `/api/v1/admin/invitation-requests/{id}/decline` | system admin | pending or unused approval → declined; revokes its link |
| GET | `/api/v1/admin/workspaces` | system admin | shared workspaces with `owners`, `member_count`, `active_owner_count`; no note data (D050) |
| GET/POST | `/api/v1/admin/workspaces/{id}/members` | system admin | only workspaces with no active Owner (else 404): list, or `{username, role}` add, with Owner rules |
| PATCH/DELETE | `/api/v1/admin/workspaces/{id}/members/{userId}` | system admin | same restriction; `{role}` change, or remove; last Owner → 409 |
| GET/POST | `/api/v1/admin/open-workspaces` | system admin | Shared Workspaces (D067): list `{id, name, description, member_count, pending_request_count}`, or `{name, description}` create; no note data. Their members use the `/admin/workspaces/{id}/members` routes above (always allowed, Editor or Reader only, else 422) |
| PATCH/DELETE | `/api/v1/admin/open-workspaces/{id}` | system admin | `{name, description}` change, or delete; 409 `workspace_not_empty` while it holds notes |
| GET | `/api/v1/admin/workspace-join-requests` (`/pending-count`) | system admin | pending join requests with user, workspace and note (or just the count) |
| POST | `/api/v1/admin/workspace-join-requests/{id}/approve` / `/decline` | system admin | approve `{role: editor\|reader}` (default editor) or decline; 204; already decided → 404 `request_not_pending` |
| GET | `/api/v1/open-workspaces` | user | every Shared Workspace: `{id, name, description, is_member, your_role, join_request}` (D067); administrators 403 |
| POST/DELETE | `/api/v1/open-workspaces/{id}/join-request` | user | ask to join `{note}` (201; 409 `already_member` / `request_pending`; 429 over 10 a day), or cancel a pending request (204) |
| GET | `/api/v1/workspaces` | user | the caller's workspaces with `your_role` and `permissions` |
| POST | `/api/v1/workspaces` | user | `{name}` → 201, new shared workspace, caller is Owner |
| GET | `/api/v1/workspaces/{id}` | Reader+ | one workspace |
| PATCH | `/api/v1/workspaces/{id}` | Owner | `{name}` rename |
| DELETE | `/api/v1/workspaces/{id}` | Owner | delete an empty shared workspace (204; 409 if it has notes) |
| GET | `/api/v1/workspaces/{id}/members` | Reader+ | members with roles |
| POST | `/api/v1/workspaces/{id}/members` | Admin+ | `{username, role}` → 201 |
| PATCH | `/api/v1/workspaces/{id}/members/{userId}` | Admin+ | `{role}` |
| DELETE | `/api/v1/workspaces/{id}/members/{userId}` | Admin+ (or self) | remove, or leave (204) |
| GET | `/api/v1/workspaces/{id}/notes` | Reader+ | notes without content, with a short plain-text `excerpt` (Markdown markers removed, D051), `folder_id` and `tags`; optional `?folder=<id>` or `?folder=none`, and `?tag=<name>`; `?archived=1` lists the Archive instead |
| POST | `/api/v1/workspaces/{id}/notes` | Editor+ | `{title?, content?, folder_id?, tags?}` → 201 |
| GET | `/api/v1/notes/{id}` | Reader+ | one note with `content`, `revision`, `folder_id`, `folder_name`, `tags`, `archived_at`, `history_count` and `history_limit` |
| PATCH | `/api/v1/notes/{id}` | Editor+ | `{revision, title?, content?, folder_id?, tags?}`; stale revision → 409 `revision_conflict` |
| DELETE | `/api/v1/notes/{id}` | Editor+ | move to Trash (204) |
| POST | `/api/v1/notes/{id}/archive` | Editor+ | move to the Archive → the note |
| POST | `/api/v1/notes/{id}/unarchive` | Editor+ | move back out of the Archive → the note |
| GET | `/api/v1/notes/{id}/versions` | Reader+ | earlier versions, newest first, without content; `history_count`, `history_limit` |
| GET | `/api/v1/notes/{id}/versions/{versionId}` | Reader+ | one earlier version with `content`, `folder_name` and `tags` |
| POST | `/api/v1/notes/{id}/versions/{versionId}/restore` | Editor+ | `{revision}` → the note with that version's title, content, folder and tags; stale revision → 409 |
| GET | `/api/v1/workspaces/{id}/trash` | Reader+ | trashed notes with `trashed_at`, `trashed_by`, `purge_after`; `retention_days` |
| DELETE | `/api/v1/workspaces/{id}/trash` | Admin+ | empty the Trash for good → `{deleted_notes}` |
| POST | `/api/v1/trash/{noteId}/restore` | Editor+ | bring a trashed note back → the note |
| DELETE | `/api/v1/trash/{noteId}` | Admin+ | delete one trashed note for good (204) |
| GET | `/api/v1/search?q=<words>` | user | notes in every workspace the caller can read; `&archived=1` includes the Archive; → `results`, `terms`, `limited` |
| GET | `/api/v1/workspaces/{id}/folders` | Reader+ | folders in tree order with `parent_id`, `level`, `note_count` (active notes directly in it) and `total_note_count` (sub-folders included) |
| POST | `/api/v1/workspaces/{id}/folders` | Editor+ | `{name, parent_id?}` → 201; duplicate name → 409 `folder_name_taken` |
| PATCH | `/api/v1/folders/{id}` | Editor+ | `{name?, parent_id?}` rename and/or move (`parent_id: null` = top level) |
| DELETE | `/api/v1/folders/{id}` | Editor+ | delete (204); its notes and sub-folders move up one level |
| GET | `/api/v1/workspaces/{id}/tags` | Reader+ | tags used by active notes, with `note_count` |
| POST | `/api/v1/notes/{id}/attachments` | Editor+ | raw image bytes as the body → 201 with the attachment and its `markdown` |
| GET | `/api/v1/notes/{id}/attachments` | Reader+ | the note's attachments |
| GET | `/api/v1/attachments/{id}/content` | Reader+ | the image itself (used by `<img>` tags) |
| DELETE | `/api/v1/attachments/{id}` | Editor+ | remove one image for good (204); the note's text is not changed |
| GET | `/api/v1/workspaces/{id}/magic-links` | Admin+ | the workspace's Magic Links (D059) with `status`, `target_name`, `use_count`; never tokens; plus `timezone`, `default_valid_days`, `max_valid_days` |
| POST | `/api/v1/workspaces/{id}/magic-links` | Admin+ | `{label, target_type: workspace\|folder\|note, target_id?, permission?: read\|write, valid_from?, valid_until?, daily_start_time?, daily_end_time?}` → 201 with one-time `link_url` |
| DELETE | `/api/v1/magic-links/{id}` | Admin+ | revoke (204); stops open visits at once |
| GET | `/api/v1/admin/magic-links` | system admin | every link: workspace, kind of target, creator, status; no labels, folder names or note titles |
| DELETE | `/api/v1/admin/magic-links/{id}` | system admin | revoke any link (204) |
| GET | `/api/v1/admin/overview` | system admin | the Overview page (D060): `warnings`, `users`, `content`, `magic_links`, `activity` (7×24 grids, Monday first, in `timezone`), `sign_in_attempts` (usernames masked), `database`, `storage`, `audit_log`, `maintenance`, `software`; counts and sizes only |
| GET | `/api/v1/admin/sign-in-attempts[?reveal=true]` | system admin | the sign-in section alone; `reveal=true` shows typed usernames and is audited (`admin.sign_in_usernames_revealed`) |
| POST | `/api/v1/admin/audit-log/archive` | system admin | zip audit entries older than `audit_log.archive_after_months`, verify, then delete them → 201 `{archive}`; nothing old enough → 409 `nothing_to_archive`; another run → 409 `archive_running`; no zip extension → 503 `zip_unavailable` |
| GET | `/api/v1/admin/audit-log/archives/{archiveId}` | system admin | download one archive (`archiveId` is its file name without `.zip`) |
| GET | `/api/v1/broadcasts` | signed in | broadcasts showing to the caller right now (D061): `id`, `kind` (`once`\|`sticky`\|`vote`), `message`, `allows_multiple_choices`, `starts_at`, `ends_at`, `options` (`id`, `label`); never results; always empty for administrator accounts |
| POST | `/api/v1/broadcasts/{id}/seen` | signed in | a one-time banner was shown (204); 404 when it is not a showing one-time banner |
| POST | `/api/v1/broadcasts/{id}/dismiss` | signed in | a sticky banner was closed with its X (204) |
| POST | `/api/v1/broadcasts/{id}/vote` | signed in | `{option_ids: [...]}` (one, or several when allowed) → 204; queues an ntfy notification to the administrator; second vote → 409 `already_voted` |
| GET | `/api/v1/admin/broadcasts` | system admin | every broadcast with `status` (`scheduled`\|`showing`\|`ended`), `done_count` and, for votes, each option's `vote_count` and `voters` |
| POST | `/api/v1/admin/broadcasts` | system admin | `{kind, message, starts_at, ends_at, allows_multiple_choices?, options?: [2-5 labels]}` → 201 `{broadcast}`; times need an explicit offset |
| PATCH | `/api/v1/admin/broadcasts/{id}` | system admin | `{message, starts_at, ends_at}` (all three); `kind` and `options` cannot change; an end in the past ends it |
| DELETE | `/api/v1/admin/broadcasts/{id}` | system admin | delete with its answers and votes (204) |
| GET | `/api/v1/chat` | user | the chats the caller may use (D062): `chat_access`, `global` (`can_read`, `can_write`, `unread_count`), `workspaces` (`workspace_id`, `name`, `your_role`, `can_write`, `unread_count`; shared workspaces only), `limits` (`message_max_length`, `messages_per_minute`, `retention_days`) |
| GET | `/api/v1/chat/unread` | user | `{total_unread}`: messages from others in the chats the caller may open that they have not seen (D063); 0 for administrators and level `off` |
| GET | `/api/v1/chat/global/messages` | user, chat level not `off` | the global channel: `channel` (`kind`, `name`, `can_write`, `can_moderate`), `messages` oldest first (`id`, `body`, `created_at`, `author` {`username`, `display_name`}, `is_own`, `can_delete`), `has_older`, `cursor`. Without `before`, the channel counts as seen up to `cursor` (D063). `?before=<message id>` → the 50 before it; `?since=<cursor>` → everything changed since (new messages, and deleted ones as `{id, is_deleted: true}`), a new `cursor`, and `reset: true` when too much changed to send |
| POST | `/api/v1/chat/global/messages` | user, chat level `global` | `{body}` (plain text, 1-2000 characters) → 201 `{message}`; other levels → 403 `chat_read_only`; more than 10 messages a minute → 429 `chat_rate_limited` |
| GET | `/api/v1/workspaces/{id}/chat/messages` | Reader+, shared workspace, chat level allows it | same shape and parameters as the global channel; non-member → 404; personal workspace, level `off`, or `own_workspaces` without being Owner → 403 `chat_not_allowed` |
| POST | `/api/v1/workspaces/{id}/chat/messages` | Editor+ (same chat level rules) | `{body}` → 201 `{message}`; Reader → 403 `insufficient_role` |
| DELETE | `/api/v1/chat/messages/{id}` | author, or workspace Admin+ | delete (204): the text is removed at once; a deletion by someone else is audited as `chat.message_removed_by_moderator` without the text. In the global channel only the author may delete |
| PATCH | `/api/v1/admin/users/{id}/chat-access` | system admin | `{chat_access: off\|own_workspaces\|member_workspaces\|global}` → `{chat_access}`; administrator accounts → 422; audited as `user.chat_access_changed` |
| GET | `/api/v1/admin-attention` | user | `{is_recipient, needs_attention}` (D066): `needs_attention` is true only for the account the administrator picked, while invitation requests, workspace join requests (D067) or unread inbox conversations wait; never says what. Everyone else, administrators included, gets `false` for both |
| GET | `/api/v1/admin/attention-recipient` | system admin | `{recipient: {id, username, display_name, status} \| null}` (D066) |
| PATCH | `/api/v1/admin/attention-recipient` | system admin | `{user_id: "<uuid>" \| null}` → `{recipient}`; administrator, disabled or unknown accounts → 422; audited as `admin.attention_recipient_changed` |

### Magic Link visitors (D059)

A browser that opened a Magic Link uses only these endpoints. `POST /link/open` sets the
`__Host-muninn_link` visit cookie and returns a `csrf_token` for state changes. The sign-in
cookie does not work here, and the visit cookie works nowhere else. Every request checks the
link again (revoked, dates, daily hours, creator still Admin/Owner, target still there) and
answers 401 `link_unavailable` once it stops. Notes and folders outside the link give 404.

| Method | Path | Access | Purpose |
|---|---|---|---|
| POST | `/api/v1/link/open` | public | `{token}` → link description + `csrf_token`, sets the visit cookie; 404 `link_unavailable`, 403 `link_outside_hours`, 429 after guesses |
| GET | `/api/v1/link/me` | visit | what the link opens (`target_type`, `target_name`, `permission`, `valid_until`) + `csrf_token` |
| POST | `/api/v1/link/close` | visit | end the visit (204) |
| GET | `/api/v1/link/folders` | visit | folders the link reaches, in tree order |
| GET | `/api/v1/link/notes` | visit | notes the link reaches, without content or member names; `?folder=<id>` (inside the link) or `?folder=none` (workspace links) |
| GET | `/api/v1/link/notes/{id}` | visit | one note with `content`; no member names or history counter |
| POST | `/api/v1/link/notes` | write visit | `{title?, content?, folder_id?}` → 201; workspace and folder links only (else 403 `link_cannot_create`) |
| PATCH | `/api/v1/link/notes/{id}` | write visit | `{revision, title?, content?}`; stale revision → 409 `revision_conflict` |
| POST | `/api/v1/link/notes/{id}/attachments` | write visit | raw image bytes, same checks as the signed-in upload → 201 |
| GET | `/api/v1/link/attachments/{id}/content` | visit | an image of a note the link reaches |

### Folders and tags

Folders belong to one workspace (D033) and can sit inside each other, at most 3 levels deep
(D055). `parent_id` must be a folder of the same workspace; another workspace's folder, a folder
inside the one being moved, the folder itself, or a place that would make any folder deeper than
level 3 gives 422 on `parent_id`. `?folder=<id>` on the note list includes the sub-folders' notes.
`folder_id` on a note may be a folder of the same workspace or `null` ("No folder"); any other
ID, including another workspace's folder, gives 422 on `folder_id`. Leaving `folder_id` out of a
PATCH keeps the folder.

Tags belong to one workspace (D034) and are set through the note: `tags` is a list of names
(1–50 characters, no commas, at most 20 per note). A leading `#` is dropped, duplicates are
compared ignoring case and the first spelling used in the workspace is kept. Sending `tags`
replaces the note's tags; leaving it out keeps them. Tags no note uses any more disappear.

### Archive, Trash and history

Archived notes (D048) stay readable, editable and searchable on request, but leave the normal
note list and the folder and tag counts. Archiving is not an edit: it changes neither the
revision nor the history.

Trash (D012, D039, D045): `DELETE /api/v1/notes/{id}` moves a note to Trash. `/api/v1/trash/{noteId}`
only ever addresses trashed notes and `/api/v1/notes/{id}` only active ones, so an active note
can never be purged by mistake (404). A restored note returns to its folder, or to the Archive
if it was archived. Deleting for good removes the note, its history, tag links, attachment rows
and image files, and tags no note uses any more. The API does the same by itself, at most once
an hour after a signed-in request, for notes trashed more than `trash.retention_days` (default
30) days ago; `bin/purge-trash.php` runs that cleanup by hand.

History (D009, D037): every save keeps the state it replaces, unless nothing changed. Saves by
the same person within 10 minutes of the last kept version count as one; a save by someone else
always keeps the previous state. At most 100 earlier versions are kept per note (oldest go
first). A restore is a normal save that needs the current `revision`, and the state it replaces
is always kept, so a restore can be undone.

### Search

`GET /api/v1/search?q=…` (D011, D038) looks in every workspace the caller is a member of (any
role) and nowhere else; administrator accounts find nothing. Every word must occur in the
title, the text or a tag name, anywhere inside a word ("möte" finds "mötesanteckningar"),
ignoring case; `%`, `_` and `!` are matched literally. Trashed notes are never searched;
archived ones only with `&archived=1`. At most 50 results (title matches first, then most
recently changed); `limited: true` means there may be more. `q` is required, at most 200
characters, one line (422 otherwise).

### Attachments (images)

Uploads are the file itself as the request body, not a multipart form:

```http
POST /api/v1/notes/{id}/attachments
Content-Type: image/png            (any image/* or application/octet-stream)
X-Filename: Sk%C3%A4rmbild.png     (optional, percent-encoded, display only)
X-CSRF-Token: …
```

The API decides the type from the bytes: PNG, JPEG, GIF and WebP are accepted (SVG never), up to
`attachments.max_upload_bytes` (default 10 MB, 413 `file_too_large`) and 12 000 pixels per side;
anything else is 422. Other body types are 415. Files are stored under random names outside the
web root. The response's `markdown` (`![name](attachment:<id>)`) is what the editor inserts.

`GET /api/v1/attachments/{id}/content` requires read access to the attachment's note; unknown
IDs, other workspaces' attachments and attachments of trashed notes all give the same 404. It is
served with the detected `Content-Type`, `nosniff`, `Content-Disposition: inline`,
`Cache-Control: private, max-age=300`, an `ETag` (SHA-256, so `If-None-Match` gives 304) and
`Cross-Origin-Resource-Policy: same-site`.

`DELETE /api/v1/attachments/{id}` removes one image of an active note for good (D056): the row
and the file, recorded in the audit log as `attachment.deleted`. It needs Editor or higher;
unknown IDs, other workspaces' attachments and attachments of trashed notes give the same 404.
The note's text is left alone: the editor takes the image's Markdown out and the user saves.

Workspace roles follow D029: Admins may only add, change and remove Editors and Readers; Owners
manage everyone. Removing or demoting the last Owner gives 409 `last_owner`. A caller who is not
a member of the workspace gets 404 on every workspace and note endpoint; a member whose role is
too weak gets 403 `insufficient_role`.

Timestamps in responses are ISO 8601 UTC, e.g. `2026-10-04T09:30:00Z`. IDs are UUIDv4 strings.

## Adding an endpoint

1. Write the handler as a method taking `(Request $request, RequestContext $context): Response`.
2. Register it in `Application::registerRoutes()` with the right access level.
3. Validate input with `InputReader` and throw `HttpException` for client errors.
4. Add integration tests, including the unauthorized and wrong-role cases.
