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
| 401 | Not signed in, or failed login | `unauthenticated`, `invalid_credentials` |
| 403 | Signed in but the request is not acceptable | `csrf_failed`, `origin_not_allowed`, `insufficient_role`, `admin_account` |
| 404 | Not found **or not yours to know about** | `not_found`, `invitation_invalid` |
| 405 | Wrong method (with `Allow` header) | `method_not_allowed` |
| 409 | State conflict | `invitation_not_pending`, `revision_conflict`, `last_owner`, `already_member`, `personal_workspace`, `workspace_not_empty`, `cannot_disable_self` |
| 415 | Body is not JSON | `unsupported_media_type` |
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
| POST | `/api/v1/invitations/inspect` | public | `{token}` → `{valid, expires_at}` or 404 |
| POST | `/api/v1/invitations/accept` | public | `{token, username, display_name, password}` → 201, signed in |
| GET | `/api/v1/admin/invitations` | system admin | list (never tokens) |
| POST | `/api/v1/admin/invitations` | system admin | `{note?, expires_in_hours?}` → 201 with one-time `invitation_url` |
| DELETE | `/api/v1/admin/invitations/{id}` | system admin | revoke a pending invitation (204) |
| GET | `/api/v1/admin/users` | system admin | list accounts (never password hashes) |
| POST | `/api/v1/admin/users/{id}/disable` | system admin | disable and sign out everywhere (204) |
| POST | `/api/v1/admin/users/{id}/enable` | system admin | re-enable (204) |
| GET | `/api/v1/workspaces` | user | the caller's workspaces with `your_role` and `permissions` |
| POST | `/api/v1/workspaces` | user | `{name}` → 201, new shared workspace, caller is Owner |
| GET | `/api/v1/workspaces/{id}` | Reader+ | one workspace |
| PATCH | `/api/v1/workspaces/{id}` | Owner | `{name}` rename |
| DELETE | `/api/v1/workspaces/{id}` | Owner | delete an empty shared workspace (204; 409 if it has notes) |
| GET | `/api/v1/workspaces/{id}/members` | Reader+ | members with roles |
| POST | `/api/v1/workspaces/{id}/members` | Admin+ | `{username, role}` → 201 |
| PATCH | `/api/v1/workspaces/{id}/members/{userId}` | Admin+ | `{role}` |
| DELETE | `/api/v1/workspaces/{id}/members/{userId}` | Admin+ (or self) | remove, or leave (204) |
| GET | `/api/v1/workspaces/{id}/notes` | Reader+ | notes without content, with a short `excerpt` |
| POST | `/api/v1/workspaces/{id}/notes` | Editor+ | `{title?, content?}` → 201 |
| GET | `/api/v1/notes/{id}` | Reader+ | one note with `content` and `revision` |
| PATCH | `/api/v1/notes/{id}` | Editor+ | `{revision, title?, content?}`; stale revision → 409 `revision_conflict` |
| DELETE | `/api/v1/notes/{id}` | Editor+ | move to Trash (204) |

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
