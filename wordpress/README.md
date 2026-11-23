# wpanchorbay.com/brand

A tiny plugin that renders the brand page from `https://assets.wpanchorbay.com/brand.json`.

Nothing is uploaded to the media library. Every image on the page is hotlinked from the asset
origin, so replacing a file there updates the page here with no WordPress edit at all — and adding
a whole new product appears automatically.

## Install

1. Copy `wpanchorbay-brand-assets/` into `wp-content/plugins/`, or zip that folder and upload it
   through Plugins → Add New → Upload.
2. Activate it.
3. Create a page titled **Brand Assets** at the slug `brand`, and add three Shortcode blocks, one
   per line, to reproduce the public brand-assets page exactly:

   ```
   [wpanchorbay_brand_hero]
   [wpanchorbay_brand_assets]
   [wpanchorbay_brand_usage]
   ```

Each one is a self-contained region — use just `[wpanchorbay_brand_assets]` on its own (for example
on a single product's page) if you don't want the header or the usage guidelines there too.
Anything else you add around them — a press contact, extra copy — is just normal blocks.

## Shortcodes

**`[wpanchorbay_brand_hero]`** — the page's only `h1`, a lede, the "Download all brands (.zip)"
button, a mosaic of every product icon (each links to its card), and the "Hotlink it" /
"Machine-readable" panels (with copy buttons). No attributes; the hotlink example always uses the
brand's own logo.

**`[wpanchorbay_brand_assets]`** — the product cards, each with a "Download the kit" button
(`/kits/<slug>.zip`), a light/dark preview with a variant picker, colour chips, links and an
"All files" list (copy link, download, open). With no attributes it also adds the Plugins heading,
a name filter and the Colours grid. Attributes:

| Attribute | Values | Default | Effect |
|---|---|---|---|
| `show` | `all`, `plugins`, `brand` | `all` | Which products to include |
| `products` | comma-separated slugs | — | Restrict to specific products |

```
[wpanchorbay_brand_assets show="brand"]
[wpanchorbay_brand_assets show="plugins"]
[wpanchorbay_brand_assets products="cartbay,upsellbay"]
```

Useful on a product page: `[wpanchorbay_brand_assets products="cartbay"]`.

**`[wpanchorbay_brand_usage]`** — clear space, minimum sizes, placement, and do / don't examples.
No attributes; the rule lists are pulled straight from the manifest's own `usage` field, so editing
`brand.source.json` and redeploying the asset origin updates this section too, with no plugin
change needed.

## How it behaves

- The manifest is cached in a transient for ~1 hour (with jitter), and refreshed twice daily by
  WP-Cron so a visitor almost never waits on the HTTP call.
- A **stale copy is kept for 30 days**. If assets.wpanchorbay.com is unreachable, the page renders
  from that instead of going blank.
- If the origin fails *and* there is no stale copy, visitors see nothing; users who can
  `edit_posts` see an explanatory notice.
- CSS and JS are registered on every front-end request but only *enqueued* when the shortcode
  actually runs, so other pages carry no extra weight.
- The manifest is first-party but still arrives over the network, so it is treated as untrusted:
  colours go through `sanitize_hex_color()` before touching a `style` attribute, and asset URLs are
  rejected unless they are http(s) on the manifest's own host.
- Each format gets a Copy / Download / Open-in-new-tab button group, matching the public
  brand-assets page. **The Download button can't force a save** here the way it does on that page:
  assets live on `assets.wpanchorbay.com`, a different origin from the WordPress site, and browsers
  only honour HTML's `download` attribute for same-origin URLs. Clicking it opens the file instead;
  visitors can still right-click it and choose Save As. Fixing this for real needs a same-origin
  proxy endpoint, which is out of scope for a plugin this small.
- Every layout switch uses a container query on the plugin's own wrapper (`.wpab-ba`) rather than a
  viewport `@media` query, and grids use `minmax(min(Npx,100%),1fr)`. This plugin can end up in a
  narrow sidebar widget just as easily as a full-width page, and a viewport breakpoint reacts to
  the *browser window*, not the space the shortcode actually has.
- Colours and fonts come from the active theme's palette (`--wp--preset--color--*`,
  `--wp--preset--font-family--*`) with ThemeBay One's values as fallbacks. The design is light only;
  the dark mode and the browser-side "copy file" of assets.wpanchorbay.com are not carried over.
- Kit zips are linked at `<manifest origin>/kits/<slug>.zip` and `/kits/wpanchorbay-all-brands.zip`.
  Like the per-file Download button, the browser decides whether `download` saves or opens them.

## Pointing at a different origin

```php
add_filter( 'wpab_brand_assets_manifest_url', fn() => 'https://assets-staging.wpanchorbay.com/brand.json' );
```

## Changelog

### 1.5.0

- Files list: Download saves the file in one click, the Open chevron sits after the text, and the list uses the
  same #edf3f9 background as the card with a top border.
- Link buttons (WordPress.org, Product page, Docs, JSON, Copy info) show a short hint on hover and focus.
- New shortcodes `[wpanchorbay_brand_typography]` (Inter Tight and Inter) and `[wpanchorbay_brand_closing]`
  (Hotlink it and Machine-readable panels moved here, trademark note with the contact address, last download button).
  Place typography before and closing after `[wpanchorbay_brand_usage]`.

### 1.4.0

- Rebuilt the page UI to match assets.wpanchorbay.com, in the theme's colours and fonts: hero with a
  product mosaic, product cards with a light/dark preview and variant picker, colours grid with
  WCAG contrast, and a usage section (clear space, minimum sizes, do and don't).
- Added a "Download the kit" button on every card and "Download all brands (.zip)" in the hero.
- Added a name filter for the cards.
- All CSS is scoped under `.wpab-ba` and uses container queries.

### 1.3.2 and earlier

- See the git history.
