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
| Data reset only via CLI, keeps admins and audit log, all-or-nothing, refuses without an admin (D046) | `DataResetTest` |
| Parameterised SQL everywhere | code review (no string-built SQL with input) |
| Secrets never committed; config files git-ignored and excluded from release zips | `.gitignore`, `deploy/build-release.sh` safety check |

## Week 2 — workspaces, roles and notes

| Control | Verified by |
|---|---|
| One central authorization check (`WorkspaceAuthorizer`) for every workspace and note endpoint | code review; `DataIsolationTest` |
| Role matrix D029 pinned | `WorkspaceRoleTest` (unit), `WorkspaceRolesTest` (API) |
| User A cannot read, edit or trash user B's note | `DataIsolationTest::testUserCannotReadUpdateOrTrashAnotherUsersNote` |
| Inaccessible, unknown and malformed IDs give an identical 404 body | `DataIsolationTest::assertLooksNonexistent`, `testMalformedAndRandomIdsGiveTheSame404` |
| Listings never include other users' workspaces or notes | `DataIsolationTest::testListingsNeverIncludeOtherUsersWorkspacesOrNotes` |
| Reader cannot write; Editor cannot manage members or the workspace; Admin cannot touch Owners/Admins | `WorkspaceRolesTest` |
| A workspace always keeps one Owner (checked under a row lock) | `WorkspaceRolesTest::testWorkspaceAlwaysKeepsOneOwner` |
| Removed members lose access immediately | `DataIsolationTest::testRemovedMemberLosesAccessImmediately` |
| System administrators have no note access, even with a planted membership row (D025, D044) | `DataIsolationTest::testSystemAdministratorHasNoWorkspaceOrNoteAccess` |
| Stale revision refused with 409; nothing silently overwritten | `NoteTest::testStaleRevisionIsRefusedAndNothingIsOverwritten` |
| Delete moves to Trash; trashed notes invisible; workspace deletion never deletes notes | `DataIsolationTest::testTrashedNoteDisappearsFromReadsAndListings`, `WorkspaceRolesTest::testOnlyEmptySharedWorkspacesCanBeDeleted` |
| Disabling a user revokes all sessions and blocks sign-in | `UserAdminTest` |
| CSRF required on workspace and note changes | `DataIsolationTest::testStateChangesWithoutCsrfTokenAreRefused` |
| Note content shown with `textContent` only (no HTML interpretation) | e2e check: `<b>` shown as text |
| Membership and note-trash events audited | `WorkspaceRolesTest::testMembershipChangesAreAudited`, `NoteTest` |

## Week 3 — Markdown, folders, tags and attachments

| Control | Verified by |
|---|---|
| Raw HTML in Markdown shown as text; output sanitised by DOMPurify; no `javascript:` links; links get `rel="noopener noreferrer nofollow"` | e2e check (script, `onerror` and `javascript:` payloads did not run; `markdown-renderer.js`) |
| Only `attachment:` images render; outside image URLs become plain links (D036) | e2e check; `markdown-renderer.js` sanitiser hook |
| Rendered Markdown inserted as a DOM fragment, never via `innerHTML` | code review |
| Folders and tags scoped to one workspace; outsiders get 404; Readers cannot change folders | `FolderAndTagTest::testOutsidersCannotSeeOrChangeFoldersAndTags`, `testReadersSeeFoldersButCannotChangeThem` |
| A note cannot be filed into another workspace's folder (same answer as an unknown folder) | `FolderAndTagTest::testNoteCannotBeFiledIntoAnotherWorkspacesFolder` |
| Deleting a folder never deletes notes | `FolderAndTagTest::testFolderLifecycleAndDeletingMovesNotesToNoFolder` |
| Refused (stale) saves change neither folder nor tags | `FolderAndTagTest::testRefusedSaveChangesNeitherFolderNorTags` |
| User A cannot fetch, list or upload to user B's attachments; identical 404 to an unknown ID | `AttachmentTest::testOtherUsersCannotFetchListOrUploadAttachments` |
| Attachments of trashed notes and of workspaces a member left are unavailable | `AttachmentTest::testAttachmentsOfTrashedNotesAreUnavailable`, `testReadersCanViewButNotUpload` |
| Upload type detected from bytes (magic bytes + `getimagesizefromstring`); SVG, disguised and broken files refused; browser type and extension ignored | `AttachmentTest::testNonImagesAndDisguisedFilesAreRefused`, `testBrowserTypeIsIgnoredInFavourOfTheRealType` |
| Upload size limit; CSRF token required; form content types refused | `AttachmentTest::testUploadSizeLimitAndCsrfAreEnforced` |
| Files stored outside the web root under random UUID names; filenames are display text only and sanitised | `AttachmentStorage`, `AttachmentTest::testFilenamesAreSanitised` |
| Downloads: detected type, `nosniff`, CSP `default-src 'none'`, `private` caching, CORP `same-site` | `AttachmentTest::testUploadListAndDownload` |
| Clipboard paste uses the same upload endpoint | `note-editor.js`; e2e check |
| Data reset deletes folders, tags, attachments and their files | `DataResetTest` |

## Week 4 — Search, history, Archive and Trash

