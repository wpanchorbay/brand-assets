#!/usr/bin/env node
/**
 * Generates public/brand.json, public/brand/<slug>/brand.json and public/index.html.
 *
 * Assets are DISCOVERED from public/brand/<slug>/ rather than listed in
 * brand.source.json, so the manifest can never advertise a file that is not
 * there. Drop a new file in, re-run, done.
 */
import { readFileSync, writeFileSync, readdirSync, statSync } from 'node:fs';
import { join, dirname, extname, basename } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const BRAND_DIR = join(ROOT, 'public', 'brand');

const src = JSON.parse(readFileSync(join(ROOT, 'brand.source.json'), 'utf8'));
const ORIGIN = src.origin.replace(/\/$/, '');

/* ---------- dimensions ---------- */

function pngSize(buf) {
  // IHDR width/height are big-endian uint32 at bytes 16..23.
  if (buf.length < 24 || buf.readUInt32BE(0) !== 0x89504e47) return null;
  return { width: buf.readUInt32BE(16), height: buf.readUInt32BE(20) };
}

function svgSize(text) {
  const vb = text.match(/viewBox\s*=\s*"([^"]+)"/i);
  if (vb) {
    const p = vb[1].trim().split(/[\s,]+/).map(Number);
    if (p.length === 4 && p.every(Number.isFinite)) {
      return { width: p[2], height: p[3] };
    }
  }
  const w = text.match(/\bwidth\s*=\s*"(\d+(?:\.\d+)?)"/i);
  const h = text.match(/\bheight\s*=\s*"(\d+(?:\.\d+)?)"/i);
  return w && h ? { width: +w[1], height: +h[1] } : null;
}

const MIME = {
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.avif': 'image/avif',
  '.pdf': 'application/pdf',
  '.zip': 'application/zip',
};

/** Human label for an asset name, e.g. "logo-dark" -> "Logo (dark)". */
function label(name) {
  const m = name.match(/^(logo|icon|wordmark|mark|banner|og|screenshot)(?:-(.+))?$/);
  if (!m) return name;
  const base = { og: 'Social image', screenshot: 'Screenshot' }[m[1]]
    || m[1][0].toUpperCase() + m[1].slice(1);
  return m[2] ? `${base} (${m[2].replace(/-/g, ' ')})` : base;
}

/* ---------- discovery ---------- */

const ORDER = ['logo', 'wordmark', 'mark', 'icon', 'banner', 'og', 'screenshot'];
const rank = (n) => {
  const i = ORDER.indexOf(n.split('-')[0]);
  return i === -1 ? ORDER.length : i;
};

function discover(slug) {
  const dir = join(BRAND_DIR, slug);
  let entries;
  try {
    entries = readdirSync(dir);
  } catch {
    return {};
  }
  const assets = {};
  for (const file of entries.sort()) {
    const full = join(dir, file);
    // brand.json here is OUR OWN generated per-product manifest, written after
    // discovery runs — never treat it as a discoverable asset, or it becomes a
    // self-referential file whose size changes on every build.
    if (!statSync(full).isFile() || file.startsWith('.') || file === 'brand.json') continue;
    const ext = extname(file).toLowerCase();
    const name = basename(file, ext);
    const buf = readFileSync(full);
    const size = ext === '.png' ? pngSize(buf)
      : ext === '.svg' ? svgSize(buf.toString('utf8'))
      : null;

    (assets[name] ??= { label: label(name), formats: {} }).formats[ext.slice(1)] = {
      url: `${ORIGIN}/brand/${slug}/${file}`,
      path: `/brand/${slug}/${file}`,
      type: MIME[ext] ?? 'application/octet-stream',
      bytes: buf.length,
      ...(size ?? {}),
    };
  }
  return Object.fromEntries(
    Object.entries(assets).sort(([a], [b]) => rank(a) - rank(b) || a.localeCompare(b))
  );
}

/* ---------- manifest ---------- */

const products = {};
const missing = [];
for (const [slug, meta] of Object.entries(src.products)) {
  const assets = discover(slug);
  if (!Object.keys(assets).length) missing.push(slug);
  products[slug] = { slug, ...meta, assets };
}

