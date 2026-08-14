/*
 * verify-approaches.js — proves the claims this demo makes, in a real browser.
 *
 * Run with `npm run verify`.
 *
 * The two claims that actually matter to the client are:
 *
 *   1. The overlay approach works against their S3 layer with no changes to it.
 *   2. The composite (bake-the-QR-into-the-pixels) approach CANNOT work against
 *      that same S3 layer without CORS headers we are not able to set.
 *
 * Claim 2 is the reason the architecture is what it is, so it is asserted here
 * rather than merely explained. A real Chromium is required for that: canvas
 * tainting is a browser security behaviour and cannot be reproduced in
 * node-canvas, which has no concept of origins.
 *
 * The layout check works by loading the page twice — once with the overlay
 * script blocked, once with it running — and comparing element geometry. That
 * is the only honest way to claim "the page does not move".
 */

'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');

const OUT_DIR = path.join(__dirname, '..', 'verify-output');

const CMS = 'http://localhost:3000';
const S3 = 'http://localhost:3001';
const TRACEIT = 'http://localhost:3002';
const OVERLAY_SCRIPT = `${TRACEIT}/js/traceit-qr-overlay.js`;

let failures = 0;
let checks = 0;

function ok(name, detail) {
  checks++;
  console.log(`  ✓ ${name}${detail ? ` — ${detail}` : ''}`);
}

function fail(name, detail) {
  checks++;
  failures++;
  console.log(`  ✗ ${name}${detail ? ` — ${detail}` : ''}`);
}

function assert(cond, name, detail) {
  cond ? ok(name, detail) : fail(name, detail);
  return cond;
}

function section(title) {
  console.log(`\n${title}\n${'-'.repeat(title.length)}`);
}

/* ------------------------------------------------------------------ stack -- */

/**
 * Uses an already-running demo if there is one, otherwise boots the three
 * origins in-process against a throwaway data directory so the run is
 * deterministic and leaves no state behind.
 */
async function ensureStack() {
  try {
    const res = await fetch(`${TRACEIT}/v1/health`, { signal: AbortSignal.timeout(1500) });
    if (res.ok) return { started: [], external: true };
  } catch {
    /* nothing listening; boot our own below */
  }

  const dataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'traceit-verify-'));
  process.env.TRACEIT_DATA_DIR = dataDir;

  // Required AFTER TRACEIT_DATA_DIR is set — store.js reads it at load time.
  const apps = [
    [require('../server/fake-s3'), 3001],
    [require('../server/traceit-service'), 3002],
    [require('../server/publisher-site'), 3000],
  ];

  const started = [];
  for (const [app, port] of apps) {
    await new Promise((resolve, reject) => {
      const s = app.listen(port);
      s.once('listening', () => { started.push(s); resolve(); });
      s.once('error', reject);
    });
  }
  return { started, external: false, dataDir };
}

/* ------------------------------------------------------- http-level checks -- */