| Control | Verified by |
|---|---|
| User A cannot discover user B's notes through search; joining or leaving a workspace changes exactly what is searchable | `SearchTest::testSearchNeverShowsOtherPeoplesNotes` |
| Administrator accounts find nothing | `SearchTest::testAdministratorAccountsFindNothing` |
| Trash is never searched; the Archive only on request | `SearchTest::testTrashIsNeverSearchedAndArchiveOnlyOnRequest` |
| Search input: LIKE wildcards matched literally, length and control characters validated, sign-in required | `SearchTest::testWildcardCharactersAreMatchedLiterally`, `testInvalidQueriesAreRejected` |
| User A cannot list, read or restore user B's history; version IDs only work with their own note; trashed notes' history is unavailable | `NoteHistoryTest::testHistoryIsInvisibleWithoutAccessToTheNote` |
| Readers see history but cannot restore | `NoteHistoryTest::testReadersSeeHistoryButCannotRestore` |
| Restore needs the current revision (no silent overwrite) and is itself undoable | `NoteHistoryTest::testRestoringAVersionIsANewSaveThatCanBeUndone` |
| At most 100 versions kept | `NoteHistoryTest::testOnlyTheNewestHundredVersionsAreKept` |
| Outsiders cannot list, restore, purge or empty another workspace's Trash | `TrashAndArchiveTest::testOutsidersCannotSeeOrTouchTheTrash` |
| Readers cannot restore; Editors cannot delete for good; active notes can never be purged | `TrashAndArchiveTest::testTrashListRestoreAndRoles`, `testOnlyAdminsAndOwnersDeleteForGood` |
| Purge removes history, tag links, unused tags, attachment rows and only that note's files | `TrashAndArchiveTest::testPurgingRemovesHistoryTagsAttachmentsAndFiles` |
| Retention cleanup never deletes active or archived content, only Trash older than the limit | `TrashAndArchiveTest::testRetentionCleanupDeletesOnlyExpiredTrash` |
| Restore, purge, empty Trash, automatic cleanup and version restores are audited | `TrashAndArchiveTest`, `NoteHistoryTest`, `ExpiredTrashCleanup` |
| Search highlighting built from text nodes and `<mark>` elements, never `innerHTML` | `search.js` code review |

## Week 5 — password reset, invitation requests, admin workspaces, review

| Control | Verified by |
|---|---|
| Reset tokens 256-bit, hash-only, single use, 24 h default (72 h max), newest link replaces older ones | `PasswordResetTest::testResetLinkSetsNewPasswordAndSignsOutEverywhere`, `testLinkCannotBeReused`, `testNewLinkRevokesOlderOneAndUnusableLinksFailIdentically` |
| Used, replaced, expired, unknown and malformed reset links give an identical 404 | `PasswordResetTest::testNewLinkRevokesOlderOneAndUnusableLinksFailIdentically` |
| A reset signs the account out everywhere; disabling an account revokes its reset links | `PasswordResetTest::testResetLinkSetsNewPasswordAndSignsOutEverywhere`, `testDisablingTheAccountRevokesItsLink` |
| Reset token guesses rate limited per IP; only admins create links, never for themselves or disabled accounts | `PasswordResetTest::testInvalidTokenGuessesAreRateLimitedPerIp`, `testAdminRulesForCreatingLinks`, `testEverydayUserCannotCreateResetLinks` |
| Changing one's own password needs the current one, is rate limited like sign-in, needs CSRF, signs out other sessions | `PasswordResetTest::testUserChangesOwnPasswordAndOtherSessionsEnd`, `testWrongCurrentPasswordIsRejectedAndRateLimited`, `testChangingOwnPasswordNeedsCsrfToken` |
| Users see and act on only their own invitation requests (others' look nonexistent) | `InvitationRequestTest::testUsersSeeAndTouchOnlyTheirOwnRequests` |
| Only admins approve or decline; links only after approval; decline, cancel and a disabled requester stop the link | `InvitationRequestTest` |
| Requested links are ordinary single-use invitations; a new link revokes the old one | `InvitationRequestTest::testNewLinkReplacesTheOldOne`, `testFullFlowFromRequestToNewAccount` |
| Request state changes lock the request and invitation rows (no decline/accept race) | `InvitationRequestService::lockRequest` code review |
| Admin workspace pages show no note titles, counts or content; personal workspaces are not reachable | `WorkspaceAdminTest::testListShowsSharedWorkspacesWithoutContent`, `testPersonalWorkspacesAreNotManageable` |
| Member management by admins keeps the one-Owner rule, is audited, and grants admins no note access | `WorkspaceAdminTest` |
| Admin workspace endpoints are 404 for everyday users, including the workspace's own Owner | `WorkspaceAdminTest::testEverydayUsersCannotUseAdminWorkspaceEndpoints` |
| No new password or token reaches the log file or any table; new events audited | `AuditAndSecretsTest::testPasswordResetAndRequestedInvitationLeakNoSecrets` |
| Data reset also clears invitation requests and reset links | `DataResetTest` |
| Frontend: no `innerHTML`, `eval` or inline script anywhere; reset token in the URL fragment and removed from history | code review (grep), `reset-password.js` |
| SQL review: every query with input uses bound parameters; the few built clauses are fixed strings from code | code review (grep for concatenated SQL) |
| Contrast: outline buttons, danger text and checkbox borders meet WCAG AA | `muninn.css`, screenshots at 390 px |

## Week 5 review notes

- Reviewed every route in `Application::registerRoutes` against SECURITY.md: each protected route
  is behind the session check in the pipeline, admin routes are 404 for everyone else, every
  state change needs the CSRF token, and every workspace route goes through `WorkspaceAuthorizer`.
- Accepted trade-off D050: administrators can add any everyday account to a shared workspace.
- Out of the application's reach (hosting): HTTPS redirect and HSTS for the frontend on one.com,
  and backups of the NAS database (see known limitations).
