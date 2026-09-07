# WPAnchorBay Brand Assets

The origin behind **https://assets.wpanchorbay.com** — one permanent URL per logo, icon and
colour, for every WPAnchorBay product.

The whole point: **the URL never changes, the file behind it can.** Replace
`public/brand/cartbay/logo.svg`, push, and every place that hotlinks it — marketing site, docs,
emails, partner decks — updates. No more re-uploading the same PNG into the media library.

```
https://assets.wpanchorbay.com/brand/<product>/<asset>.<ext>
https://assets.wpanchorbay.com/brand.json                ← every product, in one file
https://assets.wpanchorbay.com/brand/<product>/brand.json ← just that one product
```

## Layout

```
brand.source.json      Product metadata: names, taglines, colours, links. THE source of truth.
public/
  brand/<slug>/        The asset files themselves. One folder per product.
  brand/<slug>/brand.json  Generated — do not edit. That product's own manifest entry.
  brand.json           Generated — do not edit. Every product, in one file.
  index.html           Generated — the public asset browser.
  404.html             Generated.
  _headers             Cache and CORS rules. Hand-written.
scripts/build.mjs      Regenerates every generated file above.
wordpress/             The plugin that renders wpanchorbay.com/brand from brand.json.
sources/<slug>/        Pre-optimization design files kept for reference. NOT served; discover()
                       never looks here, so nothing in this folder can leak onto the public page.
```

## Everyday tasks

**Update an existing asset** — overwrite the file in `public/brand/<slug>/`, then:

```bash
npm run build && git commit -am "cartbay: new logo" && git push
```

Live within a minute or two of the deploy. Browsers pick it up within 10 minutes (see Caching).

**Add an asset to an existing product** — drop the file in `public/brand/<slug>/` using one of the
recognised names, then `npm run build`. The manifest and page pick it up automatically; nothing
else to edit.

| Filename | Renders as | Notes |
|---|---|---|
| `logo.svg` / `logo.png` | Logo | Horizontal lockup: mark + wordmark |
| `logo-dark.svg` | Logo (dark) | For use *on* dark backgrounds |
| `icon.svg` / `icon.png` | Icon | The square mark alone |
| `wordmark.svg` | Wordmark | Type only, no mark |
| `banner-1544x500.png` | Banner (1544x500) | wp.org listing header |
| `og.png` | Social image | 1200x630 |
| `screenshot-1.png` | Screenshot | |

Any other filename still works — it is listed under its own name.

**Add a product** — create `public/brand/<slug>/`, add the files, and add a matching entry to
`brand.source.json`. `npm run build` warns loudly if one exists without the other.

## Caching — why the "one URL forever" trick actually works

A permanent URL is useless if caches pin the old bytes to it. `public/_headers` sets:

```
/brand/*   Cache-Control: public, max-age=600, stale-while-revalidate=86400
```

Browsers reuse a file for 10 minutes, then serve the cached copy *while* fetching the new one in
the background — fast, and never more than a few minutes stale. Cloudflare purges its own edge
cache on every deploy, so the edge is current immediately.

**Nothing here gets `immutable` or a year-long `max-age`.** That would defeat the entire design.

Need an instant, guaranteed bust somewhere (a printed asset, an email blast)? Append a query
string — `logo.svg?v=2`. Cloudflare keys the cache on the full URL, so that bypasses both the edge
and the browser copy.

## Deploying

Cloudflare's own Git integration ("Workers Builds") deploys on push. No GitHub Actions secrets,
no API token stored anywhere — Cloudflare's GitHub App handles auth on its own side.

One-time setup, already pushed to `wpanchorbay/brand-assets`:

1. Cloudflare dashboard → **Workers & Pages** → **Create application** → **Import a repository** →
   select `wpanchorbay/brand-assets` (authorize the Cloudflare GitHub App for the `wpanchorbay` org
   the first time you do this).
2. **Worker name must be exactly `wpanchorbay-assets`** — it has to match `name` in
   `wrangler.jsonc`, or every build fails silently until it does. Cloudflare will suggest a name
   from the repo (likely `brand-assets`); override it.
3. Build command: `npm run build` — regenerates `public/` from `brand.source.json` fresh on every
   deploy, so a forgotten local `npm run build` before a commit can't ship a stale manifest even
   though the generated files are also committed. Deploy command: leave the default
   (`npx wrangler deploy`).
4. Root directory: leave blank (`wrangler.jsonc` is at the repo root). Production branch: `main`.
5. Save and deploy. From here, every push to `main` rebuilds and redeploys automatically; opening
   a PR gets a preview URL and a bot comment on the PR.
6. Once the Worker is live: Worker → **Settings** → **Domains & Routes** → **Add custom domain** →
   `assets.wpanchorbay.com`. That creates the proxied DNS record itself; don't add one by hand
   first.

`.github/workflows/check.yml` still runs on every push and PR — it regenerates `public/` and fails
if the committed output doesn't match `brand.source.json` and the files on disk, catching a
forgotten `npm run build` before it ever reaches Cloudflare. It only checks; it never deploys.

```bash
npm run build     # regenerate brand.json, index.html, 404.html
npm run check     # regenerate and fail if the committed output was stale (CI runs this too)
npm run deploy    # build + wrangler deploy, from your machine — a manual escape hatch
```

## Where these assets should and should NOT be used

**Do hotlink** from: wpanchorbay.com, docs.wpanchorbay.com, marketing emails, OG/social tags,
marketplace and partner listings, press.

**Do not hotlink** from:

- **WordPress.org plugin pages.** The directory does not hotlink — icons, banners and screenshots
  must be committed into each plugin's SVN `/assets/` directory, and `readme.txt` supports no
  images at all. This repo is the *source*; releasing still means copying the right files into SVN.
- **Plugin admin UI.** Bundle icons inside the plugin. Hotlinking makes every wp-admin page load
  call home, which is both a privacy problem and something wp.org reviewers flag.

## Known gaps

- **Only WPAnchorBay has a `logo-dark` variant so far.** Every other wordmark is still dark ink
  (`#001F3F`) only, so it disappears on dark backgrounds. Export `logo-dark.svg` per product when
  you get a chance: the build already knows the filename, and gives it its own dark-background
  preview automatically (see `public/brand/wpanchorbay/` for the pattern).
- **Icon PNGs are 120x120** (240 for the brand mark). WordPress.org wants 128 and 256. Export
  those from the design source; do not upscale the existing PNGs.
- **PointBay's wordmark reads "LoyaltyBay"** (`public/brand/pointbay/logo.svg` / `.png`). Looks
  like a rename that never reached the logo.