async function checkHttp() {
  section('Preconditions (the situation being tested)');

  const s3 = await fetch(`${S3}/media/article-1.jpg`, {
    headers: { Origin: CMS },
  });
  assert(s3.ok, 'S3 origin serves the thumbnail', `HTTP ${s3.status}`);
  assert(
    s3.headers.get('access-control-allow-origin') === null,
    'S3 sends NO Access-Control-Allow-Origin',
    'this is the test condition — it is what taints a canvas'
  );

  section('Our service');

  const health = await (await fetch(`${TRACEIT}/v1/health`)).json();
  ok('service healthy', `QR source: ${health.mode}, lazy mint: ${health.lazyMint}`);

  // Publish through the CMS so the ID is captured exactly as in production.
  const published = await (
    await fetch(`${CMS}/api/publish`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ headline: 'Verification run', image: 'article-1.jpg' }),
    })
  ).json();

  const articleId = published?.article?.id;
  assert(!!articleId, 'CMS assigned an article ID', articleId);
  assert(
    published?.traceIt?.ok === true,
    'publish captured the ID and minted a code',
    `created=${published?.traceIt?.payload?.created}`
  );

  // Route ordering: /v1/qr/:id also matches "id.png", so the PNG route has to
  // win. A regression here silently returns JSON where an image is expected.
  const png = await fetch(`${TRACEIT}/v1/qr/${articleId}.png`);
  const bytes = Buffer.from(await png.arrayBuffer());
  assert(
    png.headers.get('content-type') === 'image/png' &&
      bytes.subarray(0, 8).toString('hex') === '89504e470d0a1a0a',
    '.png route returns a real PNG, not JSON',
    `${bytes.length} bytes`
  );

  // Auth + input validation on the mint path.
  const noAuth = await fetch(`${TRACEIT}/v1/hooks/article-published`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ articleId: 'x-1' }),
  });
  assert(noAuth.status === 401, 'publish webhook rejects unauthenticated calls', `HTTP ${noAuth.status}`);

  // A leaked webhook secret must not become a way to point the client's branded
  // short links at someone else's content.
  const foreignDest = await fetch(`${TRACEIT}/v1/hooks/article-published`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer dev-webhook-secret' },
    body: JSON.stringify({ articleId: 'dest-check-1', url: 'https://evil.example.com/phish' }),
  });
  assert(foreignDest.status === 400, 'QR destination is pinned to the publisher\'s host',
    `foreign destination got HTTP ${foreignDest.status}`);

  const badIds = ['../../etc/passwd', 'a b', '', '@@@', 'x'.repeat(200)];
  const statuses = [];
  for (const bad of badIds) {
    const r = await fetch(`${TRACEIT}/v1/hooks/article-published`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: 'Bearer dev-webhook-secret' },
      body: JSON.stringify({ articleId: bad }),
    });
    statuses.push(r.status);
  }
  assert(
    statuses.every((s) => s === 400),
    'malformed article IDs are rejected',
    `statuses: ${statuses.join(',')}`
  );

  return articleId;
}

/* ------------------------------------------------------------ embed mode --- */

/**
 * The claim being tested: a native "Save image as…" on the article page yields a
 * file with a scannable QR in it.
 *
 * Save-as writes the bytes of the resource the <img> is displaying, so the way
 * to test it without driving an OS file dialog is to fetch that exact resource
 * and decode a QR out of it. That is what a save would have written, byte for
 * byte.
 */
async function checkEmbed(articleId) {
  section('Embed mode (what "Save image as…" writes)');

  const framed = await fetch(`${TRACEIT}/v1/framed/${articleId}.jpg`);
  assert(framed.ok, 'framed endpoint serves an image', `HTTP ${framed.status}`);
  assert(
    framed.headers.get('x-traceit-badge') === 'embedded',
    'service reports the QR was composited in',
    framed.headers.get('x-traceit-badge')
  );
  assert(
    /filename="article-.*-qr\.(jpg|png)"/.test(framed.headers.get('content-disposition') || ''),
    'sends a sensible save-as filename',
    framed.headers.get('content-disposition')
  );

  const framedBuf = Buffer.from(await framed.arrayBuffer());

  // Decode the QR straight out of the delivered bytes.
  const jsQR = require('jsqr');
  const { loadImage, createCanvas } = require('@napi-rs/canvas');
  const img = await loadImage(framedBuf);
  const c = createCanvas(img.width, img.height);
  const ctx = c.getContext('2d');
  ctx.drawImage(img, 0, 0);
  const data = ctx.getImageData(0, 0, img.width, img.height);
  const decoded = (jsQR.default || jsQR)(
    new Uint8ClampedArray(data.data), img.width, img.height
  );

  assert(!!decoded, 'a QR decodes out of the saved bytes',
    decoded ? decoded.data : 'DECODE FAILED');
  assert(
    !!decoded && decoded.data.includes(articleId),
    'the decoded QR points at this article',
    decoded ? decoded.data : '—'
  );

  // Dimensions must match the source exactly: compositing must not rescale the
  // publisher's photography.
  const srcRes = await fetch(`${S3}/media/article-1.jpg`);
  const srcImg = await loadImage(Buffer.from(await srcRes.arrayBuffer()));
  const record = await (await fetch(`${TRACEIT}/v1/qr/${articleId}`)).json();
  if (record.pngUrl) {
    assert(
      img.width > 0 && img.height > 0,
      'composite has real dimensions',
      `${img.width}x${img.height}`
    );
  }
  assert(
    img.width === srcImg.width && img.height === srcImg.height,
    'composite keeps the source resolution (no rescaling)',
    `${img.width}x${img.height} vs source ${srcImg.width}x${srcImg.height}`
  );

  // SSRF: the source URL can arrive as a query parameter, so it must be pinned.
  const ssrf = await fetch(
    `${TRACEIT}/v1/framed/${articleId}.jpg?src=${encodeURIComponent('http://169.254.169.254/latest/meta-data/')}`
  );
  assert(ssrf.status === 403, 'refuses a source image host off the allowlist',
    `HTTP ${ssrf.status} for a link-local metadata address`);

  const ssrfFile = await fetch(
    `${TRACEIT}/v1/framed/${articleId}.jpg?src=${encodeURIComponent('file:///etc/passwd')}`
  );
  assert(ssrfFile.status === 403, 'refuses non-http schemes', `HTTP ${ssrfFile.status}`);

  return framedBuf.length;
}

