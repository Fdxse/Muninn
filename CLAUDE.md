# CLAUDE.md — Muninn Development Instructions

## Purpose

This file defines how Claude should work on the Muninn project.

Read `PROJECT.md`, `SECURITY.md`, `ROADMAP.md`, and `DECISIONS.md` before making architectural changes or implementing a new phase.


## Course MVP Priority

`COURSE-MVP.md` defines the delivery contract for the six-week course project and takes priority over stretch goals in the long-term roadmap.

The Course MVP must be deployable, documented, tested to a reasonable level, and usable by real users before stretch goals are started. A stretch goal must never delay completion, testing, documentation, deployment, or demo readiness of the Course MVP.

When choosing between adding a new feature and improving completion quality of the Course MVP, prioritize completion quality.

Do not implement a stretch goal unless the project owner explicitly asks for it or the Course MVP Definition of Done has been satisfied.

## Core Working Rule

Do not attempt to build the entire application at once.

Work incrementally according to `ROADMAP.md`.

When asked to implement a feature:

1. Identify the relevant roadmap phase.
2. Read the existing implementation before changing it.
3. State any assumptions that materially affect architecture, security, or stored data.
4. Ask before making a major unresolved architectural choice.
5. Implement the smallest coherent change that completes the requested work.
6. Add/update tests where practical.
7. Update relevant documentation.
8. Do not implement unrelated future-roadmap features "while you are there".

## Technology Constraints

Unless explicitly changed by the project owner:

### Frontend

- PHP 8+
- Bootstrap 5.3
- Vanilla JavaScript
- Mobile-first
- PWA installability
- No frontend framework such as React, Vue, Angular, Svelte, etc.

### Backend

- PHP 8+
- REST-style JSON API
- MariaDB/MySQL
- NAS filesystem for attachments

Avoid introducing heavy dependencies where PHP, browser APIs, Bootstrap, or small focused libraries are sufficient.

Before adding a dependency, explain what problem it solves and why the dependency is preferable to a simple native implementation.

## Security Is Not Optional

The API is the authoritative security boundary.

Never trust:

- Hidden buttons
- Client-provided user IDs
- Client-provided workspace IDs without authorization validation
- Client-provided roles
- Client-provided ownership claims
- Frontend filtering
- Filenames
- MIME types supplied by the browser
- Raw identifiers merely because they are UUIDs

Every protected API operation must authenticate and authorize independently.

Authorization applies to content **and metadata**.

Search, version history, attachments, exports, folder listings, counts, tags, filenames, Magic Links, and API tokens are all potential information-leak boundaries.

Read and obey `SECURITY.md`.

## Data Isolation Rule

A user must never receive information from a resource they cannot access.

This includes accidental disclosure through:

- Search results
- Autocomplete
- Error messages
- Sequential IDs
- Counts
- Logs exposed to users
- Attachment URLs
- Export archives
- Version history
- Workspace/folder navigation

Whenever implementing a query that returns user-visible data, explicitly consider its authorization scope.

## Database Changes

Use versioned migrations/schema scripts.

Do not make undocumented manual database changes.

Database changes should:

- Use foreign keys where appropriate.
- Use indexes intentionally.
- Preserve referential integrity.
- Avoid storing plaintext credentials/tokens.
- Prefer UTC timestamps in persistent storage unless there is a documented reason otherwise.

Time-of-day restrictions on Magic Links must be evaluated in a clearly defined timezone. Do not silently rely on PHP/server local timezone.

## API Design

Use a versioned API namespace, initially `/api/v1/`.

Use consistent JSON response structures and HTTP status codes.

Examples of expected semantics:

- `200` successful read/update where appropriate
- `201` created
- `204` successful operation with no response body where appropriate
- `400` malformed/invalid request
- `401` authentication required/invalid credentials
- `403` authenticated but not permitted
- `404` resource unavailable/not found; consider using this rather than revealing existence of inaccessible resources
- `409` optimistic concurrency conflict
- `422` semantically invalid input where useful
- `429` rate limited

Do not expose PHP warnings, stack traces, SQL errors, filesystem paths, credentials, or internal implementation details in production API responses.

## Authentication

Authentication uses username/password for interactive accounts.

Accounts are invitation-only.

Do not add open registration.

Use PHP password hashing APIs. Never design custom password cryptography.

Authentication/session implementation must use secure cookies/tokens and appropriate HTTPS protections.

If frontend and API deployment creates cross-origin credential constraints, document the chosen solution and security implications before implementing it.

## Invitations

Admin issues invitations.

Users may submit a proposed-user/invitation request for admin review.

Invitation tokens are:

- Cryptographically random
- Time limited
- Single use
- Stored only as hashes
- Never logged in plaintext

