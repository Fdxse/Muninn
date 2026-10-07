# Known limitations

Current state after Week 2. Updated as the MVP grows.

- **Notes show their Markdown source.** Rendering, checklists, folders, tags and images arrive
  in Week 3.
- **Deleted notes cannot be restored yet.** Deleting moves a note to Trash (nothing is lost),
  but the Trash view, restore and permanent deletion arrive in Week 4. Until then a shared
  workspace that ever held a note cannot be deleted.
- **Note lists show at most 500 notes per workspace,** most recently changed first. Search and
  folders (Weeks 3 and 4) are the way to find older notes.
- **Members are added by exact username.** Workspace Owners and Admins can therefore confirm
  whether an active username exists; there is no user directory or autocomplete.
- **A disabled user stays listed as a member** of their workspaces (marked "disabled account").
  If they were a shared workspace's only Owner, nobody can manage its members until they are
  re-enabled; system administrator membership management is part of the Week 5 admin work.
- **No password reset.** A forgotten password needs an administrator (planned as D040).
- **No email.** Administrators copy invitation links and send them themselves.
- **Splash and hero images are stored but not used yet.** iOS needs one startup image per device
  size; Android builds its splash from the icon and the manifest colour
  (`assets/branding/ASSET-MANIFEST.md`).
- **iPhone sign-in needs `api.dx.se`.** Calling the API as `fehre.synology.me` from www.dx.se
  works on desktop Chrome but not on iOS, because the cookie would be third-party.
- **Manual deployment.** By design; see `deploy/DEPLOY.md`.
- **No automated backups.** Use Synology Hyper Backup or a scheduled `mysqldump` for the
  `muninn` database until export/backup tooling exists.
