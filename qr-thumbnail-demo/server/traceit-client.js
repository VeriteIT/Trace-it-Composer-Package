/*
 * traceit-client.js
 * ===========================================================================
 * THE ONLY FILE IN THIS PROJECT THAT KNOWS THE TRACE-IT API SHAPE.
 *
 * VERIFIED against the Trace-It source (Next.js app, src/app/api/v1/qr/*,
 * src/lib/api-qr.ts) and its client-facing doc (public/docs/api.html). Earlier
 * revisions of this file guessed at the contract and guessed wrong — it sent
 * `sourceUrls`/`name`/`reference`, none of which exist. The real shape:
 *
 *   Base URL   https://<tenant-subdomain>.trace-it.io/api/v1
 *   Auth       Authorization: Bearer sk_live_…
 *
 *   POST /api/v1/qr                       create or refresh, IDEMPOTENT
 *     { postId, title?, targetUrl?, folder?, followUpPages? }
 *     -> 201 { …, created: true }   first publish, charges one quota unit
 *     -> 200 { …, created: false }  every later publish, no quota charged
 *
 *   GET /api/v1/qr/by-post/{postId}       read, NEVER charges quota
 *     -> 200 { …, qr: { pngUrl, png: '' } }
 *     -> 404 { error: { code: 'not_found', … } }
 *
 *   Both return:
 *     { id, postId, shortUrl, title, folder, targetUrl, followUpPages,
 *       type: 'verified_content', createdAt, qr: { pngUrl, png } }
 *
 *   Errors are { error: { code, message } } with a meaningful HTTP status.
 *
 * THREE THINGS WORTH KNOWING
 *
 *   1. `qr.png` (a base64 data URI) is populated ONLY when created is true. An
 *      update does not re-render the image, because the QR encodes shortUrl and
 *      that has not changed. Every other time the field is an empty string and
 *      you must use `qr.pngUrl`. Code that assumes `qr.png` is always there
 *      works on first publish and silently breaks on the second.
 *
 *   2. `qr.pngUrl` is a durable, PUBLIC, hosted 1024px PNG (error correction
 *      level H). No auth is needed to load it, which is why the browser can
 *      point an <img> straight at it and why our compositor can fetch it
 *      server-side without credentials.
 *
 *   3. by-post is server-to-server only — it takes the secret key. It must never
 *      be called from the browser. Reads are rate-limited but free of quota, so
 *      it is the authority and our own store is just a cache in front of it.
 *
 * The QR's Trace-It id is `{tenantPrefix}-{postId}`, where the prefix is
 * assigned to the tenant on their first API call. We never need to store it:
 * everything is addressable by the publisher's own postId.
 * ===========================================================================
 */

'use strict';

const QRCode = require('qrcode');

const TRACEIT_BASE = process.env.TRACEIT_BASE || 'https://demo.trace-it.io';
const TRACEIT_KEY = process.env.TRACEIT_API_KEY || '';
const TRACEIT_FOLDER = process.env.TRACEIT_FOLDER || 'Newsroom thumbnails';

const TIMEOUT_MS = 15000;

/*
 * Hostnames that ship as placeholders in this repo. A bearer token must never be
 * sent to one of them: they are not known to belong to Trace-It, and the
 * Authorization header goes out on the very first request — before any response
 * could tell us we guessed wrong. Handing a live credential to whoever happens
 * to own a guessed domain is not a recoverable mistake.
 *
 * A real base URL looks like https://<tenant-subdomain>.trace-it.io
 */
const PLACEHOLDER_HOSTS = new Set([
  'demo.trace-it.io',
  'your-subdomain.trace-it.io',
  'traceit.example.com',
  'example.com',
]);

function baseIsConfigured(base) {
  try {
    const host = new URL(base).hostname.toLowerCase();
    if (!host || host.includes('<') || host.includes('>')) return false;
    return !PLACEHOLDER_HOSTS.has(host);
  } catch {
    return false;
  }
}

const BASE_OK = baseIsConfigured(TRACEIT_BASE);

// A key against a placeholder base is treated as "no key": generate locally
// rather than send the credential somewhere unverified.
const MODE = TRACEIT_KEY && BASE_OK ? 'live' : 'local';

if (TRACEIT_KEY && !BASE_OK) {
  console.warn(
    `\n  [traceit] TRACEIT_API_KEY is set but TRACEIT_BASE is "${TRACEIT_BASE}", which is a\n` +
      '            placeholder. Refusing to send the key there. Set TRACEIT_BASE to the\n' +
      '            tenant subdomain (https://<subdomain>.trace-it.io) to enable live\n' +
      '            minting; generating QR codes locally for now.\n'
  );
}

