# Muninn

**Your notes. Your knowledge.** A self-hosted, multi-user, mobile-first note application
(PHP 8 + MariaDB API, Bootstrap 5.3 + vanilla JavaScript PWA).

Current state: **Course MVP Week 1** — invitation-only accounts, sign-in/sign-out, invitation
administration, installable PWA shell. Notes and workspaces start in Week 2.

## Where things are

| | |
|---|---|
| `api/` | REST-style JSON API (deployed to the NAS as `https://api.dx.se`) |
| `frontend/public/` | Web frontend (deployed to `https://www.dx.se`) |
| `deploy/` | Release zip builder, `Deploy-Api.ps1`, [`DEPLOY.md`](deploy/DEPLOY.md) |
| [`docs/architecture.md`](docs/architecture.md) | How the pieces fit together |
| [`docs/api-conventions.md`](docs/api-conventions.md) | Response format, status codes, endpoints |
| [`docs/setup-dev.md`](docs/setup-dev.md) | Running it locally and running the tests |
| [`docs/security-checklist.md`](docs/security-checklist.md) | Security controls and the tests that prove them |
| [`docs/known-limitations.md`](docs/known-limitations.md) | What is not there yet |

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
