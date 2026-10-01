# Muninn

## Active Delivery Target — Six-Week Course MVP

Muninn is intended to become a real, continuously used product, but the immediate delivery target is the six-week Course MVP defined in `COURSE-MVP.md`. That document is the authoritative scope and Definition of Done for the course. Long-term features remain valid product ideas but must not delay a deployable, secure, documented MVP.


## Project Vision

Muninn is a self-hosted, multi-user note and knowledge application intended for both private and professional use.

The system must make it easy to capture, organize, search, share, and revisit information while maintaining strict separation between users and workspaces.

The application is mobile-first and delivered as an installable Progressive Web App (PWA). Offline editing and offline synchronization are explicitly out of scope for V1.

The guiding principles are:

- User data must never leak across authorization boundaries.
- The API is the authoritative security boundary; the frontend is never trusted for authorization.
- Data should remain portable and exportable.
- The architecture should remain simple enough to self-host and maintain.
- Features should be implemented incrementally rather than as a monolithic build.
- Avoid unnecessary framework complexity.

## Working Name

**Muninn** — inspired by Muninn, one of Odin's ravens and commonly associated with memory in Norse mythology.

## Deployment Architecture

### Frontend

Host: `dx.se`

Technology:

- PHP 8+
- Bootstrap 5.3
- Vanilla JavaScript
- HTML5/CSS
- Mobile-first responsive design
- Installable PWA
- No offline editing/synchronization in V1

The frontend communicates with the API exclusively over HTTPS using JSON.

### Backend/API

Host: `fehre.synology.me`

Technology:

- PHP 8+
- REST-style JSON API
- MariaDB/MySQL
- Files stored on NAS filesystem rather than as database BLOBs where practical

### Logical Layout

```text
Browser / PWA
     |
     | HTTPS / JSON
     v
Frontend: dx.se
     |
     | HTTPS / JSON
     v
API: fehre.synology.me
     |
     +---- MariaDB
     |
     +---- NAS file storage
     |
     +---- ntfy server
```

The frontend and API are separate security domains. CORS must be explicitly configured and restricted to approved origins.

## Core Domain Model

### Users

Accounts are invitation-only. There is no open public registration.

An administrator creates and sends invitations. Existing users may submit a request proposing a new user. An administrator reviews that request and decides whether to issue an invitation.

Invitations should use secure, random, time-limited, single-use tokens. Store only a cryptographic hash of an invitation token in the database.

Authentication uses normal username/password credentials.

Passwords must be stored using PHP's modern password hashing APIs (`password_hash` / `password_verify`) and never with reversible encryption.

### Workspaces

A user may belong to multiple workspaces.

Examples:

- Personal
- Work
- Family
- Project Alpha

Every user automatically receives a personal workspace when the account is created. A personal workspace uses the same authorization model as every other workspace but initially has only its owner as a member.

Workspace roles should support a reasonable granular model such as:

- Owner
- Admin
- Editor
- Reader

Exact permissions should be represented explicitly in code and documented rather than inferred from UI behavior.

### Notes

Every note belongs to a workspace.

V1 note content supports:

- Markdown
- Checklists
- Code blocks with syntax highlighting
- Links
- Images

Notes may also have:

- Folder
- Tags
- Favorite flag
- Attachments
- Optional geolocation
- Archive state
- Trash/deleted state
- Creation/update metadata
- Version history

Prefer a unified Markdown-capable note model rather than separate database entities for each content type unless a concrete requirement makes that necessary.

### Folders and Tags

Notes may be organized using both folders and tags.

Magic Links may target either an individual note or a folder.

Do not assume folder hierarchy must be infinitely deep. Keep the initial implementation simple unless a deeper hierarchy is explicitly required.

## Authorization and Data Isolation

This is a critical requirement.

The frontend may improve usability by hiding unavailable actions, but it must never be considered an authorization mechanism.

Every API request must independently determine:

1. Who is authenticated or what token is being used.
2. What resource is being requested.
3. What permission the caller has for that resource.
4. Whether the requested operation is allowed.

Authorization must apply consistently to:

- Notes
- Folders
- Workspaces
- Attachments
- Images
- Search results
- Version history
- Exports
- Backups
- Magic Links
- API tokens
- Administration endpoints

