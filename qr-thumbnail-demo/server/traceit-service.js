/*
 * traceit-service.js — OUR service. Runs on :3002.
 * ===========================================================================
 * This is the only component in the demo that we own and deploy. It is a
 * different origin from the publisher's site on purpose, because in production
 * it will be: their page is on their domain, this API is on ours. That means
 * this service — and only this service — has to send CORS headers, which is
 * fine, because it is ours to configure. We never ask the publisher to change
 * anything about their bucket or their app.
 *
 * Two ways in, matching the two ways an article ID can reach us:
 *
 *   PATH A (preferred) — publish-time webhook
 *     POST /v1/hooks/article-published   { articleId, url, imageUrl? }
 *     Authorization: Bearer <TRACEIT_WEBHOOK_SECRET>
 *     The CMS calls this once when an article goes live. One mint per article,
 *     at a predictable time, authenticated. Quota is impossible to abuse from
 *     outside because the public endpoint below never needs to mint.
 *
 *   PATH B (fallback) — lazy get-or-create on first page view
 *     GET /v1/qr/:articleId
 *     Requires zero CMS changes: the frontend component sends the article ID it
 *     found in the DOM, and we mint on the first request for an unknown ID.
 *     Convenient, but it means an unauthenticated endpoint can cause a mint, so
 *     it is gated (origin allowlist + rate limit) and can be switched off with
 *     ALLOW_LAZY_MINT=false once the webhook is wired up.
 *
 * Reading either path requires nothing from the publisher but the article ID.
 * We never read their database and never write to their S3.
 * ===========================================================================
 */

'use strict';

const path = require('path');
const express = require('express');
const store = require('./store');
const compositor = require('./compositor');
const { mintQr, getQrByArticleId, normalisePostId, MODE } = require('./traceit-client');
const { imageHostAllowed } = compositor;

const app = express();
const PORT = process.env.TRACEIT_PORT || 3002;

/* --- Configuration ------------------------------------------------------- */

// Origins allowed to embed our QR codes. In production: the publisher's real
// domains. This is a CORS allowlist, not an authentication mechanism — a
// non-browser client can send any Origin it likes. It is here to stop other
// websites from hotlinking our endpoint, not to stop a determined attacker.
const ALLOWED_ORIGINS = (process.env.TRACEIT_ALLOWED_ORIGINS ||
  'http://localhost:3000,http://127.0.0.1:3000')
  .split(',')
  .map((s) => s.trim())
  .filter(Boolean);

// Shared secret for the publish webhook. Path A is authenticated; Path B is not.
const WEBHOOK_SECRET = process.env.TRACEIT_WEBHOOK_SECRET || 'dev-webhook-secret';

// Set false in production once the CMS webhook is live. Then this service can
// only ever SERVE codes, never create them, and quota exposure drops to zero.
const ALLOW_LAZY_MINT = process.env.ALLOW_LAZY_MINT !== 'false';

// Where a scan should land. The publisher's article URL is derived from the ID.
const ARTICLE_URL_TEMPLATE =
  process.env.ARTICLE_URL_TEMPLATE || 'http://localhost:3000/article/{id}';

/*
 * Article IDs we accept, matching Trace-It's own postId rules exactly (see
 * sanitizePostId in the Trace-It repo, src/lib/api-qr.ts): letters, digits,
 * underscore and hyphen, must start and end alphanumeric, 48 characters max.
 *
 * Note there is NO dot. An earlier revision here allowed dots and 64 chars,
 * which would have let a request through only for Trace-It to reject it with
 * 400 invalid_post_id — a confusing third-party error for something we can
 * catch locally. Being strict also matters because this gates a lazy-mint path:
 * a loose pattern is a quota-burning enumeration target.
 *
 * Trace-It lowercases post IDs, so IDs are case-insensitive; normalisePostId()
 * from the client does that so our cache keys and theirs cannot drift.
 */
const ARTICLE_ID_RE = /^[A-Za-z0-9][A-Za-z0-9_-]{0,46}[A-Za-z0-9]$|^[A-Za-z0-9]$/;

/* --- Middleware ---------------------------------------------------------- */

app.use(express.json({ limit: '16kb' }));

