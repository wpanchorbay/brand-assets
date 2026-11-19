#!/usr/bin/env node
/**
 * Generates public/brand.json, public/brand/<slug>/brand.json and public/index.html
 * (scripts/index.template.html filled with the discovered assets).
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

const list = Object.values(products);
const brand = list.find((p) => p.kind === 'brand');

// The page is a static template (scripts/index.template.html) plus one JSON
// blob. The blob is built here from the files in public/brand/, so the icons
// and logos on the page can never drift from the files that are served. SVGs
// are inlined as data URIs so the page previews and copies them without extra
// requests.
const pageData = {
  origin: ORIGIN,
  self: manifest.self,
  org: src.organization,
  palette: src.palette,
  usage: src.usage,
  products: list.map((p) => ({
    slug: p.slug,
    name: p.name,
    kind: p.kind,
    tagline: p.tagline,
    color: p.color,
    onColor: p.onColor,
    links: p.links,
    assets: Object.fromEntries(Object.entries(p.assets).map(([key, a]) => [key, {
      label: a.label,
      formats: Object.fromEntries(Object.entries(a.formats).map(([ext, f]) => [ext, {
        url: f.url,
        bytes: f.bytes,
        ...(f.width ? { w: f.width, h: f.height } : {}),
      }])),
      ...(a.formats.svg
        ? { data: 'data:image/svg+xml;base64,' + readFileSync(join(BRAND_DIR, p.slug, basename(a.formats.svg.path))).toString('base64') }
        : {}),
    }])),
  })),
};

// "<" is escaped so no value can ever close the <script> element early.
const dataJson = JSON.stringify(pageData).replace(/</g, '\\u003c');
const template = readFileSync(join(ROOT, 'scripts', 'index.template.html'), 'utf8');
if (!template.includes('__BRAND_DATA__')) throw new Error('index.template.html has no __BRAND_DATA__ placeholder');
const html = template.replace('__BRAND_DATA__', () => dataJson);

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