A user must never receive data, metadata, filenames, search snippets, counts, IDs, titles, history entries, or other information belonging to resources they are not authorized to access.

See `SECURITY.md` for mandatory security rules.

## Note Version History

Notes keep up to the latest **100 historical versions**.

When a new version causes the history to exceed the configured limit, the oldest historical version is deleted.

The UI should communicate history usage, for example:

`34 / 100 versions`

History should record at minimum:

- Note ID
- Version number
- Content snapshot or equivalent restorable representation
- Editor user ID
- Timestamp

Users with appropriate permissions must be able to inspect and restore historical versions.

Restoring a version creates a new current version; it must not silently rewrite history.

## Concurrent Editing

V1 does not require collaborative Google-Docs-style editing.

Use optimistic concurrency control.

A note should expose a revision/version identifier or equivalent timestamp. When saving, the client sends the revision it originally loaded. If the note has changed meanwhile, the API rejects the update with a conflict response rather than silently overwriting newer content.

The UI should explain the conflict and allow the user to reload/copy their unsaved changes.

## Search

V1 provides global search across all notes the authenticated user is authorized to read.

Search should cover at least:

- Note title
- Note content
- Tags
- Attachment filenames where appropriate

Authorization filtering must happen server-side.

Search must never reveal inaccessible notes through titles, snippets, counts, IDs, tags, filenames, or other metadata.

## Attachments and Images

Attachments are stored on the NAS filesystem with metadata stored in MariaDB.

The original user-provided filename may be retained as metadata, but filesystem names should not rely on user-provided filenames for uniqueness or security.

V1 should support image upload and pasting images from the clipboard where supported by the browser.

Allowed MIME types, maximum upload size, and storage limits must be configurable.

Files must only be served through an authorization-aware mechanism. Do not expose a predictable public filesystem path for private attachments.

## Archive and Trash

Notes support both archive and trash states.

Archive means the note remains available but is removed from normal active views.

Trash is a soft-delete state. The default retention period is 30 days and should be configurable. After the retention period, eligible trashed data may be permanently deleted.

## Magic Links

Users may create Magic Links for:

- A single note
- A folder

Magic Links are intended for convenient bookmarked access and controlled sharing/access without normal login.

A Magic Link must support:

- Secure random token
- Start date/time
- Stop date/time
- Optional allowed time-of-day window
- Permission level, initially at least Read and optionally Write
- Revocation
- Created-by user
- Creation timestamp
- Last-used timestamp where practical
- Target type and target ID

Delete permission is out of scope for Magic Links in V1.

The raw token is shown only when the Magic Link is created. Only a cryptographic hash of the token is stored in the database.

Hashing a reusable Magic Link does **not** make it single-use. Reusable Magic Links remain valid until revoked or outside their configured validity window.

Invitation tokens, by contrast, should normally be single-use.

Never log raw Magic Link or invitation tokens.

## Geotagging

A note may optionally have a geographic location.

V1 supports three ways of setting it:

1. **Current position** — explicitly requested by the user using the browser Geolocation API.
2. **Map selection** — user selects/moves a marker on an OpenStreetMap-based map.
3. **Manual coordinates** — latitude and longitude entered manually.

The application must never request or capture the user's location silently. Location access is initiated by an explicit user action and follows browser permission controls.

Store latitude and longitude as actual structured fields, not merely as a map URL.

Optional fields may include:

- Human-readable place label
- Latitude
- Longitude
- Location source (`current`, `map`, `manual`)

Use OpenStreetMap data for map display. Leaflet is the preferred lightweight frontend mapping library unless there is a concrete reason to choose another library.

Future functionality may include:

- Map of all accessible geotagged notes
- Notes near current position
- Radius search

These future features must not be implemented in V1 unless explicitly requested.

## Notifications

Use ntfy for notifications.

Server URL, credentials, and relevant system settings are centrally configurable by administrators and must not be exposed unnecessarily to the frontend.

Users can configure which supported notifications they wish to receive.

The initial notification feature set should remain small and be expanded only as concrete use cases are defined.

## API Access

In addition to browser authentication, Muninn should support personal/API access tokens for integrations.

Tokens must support explicit scopes, for example:

- `notes:read`
- `notes:write`
- `files:read`
- `files:write`

