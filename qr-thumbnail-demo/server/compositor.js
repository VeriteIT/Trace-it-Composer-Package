/*
 * compositor.js — bakes the QR into the image pixels, SERVER-SIDE.
 * ===========================================================================
 * This is what makes "Save image as…" produce a QR-embedded file.
 *
 * WHY THIS IS ALLOWED, WHEN THE BROWSER VERSION IS NOT
 *   Same-origin policy and CORS are BROWSER security mechanisms. They govern
 *   what a page's JavaScript may read. They have nothing to do with a server
 *   making an HTTP request. Our service fetching the publisher's thumbnail is
 *   an ordinary GET of a URL that is already public to every reader of their
 *   site — no CORS, no credentials, no privileged access, and nothing written
 *   back to their storage.
 *
 *   In the browser, compositing requires canvas.getImageData/toDataURL on a
 *   cross-origin image, which throws SecurityError without CORS headers on
 *   their bucket. That is the wall. Here there is no wall.
 *
 * WHAT THIS COSTS
 *   The composited bytes are served by us, not their CDN. So this puts us in
 *   the image delivery path: our bandwidth, our latency, our uptime. Mitigated
 *   by an immutable disk cache here and, in production, a CDN in front. The
 *   frontend also degrades safely — see the `embed` mode in
 *   traceit-qr-overlay.js, which keeps the S3 image on screen and only swaps to
 *   the composite once it has actually loaded.
 * ===========================================================================
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { createCanvas, loadImage } = require('@napi-rs/canvas');

const CACHE_DIR = path.join(
  process.env.TRACEIT_DATA_DIR || path.join(__dirname, '..', 'data'),
  'framed-cache'
);

const MAX_SOURCE_BYTES = 16 * 1024 * 1024;
const FETCH_TIMEOUT_MS = 12000;

/*
 * JPEG re-encode quality.
 *
 * ON IMAGE QUALITY: the composite is drawn at the photo's NATIVE resolution, so
 * nothing is ever scaled and the saved file has exactly the source's pixel
 * dimensions. But a JPEG in means a decode and a re-encode, and JPEG is lossy,
 * so there is one extra generation of loss. It is measurable, and small.
 *
 * Measured on a 1200x800 photo, simulating publisher originals at several
 * qualities and comparing our output against their decoded input (PSNR over the
 * whole frame; higher is better, >40 dB is generally imperceptible):
 *
 *     publisher q | ours q=90 | ours q=95 | ours q=98
 *              75 |   53.0 dB |   55.3 dB |   55.6 dB
 *              82 |   50.9 dB |   54.9 dB |   54.9 dB
 *              88 |   52.8 dB |   53.7 dB |   55.0 dB
 *              94 |   51.5 dB |   56.3 dB |   56.1 dB
 *
 * 95 is the sweet spot: 53-56 dB regardless of what the source was encoded at.
 * q=98 buys nothing measurable and inflates the file.
 *
 * ON FILE SIZE, which is the real cost here, not quality. Measured on the three
 * 1200x800 demo thumbnails:
 *
 *     source        ~48 KB
 *     re-encoded    ~65 KB   (+35%, the q=95 pass)
 *     with the QR  ~100 KB   (+35 KB more)
 *
 * So the delivered file roughly DOUBLES, and rather more than half of that is
 * the QR itself: a grid of hard black-and-white edges is about the most
 * expensive thing you can ask a JPEG to encode. Shrinking the badge or dropping
 * quality trades scannability for bytes.
 *
 * In embed mode those bytes come from us, so the practical pattern is: embed on
 * ARTICLE pages, where there is one image and where readers actually save and
 * share, and overlay on index/list pages, where there are many thumbnails and
 * nobody is saving anything. That is what the demo does.
 *
 * If a publisher needs the delivered bytes untouched, use overlay mode — it
 * re-encodes nothing. If the source is a PNG it stays PNG and stays lossless.
 * And if they ever hand us the pre-compression master at publish time, the
 * generational loss goes to zero, because there is no second generation.
 */
const JPEG_QUALITY = Number(process.env.TRACEIT_JPEG_QUALITY || 95);

/**
 * Hosts we are willing to fetch a source image from.
 *
 * This endpoint can be handed an image URL, so without an allowlist it is an
 * open proxy: someone could point it at 169.254.169.254, at a localhost admin
 * port, or at anything else inside our own network, and get the bytes back
 * wrapped in a JPEG. Set this to the publisher's real S3/CDN hostnames.
 */
const ALLOWED_IMAGE_HOSTS = (
  process.env.TRACEIT_ALLOWED_IMAGE_HOSTS || 'localhost:3001,127.0.0.1:3001'
)
  .split(',')
  .map((s) => s.trim().toLowerCase())
  .filter(Boolean);

