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
| 403 | Signed in but the request is not acceptable | `csrf_failed`, `origin_not_allowed` |
| 404 | Not found **or not yours to know about** | `not_found`, `invitation_invalid` |
| 405 | Wrong method (with `Allow` header) | `method_not_allowed` |
| 409 | State conflict | `invitation_not_pending` |
| 415 | Body is not JSON | `unsupported_media_type` |
| 422 | Semantically invalid input | `validation_failed` |
| 429 | Rate limited (with `Retry-After`) | `rate_limited` |
| 500 | Internal error, details only in the log | `internal_error` |

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

## Endpoints (Week 1)

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

Timestamps in responses are ISO 8601 UTC, e.g. `2026-10-04T09:30:00Z`. IDs are UUIDv4 strings.

## Adding an endpoint

1. Write the handler as a method taking `(Request $request, RequestContext $context): Response`.
2. Register it in `Application::registerRoutes()` with the right access level.
3. Validate input with `InputReader` and throw `HttpException` for client errors.
4. Add integration tests, including the unauthorized and wrong-role cases.
