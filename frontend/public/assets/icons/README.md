# App icons

Interim icons drawn as vectors from the brand sheet (raven, gold ring, mountains, navy sky).
They are not final artwork; replace them when a designer delivers production icons, keeping the
same file names and sizes so no code needs to change.

| File | Size | Exported from |
|---|---|---|
| `icon-512.png`, `icon-192.png`, `apple-touch-icon.png` | 512, 192, 180 | `assets/branding/source/muninn-icon.svg` |
| `icon-maskable-512.png` | 512 | `assets/branding/source/muninn-icon-maskable.svg` (artwork inside the 80% safe zone) |
| `favicon-32.png`, `favicon-16.png` | 32, 16 | `assets/branding/source/muninn-icon-small.svg` |
| `muninn-mark-small.svg` | vector | copy of `muninn-icon-small.svg`, used in the navigation bar |

The PNGs were exported by opening each SVG at the target size in headless Chromium and taking a
screenshot. Any SVG exporter (Inkscape, a browser) gives the same result.
