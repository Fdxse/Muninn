# Asset Manifest

## Available
- `reference/muninn-brand-reference.png` — concept/reference board (canonical visual direction).
- `reference/muninn-brand-sheet.png` — brand sheet (October 2026) with the exact colour palette,
  typography and a sketch of the asset set. It is one composite image: the logos, icons and
  splash screens on it are previews, and no separate files exist for them.
- `source/muninn-icon.svg`, `source/muninn-icon-maskable.svg`, `source/muninn-icon-small.svg` —
  interim vector raven icons drawn from the sheet (see D043).

## Applied from the brand sheet
- Colour palette → CSS tokens in `frontend/public/assets/css/muninn.css` (see D043).
- `#0B1A2B` → PWA `theme_color`/`background_color` and the `theme-color` meta tag.
- Fonts: Merriweather (headings) and Inter (body), self-hosted in
  `frontend/public/assets/vendor/fonts/`.
- App icons and favicons → `frontend/public/assets/icons/`, exported from `source/` (see the
  README there).

## Interim assets in use
- `frontend/public/assets/icons/*.png` and `muninn-mark-small.svg` — simple flat vector icons, not
  the painted raven from the sheet. Replace with final artwork using the same file names:
  `icon-192.png`, `icon-512.png`, `icon-maskable-512.png`, `apple-touch-icon.png` (180x180),
  `favicon-32.png`, `favicon-16.png`.

## Future production assets
- Horizontal light/dark logo (`muninn-logo-horizontal-light.png`/`-dark.png` on the sheet)
- Final raven icon artwork (painted style, as on the sheet)
- Portrait/landscape splash artwork (Android already builds a splash from the icon and the
  manifest background colour)

Do not block functional MVP delivery on final branding.
