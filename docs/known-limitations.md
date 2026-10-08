# Known limitations

Current state after Week 3. Updated as the MVP grows.

- **Only images can be attached** (PNG, JPEG, GIF, WebP up to 10 MB). Other file types, and
  images from other websites, are not shown in notes (D036: an outside image appears as a link).
- **Attachments cannot be removed one by one yet.** Deleting the image's Markdown hides it; the
  file stays with the note and is removed when the note is purged from Trash (Week 4).
- **The NAS must accept 10 MB request bodies.** If PHP's `post_max_size` is lower, larger images
  are refused with "too large for the server" (see `deploy/DEPLOY.md`).
- **No syntax highlighting** in code blocks, and no tables toolbar button (tables written in
  Markdown do render).
- **Ticking a checklist box saves the whole note** with its revision; if someone else saved the
  note in the meantime, the tick is refused and the page asks for a reload.
- **Folders are one level deep** (D033); there are no sub-folders.
- **Deleted notes cannot be restored yet.** Deleting moves a note to Trash (nothing is lost),
  but the Trash view, restore and permanent deletion arrive in Week 4. Until then a shared
  workspace that ever held a note cannot be deleted.
- **Note lists show at most 500 notes per workspace,** most recently changed first. Folder and
  tag filters narrow the list; search arrives in Week 4.
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
