#!/usr/bin/env node
/**
 * Generates public/brand.json and public/index.html.
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
    if (!statSync(full).isFile() || file.startsWith('.')) continue;
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

/* ---------- page ---------- */

const esc = (s) => String(s).replace(/[&<>"]/g, (c) =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

const kb = (n) => n < 1024 ? `${n} B` : `${(n / 1024).toFixed(n < 10240 ? 1 : 0)} KB`;

const dims = (f) => f.width && f.height ? `${f.width}×${f.height}` : '';

function assetRow(a) {
  const formats = Object.entries(a.formats)
    .sort(([x], [y]) => (x === 'svg' ? -1 : y === 'svg' ? 1 : x.localeCompare(y)));
  return `
        <div class="asset">
          <div class="asset-name">${esc(a.label)}</div>
          <div class="asset-formats">${formats.map(([ext, f]) => `
            <span class="fmt">
              <button class="copy" data-url="${esc(f.url)}" title="Copy URL">${esc(ext.toUpperCase())}</button>
              <a class="dl" href="${esc(f.path)}" download title="Download">↓</a>
              <span class="meta">${esc([dims(f), kb(f.bytes)].filter(Boolean).join(' · '))}</span>
            </span>`).join('')}
          </div>
        </div>`;
}

function card(p) {
  const icon = p.assets.icon?.formats.svg ?? p.assets.icon?.formats.png;
  const logo = p.assets.logo?.formats.svg ?? p.assets.logo?.formats.png;
  const links = Object.entries(p.links ?? {})
    .map(([k, v]) => `<a href="${esc(v)}" rel="noopener">${esc({ wordpress: 'WordPress.org', site: 'Product page', docs: 'Docs' }[k] ?? k)}</a>`)
    .join('');
  return `
      <article class="card" id="${esc(p.slug)}" style="--brand:${esc(p.color)};--on-brand:${esc(p.onColor)}">
        <header class="card-head">
          ${icon ? `<img class="card-icon" src="${esc(icon.path)}" alt="${esc(p.name)} icon" width="56" height="56" loading="lazy">` : ''}
          <div>
            <h3>${esc(p.name)}</h3>
            <p class="tagline">${esc(p.tagline)}</p>
          </div>
        </header>
        ${logo ? `<div class="logo-plate"><img src="${esc(logo.path)}" alt="${esc(p.name)} logo" loading="lazy"></div>` : ''}
        <div class="assets">${Object.values(p.assets).map(assetRow).join('')}</div>
        <button class="swatch copy" data-url="${esc(p.color)}" title="Copy hex">
          <span class="chip"></span>${esc(p.color)}
        </button>
        ${links ? `<nav class="card-links">${links}</nav>` : ''}
      </article>`;
}

const list = Object.values(products);
const brand = list.find((p) => p.kind === 'brand');
const plugins = list.filter((p) => p.kind !== 'brand');
const sample = plugins[0] ?? brand;
const sampleUrl = sample?.assets.logo?.formats.svg?.url ?? `${ORIGIN}/brand/…/logo.svg`;

const html = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Brand Assets — ${esc(src.organization.name)}</title>
<meta name="description" content="Official logos, icons and colours for ${esc(src.organization.name)} and its WooCommerce plugins. Stable, permanent URLs — safe to hotlink.">
<meta name="robots" content="index,follow">
<link rel="icon" href="/brand/${esc(brand?.slug ?? 'wpanchorbay')}/icon.svg" type="image/svg+xml">
<meta property="og:title" content="Brand Assets — ${esc(src.organization.name)}">
<meta property="og:description" content="Official logos, icons and colours. Stable URLs, safe to hotlink.">
<meta property="og:url" content="${esc(ORIGIN)}/">
<style>
:root{
  --ink:${esc(src.palette.ink)}; --anchor:${esc(src.palette.anchor)};
  --bg:#fbfcfd; --surface:#fff; --line:#e3e8ee; --text:#0e1b2a; --muted:#5b6b7d;
  --radius:14px; --shadow:0 1px 2px rgba(0,31,63,.06),0 8px 24px -12px rgba(0,31,63,.14);
  color-scheme:light dark;
}
@media (prefers-color-scheme:dark){
  :root{--bg:#0a1420;--surface:#101f31;--line:#1e3247;--text:#e6eef7;--muted:#93a7bb;
        --shadow:0 1px 2px rgba(0,0,0,.4),0 8px 24px -12px rgba(0,0,0,.6);}
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);
  font:15px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  -webkit-font-smoothing:antialiased}
a{color:inherit}
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
pre{margin:0;padding:12px 14px;background:var(--bg);border:1px solid var(--line);border-radius:9px;
  overflow-x:auto;font:12.5px/1.6 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:var(--text)}
section{padding:44px 0}
h2.section{margin:0 0 4px;font-size:20px;letter-spacing:-.01em}
.sub{margin:0 0 24px;color:var(--muted);font-size:14px}
.grid{display:grid;gap:20px;grid-template-columns:repeat(auto-fill,minmax(320px,1fr))}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);
  padding:20px;box-shadow:var(--shadow);display:flex;flex-direction:column;gap:14px}
