# Asset Manifest

## Available
- `reference/muninn-brand-reference.png` — concept/reference board (canonical visual direction).
- `reference/muninn-brand-sheet.png` — brand sheet (October 2026) with the exact colour palette,
  typography suggestion and the list of production files below. It is one composite image: the
  logos, icons and splash screens on it are previews, not usable files.

## Applied from the brand sheet
- Colour palette → CSS tokens in `frontend/public/assets/css/muninn.css` (see D043).
- `#0B1A2B` → PWA `theme_color`/`background_color` and the `theme-color` meta tag.
- Placeholder icons regenerated in the sheet's navy and gold.
- Fonts: Merriweather/Inter are **not** bundled yet (system serif/sans fallbacks are used).

## Placeholders in use
- `frontend/public/assets/icons/*.png` — generated placeholders (navy square, gold ring, white "M")
  from `frontend/tools/generate-placeholder-icons.php`. Replace with production raven icons using
  the same file names: `icon-192.png`, `icon-512.png`, `icon-maskable-512.png`,
  `apple-touch-icon.png` (180x180), `favicon-32.png`, `favicon-16.png`.

## Future production assets
File names as listed on the brand sheet. Icons that replace placeholders keep the placeholder
names in `frontend/public/assets/icons/`.

- Horizontal light/dark logo
- Simplified raven symbol
- PWA 512x512 and 192x192 icons
- Maskable PWA icon
- Apple touch icon
- 32x32 and 16x16 favicon
- Portrait/landscape splash artwork

Use placeholders where needed until dedicated assets exist. Do not block functional MVP delivery on final branding.
