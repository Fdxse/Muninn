# Known limitations

Current state after Week 1. Updated as the MVP grows.

- **No notes or workspaces yet.** Week 1 delivers sign-in and invitations only.
- **No password reset.** A forgotten password needs an administrator (planned as D040).
- **No email.** Administrators copy invitation links and send them themselves.
- **No user management UI.** Disabling a user currently means editing `users.status` in the
  database; the admin UI for this comes with the MVP administration work.
- **Splash and hero images are stored but not used yet.** iOS needs one startup image per device
  size; Android builds its splash from the icon and the manifest colour
  (`assets/branding/ASSET-MANIFEST.md`).
- **iPhone sign-in needs `api.dx.se`.** Calling the API as `fehre.synology.me` from www.dx.se
  works on desktop Chrome but not on iOS, because the cookie would be third-party.
- **Manual deployment.** By design; see `deploy/DEPLOY.md`.
- **No automated backups.** Use Synology Hyper Backup or a scheduled `mysqldump` for the
  `muninn` database until export/backup tooling exists.