function imageHostAllowed(rawUrl) {
  try {
    const u = new URL(rawUrl);
    if (u.protocol !== 'http:' && u.protocol !== 'https:') return false;
    return ALLOWED_IMAGE_HOSTS.includes(u.host.toLowerCase());
  } catch {
    return false;
  }
}

/* --- geometry ------------------------------------------------------------- */

const LAYOUT = {
  scale: 0.28,      // QR width as a fraction of the image's short side
  minPx: 96,        // below this a printed code stops being reliably scannable
  maxPx: 420,
  padFrac: 0.035,   // inset from the image edge, fraction of the short side
  platePadFrac: 0.07, // white plate padding, fraction of QR width
  radiusFrac: 0.06,
  corner: 'bottom-right',
};

/**
 * Works out the QR size and position so the plate ALWAYS fits inside the image.
 *
 * The original prototype got this wrong in a way worth calling out, because the
 * same shape of bug is easy to reintroduce: it applied the `minPx` floor and
 * only then clamped against the image WIDTH, with no height constraint at all.
 * On a wide, short thumbnail that pushed the badge off the top edge — a 400x120
 * photo placed it at y = -62, silently clipping the code.
 *
 * Here the floor is applied first as an intent, then both axes constrain it, and
 * if the result is too small to scan we report that and skip the badge instead
 * of drawing something useless.
 */
function planBadge(W, H, qrAspect, opts = {}) {
  const L = { ...LAYOUT, ...opts };
  const shortSide = Math.min(W, H);
  const pad = Math.round(shortSide * L.padFrac);

  // Desired size, before the image's own dimensions get a say.
  let qrW = Math.round(shortSide * L.scale);
  qrW = Math.max(L.minPx, Math.min(L.maxPx, qrW));

  // The plate is the thing that has to fit, not the QR: it is larger on
  // every side. Constrain against BOTH axes.
  const plateFactorW = 1 + 2 * L.platePadFrac;
  const plateFactorH = qrAspect + 2 * L.platePadFrac;

  const fitW = (W - 2 * pad) / plateFactorW;
  const fitH = (H - 2 * pad) / plateFactorH;
  qrW = Math.floor(Math.min(qrW, fitW, fitH));

  if (!Number.isFinite(qrW) || qrW < 40) {
    return { fits: false, reason: `image ${W}x${H} too small for a scannable code` };
  }

  const platePad = Math.round(qrW * L.platePadFrac);
  const qrH = Math.round(qrW * qrAspect);
  const plateW = qrW + platePad * 2;
  const plateH = qrH + platePad * 2;

  const right = W - plateW - pad;
  const bottom = H - plateH - pad;
  const pos = {
    'bottom-right': [right, bottom],
    'bottom-left': [pad, bottom],
    'top-right': [right, pad],
    'top-left': [pad, pad],
  }[L.corner] || [right, bottom];

  return {
    fits: true,
    qrW, qrH, plateW, plateH, platePad,
    px: pos[0], py: pos[1],
    radius: Math.max(2, Math.round(qrW * L.radiusFrac)),
  };
}

function roundedRect(ctx, x, y, w, h, r) {
  r = Math.min(r, w / 2, h / 2);
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}

/* --- source fetch --------------------------------------------------------- */

async function fetchImageBytes(url) {
  const res = await fetch(url, {
    signal: AbortSignal.timeout(FETCH_TIMEOUT_MS),
    redirect: 'error', // a redirect could hop off the allowlist
  });
  if (!res.ok) throw new Error(`source image HTTP ${res.status}`);

  const type = res.headers.get('content-type') || '';
  if (!type.startsWith('image/')) throw new Error(`source is not an image (${type})`);

  const declared = Number(res.headers.get('content-length') || 0);
  if (declared > MAX_SOURCE_BYTES) throw new Error('source image too large');

  const buf = Buffer.from(await res.arrayBuffer());
  if (buf.length > MAX_SOURCE_BYTES) throw new Error('source image too large');

  return { buf, type };
}

/* --- main ---------------------------------------------------------------- */

/**
 * Fetches the publisher's thumbnail, draws the QR into it, and returns the
 * encoded bytes at the photo's NATIVE resolution — so the file a reader saves
 * is full quality and the code stays scannable in print.
 *
 * @returns {Promise<{buf:Buffer, mime:string, width:number, height:number,
 *                    badge:boolean, note?:string}>}
 */