/**
 * Trace-It's postId rules, copied from sanitizePostId() in src/lib/api-qr.ts:
 * letters, digits, underscore and hyphen; must start and end alphanumeric;
 * lowercased (so post IDs are case-insensitive); 48 characters max.
 *
 * Note what is NOT allowed: dots. Validating here rather than letting the API
 * reject it turns a confusing 400 invalid_post_id from a third party into a
 * local error naming the offending value.
 */
const MAX_POST_ID_LENGTH = 48;
const POST_ID_RE = /^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/;

/** @returns {{ok: true, postId: string} | {ok: false, reason: string}} */
function normalisePostId(raw) {
  if (raw === null || raw === undefined || raw === '') {
    return { ok: false, reason: 'postId is required' };
  }
  const trimmed = String(raw).trim();
  if (!trimmed) return { ok: false, reason: 'postId is required' };
  if (trimmed.length > MAX_POST_ID_LENGTH) {
    return {
      ok: false,
      reason: `postId must be ${MAX_POST_ID_LENGTH} characters or fewer (got ${trimmed.length})`,
    };
  }
  // Trace-It lowercases, so we do too — otherwise our cache key and theirs drift.
  const postId = trimmed.toLowerCase();
  if (!POST_ID_RE.test(postId)) {
    return {
      ok: false,
      reason:
        `postId "${trimmed}" is not valid: only letters, digits, underscore and ` +
        'hyphen, and it must start and end with a letter or digit',
    };
  }
  return { ok: true, postId };
}

/** Quiet zone matters: a QR with no margin is much harder for phones to lock on. */
const LOCAL_QR_OPTS = {
  // H to match what Trace-It renders, so the local stand-in behaves like the
  // real thing when scanned off a printed page.
  errorCorrectionLevel: 'H',
  margin: 2,
  width: 1024,
  color: { dark: '#000000ff', light: '#ffffffff' },
};

function authHeaders() {
  return {
    Authorization: `Bearer ${TRACEIT_KEY}`,
    Accept: 'application/json',
  };
}

/** Turns Trace-It's { error: { code, message } } into a useful Error. */
async function toError(res, what) {
  let detail = `HTTP ${res.status}`;
  try {
    const body = await res.json();
    if (body?.error?.code) {
      detail = `${body.error.code}: ${body.error.message || ''} (HTTP ${res.status})`;
    }
  } catch {
    /* non-JSON body; the status is all we have */
  }
  const err = new Error(`Trace-It ${what} failed — ${detail}`);
  err.status = res.status;
  return err;
}

/**
 * Normalises a Trace-It response into what the rest of this project consumes.
 * The important bit is resolving the PNG: `qr.png` is only present on creation,
 * so fall back to fetching the public `qr.pngUrl`.
 */
async function toRecord(payload) {
  let pngDataUri = payload?.qr?.png || null;

  if (!pngDataUri && payload?.qr?.pngUrl) {
    pngDataUri = await fetchAsDataUri(payload.qr.pngUrl);
  }
  if (!pngDataUri) {
    throw new Error('Trace-It response contained no QR image');
  }

  return {
    qrId: payload.id ?? null,
    postId: payload.postId ?? null,
    shortUrl: payload.shortUrl ?? null,
    targetUrl: payload.targetUrl ?? null,
    pngUrl: payload?.qr?.pngUrl ?? null,
    pngDataUri,
    created: payload.created === true,
    source: 'trace-it',
  };
}

/**
 * Creates or refreshes the Trace-It code for one published article.
 *
 * Idempotent on Trace-It's side: safe to call on every publish, and only the
 * first call for a postId costs quota. That means we do not have to be clever
 * about avoiding duplicate publishes — though our own store still avoids the
 * round trip entirely.
 *
 * @param {object}  a
 * @param {string}  a.articleId       The publisher's own article ID -> postId.
 * @param {string} [a.destinationUrl] Becomes targetUrl: the "Original Source"
 *                                    button on the Trace-It landing page. The QR
 *                                    itself always encodes shortUrl, not this.
 * @param {string[]} [a.followUpPages]
 *
 * Note there is no `title` parameter: the Trace-It title is always the post ID.
 * See the body construction below for why.
 */
