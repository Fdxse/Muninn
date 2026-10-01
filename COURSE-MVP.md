# Muninn — Course MVP

## Purpose

This document defines the delivery contract for the six-week course project. Muninn has a broader long-term product vision, but the course submission is successful only if this MVP is complete, deployable, usable, documented, and demonstrable.

## Why this is a real project

Muninn solves a real personal and work-related problem: multiple users need a secure, self-hosted place to create, organize, search, and share notes without mixing or leaking private information between users and workspaces. The project owner intends to deploy and use the application after the course.

## Course MVP Scope

The required MVP contains:

1. Invitation-only username/password authentication.
2. Users with an automatically created personal workspace.
3. Shared workspaces with Owner/Admin/Editor/Reader authorization.
4. Strict server-side authorization and user/workspace data isolation.
5. Note CRUD.
6. Markdown content with checklists, code blocks, links, and images.
7. Folders and tags.
8. Authorized global search across content the current user may read.
9. Note version history, retaining at most 100 historical versions and showing the current history count.
10. Archive and Trash, with a default 30-day Trash retention policy.
11. Mobile-first Bootstrap 5.3 frontend.
12. Installable PWA shell, without offline editing/synchronization.
13. A deployed frontend and API that can be demonstrated through real URLs.
14. Basic administration needed to operate the MVP, including invitations and user/workspace management.

## Deployment Target

- Frontend: external hosting, intended production host `dx.se`.
- API: NAS-hosted PHP REST API, intended production host `fehre.synology.me`.
- Database: MariaDB/MySQL.
- Attachments: NAS filesystem.
- Communication: HTTPS + JSON.

Development/staging URLs may differ. Secrets and production credentials must not be committed to the repository.

## Definition of Done

The Course MVP is DONE only when all of the following are true:

- A new user can be invited by an administrator and establish credentials.
- A user can sign in and sign out safely.
- A personal workspace is created and isolated from other users.
- Shared workspace membership and roles are enforced by the API.
- Users can create, view, edit, organize, archive, trash, restore, and search notes they are authorized to access.
- Markdown, checklist syntax, code blocks, links, and image handling work in the supported UI.
- Version history works and never exposes inaccessible notes or metadata.
- Search never exposes inaccessible notes or metadata.
- Attachment access is authorized server-side.
- The UI is usable on a modern mobile browser and desktop browser.
- The application can be installed as a basic PWA. Offline editing is not required.
- Frontend and API are deployed and usable through documented URLs.
- Database setup/migrations and configuration are documented.
- A fresh deployment can be reproduced from repository documentation without undocumented manual database edits.
- Important security boundaries have automated or repeatable regression tests, especially authorization and data isolation.
- No known critical/high-severity security defect remains open.
- README/setup documentation, architecture overview, screenshots/demo instructions, and known limitations are present.
- The repository contains no production passwords, API secrets, raw tokens, or other credentials.
- A short demonstration path can be completed reliably from login to note creation/search/history/shared workspace.

## Stretch Goals — Not Required for Course Completion

These remain part of the Muninn product roadmap, but are not required for the six-week Course MVP:

- Magic Links for notes/folders.
- Geotagging and OpenStreetMap/Leaflet integration.
- ntfy notifications.
- Personal external API tokens/scopes.
- Full per-user/system export, backup, and restore tooling.
- Advanced administration beyond what the MVP needs.
- Offline editing/synchronization.
- Real-time collaborative editing.

If the MVP reaches Definition of Done early, implement stretch goals one at a time. Preferred first stretch goals are Magic Links and geotagging.

## Six-Week Delivery Plan

### Week 1 — Foundation and Authentication
Repository structure, configuration, migrations, API conventions, CORS, logging, PWA foundation, admin bootstrap, login/logout, invitations.

### Week 2 — Workspaces, Authorization and Core Notes
Personal/shared workspaces, roles, centralized authorization, note CRUD, authorization regression tests.

### Week 3 — Editing and Organization
Markdown/editor experience, checklists, code blocks, links, folders, tags, images/attachments, clipboard image paste where practical.

### Week 4 — Search, History and Lifecycle
Authorized global search, version history, optimistic concurrency, Archive, Trash, restore/cleanup behavior.

### Week 5 — Productization
Mobile-first polish, minimum admin UI, PWA installability, security review, cross-browser checks, regression tests, accessibility/usability fixes.

### Week 6 — Ship
Production deployment, bug fixing, documentation, reproducible setup, demo data/path, screenshots, architecture summary, known limitations, and CV/portfolio material. No new nonessential feature should be started if it threatens delivery quality.

## Scope-Control Rule

When a requested change risks the six-week deadline, Claude must identify the risk and propose either a smaller MVP-compatible implementation or deferral to the long-term roadmap. Do not silently expand scope.