.card.solo{grid-column:1/-1;max-width:520px}
.card-head{display:flex;gap:14px;align-items:center}
.card-icon{border-radius:14px;flex:none}
.card h3{margin:0;font-size:17px;letter-spacing:-.01em}
.tagline{margin:2px 0 0;font-size:13.5px;color:var(--muted);line-height:1.45}
/* Always light: the wordmarks are dark-ink only and would disappear on the
   dark theme's ground. A logo is previewed on the background it is made for. */
.logo-plate{display:flex;align-items:center;justify-content:center;padding:22px 18px;
  background:linear-gradient(0deg,color-mix(in srgb,var(--brand) 8%,transparent),color-mix(in srgb,var(--brand) 8%,transparent)),#fff;
  border:1px solid var(--line);border-radius:10px;min-height:96px}
.logo-plate img{max-width:100%;max-height:44px;width:auto;height:auto}
.assets{display:flex;flex-direction:column;gap:10px}
.asset{display:flex;flex-wrap:wrap;align-items:baseline;gap:6px 12px;justify-content:space-between}
.asset-name{font-size:13px;font-weight:600}
.asset-formats{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.fmt{display:inline-flex;align-items:center;gap:5px}
button{font:inherit;cursor:pointer}
.copy{border:1px solid var(--line);background:var(--bg);color:var(--text);border-radius:6px;
  padding:2px 8px;font-size:11.5px;font-weight:700;letter-spacing:.04em;transition:.14s}
.copy:hover{border-color:var(--brand,var(--anchor));color:var(--brand,var(--anchor))}
.copy.done{background:var(--brand);color:var(--on-brand);border-color:var(--brand)}
.dl{text-decoration:none;color:var(--muted);font-size:13px;line-height:1;padding:2px 4px;border-radius:5px}
.dl:hover{color:var(--brand,var(--anchor));background:var(--bg)}
.meta{font-size:11px;color:var(--muted);font-variant-numeric:tabular-nums}
.swatch{display:inline-flex;align-items:center;gap:8px;align-self:flex-start;border:1px solid var(--line);
  background:var(--bg);color:var(--text);border-radius:7px;padding:5px 10px;
  font:600 12px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.03em;transition:.14s}
.swatch:hover{border-color:var(--brand)}
.swatch.done{background:var(--brand);color:var(--on-brand);border-color:var(--brand)}
.chip{width:13px;height:13px;border-radius:4px;background:var(--brand);
  box-shadow:inset 0 0 0 1px rgba(0,0,0,.12);flex:none}
.card-links{display:flex;flex-wrap:wrap;gap:14px;margin-top:auto;padding-top:12px;border-top:1px solid var(--line)}
.card-links a{font-size:12.5px;color:var(--muted);text-decoration:none}
.card-links a:hover{color:var(--brand);text-decoration:underline}
.rules{display:grid;gap:20px;grid-template-columns:repeat(auto-fit,minmax(300px,1fr))}
.rules ul{margin:0;padding-left:20px}
.rules li{margin:0 0 7px;color:var(--muted);font-size:14px}
.rules h3{margin:0 0 10px;font-size:14px}
.yes h3{color:#0d8a5f}.no h3{color:#c2374a}
@media (prefers-color-scheme:dark){.yes h3{color:#3ecf9a}.no h3{color:#ff7a8a}}
footer{border-top:1px solid var(--line);padding:28px 0 56px;color:var(--muted);font-size:13px}
footer a{color:var(--muted)}
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
  <p class="lede">Official logos, icons and colours for ${esc(src.organization.name)} and its WooCommerce plugins. Every URL below is permanent — we update the file behind it, never the address — so you can hotlink these directly and always get the current mark.</p>
  <div class="panel">
    <h2>Hotlink it</h2>
    <p>Point straight at the URL. No copy in your media library, no version to keep in sync.</p>
    <pre>&lt;img src="${esc(sampleUrl)}" alt="${esc(sample?.name ?? '')}" height="40"&gt;</pre>
  </div>
  <div class="panel">
    <h2>Machine-readable</h2>
    <p>Every product, asset URL and colour on this page, as JSON.</p>
    <pre>${esc(ORIGIN)}/brand.json</pre>
  </div>
</div></header>

${brand ? `<section><div class="wrap">
  <h2 class="section">The ${esc(brand.name)} brand</h2>
  <p class="sub">Use these for the company itself — not for an individual plugin.</p>
  <div class="grid">${card({ ...brand, _solo: true }).replace('class="card"', 'class="card solo"')}</div>
</div></section>` : ''}

<section><div class="wrap">
  <h2 class="section">Plugins</h2>
  <p class="sub">${plugins.length} product${plugins.length === 1 ? '' : 's'}. Click a format to copy its URL, or ↓ to download.</p>
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
  Questions about usage — <a href="${esc(src.organization.url)}">${esc(src.organization.url.replace(/^https?:\/\//, ''))}</a>.
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
  const btn = e.target.closest('.copy');
  if (!btn) return;
  const text = btn.dataset.url;
  try {
    await navigator.clipboard.writeText(text);
  } catch {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;opacity:0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch { flash('Copy failed — select the URL manually'); ta.remove(); return; }
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
<title>Not found — ${esc(src.organization.name)} brand assets</title>
<meta name="robots" content="noindex">
<style>
:root{--ink:${esc(src.palette.ink)};--anchor:${esc(src.palette.anchor)};
  --bg:#fbfcfd;--text:#0e1b2a;--muted:#5b6b7d;color-scheme:light dark}
@media (prefers-color-scheme:dark){:root{--bg:#0a1420;--text:#e6eef7;--muted:#93a7bb}}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--text);
  font:15px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;text-align:center;padding:24px}
img{height:30px;width:auto;display:block}
.plate{display:inline-flex;background:#fff;border:1px solid rgba(0,31,63,.12);border-radius:11px;
  padding:11px 17px;margin-bottom:26px}
h1{margin:0 0 10px;font-size:24px;letter-spacing:-.02em}
p{margin:0 0 22px;color:var(--muted);max-width:44ch}
code{font:13px ui-monospace,SFMono-Regular,Menlo,monospace;background:rgba(127,127,127,.14);
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
console.log(`index.html  ${(html.length / 1024).toFixed(1)} KB`);
if (missing.length) console.warn(`WARN  no asset files found for: ${missing.join(', ')}`);
if (orphans.length) console.warn(`WARN  asset folder with no entry in brand.source.json: ${orphans.join(', ')}`);