/* --------------------------------------------------------- badge geometry --- */

/**
 * The prototype's placement bug: the minimum-size floor was applied before a
 * width-only clamp, with nothing constraining height, so a wide short image put
 * the badge off the top edge. Assert the plan always fits, including the shapes
 * that used to break.
 */
function checkGeometry() {
  section('Badge placement');

  const { planBadge } = require('../server/compositor');
  const aspect = 1956 / 1450; // the sample QR's real aspect ratio

  const cases = [
    [1200, 800], [300, 200], [640, 360], [400, 120],
    [300, 100], [150, 150], [1200, 300], [2400, 1600], [90, 1200],
  ];

  const bad = [];
  for (const [W, H] of cases) {
    const p = planBadge(W, H, aspect, {});
    if (!p.fits) continue; // deliberately skipped as too small — acceptable
    const inside =
      p.px >= 0 && p.py >= 0 && p.px + p.plateW <= W && p.py + p.plateH <= H;
    if (!inside) bad.push(`${W}x${H} -> (${p.px},${p.py}) ${p.plateW}x${p.plateH}`);
  }

  assert(bad.length === 0, 'badge fits inside the image at every aspect ratio',
    bad.length ? bad.join('; ') : `${cases.length} shapes incl. 400x120 and 90x1200`);

  const wide = planBadge(400, 120, aspect, {});
  assert(
    !wide.fits || wide.py >= 0,
    'the old off-the-top-edge case is handled',
    wide.fits ? `fits at y=${wide.py}` : `skipped: ${wide.reason}`
  );
}

/* ------------------------------------------------------ quota / dedup check -- */

async function checkDedup() {
  section('Quota protection');

  // Fresh module instance against a temp dir so this cannot touch real data.
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'traceit-dedup-'));
  const prev = process.env.TRACEIT_DATA_DIR;
  process.env.TRACEIT_DATA_DIR = dir;
  delete require.cache[require.resolve('../server/store')];
  const store = require('../server/store');

  let mints = 0;
  const slowMint = async () => {
    mints++;
    await new Promise((r) => setTimeout(r, 50));
    return { qrId: 'q', shortUrl: null, pngDataUri: 'data:image/png;base64,AAA', source: 'test' };
  };

  await Promise.all(Array.from({ length: 50 }, () => store.getOrCreate('dedup-1', slowMint)));
  assert(mints === 1, '50 concurrent first-views cause exactly 1 mint', `mints=${mints}`);

  let attempts = 0;
  const failing = async () => { attempts++; throw new Error('upstream down'); };
  await store.getOrCreate('dedup-2', failing).catch(() => {});
  await store.getOrCreate('dedup-2', failing).catch(() => {});
  assert(attempts === 2, 'a failed mint does not poison the cache key', `attempts=${attempts}`);

  process.env.TRACEIT_DATA_DIR = prev;
  delete require.cache[require.resolve('../server/store')];
  fs.rmSync(dir, { recursive: true, force: true });
}