## Magic Links

Magic Links may target a note or folder.

They can be reusable within their configured validity constraints.

The raw token is displayed only once at creation. Store only its cryptographic hash.

Support:

- Valid-from
- Valid-until
- Optional daily active time window
- Revocation
- Read permission
- Optional Write permission

No Magic Link delete permission in V1.

Do not confuse token hashing with single-use behavior.

## Notes and History

Notes use a Markdown-capable content model.

Support Markdown, checklists, code blocks, links, and images in V1.

Maintain at most 100 historical versions per note unless configuration later changes this rule.

Restoring history creates a new version.

Use optimistic concurrency to prevent silent overwrites.

## Files

Do not expose private attachments as predictable public files.

Validate uploads server-side.

Use generated storage identifiers/paths rather than trusting user filenames.

Prevent path traversal.

Clipboard image paste should use the same validated upload pipeline as ordinary uploads.

## Geolocation

Geotagging is optional.

Never request location automatically on page load.

Only request current position following an explicit user action.

Support:

- Browser current location
- OpenStreetMap/Leaflet map selection
- Manual latitude/longitude

Store coordinates as structured numeric data.

Do not implement location tracking or background location collection.

## PWA

Implement installability, not offline-first behavior.

Do not build offline editing or synchronization in V1.

Keep service-worker behavior minimal and predictable.

## ntfy

ntfy configuration is server-side and administrator controlled.

Users may configure supported notification preferences.

Do not expose ntfy credentials in browser code.

## Code Quality

Prefer clear, boring, maintainable code over clever abstractions.

Use:

- Strict input validation
- Parameterized SQL queries
- Centralized authorization helpers/policies
- Centralized configuration
- Reusable error handling
- Small focused functions/classes
- Meaningful names

Avoid premature abstraction and speculative generic frameworks.

## UI/UX

Design mobile-first.

The primary note workflow should be fast on a phone.

Important actions should remain usable on touch devices.

Desktop layouts may take advantage of additional space without becoming a separate application.

Accessibility should be considered throughout development: labels, keyboard navigation, focus behavior, contrast, semantic HTML, and sensible touch targets.

## Testing Expectations

Prioritize tests around security boundaries and destructive operations.

At minimum, test scenarios such as:

- User A cannot read User B's inaccessible note.
- User A cannot discover it through search.
- User A cannot fetch its attachment.
- User A cannot fetch its history.
- Reader cannot edit.
- Editor cannot perform admin-only operations.
- Revoked/expired Magic Links fail.
- Magic Link time restrictions are enforced.
- Invitation token cannot be reused.
- Concurrency conflict prevents silent overwrite.
- Trash retention does not delete active content.

When fixing an authorization bug, add a regression test whenever practical.

## Documentation and Decisions

Record meaningful architectural decisions in `DECISIONS.md`.

When a requirement is ambiguous and affects architecture, security, permissions, data loss, privacy, or compatibility, ask the project owner rather than inventing a permanent rule.

For minor implementation details that do not materially affect the project contract, choose a sensible simple solution and document it if needed.

## Definition of Done

A feature is not complete merely because the happy path works.

Before calling work complete, consider:

- Authorization
- Validation
- Error handling
- Mobile UI
- Security implications
- Data migration
- Concurrency where relevant
- Auditability where relevant
- Tests
- Documentation

## Things Claude Must Not Do Without Explicit Approval

- Change the technology stack.
- Add public registration.
- Add offline synchronization.
- Add end-to-end encryption.
- Add a major frontend framework.
- Add AI/cloud dependencies.
- Expose NAS files directly to unauthenticated users.
- Weaken authorization for convenience.
- Implement future roadmap phases merely because supporting code is nearby.
- Delete or rewrite user data during a migration without an explicit safe migration strategy.

## Visual Design and Brand

The canonical Muninn visual reference is:

`/assets/branding/reference/muninn-brand-reference.png`

When implementing UI, branding, PWA assets, login/splash screens, or visual components, consult this reference and the Visual Identity section in `PROJECT.md`.

Do not redesign the Muninn brand without explicit project-owner approval. Do not introduce unrelated color schemes, typography directions, icon styles, fantasy/Viking-game aesthetics, or excessive runic decoration.

The Norse influence is subtle. Muninn is first and foremost a productivity and knowledge application.

Functional UI icons must remain simple and immediately understandable. Decorative raven imagery must not replace functional controls.

Treat the reference board as visual direction, not as a sprite sheet. Do not crop production icons or logos from the reference board unless explicitly instructed. When a required production asset does not exist, use a clearly identified placeholder and document the missing asset instead of silently inventing a new identity.
