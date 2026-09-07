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

**`[wpanchorbay_brand_hero]`** — brand mark, "Brand assets" heading, lede paragraph, and the
"Hotlink it" / "Machine-readable" panels (with copy buttons). No attributes; the hotlink example
always uses the brand's own logo.

**`[wpanchorbay_brand_assets]`** — the product grid. Attributes:

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

**`[wpanchorbay_brand_usage]`** — the "Please do / Please don't" guidelines. No attributes; pulled
straight from the manifest's own `usage` field, so editing `brand.source.json` and redeploying the
asset origin updates this section too, with no plugin change needed.

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
- The layout grids (product cards, Logo/Icon split, do's/don'ts columns) all use
  `minmax(min(Npx,100%),1fr)` rather than a fixed pixel floor or a viewport `@media` query. This
  plugin can end up in a narrow sidebar widget just as easily as a full-width page, and a viewport
  breakpoint reacts to the *browser window*, not the actual space the shortcode has to work with —
  the `min()` guard keeps every grid from overflowing or triggering a two-column layout it doesn't
  have room for, regardless of where it's embedded.

## Pointing at a different origin

```php
add_filter( 'wpab_brand_assets_manifest_url', fn() => 'https://assets-staging.wpanchorbay.com/brand.json' );
```
