/*
 * store.js — the articleId -> QR mapping, held on OUR side.
 *
 * This file is the answer to the access constraint. We have no read or write
 * permission on the publisher's database or their S3 bucket; the only thing we
 * are given is the article ID. So the mapping
 *
 *     publisher's article ID  ->  { Trace-It code, short URL, QR PNG }
 *
 * lives here, in our own storage, keyed by their ID. Nothing is ever written
 * back to them, and nothing of theirs is read. Their ID is used purely as a
 * lookup key.
 *
 * A flat JSON file is obviously not the production store — swap this module for
 * Postgres/Redis and the rest of the demo is unaffected. What matters is the
 * behaviour it implements:
 *
 *   1. Mint once per article, ever. QR creation counts against the monthly
 *      quota, so a cache miss must be the only thing that calls Trace-It.
 *   2. Deduplicate concurrent first-views. When an article goes live and 500
 *      readers hit it in the same second, that must be ONE mint, not 500.
 *      `inFlight` below is what guarantees that.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const DATA_DIR = process.env.TRACEIT_DATA_DIR || path.join(__dirname, '..', 'data');
const DB_FILE = path.join(DATA_DIR, 'qr-store.json');

/** articleId -> Promise<record>, for mints currently in progress. */
const inFlight = new Map();

let db = load();

function load() {
  try {
    return JSON.parse(fs.readFileSync(DB_FILE, 'utf8'));
  } catch {
    return {};
  }
}

function persist() {
  fs.mkdirSync(DATA_DIR, { recursive: true });
  // Write-then-rename so a crash mid-write cannot leave a truncated file that
  // would be silently treated as an empty store on next boot.
  const tmp = `${DB_FILE}.${process.pid}.tmp`;
  fs.writeFileSync(tmp, JSON.stringify(db, null, 2));
  fs.renameSync(tmp, DB_FILE);
}

function get(articleId) {
  return db[articleId] || null;
}

function all() {
  return Object.values(db).sort((a, b) => b.createdAt.localeCompare(a.createdAt));
}

/**
 * Records the article's public thumbnail URL against its ID.
 *
 * Needed only for server-side compositing (the "embed" mode), which has to know
 * which image to draw the QR into. It is a public URL, not privileged data —
 * the same URL every reader's browser already requests.
 *
 * Returns false if there is no record yet, so the caller can decide whether to
 * mint first.
 */
function setImageUrl(articleId, imageUrl) {
  const rec = db[articleId];
  if (!rec) return false;
  if (rec.imageUrl === imageUrl) return true; // no write, no disk churn
  rec.imageUrl = imageUrl;
  persist();
  return true;
}

/**
 * Returns the QR record for an article, minting it on first request only.
 *
 * @param {string}   articleId
 * @param {function} mint   async () => { qrId, shortUrl, pngDataUri, source }
 * @param {object}  [extra] extra fields to store alongside, e.g. { imageUrl }
 */
async function getOrCreate(articleId, mint, extra) {
  const existing = get(articleId);
  if (existing) {
    // A later call may know the image URL when the first one did not.
    if (extra && extra.imageUrl && !existing.imageUrl) {
      setImageUrl(articleId, extra.imageUrl);
    }
    return existing;
  }

  // Someone else is already minting this exact article — wait for their result
  // instead of firing a second identical request at Trace-It.
  if (inFlight.has(articleId)) return inFlight.get(articleId);

  const work = (async () => {
    const minted = await mint();
    const record = {
      articleId,
      // Trace-It's own id, {tenantPrefix}-{postId}. Kept for support questions;
      // nothing here addresses a code by it, since postId is enough.
      qrId: minted.qrId,
      postId: minted.postId ?? null,
      // Where a scan actually goes: the Trace-It short link, which redirects and
      // attributes the visit. This is what the QR encodes.
      shortUrl: minted.shortUrl,
      // The "Original Source" button on the Trace-It landing page.
      targetUrl: minted.targetUrl ?? null,
      // Trace-It's durable public PNG. Held so we can re-fetch without spending
      // a lookup, and so the compositor has a URL it can use directly.
      pngUrl: minted.pngUrl ?? null,
      pngDataUri: minted.pngDataUri,
      // Did Trace-It actually mint (and charge one unit of monthly quota), or did
      // we adopt a code that already existed? Distinct from our cache being cold.
      traceItCreated: minted.created === true,
      source: minted.source,
      createdAt: new Date().toISOString(),
      ...(extra && extra.imageUrl ? { imageUrl: extra.imageUrl } : {}),
    };
    db[articleId] = record;
    persist();
    return record;
  })();

  inFlight.set(articleId, work);
  try {
    return await work;
  } finally {
    // Clear on failure too, so a transient Trace-It outage does not poison the
    // key with a permanently rejected promise.
    inFlight.delete(articleId);
  }
}

function remove(articleId) {
  if (!(articleId in db)) return false;
  delete db[articleId];
  persist();
  return true;
}

function reset() {
  db = {};
  persist();
}

module.exports = { get, getOrCreate, setImageUrl, all, remove, reset, DB_FILE };
