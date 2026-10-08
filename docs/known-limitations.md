# Known limitations

State at the end of the Course MVP (Week 6), updated for sub-folders, syntax highlighting and
image removal (D054–D056). The stretch goals in `COURSE-MVP.md` (Magic Links, geotagging, ntfy,
API tokens, export/backup, offline editing, real-time collaboration) are not built.

- **Only images can be attached** (PNG, JPEG, GIF, WebP up to 10 MB). Other file types, and
  images from other websites, are not shown in notes (D036: an outside image appears as a link).
- **A removed image is gone for good.** Removing an image (D056) deletes its file at once, so
  older versions in the history that showed it say "(image removed)" instead. Deleting only the
  image's Markdown from the text still keeps the file until it is removed or the note is purged.
- **The NAS must accept 10 MB request bodies.** If PHP's `post_max_size` is lower, larger images
  are refused with "too large for the server" (see `deploy/DEPLOY.md`).
- **Syntax highlighting knows 12 languages** (PHP, JavaScript, SQL, HTML/XML, CSS, JSON, Bash,
  PowerShell, Python, YAML, Markdown, C#; D054). Other languages show as plain code. There is no
  tables toolbar button (tables written in Markdown do render).
- **Ticking a checklist box saves the whole note** with its revision; if someone else saved the
  note in the meantime, the tick is refused and the page asks for a reload.
- **Folders are at most 3 levels deep** (D055), and folder names are unique in the whole
  workspace, so two sub-folders in different places cannot share a name.
- **Note lists show at most 500 notes per workspace,** most recently changed first. Folder and
  tag filters and search narrow the list. The Trash also shows at most 500 notes.
- **Search shows at most 50 results** and matches words literally (no spelling tolerance or
  word stemming). Matching ignores case and also accents, so "o" finds "ö" too.
- **Trash cleanup runs only when someone uses Muninn.** The API deletes expired Trash after a
  signed-in request, at most once an hour, so on days nobody signs in, notes past 30 days stay in
  Trash (still restorable) until the next visit. One cleanup deletes at most 500 notes; the rest
  follow an hour later.
- **History keeps at most 100 earlier versions per note,** and quick successive saves by the same
  person count as one version. Archiving, trashing and restoring from Trash are not versions.
- **Images in old versions** show only while the image is still attached to the note.
- **Members are added by exact username.** Workspace Owners and Admins can therefore confirm
  whether an active username exists; there is no user directory or autocomplete.
- **A disabled user stays listed as a member** of their workspaces (marked "disabled account").
  If they were a shared workspace's only Owner, a system administrator gives the workspace a new
  Owner on the admin Workspaces page (D050); administrators cannot change workspaces that still
  have an active Owner.
- **No self-service password reset.** Someone who forgot their password asks an administrator
  for a one-time reset link (D040).
- **Notifications go to the administrator only** (D057, D058): new invitation requests, blocked
  sign-ins and "Contact admin" messages, through the NAS's ntfy, configured in `config.php`. Everyday users cannot choose
  notifications yet, and the settings cannot be edited on the admin pages.
- **Contact admin is one-way** (D058): messages are not stored and there is no reply inside
  Muninn. The administrator answers through the contact details the user added, if any.
- **No email.** Administrators copy invitation and reset links and send them themselves; users
  whose invitation request was approved do the same with their link (D049).
- **Approved invitation requests do not expire.** The user can create a link until an
  administrator withdraws the approval; each link itself expires after 72 hours.
- **The admin Workspaces page lists at most 500 shared workspaces** and the admin request list
  shows the newest 200 requests.
- **Splash and hero images are stored but not used yet.** iOS needs one startup image per device
  size; Android builds its splash from the icon and the manifest colour
  (`assets/branding/ASSET-MANIFEST.md`).
- **iPhone sign-in needs `api.dx.se`.** Calling the API as `fehre.synology.me` from www.dx.se
  works on desktop Chrome but not on iOS, because the cookie would be third-party.
- **Note previews are plain text.** Lists and search results show the start of a note without
  its Markdown formatting (D051); tables, quotes and code read as ordinary words there.
- **Demo data is removed by hand.** `bin/seed-demo.php` has no undo; disable the demo accounts
  on the admin Users page afterwards (D052).
- **The setup check cannot see the web server's PHP settings.** `bin/check-setup.php` runs with
  the command-line PHP, so `post_max_size` and `open_basedir` of the Web Station profile are
  checked by hand.
- **Manual deployment.** By design; see `deploy/DEPLOY.md`.
- **No automated backups.** Use Synology Hyper Backup or a scheduled `mysqldump` for the
  `muninn` database until export/backup tooling exists.
