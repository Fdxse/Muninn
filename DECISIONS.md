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