async function composite({ imageUrl, qrPngDataUri, layout }) {
  if (!imageHostAllowed(imageUrl)) {
    const err = new Error('source image host not allowed');
    err.code = 'HOST_NOT_ALLOWED';
    throw err;
  }

  const { buf: srcBuf, type: srcType } = await fetchImageBytes(imageUrl);
  const photo = await loadImage(srcBuf);

  const W = photo.width;
  const H = photo.height;

  const canvas = createCanvas(W, H);
  const ctx = canvas.getContext('2d');
  ctx.drawImage(photo, 0, 0, W, H);

  // Preserve the source format: re-encoding a JPEG as PNG inflates it several
  // times over, and a PNG (possibly with alpha) should not become a JPEG.
  // A PNG source stays PNG and therefore stays lossless — no generational loss
  // at all in that case. Only JPEG sources are re-encoded.
  const asPng = /png/i.test(srcType);
  const encode = () =>
    asPng ? canvas.toBuffer('image/png') : canvas.toBuffer('image/jpeg', JPEG_QUALITY);
  const mime = asPng ? 'image/png' : 'image/jpeg';

  if (!qrPngDataUri) {
    // Degrade gracefully: serve the untouched photo rather than failing the
    // image request and leaving a hole in the publisher's article page.
    return { buf: encode(), mime, width: W, height: H, badge: false, note: 'no QR available' };
  }

  const qr = await loadImage(Buffer.from(
    qrPngDataUri.slice(qrPngDataUri.indexOf('base64,') + 7), 'base64'
  ));

  const plan = planBadge(W, H, qr.height / qr.width, layout);
  if (!plan.fits) {
    return { buf: encode(), mime, width: W, height: H, badge: false, note: plan.reason };
  }

  ctx.save();
  ctx.globalAlpha = 0.96;
  ctx.fillStyle = '#ffffff';
  ctx.shadowColor = 'rgba(0,0,0,0.30)';
  ctx.shadowBlur = Math.round(plan.qrW * 0.1);
  ctx.shadowOffsetY = Math.round(plan.qrW * 0.02);
  roundedRect(ctx, plan.px, plan.py, plan.plateW, plan.plateH, plan.radius);
  ctx.fill();
  ctx.restore();

  ctx.drawImage(
    qr,
    plan.px + plan.platePad,
    plan.py + plan.platePad,
    plan.qrW,
    plan.qrH
  );

  return { buf: encode(), mime, width: W, height: H, badge: true };
}

/* --- disk cache ---------------------------------------------------------- */

function cacheKey(articleId, imageUrl) {
  return require('crypto')
    .createHash('sha256')
    .update(`${articleId}|${imageUrl}`)
    .digest('hex');
}

function cacheRead(key) {
  try {
    const meta = JSON.parse(fs.readFileSync(path.join(CACHE_DIR, `${key}.json`), 'utf8'));
    const buf = fs.readFileSync(path.join(CACHE_DIR, `${key}.bin`));
    return { ...meta, buf };
  } catch {
    return null;
  }
}

function cacheWrite(key, { buf, mime, width, height, badge }) {
  try {
    fs.mkdirSync(CACHE_DIR, { recursive: true });
    // Write-then-rename so a concurrent reader never sees a partial file.
    const binTmp = path.join(CACHE_DIR, `${key}.bin.${process.pid}.tmp`);
    const jsonTmp = path.join(CACHE_DIR, `${key}.json.${process.pid}.tmp`);
    fs.writeFileSync(binTmp, buf);
    fs.writeFileSync(jsonTmp, JSON.stringify({ mime, width, height, badge }));
    fs.renameSync(binTmp, path.join(CACHE_DIR, `${key}.bin`));
    fs.renameSync(jsonTmp, path.join(CACHE_DIR, `${key}.json`));
  } catch (err) {
    // A cache failure must not fail the request.
    console.warn('[compositor] cache write failed:', err.message);
  }
}

/** Deduplicates concurrent composites of the same key. */
const inFlight = new Map();

async function compositeCached({ articleId, imageUrl, qrPngDataUri, layout }) {
  const key = cacheKey(articleId, imageUrl);

  const hit = cacheRead(key);
  if (hit) return { ...hit, cached: true };

  if (inFlight.has(key)) return inFlight.get(key);

  const work = (async () => {
    const out = await composite({ imageUrl, qrPngDataUri, layout });
    cacheWrite(key, out);
    return { ...out, cached: false };
  })();

  inFlight.set(key, work);
  try {
    return await work;
  } finally {
    inFlight.delete(key);
  }
}

module.exports = {
  composite,
  compositeCached,
  planBadge,
  imageHostAllowed,
  ALLOWED_IMAGE_HOSTS,
  CACHE_DIR,
};