// Warn about asset folders with no metadata — they would be silently dropped.
const orphans = readdirSync(BRAND_DIR)
  .filter((d) => statSync(join(BRAND_DIR, d)).isDirectory() && !src.products[d]);

// Deliberately no build timestamp: the output must be a pure function of the
// inputs, or `npm run check` would report a stale manifest on every new day.
const manifest = {
  self: `${ORIGIN}/brand.json`,
  origin: ORIGIN,
  organization: src.organization,
  palette: src.palette,
  usage: src.usage,
  products,
};

writeFileSync(join(ROOT, 'public', 'brand.json'), JSON.stringify(manifest, null, 2) + '\n');

// Per-product manifest, colocated with that product's own files
// (/brand/<slug>/brand.json) — so a consumer that only cares about one
// product never has to fetch and filter the whole catalogue.
let perProductCount = 0;
for (const [slug, product] of Object.entries(products)) {
  if (!Object.keys(product.assets).length) continue; // no folder, nothing to write into
  writeFileSync(
    join(BRAND_DIR, slug, 'brand.json'),
    JSON.stringify(
      {
        self: `${ORIGIN}/brand/${slug}/brand.json`,
        origin: ORIGIN,
        organization: src.organization,
        product,
      },
      null,
      2
    ) + '\n'
  );
  perProductCount += 1;
}

/* ---------- page ---------- */

