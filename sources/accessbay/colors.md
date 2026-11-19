# AccessBay brand colors and assets

## Colors

| Name | Hex | RGB | Use |
|---|---|---|---|
| Blue | `#37A8FF` | 55, 168, 255 | icon tile (primary) |
| Navy | `#001F3F` | 0, 31, 63 | wordmark on light, dark tile, dark backgrounds |
| White | `#FFFFFF` | 255, 255, 255 | shield and key, wordmark on dark, light tile |

## Icon color variants

| Variant | Tile | Glyph | Glyph contrast | Status | Files |
|---|---|---|---|---|---|
| blue tile | `#37A8FF` | `#FFFFFF` | 2.56 | made | `accessbay-icon.svg`, `accessbay-icon.png` |
| navy tile | `#001F3F` | `#FFFFFF` | 16.56 | made (png only) | `accessbay-icon-tile-navy.png` |
| white tile | `#FFFFFF` | `#001F3F` | 16.56 | made (png only) | `accessbay-icon-tile-white.png` |
| mono navy (no tile) | none | `#001F3F` | - | suggested, not made | - |
| mono white (no tile) | none | `#FFFFFF` | - | suggested, not made | `accessbay-key.svg` |
| mono black (no tile) | none | `#000000` | - | suggested, not made | - |

## Files

| File | Kind | Size (px) | Ratio | Tile | Glyph | Wordmark | Best on |
|---|---|---|---|---|---|---|---|
| `accessbay-icon.svg` | icon | 240x240 | 1:1 | #37A8FF | #FFFFFF | - | light and dark |
| `accessbay-key.svg` | key (shield and key only, no tile) | 237x287 | 0.83:1 | - | #FFFFFF | - | blue or dark backgrounds |
| `accessbay-logo.svg` | logo | 1041x240 | 4.34:1 | #37A8FF | #FFFFFF | #001F3F | light backgrounds |
| `accessbay-logo-dark.svg` | logo | 1041x240 | 4.34:1 | #37A8FF | #FFFFFF | #FFFFFF | dark backgrounds |
| `accessbay-icon.png` | icon | 240x240 | 1:1 | #37A8FF | #FFFFFF | - | light and dark |
| `accessbay-icon-tile-navy.png` | icon | 240x240 | 1:1 | #001F3F | #FFFFFF | - | light backgrounds (and blue) |
| `accessbay-icon-tile-white.png` | icon | 240x240 | 1:1 | #FFFFFF | #001F3F | - | dark or blue backgrounds |
| `accessbay-logo.png` | logo | 1041x240 | 4.34:1 | #37A8FF | #FFFFFF | #001F3F | light backgrounds |
| `accessbay-logo-dark.png` | logo | 1041x240 | 4.34:1 | #37A8FF | #FFFFFF | #FFFFFF | dark backgrounds |
| `accessbay-logo-tile-white.png` | logo | 1041x240 | 4.34:1 | #FFFFFF | #001F3F | #FFFFFF | dark or blue backgrounds |
| `accessbay-logo-tile-navy.png` | logo | 1041x240 | 4.34:1 | #001F3F | #FFFFFF | #001F3F | light backgrounds |

SVG sizes are the logical size; the logo and icon SVGs use a 4x viewBox, so they scale to any size.

## Layout

- **icon tile:** 240x240 px, rounded square, 1:1
- **logo:** 1041x240 px, 4.34:1; icon tile on the left, wordmark on the right
- **wordmark:** 'Access' bold + 'Bay' light, same size
- **svg note:** logo and icon svgs use a 4x viewBox (4164x960, 960x960) with width/height set to the png size

## Contrast (WCAG)

| Foreground | Background | Ratio | Text OK? |
|---|---|---|---|
| `#001F3F` | `#FFFFFF` | 16.56 | all text |
| `#FFFFFF` | `#001F3F` | 16.56 | all text |
| `#37A8FF` | `#001F3F` | 6.47 | all text |
| `#37A8FF` | `#FFFFFF` | 2.56 | graphics only, not text (fails WCAG AA) |

## CSS

```css
:root {
  --ab-blue: #37A8FF;
  --ab-navy: #001F3F;
  --ab-white: #FFFFFF;
}
```