/* --------------------------------------------------------- browser checks --- */

/** Geometry of everything that could betray a layout shift. */
const MEASURE = `(() => {
  const box = (el) => {
    const r = el.getBoundingClientRect();
    return [Math.round(r.x * 10) / 10, Math.round(r.y * 10) / 10,
            Math.round(r.width * 10) / 10, Math.round(r.height * 10) / 10];
  };
  return {
    thumbs: [...document.querySelectorAll('img.story-thumb')].map(box),
    stories: [...document.querySelectorAll('article.story')].map(box),
    paras: [...document.querySelectorAll('article.story p')].map(box),
    bodyHeight: Math.round(document.body.scrollHeight),
  };
})()`;

async function settle(page) {
  await page.waitForSelector('img.story-thumb', { timeout: 15000 });
  await page.evaluate(`document.fonts.ready`);
  await page.evaluate(`(async () => {
    const imgs = [...document.images];
    await Promise.all(imgs.map(i => i.complete ? null : new Promise(r => {
      i.addEventListener('load', r, { once: true });
      i.addEventListener('error', r, { once: true });
    })));
  })()`);
  await page.waitForTimeout(600);
}

async function checkBrowser(articleId) {
  let chromium;
  try {
    ({ chromium } = require('playwright'));
  } catch {
    section('Browser checks');
    fail('playwright not installed', 'run: npm i -D playwright && npx playwright install chromium');
    return;
  }

  // Prefer Playwright's bundled build, but fall back to a system Chrome/Edge so
  // the browser checks still run without a 200MB download. All three are
  // Chromium and share the canvas-tainting behaviour being tested.
  let browser = null;
  let launched = '';
  for (const opts of [{}, { channel: 'chrome' }, { channel: 'msedge' }]) {
    try {
      browser = await chromium.launch(opts);
      launched = opts.channel || 'bundled chromium';
      break;
    } catch (err) {
      if (opts.channel === 'msedge') {
        section('Browser checks');
        fail('could not launch any Chromium', err.message.split('\n')[0]);
        return;
      }
    }
  }
  console.log(`\n(browser: ${launched})`);

  const context = await browser.newContext({ viewport: { width: 1280, height: 1000 } });

  try {
    /* --- baseline: overlay script blocked ------------------------------- */
    const basePage = await context.newPage();
    await basePage.route(OVERLAY_SCRIPT, (route) => route.abort());
    await basePage.goto(`${CMS}/`, { waitUntil: 'load' });
    await settle(basePage);
    const before = await basePage.evaluate(MEASURE);
    await basePage.screenshot({ path: path.join(OUT_DIR, 'without-overlay.png'), fullPage: true });
    await basePage.close();

    /* --- with the overlay running -------------------------------------- */
    section('Browser: overlay behaviour');

    const page = await context.newPage();
    const consoleErrors = [];
    const badResponses = [];
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
    page.on('pageerror', (e) => consoleErrors.push(String(e)));
    // A bare "Failed to load resource: 404" in the console names no URL, which
    // makes it useless to debug. Record the responses themselves too.
    page.on('response', (r) => {
      if (r.status() >= 400) badResponses.push(`HTTP ${r.status()} ${r.url()}`);
    });

    await page.goto(`${CMS}/`, { waitUntil: 'load' });
    await settle(page);
    await page.waitForFunction(
      `document.querySelectorAll('[data-tqr-el="code"]').length > 0`,
      { timeout: 15000 }
    ).catch(() => {});

    const badges = await page.evaluate(`(() => {
      const codes = [...document.querySelectorAll('[data-tqr-el="code"]')];
      return codes.map((c) => {
        const frame = c.closest('[data-tqr-el="frame"]') || c.parentElement.parentElement;
        const img = frame.querySelector('img');
        const cb = c.getBoundingClientRect();
        const ib = img.getBoundingClientRect();
        return {
          inside: cb.left >= ib.left - 1 && cb.top >= ib.top - 1 &&
                  cb.right <= ib.right + 1 && cb.bottom <= ib.bottom + 1,
          w: Math.round(cb.width), h: Math.round(cb.height),
          imgW: Math.round(ib.width), imgH: Math.round(ib.height),
          bg: getComputedStyle(c).backgroundImage.slice(0, 60),
          pointerEvents: getComputedStyle(c).pointerEvents,
          visible: cb.width > 0 && cb.height > 0 && getComputedStyle(c).opacity !== '0',
        };
      });
    })()`);

    // `[].every()` is true, so every badge assertion below has to require a
    // non-empty list or they all pass vacuously when nothing rendered at all.
    const all = (fn) => badges.length > 0 && badges.every(fn);

    assert(badges.length > 0, 'QR badge rendered', `${badges.length} badge(s)`);
    assert(all((b) => b.inside), 'every badge sits INSIDE its image frame',
      badges.map((b) => `${b.w}x${b.h} in ${b.imgW}x${b.imgH}`).join(', '));
    assert(all((b) => b.visible), 'badges are visible (non-zero box, faded in)');
    assert(all((b) => b.bg.includes('/v1/qr/')), 'badge is painted from our QR endpoint');
    assert(all((b) => b.pointerEvents === 'none'),
      'badge does not swallow pointer events', 'right-click/click on the photo still reaches the photo');

    // Overlay mode must leave the image itself alone: their CDN still serves it,
    // and a save-as there gets the clean photo. This is the counterpart of the
    // embed-mode assertion further down.
    const srcs = await page.evaluate(`(() => [...document.querySelectorAll('img.story-thumb')]
      .map(i => i.currentSrc || i.src))()`);
    assert(
      srcs.length > 0 && srcs.every((s) => s.includes(':3001/media/')),
      'overlay mode leaves @src pointing at their S3',
      `${srcs.length} thumbnails still served by their CDN`
    );
    assert(
      srcs.every((s) => !s.includes('/v1/framed/')),
      'overlay mode serves no image bytes from us'
    );

    // One badge per thumbnail — the in-flight re-entry bug would double them.
    const counts = await page.evaluate(`(() => {
      const frames = [...document.querySelectorAll('[data-tqr-el="frame"]')];
      return frames.map(f => f.querySelectorAll('[data-tqr-el="code"]').length);
    })()`);
    assert(counts.length > 0 && counts.every((n) => n === 1),
      'exactly one badge per thumbnail', `counts: ${counts.join(',')}`);

    /* --- layout must not move ------------------------------------------ */
    section('Browser: layout safety');

    const after = await page.evaluate(MEASURE);
    await page.screenshot({ path: path.join(OUT_DIR, 'with-overlay.png'), fullPage: true });

    const same = (a, b, tol = 1.0) =>
      a.length === b.length && a.every((row, i) => row.every((v, j) => Math.abs(v - b[i][j]) <= tol));

    assert(same(before.thumbs, after.thumbs), 'thumbnail geometry unchanged',
      `${before.thumbs.length} thumbnails`);
    assert(same(before.paras, after.paras), 'body-text geometry unchanged',
      `${before.paras.length} paragraphs — float and text wrap preserved`);
    assert(same(before.stories, after.stories), 'article block geometry unchanged');
    assert(Math.abs(before.bodyHeight - after.bodyHeight) <= 2, 'page height unchanged',
      `${before.bodyHeight}px vs ${after.bodyHeight}px`);

    /* --- console must be clean ----------------------------------------- */
    // Snapshot BEFORE the tainting probe below, which provokes CORS failures on
    // purpose. Asserting after it would be measuring the test's own noise.
    section('Browser: console');
    assert(consoleErrors.length === 0, 'no console errors from normal page load',
      badResponses.slice(0, 3).join(' | ') ||
        consoleErrors.slice(0, 3).join(' | ') || 'clean');
    assert(badResponses.length === 0, 'every request on the page succeeded',
      badResponses.slice(0, 3).join(' | ') || 'clean');

    /* --- teardown restores the DOM ------------------------------------- */
    const restored = await page.evaluate(`(() => {
      window.TraceItQROverlay.teardown();
      const img = document.querySelector('img.story-thumb');
      return {
        wrappers: document.querySelectorAll('[data-tqr-el="frame"]').length,
        badges: document.querySelectorAll('[data-tqr-el="code"]').length,
        style: img ? img.getAttribute('style') : null,
      };
    })()`);
    assert(restored.wrappers === 0 && restored.badges === 0, 'teardown() removes every element it added');
    assert(
      /float:\s*left/.test(restored.style || '') && /max-width:\s*300px/.test(restored.style || ''),
      "teardown() restores the author's original inline style",
      restored.style
    );

    /* --- why compositing has to happen server-side ---------------------- */
    section('Browser: why compositing must happen on the server');

    const taint = await page.evaluate(`(async () => {
      const url = '${S3}/media/article-1.jpg';
      const out = {};

      // (a) plain load + canvas readback — what a compositing script must do.
      const img = new Image();
      img.src = url;
      try { await img.decode(); out.plainLoad = 'loaded'; }
      catch (e) { out.plainLoad = 'FAILED: ' + e.message; }

      const c = document.createElement('canvas');
      c.width = img.naturalWidth || 10;
      c.height = img.naturalHeight || 10;
      c.getContext('2d').drawImage(img, 0, 0);
      try { c.toDataURL('image/png'); out.readback = 'SUCCEEDED (canvas was not tainted)'; }
      catch (e) { out.readback = e.name; }

      // (b) the documented workaround: crossOrigin="anonymous". Needs the very
      //     CORS header the bucket does not send, so the load itself fails.
      const img2 = new Image();
      img2.crossOrigin = 'anonymous';
      img2.src = url + '?cors';
      try { await img2.decode(); out.corsAttr = 'loaded (bucket DOES send CORS)'; }
      catch (e) { out.corsAttr = 'load failed — no CORS header to satisfy it'; }

      return out;
    })()`);

    assert(taint.plainLoad === 'loaded',
      'the S3 photo displays fine cross-origin', 'which is all the overlay needs');
    assert(taint.readback === 'SecurityError',
      'canvas readback of that photo throws SecurityError',
      `got: ${taint.readback} — so the browser cannot composite; our server can`);
    assert(taint.corsAttr.startsWith('load failed'),
      'crossOrigin="anonymous" cannot load it either',
      taint.corsAttr);

    /* --- the article page: ID from the URL ----------------------------- */
    section('Browser: article page (ID recovered from the URL)');

    const artPage = await context.newPage();
    await artPage.goto(`${CMS}/article/${articleId}`, { waitUntil: 'load' });
    await artPage.waitForSelector('img.story-thumb', { timeout: 15000 });
    await artPage.waitForFunction(
      `document.querySelectorAll('[data-tqr-el="code"]').length > 0`,
      { timeout: 15000 }
    ).catch(() => {});

    // The article page runs embed mode, so there is no overlay element here —
    // the QR is in the image itself and only @src changes.
    await artPage.waitForFunction(
      `(() => { const i = document.querySelector('img.story-thumb');
                return i && i.getAttribute('data-tqr-state') === 'done'; })()`,
      { timeout: 15000 }
    ).catch(() => {});

    const art = await artPage.evaluate(`(() => {
      const img = document.querySelector('img.story-thumb');
      if (!img) return { found: false };
      return {
        found: true,
        hasIdAttr: img.hasAttribute('data-article-id'),
        mode: img.getAttribute('data-tqr-mode'),
        state: img.getAttribute('data-tqr-state'),
        src: img.currentSrc || img.src,
        original: img.getAttribute('data-tqr-src0'),
        naturalW: img.naturalWidth,
        naturalH: img.naturalHeight,
        overlayEls: document.querySelectorAll('[data-tqr-el="code"]').length,
        wrappers: document.querySelectorAll('[data-tqr-el="frame"]').length,
      };
    })()`);

    assert(art.found, 'article thumbnail present');
    assert(art.hasIdAttr === false, 'thumbnail carries no data-article-id here',
      'so the ID must come from the URL');
    assert(art.mode === 'embed', 'embed mode engaged', `state=${art.state}`);
    assert(
      art.src.includes(`/v1/framed/${articleId}`),
      'the <img> now serves the composited file',
      'so a native save-as writes the QR-embedded image'
    );
    assert(
      (art.original || '').includes(':3001/'),
      'the original src was the S3 photo',
      art.original
    );
    assert(
      art.overlayEls === 0 && art.wrappers === 0,
      'embed mode adds NO elements to the DOM',
      `overlay spans: ${art.overlayEls}, wrappers: ${art.wrappers}`
    );
    assert(art.naturalW > 0 && art.naturalH > 0,
      'the composite actually decoded in the browser',
      `${art.naturalW}x${art.naturalH}`);

    await artPage.screenshot({ path: path.join(OUT_DIR, 'article-page.png'), fullPage: true });
    await artPage.close();

    /* --- mobile: the float re-sync ------------------------------------- */
    section('Browser: responsive re-sync');

    await page.setViewportSize({ width: 420, height: 900 });
    await page.reload({ waitUntil: 'load' });
    await settle(page);
    await page.waitForFunction(
      `document.querySelectorAll('[data-tqr-el="code"]').length > 0`,
      { timeout: 15000 }
    ).catch(() => {});

    const mobile = await page.evaluate(`(() => {
      const frame = document.querySelector('[data-tqr-el="frame"]');
      const code = document.querySelector('[data-tqr-el="code"]');
      if (!frame || !code) return { ok: false };
      const fb = frame.getBoundingClientRect();
      const cb = code.getBoundingClientRect();
      return {
        ok: true,
        frameFloat: getComputedStyle(frame).float,
        overflowsViewport: fb.right > window.innerWidth + 1,
        badgeInside: cb.right <= fb.right + 1 && cb.bottom <= fb.bottom + 1,
        badgeW: Math.round(cb.width),
      };
    })()`);

    assert(mobile.ok, 'badge renders at mobile width');
    assert(!mobile.overflowsViewport, 'frame does not overflow the viewport',
      `float: ${mobile.frameFloat}`);
    assert(mobile.badgeInside, 'badge still inside the frame at mobile width',
      `badge ${mobile.badgeW}px wide`);

    await page.screenshot({ path: path.join(OUT_DIR, 'mobile.png'), fullPage: true });
    await page.close();
  } finally {
    await context.close();
    await browser.close();
  }
}

/* ------------------------------------------------------------------- main -- */

(async () => {
  fs.mkdirSync(OUT_DIR, { recursive: true });

  console.log('\nTrace-It QR overlay — verification');
  console.log('==================================');

  const stack = await ensureStack();
  console.log(
    stack.external
      ? '\n(using the demo already running on :3000/:3001/:3002)'
      : '\n(booted a private stack on :3000/:3001/:3002)'
  );

  try {
    const articleId = await checkHttp();
    checkGeometry();
    await checkEmbed(articleId);
    await checkDedup();
    await checkBrowser(articleId);
  } catch (err) {
    fail('run aborted', err.message);
    console.error(err);
  } finally {
    for (const s of stack.started) s.close();
  }

  console.log(`\n${'='.repeat(34)}`);
  console.log(`${checks - failures}/${checks} checks passed`);
  console.log(`screenshots → ${OUT_DIR}`);
  if (failures) {
    console.log(`\n${failures} FAILED\n`);
    process.exit(1);
  }
  console.log('\nAll checks passed.\n');
  process.exit(0);
})();
