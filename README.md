# Muninn

**Your notes. Your knowledge.** A self-hosted, multi-user, mobile-first note application
(PHP 8 + MariaDB API, Bootstrap 5.3 + vanilla JavaScript PWA).

**Course MVP feature-complete (Week 6); [`docs/mvp-status.md`](docs/mvp-status.md) lists the last checks on the live servers.** Live at **https://www.dx.se/muninn/** (API: `https://api.dx.se`).

| | |
|---|---|
| ![Notes on a phone](docs/screenshots/phone-notes.png) | ![A note with a checklist](docs/screenshots/phone-note.png) |

## What it does

- Invitation-only accounts (admin invitations, or a user asks and an admin approves), sign-in
  and sign-out, password change, one-time reset links from an administrator.
- A private personal workspace for everyone, plus shared workspaces with Owner, Admin, Editor
  and Reader roles. The API enforces every permission; nothing leaks between workspaces, also
  not through search, history or images.
- Markdown notes with tickable checklists, code blocks, links and images (upload or paste),
  folders and tags, readable previews in the note list.
- Search across all your workspaces, version history (100 versions) with restore, conflict
  detection instead of silent overwrites, Archive, and Trash with 30-day cleanup.
- Mobile-first, keyboard-friendly, installable as an app (no offline mode, by design).
- Admin pages for invitations, users and ownerless shared workspaces.

## Where things are

| | |
|---|---|
| `api/` | REST-style JSON API (deployed to the NAS as `https://api.dx.se`) |
| `frontend/public/` | Web frontend (deployed to `https://www.dx.se/muninn/`) |
| `deploy/` | Release zip builder, `Deploy-Api.ps1`, [`DEPLOY.md`](deploy/DEPLOY.md) (production setup) |
| [`docs/architecture.md`](docs/architecture.md) | How the pieces fit together |
| [`docs/api-conventions.md`](docs/api-conventions.md) | Response format, status codes, endpoints |
| [`docs/setup-dev.md`](docs/setup-dev.md) | Running it locally and running the tests |
| [`docs/security-checklist.md`](docs/security-checklist.md) | Security controls and the tests that prove them |
| [`docs/known-limitations.md`](docs/known-limitations.md) | What is not there yet |
| [`docs/device-checklist.md`](docs/device-checklist.md) | Manual checks on phones and browsers after a deploy |
| [`docs/demo.md`](docs/demo.md) | Ten-minute demo path, with demo data from `bin/seed-demo.php` |
| [`docs/mvp-status.md`](docs/mvp-status.md) | Course MVP Definition of Done, item by item, with evidence |
| [`docs/portfolio.md`](docs/portfolio.md) | Summary for CV, portfolio and presentation |
| [`docs/deployment.md`](docs/deployment.md) | Upgrade notes per week and the live environment record |

## Quick start (local)

```sh
cd api && composer install && cp config/config.example.php config/config.php   # then edit
php bin/migrate.php && php bin/create-admin.php
php -S localhost:8000 -t public dev-router.php
# in another terminal
cd frontend/public && cp includes/config.example.php includes/config.php       # api_base_url → http://localhost:8000
php -S localhost:8080
```

Full instructions: [`docs/setup-dev.md`](docs/setup-dev.md). Tests: `cd api && vendor/bin/phpunit`.
Demo data: `php bin/seed-demo.php` (prints two demo accounts). Installation check:
`php bin/check-setup.php`. Production: [`deploy/DEPLOY.md`](deploy/DEPLOY.md).

## Project documents

Read in this order before architectural changes:

1. `CLAUDE.md` — how Claude must work.
2. `COURSE-MVP.md` — active six-week scope and Definition of Done.
3. `PROJECT.md` — product vision and architecture.
4. `SECURITY.md` — mandatory security/data-isolation rules.
5. `DECISIONS.md` — accepted decisions.
6. `ROADMAP.md` — six-week summary plus long-term product roadmap.

## Branding

The concept board is `assets/branding/reference/muninn-brand-reference.png` (visual reference,
not a sprite sheet); `muninn-brand-sheet.png` holds the colour palette. The production images
are in `assets/branding/production/`; see `assets/branding/ASSET-MANIFEST.md`.

**Ship the secure, useful MVP first. Extend Muninn second.**