Store only token hashes when technically practical. Raw API tokens should be shown only at creation time.

All API-token access uses the same authorization rules as interactive users in addition to token scopes.

## Administration

An administration interface is required.

V1 administration should cover at least:

- Users
- Invitations
- User requests/proposed users
- Workspaces
- Memberships and roles
- Magic Link overview/revocation where appropriate
- API token management/revocation where appropriate
- Storage overview
- ntfy configuration
- Application configuration
- Audit/security-relevant logs

Admin access does not justify bypassing authorization casually. Administrative capabilities should be explicit and auditable.

## Export and Backup

Data portability is a core requirement.

Support:

### User export

An authorized user can export their accessible/owned data in a portable format such as a ZIP containing:

- Markdown
- JSON metadata
- Attachments

The exact format should be documented and versioned.

### System backup/export

Administrators can create an export/backup of the complete Muninn installation data.

Backup and restore are separate concerns. A backup is not considered complete until a restore procedure is documented and tested.

Sensitive secrets should not be placed into ordinary user exports.

## PWA

Muninn V1 is an installable Progressive Web App.

Include the normal PWA foundation such as:

- Web app manifest
- Application icons
- Appropriate metadata/display mode
- HTTPS-compatible setup

Offline editing, conflict synchronization after offline work, and general offline-first behavior are explicitly out of scope for V1.

Do not add complex service-worker caching unless required for installation or a specifically approved feature.

## Non-Goals for V1

Do not implement these without explicit approval:

- Offline note editing/synchronization
- Real-time collaborative editing
- End-to-end encryption
- Native Android/iOS application
- AI note generation/summarization
- Public self-registration
- Complex workflow engines
- Unlimited version history
- Magic Link delete permission
- Nearby/radius geospatial search

## Documentation

Keep documentation current as architecture and decisions change.

Important decisions should be recorded in `DECISIONS.md`.

Do not silently change an accepted architectural decision because a different approach seems preferable. Propose the change and explain the trade-offs first.

## Visual Identity

Muninn uses a Scandinavian/Norse-inspired visual identity centered around the raven as the primary symbol.

The visual style should communicate:

- Knowledge
- Memory
- Security
- Calmness
- Scandinavian simplicity
- A subtle connection to Norse mythology

The canonical visual reference is located at:

`/assets/branding/reference/muninn-brand-reference.png`

### Brand Elements

The primary Muninn identity consists of:

- A raven as the main symbol
- The word **Muninn**
- Optional circular/muted-gold graphical elements
- Subtle Nordic landscape elements where appropriate
- Preferred tagline: **Your notes. Your knowledge.**

### Color Direction

The visual identity primarily uses:

- Very dark navy / near black
- Slate blue
- White / off-white
- Muted gold as an accent color

Gold should be used sparingly for highlights, active states, location markers, notifications, and selected UI elements. Exact production color values should be defined once during UI foundation work and then kept as shared design tokens/CSS variables.

### UI Style

The application should use a clean, modern, content-first interface. The Norse theme must remain subtle. Muninn must not resemble a fantasy game or use excessive runes, ornaments, textures, or decorative elements.

Content, accessibility, readability, and usability always take priority over decoration.

The UI must be designed mobile-first using Bootstrap 5.3. Both light and dark themes should remain architecturally possible, although implementing both is not required during the initial foundation phase.

### Functional Icons

Application UI icons should use a consistent, simple line-icon style. Typical icons include Home, Notes, Search, Tags, Location, Favorites, Archive, Trash, Workspace, Settings, Notifications, User, and Menu.

Decorative raven graphics must not replace functional UI icons.

### PWA and Small Assets

Muninn will be installable as a Progressive Web App. Production assets should eventually include at minimum:

- 512x512 application icon
- 192x192 application icon
- Maskable application icon
- Apple touch icon where useful
- 32x32 favicon
- 16x16 favicon
- Mobile splash/loading artwork
- Desktop/wide splash or login artwork where useful

Do not simply shrink a detailed full logo into tiny sizes. Small icons should use a simplified raven symbol designed to remain recognizable at favicon and launcher sizes.

The current brand reference is a visual concept board, not a source file containing individually production-ready icons. If a required production asset does not yet exist, use a clearly identified temporary placeholder and record the missing asset rather than inventing a different brand direction.
