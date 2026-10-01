# Muninn Roadmap

## Delivery Priority

The six-week Course MVP in `COURSE-MVP.md` is the active delivery plan. It takes priority over the long-term phases below. The phases below describe the broader Muninn product direction and must not be interpreted as requirements for course completion.

### Course schedule

| Week | Focus | Exit condition |
|---|---|---|
| 1 | Foundation + authentication | Deployable skeleton, migrations, login/invitations |
| 2 | Workspaces + authorization + notes | Secure roles/data isolation and note CRUD |
| 3 | Editing + organization + images | Useful daily note workflow |
| 4 | Search + history + lifecycle | Search/history/archive/trash complete |
| 5 | Productization | Mobile/PWA/admin/security/testing polished |
| 6 | Ship | Production deployment, docs, demo, bug fixes |

**Freeze rule:** During Week 6, do not begin nonessential features until the Definition of Done in `COURSE-MVP.md` is satisfied.

## Long-Term Product Roadmap

## Phase 0 — Foundation

Goal: establish a clean, deployable project skeleton.

Deliverables:

- Repository structure
- Environment/configuration strategy
- Frontend skeleton on PHP + Bootstrap 5.3 + vanilla JS
- API skeleton under `/api/v1/`
- MariaDB connection layer
- Versioned database migration approach
- Standard JSON error/response handling
- CORS configuration for approved frontend origins
- Logging foundation
- Development/setup documentation
- Initial PWA manifest/icons/installability foundation

No notes functionality is required yet.

## Phase 1 — Authentication and Users

Deliverables:

- User model
- Admin bootstrap procedure
- Username/password login/logout
- Secure session/auth transport
- Invitation-only account creation
- Invitation expiry and single-use behavior
- User-proposed invitation requests for admin review
- Basic user profile/settings
- Login rate limiting
- Relevant audit events

Do not add public registration.

## Phase 2 — Workspaces and Authorization

Deliverables:

- Workspace model
- Automatic personal workspace
- Workspace membership
- Owner/Admin/Editor/Reader roles
- Central authorization layer
- Admin membership management
- Authorization regression tests

This phase must be stable before sharing features expand.

## Phase 3 — Notes MVP

Deliverables:

- Create/read/update notes
- Markdown content
- Checklist syntax/support
- Code blocks with syntax highlighting
- Links
- Favorite flag
- Folder organization
- Tags
- Mobile-first editor/viewer
- Optimistic concurrency control

No real-time collaborative editing.

## Phase 4 — Images and Attachments

Deliverables:

- Authorized file upload/download
- NAS-backed storage
- Configurable size/type limits
- Image attachments
- Clipboard image paste
- Attachment metadata
- Secure generated storage paths

## Phase 5 — Search, Archive and Trash

Deliverables:

- Global authorized note search
- Search title/content/tags and suitable attachment metadata
- Archive
- Trash
- Configurable trash retention, default 30 days
- Permanent cleanup process

Search authorization must have explicit tests.

## Phase 6 — Version History

Deliverables:

- Automatic note versions
- Maximum 100 historical versions
- UI history counter such as `34 / 100`
- History viewer
- Restore-as-new-version
- Authorization tests for history

## Phase 7 — Magic Links

Deliverables:

- Magic Link for note
- Magic Link for folder
- Read permission
- Optional Write permission
- Start/end validity
- Optional daily time window
- Explicit timezone handling
- Revocation
- One-time raw token display
- Hashed token storage
- Last-used metadata where practical
- Security/audit tests

No Delete permission.

## Phase 8 — Geotagging and Maps

Deliverables:

- Optional latitude/longitude on notes
- Optional place label
- Explicit "use current position" action
- Manual coordinates
- OpenStreetMap map picker using Leaflet
- Marker display in note viewer
- Authorization tests for location metadata

Do not implement background location tracking or nearby/radius search yet.

## Phase 9 — Notifications

Deliverables:

- Central ntfy configuration
- Secure credential storage
- User notification preferences
- Initial useful notification events agreed with project owner
- Failure handling/logging

Keep notification scope intentionally small at first.

## Phase 10 — API Tokens and Integrations

Deliverables:

- Personal API tokens
- Hashed token storage
- One-time token display
- Token revocation
- Explicit scopes such as `notes:read`, `notes:write`, `files:read`, `files:write`
- Authorization + scope enforcement
- API usage documentation/examples

## Phase 11 — Administration

Deliverables:

- User administration
- Invitation/request administration
- Workspace/member administration
- Configuration UI
- Storage overview
- Magic Link/token revocation views where appropriate
- Audit/security log viewer with appropriate restrictions

Some minimal admin screens may be implemented earlier when required by earlier phases; this phase consolidates the full admin experience.

## Phase 12 — Export, Backup and Restore

Deliverables:

- Per-user portable export
- Markdown + JSON metadata + attachments
- Documented/versioned export format
- Complete system backup/export
- Documented restore procedure
- Restore test procedure

## Later / Explicitly Deferred

Potential future work, not approved for V1:

- Offline editing/synchronization
- Real-time collaborative editing
- Nearby/radius note search
- Map showing all geotagged accessible notes
- Native mobile clients
- End-to-end encryption
- AI features
- More advanced notification workflows
