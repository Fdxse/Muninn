# Muninn: portfolio summary

Material for a CV, LinkedIn or the course presentation. Edit freely; the numbers are from the
repository at the end of Week 6 (2026-10-08).

## One line

Muninn is a self-hosted, multi-user note application with strict data isolation between users
and shared workspaces, built in six weeks as a PHP 8 REST API on a Synology NAS and a
mobile-first Bootstrap 5.3 PWA.

## Short description (CV / LinkedIn)

> Designed and built Muninn, a self-hosted note application for personal and team knowledge.
> Invitation-only accounts, personal and shared workspaces with Owner/Admin/Editor/Reader roles,
> Markdown notes with checklists, code and pasted images, folders and tags, global search,
> version history with restore, Archive and Trash. The PHP 8 REST API and MariaDB run on my own
> Synology NAS; the installable mobile-first web app is plain PHP, Bootstrap 5.3 and vanilla
> JavaScript. Security was treated as the core feature: every endpoint authorises through one
> central policy, search, history and attachments are covered by data-isolation regression
> tests, and CI runs 200+ automated tests on every change.

## What it shows

- **Security by design.** One `WorkspaceAuthorizer` decides every access; the services only
  accept a verified membership, so a query cannot forget its scope. Inaccessible and
  non-existent resources answer the same 404. Session, invitation and reset tokens are 256-bit
  random and stored only as SHA-256 hashes; `__Host-` cookies, CSRF tokens, rate-limited sign-in,
  strict CSP, sanitised Markdown (marked + DOMPurify) and server-side image type detection.
- **Data integrity.** Optimistic concurrency (revision numbers, 409 on conflict) so no save is
  silently lost; version history capped at 100 versions; restore is itself undoable; Trash with
  30-day retention cleaned up by the API itself, without a scheduler.
- **Real deployment under real constraints.** The API runs on a home NAS behind a `api.dx.se`
  alias so the session cookie stays first-party on iOS Safari; deployment is deliberately manual
  (release zip from GitHub Actions, PowerShell copy script, versioned migrations, a setup check).
- **Mobile first and accessible.** 44px touch targets, keyboard focus rings, skip link, WCAG AA
  contrast fixes to the brand palette, installable as an app on iPhone and Android.
- **Disciplined scope.** A written MVP contract, 50+ recorded architecture decisions
  (`DECISIONS.md`), and stretch goals explicitly kept out until the MVP was done.

## Numbers

| | |
|---|---|
| API | ~8,300 lines of PHP, 60 routes under `/api/v1/` |
| Frontend | ~4,600 lines of PHP and JavaScript, no framework, no build step |
| Tests | ~4,000 lines; 200+ PHPUnit unit and integration tests against a real MariaDB |
| Database | 5 versioned migrations |
| Decisions | 53 recorded in `DECISIONS.md` |
| Runtime dependencies | Bootstrap 5.3, Bootstrap Icons, marked, DOMPurify (all vendored, no CDN) |

## Technology

PHP 8.2+ (8.4 in production), MariaDB 10, Composer, PHPUnit, Bootstrap 5.3, vanilla
JavaScript, Web App Manifest + service worker, GitHub Actions (CI and release builds), Synology
Web Station (Apache), Let's Encrypt.

## Screenshots

See `docs/screenshots/`: `phone-notes.png`, `phone-note.png`, `phone-search.png` and
`desktop-notes.png` work well on a slide.

## Talking points for the presentation

1. The problem: one place for private and shared notes without ever mixing them up.
2. Demo (`docs/demo.md`): sign in, write a note with a checklist and a pasted image, search,
   history, shared workspace, and show that Erik cannot find Anna's private note.
3. How isolation is enforced and tested (one authorizer, identical 404s, regression tests).
4. Deployment on a NAS: why `api.dx.se` exists (iOS third-party cookies).
5. What's next: Magic Links, geotagging, ntfy notifications (stretch goals in `ROADMAP.md`).
