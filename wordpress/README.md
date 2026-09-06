# wpanchorbay.com/brand

A tiny plugin that renders the brand page from `https://assets.wpanchorbay.com/brand.json`.

Nothing is uploaded to the media library. Every image on the page is hotlinked from the asset
origin, so replacing a file there updates the page here with no WordPress edit at all — and adding
a whole new product appears automatically.

## Install

1. Copy `wpanchorbay-brand-assets/` into `wp-content/plugins/`, or zip that folder and upload it
   through Plugins → Add New → Upload.
2. Activate it.
3. Create a page titled **Brand Assets** at the slug `brand`, and add one Shortcode block:

   ```
   [wpanchorbay_brand_assets]
   ```

Everything above and below the grid — your intro copy, the usage do's and don'ts, a press contact —
is just normal blocks on that page. The shortcode only renders the product grid.

## Shortcode attributes

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

## How it behaves

- The manifest is cached in a transient for ~1 hour (with jitter), and refreshed twice daily by
  WP-Cron so a visitor almost never waits on the HTTP call.
- A **stale copy is kept for 30 days**. If assets.wpanchorbay.com is unreachable, the page renders
  from that instead of going blank.
- If the origin fails *and* there is no stale copy, visitors see nothing; users who can
  `edit_posts` see an explanatory notice.
- CSS is registered on every front-end request but only *enqueued* when the shortcode actually
  runs, so other pages carry no extra weight.
- The manifest is first-party but still arrives over the network, so it is treated as untrusted:
  colours go through `sanitize_hex_color()` before touching a `style` attribute, and asset URLs are
  rejected unless they are http(s) on the manifest's own host.

## Pointing at a different origin

```php
add_filter( 'wpab_brand_assets_manifest_url', fn() => 'https://assets-staging.wpanchorbay.com/brand.json' );
```
