# Security checklist

Grows every week and feeds the Week 5 security review. Each line names how it is verified.

## Week 1 — authentication and invitations

| Control | Verified by |
|---|---|
| Passwords hashed with `password_hash` (Argon2id, else bcrypt with 72-byte cap); old hashes upgraded at login | `PasswordServiceTest`, `AuthTest::testOutdatedHashIsUpgradedOnLogin` |
| Minimum password length 12 | `PasswordServiceTest`, `InvitationTest::testInputValidationReportsEveryField` |
| Failed logins identical for wrong password, unknown user, disabled user; dummy hash for timing | `AuthTest::testFailedLoginsAreIndistinguishable` |
| Login rate limited per username+IP (5/15 min) and per IP (20/15 min) | `AuthTest` rate-limit tests |
| Client IP from `X-Forwarded-For` only behind configured trusted proxies | `ClientIpResolverTest` |
| Session token 256-bit random, stored only as SHA-256 | `AuthTest::testSuccessfulLoginSetsHardenedCookieAndStoresOnlyHash` |
| Cookie `__Host-`, `Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`, no `Domain` | same |
| New session on every login; previous session in that browser revoked | `AuthTest::testNewLoginReplacesPreviousSessionInSameBrowser` |
| Logout revokes server-side; idle (7 d) and absolute (30 d) expiry; disabled users lose sessions | `AuthTest` |
| CSRF token required on every authenticated state change | `AuthTest::testStateChangeWithoutValidCsrfTokenIsRefused` |
| Foreign `Origin` refused on state changes; non-JSON bodies refused | `CorsTest`, `HttpConventionsTest` |
| CORS exact-origin allowlist, never `*` | `CorsTest`, `ConfigTest::testWildcardOriginIsRejected` |
| Admin endpoints 404 for non-admins, 401 when signed out | `InvitationTest` |
| Invitation tokens 256-bit, hash-only, time-limited, single-use, revocable | `InvitationTest` |
| Used/expired/revoked/unknown invitations indistinguishable | `InvitationTest::testUsedExpiredRevokedAndUnknownTokensFailIdentically` |
| Concurrent acceptance cannot create two users | `InvitationTest::testConcurrentAcceptsCreateAtMostOneUser` |
| Invitation token guesses rate limited per IP | `InvitationTest::testInvalidTokenGuessesAreRateLimitedPerIp` |
| Invitation token in URL fragment (not sent to servers) and removed from history | e2e check; `invite.js` |
| No passwords or raw tokens in logs or any database table | `AuditAndSecretsTest` |
| Security events audited | `AuditAndSecretsTest` |
| Internal errors show no stack traces, SQL or paths | `HttpConventionsTest::testInternalErrorsHideDetailsAndCarryRequestId`, `BootstrapTest` |
| API security headers (`nosniff`, `no-store`, CSP `default-src 'none'`, HSTS) | `HttpConventionsTest::testSecurityHeadersArePresent` |
| Frontend CSP without inline scripts; DOM updates via `textContent` only | manual review; e2e check found no CSP violations |
| Admin accounts only via CLI; no web setup endpoint | `bin/create-admin.php` |
| Parameterised SQL everywhere | code review (no string-built SQL with input) |
| Secrets never committed; config files git-ignored and excluded from release zips | `.gitignore`, `deploy/build-release.sh` safety check |

## Open items for later weeks

- Workspace authorization policy and its regression tests (Week 2).
- Markdown sanitisation, upload validation and attachment authorization (Week 3).
- Search and history authorization tests (Week 4).
- Full security review against this list (Week 5).