app.use((req, res, next) => {
  const origin = req.headers.origin;
  if (origin && ALLOWED_ORIGINS.includes(origin)) {
    res.set('Access-Control-Allow-Origin', origin);
    res.set('Vary', 'Origin');
  }
  res.set('X-Content-Type-Options', 'nosniff');
  if (req.method === 'OPTIONS') {
    res.set('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
    res.set('Access-Control-Allow-Headers', 'Content-Type,Authorization');
    res.set('Access-Control-Max-Age', '600');
    return res.status(204).end();
  }
  next();
});

/*
 * WE host the overlay script, not the publisher. That is what reduces their
 * integration to a single <script src="https://our-service/js/..."> tag: they
 * never vendor our file, and we can ship fixes without asking them to redeploy.
 * A <script src> does not require CORS headers, so nothing special is needed
 * here beyond serving the file.
 */
app.use(
  '/js',
  express.static(path.join(__dirname, '..', 'public', 'js'), {
    setHeaders(res) {
      res.set('Cache-Control', 'public, max-age=300');
      res.set('Access-Control-Allow-Origin', '*'); // harmless, helps `crossorigin` users
    },
  })
);

/** Crude fixed-window limiter. Real deployment: use a shared store. */
const hits = new Map();
function rateLimit(max, windowMs) {
  return (req, res, next) => {
    const key = req.ip || 'anon';
    const now = Date.now();
    const rec = hits.get(key);
    if (!rec || now > rec.resetAt) {
      hits.set(key, { count: 1, resetAt: now + windowMs });
      return next();
    }
    if (++rec.count > max) {
      return res.status(429).json({ error: 'rate limited' });
    }
    next();
  };
}

// Without this the map keeps one entry per client IP forever, which on a news
// site is a slow memory leak rather than a rate limiter.
setInterval(() => {
  const now = Date.now();
  for (const [key, rec] of hits) if (now > rec.resetAt) hits.delete(key);
}, 60_000).unref();

function articleUrl(articleId) {
  return ARTICLE_URL_TEMPLATE.replace('{id}', encodeURIComponent(articleId));
}

/**
 * Hosts a QR is allowed to point at. Defaults to the host in
 * ARTICLE_URL_TEMPLATE, i.e. the publisher's own site.
 *
 * The webhook accepts a destination URL from its caller. It is authenticated,
 * but a leaked webhook secret should not become a way to mint tracked links to
 * arbitrary destinations under the client's Trace-It account — that would burn
 * their quota and put their branded short domain in front of someone else's
 * content. Pin the destination to hosts we expect.
 */
const ALLOWED_DESTINATION_HOSTS = (
  process.env.TRACEIT_ALLOWED_DESTINATION_HOSTS ||
  (() => {
    try {
      return new URL(ARTICLE_URL_TEMPLATE.replace('{id}', 'x')).host;
    } catch {
      return '';
    }
  })()
)
  .split(',')
  .map((s) => s.trim().toLowerCase())
  .filter(Boolean);

function destinationAllowed(rawUrl) {
  if (!ALLOWED_DESTINATION_HOSTS.length) return true; // not configured: allow
  try {
    const u = new URL(rawUrl);
    if (u.protocol !== 'http:' && u.protocol !== 'https:') return false;
    return ALLOWED_DESTINATION_HOSTS.includes(u.host.toLowerCase());
  } catch {
    return false;
  }
}

/* --- PATH A: publish-time webhook ---------------------------------------- */

app.post('/v1/hooks/article-published', async (req, res) => {
  const auth = req.headers.authorization || '';
  if (auth !== `Bearer ${WEBHOOK_SECRET}`) {
    return res.status(401).json({ error: 'unauthorized' });
  }

  // No `title`: the Trace-It title is always the post ID. See traceit-client.js.
  const { articleId, url, imageUrl } = req.body || {};
  if (!articleId || !ARTICLE_ID_RE.test(String(articleId))) {
    return res.status(400).json({ error: 'valid articleId required' });
  }

  // Optional, and only needed for embed mode: the public thumbnail URL, so we
  // can composite the QR into it server-side. Ignored unless it is on the
  // image-host allowlist.
  const storedImageUrl =
    imageUrl && imageHostAllowed(imageUrl) ? imageUrl : undefined;

  const destinationUrl = url || articleUrl(articleId);
  if (!destinationAllowed(destinationUrl)) {
    return res.status(400).json({
      error: 'destination host not allowed',
      allowed: ALLOWED_DESTINATION_HOSTS,
    });
  }

  try {
    const before = store.get(articleId);
    const record = await store.getOrCreate(
      articleId,
      async () => {
        // Same reasoning as resolve(): a free read before a quota-charging write.
        const found = await getQrByArticleId(articleId);
        if (found) return found;
        return mintQr({ articleId, destinationUrl });
      },
      { imageUrl: storedImageUrl }
    );
    res.status(before ? 200 : 201).json({
      articleId: record.articleId,
      shortUrl: record.shortUrl,
      qrId: record.qrId,
      source: record.source,
      // Two different facts, deliberately both reported. Reporting only the
      // second told the caller "created" every time our cache was cold, which is
      // exactly the wrong signal for "did this cost quota?".
      created: record.traceItCreated === true, // Trace-It minted; quota charged
      newToUs: !before, // our cache had no record
      qrUrl: `/v1/qr/${encodeURIComponent(articleId)}.png`,
      framedUrl: record.imageUrl
        ? `/v1/framed/${encodeURIComponent(articleId)}`
        : null,
    });
  } catch (err) {
    console.error('[traceit] mint failed:', err.message);
    res.status(502).json({ error: 'mint failed', detail: err.message });
  }
});

/* --- PATH B: read (and optionally lazily create) -------------------------- */

/**
 * Resolves an article ID to a stored record, minting on miss when Path B is on.
 * Returns null if there is no record and we are not allowed to create one.
 */
async function resolve(articleId) {
  const existing = store.get(articleId);
  if (existing) return { record: existing, cached: true };
  if (!ALLOW_LAZY_MINT) return null;

  const record = await store.getOrCreate(articleId, async () => {
    /*
     * Ask Trace-It whether it already has a code for this post BEFORE creating
     * one. GET /api/v1/qr/by-post/{postId} never charges monthly quota, while
     * POST /api/v1/qr charges on first create. Our local store is only a cache;
     * it is empty after a redeploy or a cache wipe, and without this lookup
     * every such event would re-mint every article and burn quota for codes that
     * already exist.
     *
     * Trace-It's create is idempotent too, so this is belt and braces — but it
     * is the cheaper belt, and it keeps `created` honest in our own records.
     */
    const found = await getQrByArticleId(articleId);
    if (found) return found;

    return mintQr({ articleId, destinationUrl: articleUrl(articleId) });
  });

  return { record, cached: false };
}

/*
 * NOTE ON ROUTE ORDER: `/v1/qr/:articleId` also matches "/v1/qr/abc.png", so
 * the .png route has to be registered FIRST or the JSON handler answers image
 * requests with JSON. Do not reorder these two.
 */

/**
 * Raw PNG form, for `background-image: url(...)` or a plain <img src>.
 * Cheaper than the JSON form on repeat views: the browser HTTP cache handles
 * it, and no base64 inflation crosses the wire.
 */
app.get('/v1/qr/:articleId.png', rateLimit(240, 60_000), async (req, res) => {
  const { articleId } = req.params;
  if (!ARTICLE_ID_RE.test(articleId)) return res.status(400).end();

  try {
    const hit = await resolve(articleId);
    if (!hit) return res.status(404).end();

    const b64 = hit.record.pngDataUri.slice(hit.record.pngDataUri.indexOf('base64,') + 7);
    const buf = Buffer.from(b64, 'base64');
    res.set('Content-Type', 'image/png');
    res.set('Cache-Control', 'public, max-age=31536000, immutable');
    res.set('Content-Length', String(buf.length));
    res.send(buf);
  } catch (err) {
    console.error('[traceit] mint failed:', err.message);
    res.status(502).end();
  }
});

/**
 * JSON form. Returns the QR as a base64 data URI so the calling page can drop
 * it straight into a style or src with no second request.
 */
app.get('/v1/qr/:articleId', rateLimit(120, 60_000), async (req, res) => {
  const { articleId } = req.params;
  if (!ARTICLE_ID_RE.test(articleId)) {
    return res.status(400).json({ error: 'bad articleId' });
  }

  try {
    const hit = await resolve(articleId);
    if (!hit) {
      // Webhook-only mode: an unknown ID means the CMS never told us about it.
      return res.status(404).json({ error: 'no code for that articleId' });
    }
    res.set('Cache-Control', 'public, max-age=86400');
    res.json(publicView(hit.record, hit.cached));
  } catch (err) {
    console.error('[traceit] mint failed:', err.message);
    res.status(502).json({ error: 'mint failed', detail: err.message });
  }
});

/* --- EMBED MODE: the QR baked into the image pixels ----------------------- */

/**
 * GET /v1/framed/:articleId  ->  the publisher's thumbnail with the QR IN IT.
 *
 * This is the endpoint that makes the browser's native "Save image as…" produce
 * a QR-embedded file. There is no JavaScript trick involved and none is
 * possible: save-as writes the bytes of whatever the <img> is displaying, so the
 * only way to get a QR into the saved file is for the QR to be part of the
 * image. It is, here, because we composited it.
 *
 * Right-click → Copy image, drag-to-desktop, and printing all get the QR too,
 * for the same reason. So does a social crawler, if the publisher points
 * og:image at this URL.
 *
 * The source image URL comes from our own store (set by the publish webhook),
 * or from a ?src= parameter for the no-webhook path. Either way it is checked
 * against the image-host allowlist in compositor.js — a URL parameter that
 * causes a server-side fetch is an SSRF hole unless it is pinned to known hosts.
 */
app.get('/v1/framed/:articleId', rateLimit(240, 60_000), async (req, res) => {
  // Tolerate an extension so the URL can end in .jpg for tidier saved filenames.
  const articleId = String(req.params.articleId).replace(/\.(jpe?g|png)$/i, '');
  if (!ARTICLE_ID_RE.test(articleId)) return res.status(400).end();

  let record;
  try {
    const hit = await resolve(articleId);
    if (!hit) return res.status(404).end();
    record = hit.record;
  } catch (err) {
    console.error('[traceit] mint failed:', err.message);
    return res.status(502).end();
  }

  const imageUrl = req.query.src || record.imageUrl;
  if (!imageUrl) {
    return res.status(400).json({
      error: 'no source image known for this article',
      hint: 'send imageUrl in the publish webhook, or pass ?src=<public image url>',
    });
  }

  if (!compositor.imageHostAllowed(imageUrl)) {
    return res.status(403).json({
      error: 'source image host not allowed',
      allowed: compositor.ALLOWED_IMAGE_HOSTS,
    });
  }

  // Remember a src that arrived by query string, so later requests need only
  // the ID and the URL stops being client-controlled.
  if (req.query.src && !record.imageUrl) {
    store.setImageUrl(articleId, String(req.query.src));
  }

  try {
    const out = await compositor.compositeCached({
      articleId,
      imageUrl: String(imageUrl),
      qrPngDataUri: record.pngDataUri,
      layout: layoutFromQuery(req.query),
      // Badge-design version from the client (BADGE_VERSION in the overlay
      // script). Part of the cache key so a bump yields a new URL AND a new
      // cache entry, instead of serving the previous design back.
      version: String(req.query.v || '0'),
    });

    const ext = out.mime === 'image/png' ? 'png' : 'jpg';
    res.set('Content-Type', out.mime);
    res.set('Content-Length', String(out.buf.length));
    // Long-lived: the composite for a given article never changes.
    res.set('Cache-Control', 'public, max-age=31536000, immutable');
    // Gives save-as a meaningful default filename instead of a random string.
    res.set('Content-Disposition', `inline; filename="article-${articleId}-qr.${ext}"`);
    res.set('X-TraceIt-Cache', out.cached ? 'hit' : 'miss');
    res.set('X-TraceIt-Badge', out.badge ? 'embedded' : `absent (${out.note || 'unknown'})`);
    res.set('X-Content-Type-Options', 'nosniff');
    res.send(out.buf);
  } catch (err) {
    console.error('[traceit] composite failed:', err.message);
    res.status(502).json({ error: 'composite failed', detail: err.message });
  }
});

/** Per-request layout overrides, so a publisher can tune placement per template. */
function layoutFromQuery(q) {
  const out = {};
  const num = (v) => (v === undefined ? undefined : Number(v));
  if (q.corner) out.corner = String(q.corner);
  if (Number.isFinite(num(q.scale))) out.scale = num(q.scale);
  if (Number.isFinite(num(q.min))) out.minPx = num(q.min);
  if (Number.isFinite(num(q.max))) out.maxPx = num(q.max);
  return out;
}

/* --- Demo introspection --------------------------------------------------- */

app.get('/v1/codes', (_req, res) => {
  res.json({
    mode: MODE,
    lazyMint: ALLOW_LAZY_MINT,
    count: store.all().length,
    codes: store.all().map((r) => publicView(r, true)),
  });
});

app.get('/v1/health', (_req, res) =>
  res.json({ ok: true, mode: MODE, lazyMint: ALLOW_LAZY_MINT, allowedOrigins: ALLOWED_ORIGINS })
);

function publicView(record, cached) {
  return {
    articleId: record.articleId,
    shortUrl: record.shortUrl,
    source: record.source,
    createdAt: record.createdAt,
    pngDataUri: record.pngDataUri,
    pngUrl: `/v1/qr/${encodeURIComponent(record.articleId)}.png`,
    cached,
  };
}

if (require.main === module) {
  app.listen(PORT, () => {
    console.log(`  [trace-it] our service  → http://localhost:${PORT}`);
    console.log(`             QR source: ${MODE === 'live' ? 'live Trace-It API' : 'locally generated (no API key set)'}`);
    console.log(`             lazy mint: ${ALLOW_LAZY_MINT ? 'on (Path B enabled)' : 'off (webhook only)'}`);
  });
}

module.exports = app;
