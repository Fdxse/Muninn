# Course MVP status

Every Definition of Done item from `COURSE-MVP.md`, with where it is proven. State at the end
of Week 6 (2026-10-08). "Owner" marks the checks only the project owner can do on the live
servers and real devices.

| # | Definition of Done | Status | Evidence |
|---|---|---|---|
| 1 | An administrator invites a new user, who sets their credentials | Done | `InvitationTest`; admin Invitations page; `invite.php`; invitation requests from users (D049) |
| 2 | Sign in and sign out safely | Done | `AuthTest` (hardened `__Host-` cookie, CSRF, rate limits, revocation) |
| 3 | A personal workspace is created and isolated | Done | `DataIsolationTest::testEveryUserGetsExactlyOnePersonalWorkspaceAsOwner`, `testPersonalWorkspacesAreSeparate` |
| 4 | Shared workspace membership and roles enforced by the API | Done | `WorkspaceRolesTest`, `WorkspaceRoleTest` (role matrix D029), `WorkspaceAdminTest` (D050) |
| 5 | Create, view, edit, organise, archive, trash, restore and search authorised notes | Done | `NoteTest`, `FolderAndTagTest`, `TrashAndArchiveTest`, `SearchTest` |
| 6 | Markdown, checklists, code blocks, links and images in the UI | Done | `markdown-renderer.js` (marked + DOMPurify, D035/D036); `AttachmentTest`; demo step 2 |
| 7 | Version history never exposes inaccessible notes or metadata | Done | `NoteHistoryTest` (404 for non-members, 100-version cap, restore as new version) |
| 8 | Search never exposes inaccessible notes or metadata | Done | `SearchTest` (other users, Trash, Archive, administrators); `DemoDataTest` |
| 9 | Attachment access is authorised server-side | Done | `AttachmentTest` (non-members 404, trashed notes, content sniffing) |
| 10 | Usable in modern mobile and desktop browsers | Done, owner to confirm on iPhone | Week 6 click-through at 375px and 1280px: no console errors or sideways scrolling; `docs/device-checklist.md` |
| 11 | Installable as a basic PWA (no offline editing) | Done, owner to confirm | `manifest.webmanifest`, `service-worker.js` (caches nothing); device checklist "Install as an app" |
| 12 | Frontend and API deployed and usable through documented URLs | Owner | `https://www.dx.se/muninn/` and `https://api.dx.se` (live since 2026-10-07); Week 5 and 6 still to deploy |
| 13 | Database setup, migrations and configuration documented | Done | `deploy/DEPLOY.md`, `docs/setup-dev.md`, `api/config/config.example.php` |
| 14 | A fresh deployment can be reproduced without manual database edits | Done | Migrations 0001–0005 only; `MigrationTest`; `bin/check-setup.php`; `deploy/DEPLOY.md` first-time setup |
| 15 | Important security boundaries have automated regression tests | Done | 200+ PHPUnit tests run by CI on every pull request; `docs/security-checklist.md` maps each control to a test |
| 16 | No known critical or high security defect open | Done | Week 5 review in `docs/security-checklist.md`; Week 6 changes reviewed there too |
| 17 | README, setup, architecture, screenshots, demo instructions, known limitations | Done | `README.md`, `docs/architecture.md`, `docs/demo.md`, `docs/screenshots/`, `docs/known-limitations.md` |
| 18 | No production passwords, secrets or raw tokens in the repository | Done | `.gitignore`; `deploy/build-release.sh` refuses to package `config.php`; demo passwords are random and printed once |
| 19 | A short demo from login to note creation, search, history and shared workspace | Done | `docs/demo.md` with `bin/seed-demo.php` |

## What the owner still does

1. Deploy Weeks 5 and 6 (`docs/deployment.md`, "Upgrading to Week 5 and 6").
2. Work through the "Going live checklist" in `deploy/DEPLOY.md`, including
   `app.environment = production`.
3. Run `docs/device-checklist.md` on the iPhone and one desktop browser.
4. Fill in the remaining `_to record_` cells of the environment record in `docs/deployment.md`.
