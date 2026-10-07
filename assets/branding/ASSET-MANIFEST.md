# Asset Manifest

## Reference
- `reference/muninn-brand-reference.png` — concept/reference board (canonical visual direction).
- `reference/muninn-brand-sheet.png` — brand sheet with the exact colour palette and typography.

## Production images (`production/`)
Supplied by the project owner on 2026-10-07 and stored unchanged. `LAS-MIG.txt` and
`GENERERING.txt` (Swedish) describe how they were made: new renderings based on the brand sheet.
`bildstorlekar.csv` lists every file's pixel size.

| Folder | Contents | Used in the app |
|---|---|---|
| `app-icons/` | 512, 192, 180 and maskable 512 PNG | Yes: PWA icons and Apple touch icon |
| `favicons/` | 16, 32, 256 master PNG and multi-size `.ico` | 16/32 PNG yes; master resized for the navigation bar |
| `logos/` | Horizontal, square and symbol logos, light and dark; typography sample | Dark horizontal logo on sign-in and invitation pages |
| `splash/` | Mobile portrait 1080x1920, desktop 1920x1080 | Not yet |
| `backgrounds/` | Three 1920x1080 hero images | Not yet |
| `social/` | 1024x1024 social/app store image | Not yet |
| `ui-icons/` | 13 line icons, SVG and 256px PNG | Not yet (the app uses Bootstrap Icons) |
| `reference/` | Branding banner and document icon | Not yet |

## Applied in the frontend
- Colour palette → CSS tokens in `frontend/public/assets/css/muninn.css` (D043).
- Fonts: Merriweather and Inter, self-hosted in `frontend/public/assets/vendor/fonts/`.
- Icons → `frontend/public/assets/icons/` (see the README there).
- Logo → `frontend/public/assets/branding/muninn-logo-horizontal-dark.webp` (800x320). Its baked-in
  background `#03111B` is also the sign-in page background, so the edge is invisible.

Only the downsized copies above are deployed; the full-size originals stay in the repository.