async function mintQr({ articleId, destinationUrl, followUpPages }) {
  const id = normalisePostId(articleId);
  if (!id.ok) throw new Error(id.reason);

  if (MODE === 'local') {
    // Local stand-in encodes the destination directly. A real Trace-It code
    // encodes its shortUrl and redirects, so the scan is attributed; this one is
    // not tracked. Everything else about the flow is identical.
    return {
      qrId: null,
      postId: id.postId,
      shortUrl: null,
      targetUrl: destinationUrl || null,
      pngUrl: null,
      pngDataUri: await QRCode.toDataURL(destinationUrl || id.postId, LOCAL_QR_OPTS),
      created: true,
      source: 'local',
    };
  }

  /*
   * `title` is the POST ID, not the headline.
   *
   * It is what names the code in the Trace-It dashboard, and naming by post ID
   * means a row there maps to a CMS post without anyone having to match wording.
   * Headlines also get edited after publishing, and Trace-It treats a present
   * `title` as an update, so sending the headline would rewrite the name on every
   * re-publish. The post ID never changes.
   *
   * Left unsent, Trace-It derives a title from the target URL's host or falls
   * back to "Post <postId>" — close to this, but not stable when targetUrl is
   * present. Sending it explicitly is deterministic.
   */
  const body = { postId: id.postId, title: id.postId };

  /*
   * targetUrl MUST be https — Trace-It rejects anything else with
   * 400 invalid_target_url (see normaliseTargetUrl in src/lib/api-qr.ts).
   *
   * It is also optional: omit it and the landing page shows branding, the
   * verification statement and the published date, with no "Original Source"
   * button. So an http URL is a reason to drop the field, not to fail the mint —
   * the QR still works, because it encodes shortUrl, not targetUrl.
   *
   * This matters mainly in development: the demo runs on http://localhost, so
   * live mode here mints codes with no target. Production article URLs are https,
   * where the field goes through normally.
   */
  if (destinationUrl) {
    if (/^https:/i.test(destinationUrl)) {
      body.targetUrl = destinationUrl;
    } else {
      console.warn(
        `  [traceit] targetUrl "${destinationUrl}" is not https — Trace-It only accepts\n` +
          '            https, so it is being omitted. The QR still works and still\n' +
          '            tracks; the landing page just has no "Original Source" button.'
      );
    }
  }

  if (TRACEIT_FOLDER) body.folder = TRACEIT_FOLDER;
  if (Array.isArray(followUpPages) && followUpPages.length) body.followUpPages = followUpPages;

  const res = await fetch(`${TRACEIT_BASE.replace(/\/+$/, '')}/api/v1/qr`, {
    method: 'POST',
    headers: { ...authHeaders(), 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
    signal: AbortSignal.timeout(TIMEOUT_MS),
  });

  if (!res.ok) throw await toError(res, 'create');

  return toRecord(await res.json());
}

/**
 * Looks up an existing code by the publisher's own article ID.
 *
 * Free of monthly quota (still rate-limited), so this is the cheap path and the
 * authority. Returns null when Trace-It has no code for that post.
 *
 * SERVER-TO-SERVER ONLY — it sends the secret key. Never call from a browser.
 */
async function getQrByArticleId(articleId) {
  const id = normalisePostId(articleId);
  if (!id.ok) throw new Error(id.reason);

  if (MODE === 'local') return null; // nothing remote to look up

  const url =
    `${TRACEIT_BASE.replace(/\/+$/, '')}/api/v1/qr/by-post/` + encodeURIComponent(id.postId);

  const res = await fetch(url, {
    headers: authHeaders(),
    signal: AbortSignal.timeout(TIMEOUT_MS),
  });

  if (res.status === 404) return null;
  if (!res.ok) throw await toError(res, 'lookup');

  return toRecord(await res.json());
}

async function fetchAsDataUri(url) {
  // qr.pngUrl is public, so this deliberately sends no Authorization header.
  const res = await fetch(url, { signal: AbortSignal.timeout(TIMEOUT_MS) });
  if (!res.ok) throw new Error(`QR image fetch failed: HTTP ${res.status}`);
  const type = res.headers.get('content-type') || 'image/png';
  if (!type.startsWith('image/')) throw new Error(`QR image had type ${type}`);
  const b64 = Buffer.from(await res.arrayBuffer()).toString('base64');
  return `data:${type};base64,${b64}`;
}

module.exports = {
  mintQr,
  getQrByArticleId,
  normalisePostId,
  MODE,
  TRACEIT_BASE,
  MAX_POST_ID_LENGTH,
  POST_ID_RE,
};
