# Muninn Security Requirements

Security and strict data isolation are first-class requirements because Muninn may contain both private and professional information in the same installation.

## Trust Boundary

The backend API is the authoritative trust boundary.

The frontend is untrusted for authorization purposes.

Every protected API endpoint must independently authenticate and authorize the caller.

## Authorization

Use deny-by-default authorization.

Access should be granted only when an explicit rule allows it.

Authorization must cover:

- Workspaces
- Notes
- Folders
- Tags where visibility can reveal protected information
- Attachments
- Version history
- Search
- Exports
- Magic Links
- API tokens
- Administration

Avoid duplicated ad-hoc permission logic across endpoints. Prefer centralized, testable authorization policies/helpers.

## Information Leakage

Unauthorized users must not learn whether protected resources exist through:

- Titles
- IDs
- Search snippets
- Filenames
- Tag names
- Counts
- Version metadata
- Error differences
- Timing-dependent UI behavior where practical to avoid

Where appropriate, return `404` for inaccessible resources rather than confirming existence with `403`.

## Authentication

- Invitation-only accounts.
- No public registration in V1.
- Passwords stored using `password_hash()` and verified with `password_verify()`.
- Apply reasonable login rate limiting.
- Regenerate session identifiers after successful login if PHP sessions are used.
- Secure authentication cookies with appropriate `Secure`, `HttpOnly`, and `SameSite` settings.
- Require HTTPS.
- Implement logout/revocation correctly.

Do not build custom cryptography.

## Tokens

Invitation, Magic Link, password-reset if later implemented, and API tokens must use cryptographically secure randomness.

Store only cryptographic token hashes whenever the raw token does not need to be recovered.

Raw tokens:

- Are shown only when necessary.
- Must not be written to application logs.
- Must not be stored in plaintext in the database.
- Should not be included in analytics or error-reporting payloads.

Compare token hashes using safe comparison mechanisms appropriate to the chosen representation.

## Magic Links

Validate all of the following server-side on every use:

- Token hash
- Revocation state
- Valid-from timestamp
- Valid-until timestamp
- Allowed daily time window if configured
- Target resource
- Granted permission

Define and document the timezone used for daily active windows.

Magic Links do not grant Delete permission in V1.

## SQL

Use parameterized queries/prepared statements everywhere.

Never construct SQL by concatenating untrusted values.

Database accounts should have only the permissions required by the application.

## Cross-Origin Architecture

Frontend and API are hosted on different origins.

CORS must use an explicit allowlist. Do not use wildcard origins for credentialed requests.

The final authentication/session transport design must explicitly account for cross-origin behavior, CSRF, cookie settings, and HTTPS.

Do not "fix" CORS by disabling browser security controls.

## CSRF

If browser authentication relies on cookies, state-changing requests require an appropriate CSRF defense.

Do not assume CORS alone is a complete CSRF defense.

## XSS and Markdown

User-provided Markdown is untrusted input.

Rendered Markdown/HTML must be sanitized using a proven approach before insertion into the DOM.

Do not allow arbitrary script execution, unsafe event attributes, `javascript:` URLs, or unsafe embedded HTML merely because content belongs to the current user; shared workspaces make stored XSS especially dangerous.

## File Uploads

Treat uploads as untrusted.

- Enforce configurable size limits.
- Validate server-side.
- Do not trust extensions or browser-provided MIME types alone.
- Generate internal storage names.
- Prevent path traversal.
- Keep private files outside directly public web roots where practical.
- Serve/download files only after authorization checks.
- Use safe response headers for downloads.

Clipboard-pasted images go through the same pipeline.

## Geolocation

Location is optional sensitive note metadata.

- Never collect location in the background.
- Never request browser location without explicit user action.
- Apply normal note/workspace authorization to location fields.
- Do not expose coordinates through search or map endpoints to unauthorized callers.

## Logging

Security-relevant events should be logged where useful, such as:

- Successful/failed login attempts
- Invitation creation/use
- Magic Link creation/revocation
- API token creation/revocation
- Important admin actions
- Permission changes

Never log:

- Passwords
- Raw invitation tokens
- Raw Magic Link tokens
- Raw API tokens
- Session secrets
- ntfy credentials

Logs themselves require restricted access.

## Secrets and Configuration

Do not commit secrets to source control.

Keep environment-specific secrets outside the repository using an appropriate server-side configuration mechanism.

Provide a safe example configuration file containing placeholders only.

## Exports and Backups

Exports must enforce authorization before collection begins and while data is assembled.

Do not include inaccessible workspace data merely because the exporting user has access to another note in the same system.

System backups may contain sensitive data and must be protected accordingly.

Document restoration and test it periodically.

## Security Regression Rule

When a security defect is discovered, fix the underlying authorization/validation layer rather than only hiding the affected UI path, and add a regression test whenever practical.