const esc = (s) => String(s).replace(/[&<>"]/g, (c) =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

const kb = (n) => n < 1024 ? `${n} B` : `${(n / 1024).toFixed(n < 10240 ? 1 : 0)} KB`;

const dims = (f) => f.width && f.height ? `${f.width}×${f.height}` : '';

// Generic, feather-style glyphs — no third-party marks, currentColor so they
// inherit the button's hover/focus colour for free.
const ICONS = {
  copy: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
  download: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
  open: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
  info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
  // The official WordPress "W" mark (source: WordPress Foundation, via
  // commons.wikimedia.org/wiki/File:Wordpress-Logo.svg), recoloured to
  // currentColor so it inherits the badge's state. Used only to identify a
  // link to the product's own WordPress.org listing — nominative use, the
  // same as any "on WordPress.org" badge — not a claim of endorsement.
  wordpress: '<svg viewBox="0 0 122.52 122.523" aria-hidden="true" focusable="false"><g fill="currentColor"><path d="m8.708 61.26c0 20.802 12.089 38.779 29.619 47.298l-25.069-68.686c-2.916 6.536-4.55 13.769-4.55 21.388z"/><path d="m96.74 58.608c0-6.495-2.333-10.993-4.334-14.494-2.664-4.329-5.161-7.995-5.161-12.324 0-4.831 3.664-9.328 8.825-9.328.233 0 .454.029.681.042-9.35-8.566-21.807-13.796-35.489-13.796-18.36 0-34.513 9.42-43.91 23.688 1.233.037 2.395.063 3.382.063 5.497 0 14.006-.667 14.006-.667 2.833-.167 3.167 3.994.337 4.329 0 0-2.847.335-6.015.501l19.138 56.925 11.501-34.493-8.188-22.434c-2.83-.166-5.511-.501-5.511-.501-2.832-.166-2.5-4.496.332-4.329 0 0 8.679.667 13.843.667 5.496 0 14.006-.667 14.006-.667 2.835-.167 3.168 3.994.337 4.329 0 0-2.853.335-6.015.501l18.992 56.494 5.242-17.517c2.272-7.269 4.001-12.49 4.001-16.989z"/><path d="m62.184 65.857-15.768 45.819c4.708 1.384 9.687 2.141 14.846 2.141 6.12 0 11.989-1.058 17.452-2.979-.141-.225-.269-.464-.374-.724z"/><path d="m107.376 36.046c.226 1.674.354 3.471.354 5.404 0 5.333-.996 11.328-3.996 18.824l-16.053 46.413c15.624-9.111 26.133-26.038 26.133-45.426.001-9.137-2.333-17.729-6.438-25.215z"/><path d="m61.262 0c-33.779 0-61.262 27.481-61.262 61.26 0 33.783 27.483 61.263 61.262 61.263 33.778 0 61.265-27.48 61.265-61.263-.001-33.779-27.487-61.26-61.265-61.26zm0 119.715c-32.23 0-58.453-26.223-58.453-58.455 0-32.23 26.222-58.451 58.453-58.451 32.229 0 58.45 26.221 58.45 58.451 0 32.232-26.221 58.455-58.45 58.455z"/></g></svg>',
  globe: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
  book: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
  code: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
};

const LINK_META = {
  wordpress: { label: 'WordPress.org', icon: ICONS.wordpress },
  site: { label: 'Product page', icon: ICONS.globe },
  docs: { label: 'Docs', icon: ICONS.book },
};

function assetRow(a) {
  const formats = Object.entries(a.formats)
    .sort(([x], [y]) => (x === 'svg' ? -1 : y === 'svg' ? 1 : x.localeCompare(y)));
  return `
        <div class="asset">
          <div class="asset-name">${esc(a.label)}</div>
          <div class="fmt-list">${formats.map(([ext, f]) => {
            const EXT = ext.toUpperCase();
            const info = esc([dims(f), kb(f.bytes)].filter(Boolean).join(' · '));
            return `
            <div class="fmt-row">
              <span class="fmt-label">${esc(EXT)}</span>
              <button type="button" class="info-btn" title="${info}" aria-label="${info}">${ICONS.info}</button>
              <span class="btn-group" role="group" aria-label="${esc(EXT)} actions">
                <button type="button" class="act js-copy" data-copy="${esc(f.url)}" title="Copy URL" aria-label="Copy ${esc(EXT)} URL">${ICONS.copy}</button>
                <a class="act" href="${esc(f.path)}" download title="Download ${esc(EXT)}" aria-label="Download ${esc(EXT)}">${ICONS.download}</a>
                <a class="act" href="${esc(f.url)}" target="_blank" rel="noopener" title="Open in new tab" aria-label="Open ${esc(EXT)} in new tab">${ICONS.open}</a>
              </span>
            </div>`;
          }).join('')}
          </div>
        </div>`;
}

/**
 * Colour chips for a card. WPAnchorBay the brand carries two identity
 * colours (teal + navy); a plugin's `onColor` is only ever a text-contrast
 * helper for its badge, never a second mark, so plugins show one chip.
 */
function swatches(p) {
  return p.kind === 'brand'
    ? [
        { hex: p.color, name: 'primary' },
        { hex: p.onColor, name: 'secondary' },
      ]
    : [{ hex: p.color, name: '' }];
}

function linkBadges(p) {
  const entries = Object.entries(p.links ?? {}).filter(([k]) => LINK_META[k]);
  const linkBadgeHtml = entries.map(([k, v]) => {
    const meta = LINK_META[k];
    // The brand's own "site" link goes to the company homepage, not a
    // per-product page — label it accordingly.
    const label = k === 'site' && p.kind === 'brand' ? 'Visit Website' : meta.label;
    return `<a class="badge" href="${esc(v)}" rel="noopener" title="${esc(label)}">${meta.icon}<span>${esc(label)}</span></a>`;
  });

  // Every product with any asset files also gets its own brand.json (see
  // the per-product manifest step above) — surface it here, or it exists
  // with no link to find it from.
  const hasOwnManifest = Object.keys(p.assets).length > 0;
  if (hasOwnManifest) {
    const jsonUrl = `${ORIGIN}/brand/${p.slug}/brand.json`;
    linkBadgeHtml.push(
      `<button type="button" class="badge js-copy" data-copy="${esc(jsonUrl)}" title="Copy this product's JSON URL" aria-label="Copy ${esc(p.name)} JSON URL">${ICONS.code}<span>JSON</span></button>`
    );
  }

  if (!linkBadgeHtml.length) return '';
  return `<nav class="card-links" style="grid-template-columns:repeat(${linkBadgeHtml.length},1fr)">${linkBadgeHtml.join('')}</nav>`;
}

/** Plain-text summary for the "Copy Info" button: name, tagline, colours, links. */
function productInfoText(p) {
  const lines = [p.name, p.tagline, ''];

  const cols = swatches(p);
  if (cols.length > 1) {
    lines.push('Colours:');
    for (const s of cols) lines.push(`  ${s.name[0].toUpperCase()}${s.name.slice(1)}: ${s.hex}`);
  } else {
    lines.push(`Colour: ${cols[0].hex}`);
  }

  const linkEntries = Object.entries(p.links ?? {}).filter(([k]) => LINK_META[k]);
  if (linkEntries.length) {
    lines.push('', 'Links:');
    for (const [k, v] of linkEntries) {
      const label = k === 'site' && p.kind === 'brand' ? 'Visit Website' : LINK_META[k].label;
      lines.push(`  ${label}: ${v}`);
    }
  }

  return lines.join('\n');
}

function card(p) {
  const icon = p.assets.icon?.formats.svg ?? p.assets.icon?.formats.png;
  const logo = p.assets.logo?.formats.svg ?? p.assets.logo?.formats.png;
  // A "-dark" sibling is meant for dark backgrounds, so preview it on one:
  // a light-only plate would defeat the point of even having the file.
  const logoDark = p.assets['logo-dark']?.formats.svg ?? p.assets['logo-dark']?.formats.png;
  const plates = `
        <div class="plates">
          ${logo ? `<div class="logo-plate"><img src="${esc(logo.path)}" alt="${esc(p.name)} logo"></div>` : ''}
          ${logoDark ? `<div class="logo-plate logo-plate--dark"><img src="${esc(logoDark.path)}" alt="${esc(p.name)} logo, for dark backgrounds"></div>` : ''}
        </div>`;
  return `
      <article class="card" id="${esc(p.slug)}" style="--brand:${esc(p.color)};--on-brand:${esc(p.onColor)}">
        <header class="card-head">
          ${icon ? `<img class="card-icon" src="${esc(icon.path)}" alt="${esc(p.name)} icon" width="56" height="56">` : ''}
          <div class="card-head-text">
            <h3>${esc(p.name)}</h3>
            <p class="tagline">${esc(p.tagline)}</p>
          </div>
          <button type="button" class="copy-info js-copy" data-copy="${esc(productInfoText(p))}" title="Copy name, colours and links" aria-label="Copy ${esc(p.name)} info">${ICONS.copy}<span>Copy Info</span></button>
        </header>
        ${logo || logoDark ? plates : ''}
        <div class="assets">${Object.values(p.assets).map(assetRow).join('')}</div>
        <div class="swatches">${swatches(p)
          .map(
            (s) => `
          <button type="button" class="swatch js-copy" data-copy="${esc(s.hex)}" title="Copy ${esc(s.name ? s.name + ' ' : '')}hex (${esc(s.hex)})">
            <span class="chip" style="background:${esc(s.hex)}"></span>${esc(s.hex)}
          </button>`
          )
          .join('')}
        </div>
        ${linkBadges(p)}
      </article>`;
}

const list = Object.values(products);
const brand = list.find((p) => p.kind === 'brand');
const plugins = list.filter((p) => p.kind !== 'brand');
const sample = brand ?? plugins[0];
const sampleUrl = sample?.assets.logo?.formats.svg?.url ?? `${ORIGIN}/brand/…/logo.svg`;
const sampleSnippet = `<img src="${sampleUrl}" alt="${sample?.name ?? ''}" height="40">`;

const html = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Brand Assets: ${esc(src.organization.name)}</title>
<meta name="description" content="Official logos, icons and colours for ${esc(src.organization.name)} and its WooCommerce plugins. Stable, permanent URLs. Safe to hotlink.">
<meta name="robots" content="index,follow">
<link rel="icon" href="/brand/${esc(brand?.slug ?? 'wpanchorbay')}/icon.svg" type="image/svg+xml">
<meta property="og:title" content="Brand Assets: ${esc(src.organization.name)}">
<meta property="og:description" content="Official logos, icons and colours. Stable URLs, safe to hotlink.">
<meta property="og:url" content="${esc(ORIGIN)}/">
<link rel="canonical" href="${esc(ORIGIN)}/">
<style>
:root{
  --ink:${esc(src.palette.ink)}; --anchor:${esc(src.palette.anchor)};
  --bg:#f5f7fa; --surface:#ffffff; --surface-2:#fbfcfe;
  --line:#e2e7ee; --line-strong:#cdd5e0;
  --text:#101828; --muted:#5b6472;
  --radius:14px; --shadow:0 1px 2px rgba(16,24,40,.04),0 10px 26px -14px rgba(16,24,40,.16);
  color-scheme:light;
}
*{box-sizing:border-box}
/* Buttons don't inherit font-family or reset to cursor:pointer by default;
   every button class below sets its own border/background/padding, so this
   only fixes the gaps, it doesn't fight any of them. */
button{font-family:inherit;cursor:pointer;-webkit-appearance:none;appearance:none;
  border:0;background:none;color:inherit;padding:0;margin:0}
body{margin:0;background:var(--bg);color:var(--text);
  font:15px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  -webkit-font-smoothing:antialiased}
a{color:inherit}
:focus-visible{outline:2px solid var(--anchor);outline-offset:2px}
.wrap{max-width:1140px;margin:0 auto;padding:0 24px}
header.top{padding:56px 0 32px;border-bottom:1px solid var(--line)}
.brandplate{display:inline-flex;align-items:center;background:#fff;border:1px solid var(--line);
  border-radius:11px;padding:12px 18px;margin-bottom:28px;box-shadow:var(--shadow)}
.brandmark{height:32px;width:auto;display:block}
h1{margin:0 0 10px;font-size:clamp(28px,4vw,40px);line-height:1.15;letter-spacing:-.02em}
.lede{margin:0;max-width:62ch;color:var(--muted);font-size:17px}
.panel{margin:28px 0 0;padding:20px 22px;background:var(--surface);border:1px solid var(--line);
  border-radius:var(--radius);box-shadow:var(--shadow)}
.panel h2{margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.09em;color:var(--muted)}
.panel p{margin:0 0 12px;color:var(--muted)}
.code-row{display:flex;align-items:stretch;gap:8px}
/* Still scrollable on narrow screens where the snippet overflows — just a
   slim, low-contrast bar instead of the browser's full-size default. */
pre{flex:1;margin:0;padding:12px 14px;background:var(--surface-2);border:1px solid var(--line);border-radius:9px;
  overflow-x:auto;font:12.5px/1.6 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:var(--text);
  scrollbar-width:thin;scrollbar-color:var(--line) transparent}
pre::-webkit-scrollbar{height:6px}
pre::-webkit-scrollbar-track{background:transparent}
pre::-webkit-scrollbar-thumb{background:var(--line);border-radius:999px}
pre::-webkit-scrollbar-thumb:hover{background:var(--muted)}
.code-row .act{width:36px;height:auto;border:1px solid var(--line);border-radius:9px;background:var(--surface-2);
  color:var(--muted)}
.code-row .act:hover,.code-row .act:focus-visible{border-color:var(--anchor);color:var(--anchor);background:#fff}
section{padding:44px 0}
h2.section{margin:0 0 4px;font-size:20px;letter-spacing:-.01em}
.sub{margin:0 0 24px;color:var(--muted);font-size:14px}
.grid{display:grid;gap:20px;grid-template-columns:repeat(2,1fr)}
@media (max-width:720px){.grid{grid-template-columns:1fr}}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  padding:22px;box-shadow:var(--shadow);display:flex;flex-direction:column;gap:16px;
  --link-accent:var(--brand)}
/* The brand card's own links hover to its secondary colour (WPAnchorBay's
   navy) rather than the primary brand teal, which reads too pale for text. */
.card.solo{grid-column:1/-1;width:100%;--link-accent:var(--on-brand)}
.card-head{display:flex;gap:14px;align-items:center}
.card-head-text{flex:1;min-width:0}
.card-icon{border-radius:14px;flex:none}
.card h3{margin:0;font-size:17px;letter-spacing:-.01em}
.tagline{margin:3px 0 0;font-size:13.5px;color:var(--muted);line-height:1.45}
.copy-info{display:inline-flex;align-items:center;gap:6px;flex:none;align-self:flex-start;
  border:1px solid var(--line);background:var(--bg);color:var(--muted);border-radius:7px;
  padding:6px 10px;font-size:12px;font-weight:600;white-space:nowrap;transition:.14s}
.copy-info svg{width:13px;height:13px;display:block}
.copy-info:hover,.copy-info:focus-visible{border-color:var(--link-accent);color:var(--link-accent);background:#fff}
.copy-info.done{color:#0d8a5f;border-color:#0d8a5f;background:#eafbf3}
/* Light by default: a wordmark with no dark-safe sibling is dark-ink only
   and would disappear on a tinted or dark plate. A logo is previewed on the
   background it is actually made for; when a -dark variant exists it gets
   its own dark plate right beside the light one. */
.plates{display:grid;grid-template-columns:1fr;gap:10px}
.plates:has(.logo-plate--dark){grid-template-columns:1fr 1fr}
.logo-plate{display:flex;align-items:center;justify-content:center;padding:22px 18px;
  background:linear-gradient(0deg,color-mix(in srgb,var(--brand) 8%,transparent),color-mix(in srgb,var(--brand) 8%,transparent)),#fff;
  border:1px solid var(--line);border-radius:10px;min-height:96px}
.logo-plate--dark{background:linear-gradient(0deg,color-mix(in srgb,var(--brand) 14%,transparent),color-mix(in srgb,var(--brand) 14%,transparent)),var(--ink);
  border-color:var(--ink)}
.logo-plate img{max-width:100%;max-height:44px;width:auto;height:auto}
/* Stacked on small screens; side by side (Logo | Icon) once a card has room. */
.assets{display:grid;grid-template-columns:1fr;gap:14px 16px}
@media (min-width:721px){.assets{grid-template-columns:1fr 1fr}}
/* Same bordered, rounded treatment as .logo-plate, so each section (Logo,
   Icon, …) reads as its own block; most visible once they sit side by side. */
.asset{border:1px solid var(--line);border-radius:10px;padding:12px 14px 14px}
.asset-name{font-size:13px;font-weight:700;margin-bottom:8px}
.fmt-list{display:flex;flex-direction:column;gap:7px}
.fmt-row{display:flex;align-items:center}
.fmt-label{font:700 11px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.05em;
  color:var(--muted);min-width:32px;margin-right:6px}
/* No outer border: the tinted background, inner dividers and rounded corners
   already read as one grouped control. */
.btn-group{display:inline-flex;border-radius:8px;overflow:hidden;background:var(--surface-2)}
.act{display:inline-flex;align-items:center;justify-content:center;width:30px;height:28px;border:0;
  background:transparent;color:var(--muted);cursor:pointer;text-decoration:none}
.act+.act{border-left:1px solid var(--line)}
.act svg{width:14px;height:14px;display:block}
.act:hover,.act:focus-visible{background:#fff;color:var(--brand,var(--anchor))}
.act.done{color:#0d8a5f;background:#eafbf3}
.info-btn{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;
  border-radius:999px;border:1px solid var(--line);background:var(--surface);color:var(--muted);
  flex:none;margin-right:auto;cursor:default;padding:0}
.info-btn svg{width:12px;height:12px;display:block}
.info-btn:hover,.info-btn:focus-visible{color:var(--brand,var(--anchor));border-color:var(--brand,var(--anchor))}
.swatches{display:flex;flex-wrap:wrap;gap:8px}
.swatch{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);
  background:var(--surface-2);color:var(--text);border-radius:7px;padding:5px 10px;
  font:600 12px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.03em;cursor:pointer;transition:.14s}
.swatch:hover{border-color:var(--brand)}
.swatch.done{background:var(--brand);color:var(--on-brand);border-color:var(--brand)}
.chip{width:13px;height:13px;border-radius:4px;box-shadow:inset 0 0 0 1px rgba(0,0,0,.14);flex:none}
.card-links{display:grid;gap:8px;margin-top:auto;padding-top:14px;border-top:1px solid var(--line)}
/* text-overflow:ellipsis has no effect set directly on a flex container (a
   known gotcha) — it has to sit on the text run itself, which also needs
   min-width:0 to be allowed to shrink below its content size in a flex row. */
.badge{display:flex;align-items:center;justify-content:center;gap:6px;padding:8px 6px;
  border:1px solid var(--line);border-radius:8px;background:var(--surface-2);color:var(--muted);
  text-decoration:none;font-size:12px;font-weight:600;transition:.14s}
.badge svg{width:14px;height:14px;flex:none}
.badge span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.badge:hover,.badge:focus-visible{border-color:var(--link-accent);color:var(--link-accent);background:#fff}
.badge.done{color:#0d8a5f;border-color:#0d8a5f;background:#eafbf3}
.rules{display:grid;gap:20px;grid-template-columns:repeat(auto-fit,minmax(300px,1fr))}
.rules ul{margin:0;padding-left:20px}
.rules li{margin:0 0 7px;color:var(--muted);font-size:14px}
.rules h3{margin:0 0 10px;font-size:14px}
.yes h3{color:#0d8a5f}.no h3{color:#c2374a}
footer{border-top:1px solid var(--line);padding:28px 0 56px;color:var(--muted);font-size:14.5px;line-height:1.7}
footer a{color:var(--text);text-decoration:underline;text-decoration-color:var(--line-strong);
  text-underline-offset:2px}
footer a:hover,footer a:focus-visible{color:var(--anchor);text-decoration-color:var(--anchor)}
.toast{position:fixed;left:50%;bottom:26px;transform:translate(-50%,14px);background:var(--ink);color:#fff;
  padding:9px 16px;border-radius:999px;font-size:13px;opacity:0;pointer-events:none;transition:.18s;z-index:9}
.toast.show{opacity:1;transform:translate(-50%,0)}
@media (prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
</head>
<body>
<header class="top"><div class="wrap">
  ${brand?.assets.logo ? `<div class="brandplate"><img class="brandmark" src="${esc((brand.assets.logo.formats.svg ?? brand.assets.logo.formats.png).path)}" alt="${esc(src.organization.name)}" height="32"></div>` : ''}
  <h1>Brand assets</h1>
  <p class="lede">Official logos, icons and colours for ${esc(src.organization.name)} and its WooCommerce plugins. Every URL below is permanent: we update the file behind it, never the address, so you can hotlink these directly and always get the current mark.</p>
  <div class="panel">
    <h2>Hotlink it</h2>
    <p>Point straight at the URL. No copy in your media library, no version to keep in sync.</p>
    <div class="code-row">
      <pre>${esc(sampleSnippet)}</pre>
      <button type="button" class="act js-copy" data-copy="${esc(sampleSnippet)}" title="Copy snippet" aria-label="Copy snippet">${ICONS.copy}</button>
    </div>
  </div>
  <div class="panel">
    <h2>Machine-readable</h2>
    <p>Every product, asset URL and colour on this page, as JSON, or fetch one product on its own at <code>/brand/&lt;product&gt;/brand.json</code>.</p>
    <div class="code-row">
      <pre>${esc(ORIGIN)}/brand.json</pre>
      <button type="button" class="act js-copy" data-copy="${esc(ORIGIN + '/brand.json')}" title="Copy URL" aria-label="Copy URL">${ICONS.copy}</button>
    </div>
  </div>
</div></header>

${brand ? `<section><div class="wrap">
  <h2 class="section">The ${esc(brand.name)} brand</h2>
  <p class="sub">Use these for the company itself, not for an individual plugin.</p>
  <div class="grid">${card({ ...brand, _solo: true }).replace('class="card"', 'class="card solo"')}</div>
</div></section>` : ''}

<section><div class="wrap">
  <h2 class="section">Plugins</h2>
  <p class="sub">${plugins.length} product${plugins.length === 1 ? '' : 's'}. Copy, download or open any file directly. Hover a control to see what it does.</p>
  <div class="grid">${plugins.map(card).join('')}</div>
</div></section>

<section><div class="wrap">
  <h2 class="section">Using the marks</h2>
  <p class="sub">Short version: don't redraw them.</p>
  <div class="rules">
    <div class="yes"><h3>Please do</h3><ul>${src.usage.allowed.map((r) => `<li>${esc(r)}</li>`).join('')}</ul></div>
    <div class="no"><h3>Please don't</h3><ul>${src.usage.notAllowed.map((r) => `<li>${esc(r)}</li>`).join('')}</ul></div>
  </div>
</div></section>

<footer><div class="wrap">
  Trademarks and logos are the property of ${esc(src.organization.name)}.
  Questions about usage: <a href="${esc(src.organization.url)}">${esc(src.organization.url.replace(/^https?:\/\//, ''))}</a>.
  · <a href="/brand.json">brand.json</a>
</div></footer>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script>
const toast = document.getElementById('toast');
let timer;
function flash(msg){
  toast.textContent = msg;
  toast.classList.add('show');
  clearTimeout(timer);
  timer = setTimeout(() => toast.classList.remove('show'), 1600);
}
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.js-copy');
  if (!btn) return;
  const text = btn.dataset.copy;
  try {
    await navigator.clipboard.writeText(text);
  } catch {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;opacity:0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch { flash('Copy failed. Select the URL manually'); ta.remove(); return; }
    ta.remove();
  }
  btn.classList.add('done');
  setTimeout(() => btn.classList.remove('done'), 900);
  flash('Copied ' + text);
});
</script>
</body>
</html>
`;

writeFileSync(join(ROOT, 'public', 'index.html'), html);

/* ---------- 404 ---------- */

writeFileSync(join(ROOT, 'public', '404.html'), `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Not found: ${esc(src.organization.name)} brand assets</title>
<meta name="robots" content="noindex">
<style>
:root{--ink:${esc(src.palette.ink)};--anchor:${esc(src.palette.anchor)};
  --bg:#f5f7fa;--text:#101828;--muted:#5b6472;color-scheme:light}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--text);
  font:15px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;text-align:center;padding:24px}
img{height:30px;width:auto;display:block}
.plate{display:inline-flex;background:#fff;border:1px solid rgba(0,31,63,.12);border-radius:11px;
  padding:11px 17px;margin-bottom:26px}
h1{margin:0 0 10px;font-size:24px;letter-spacing:-.02em}
p{margin:0 0 22px;color:var(--muted);max-width:44ch}
code{font:13px ui-monospace,SFMono-Regular,Menlo,monospace;background:rgba(16,24,40,.06);
  padding:2px 6px;border-radius:5px}
a.btn{display:inline-block;background:var(--anchor);color:var(--ink);text-decoration:none;font-weight:600;
  padding:10px 20px;border-radius:9px}
</style>
</head>
<body>
<div>
  ${brand?.assets.logo ? `<div class="plate"><img src="${esc((brand.assets.logo.formats.svg ?? brand.assets.logo.formats.png).path)}" alt="${esc(src.organization.name)}"></div>` : ''}
  <h1>No asset at that address</h1>
  <p>Asset URLs look like <code>/brand/cartbay/logo.svg</code>. Browse everything below, or check <code>/brand.json</code> for the full list.</p>
  <a class="btn" href="/">All brand assets</a>
</div>
</body>
</html>
`);
console.log('404.html    generated');

/* ---------- report ---------- */
const total = list.reduce((n, p) => n + Object.values(p.assets).reduce((m, a) => m + Object.keys(a.formats).length, 0), 0);
console.log(`brand.json  ${list.length} products, ${total} files`);
console.log(`per-product ${perProductCount} brand.json files under public/brand/<slug>/`);
console.log(`index.html  ${(html.length / 1024).toFixed(1)} KB`);
if (missing.length) console.warn(`WARN  no asset files found for: ${missing.join(', ')}`);
if (orphans.length) console.warn(`WARN  asset folder with no entry in brand.source.json: ${orphans.join(', ')}`);
