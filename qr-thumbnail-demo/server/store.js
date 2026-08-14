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
 * Returns the QR record for an article, minting it on first request only.
 *
 * @param {string}   articleId
 * @param {function} mint  async () => { qrId, shortUrl, pngDataUri, source }
 */
async function getOrCreate(articleId, mint) {
  const existing = get(articleId);
  if (existing) return existing;

  // Someone else is already minting this exact article — wait for their result
  // instead of firing a second identical request at Trace-It.
  if (inFlight.has(articleId)) return inFlight.get(articleId);

  const work = (async () => {
    const minted = await mint();
    const record = {
      articleId,
      qrId: minted.qrId,
      shortUrl: minted.shortUrl,
      pngDataUri: minted.pngDataUri,
      source: minted.source,
      createdAt: new Date().toISOString(),
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

module.exports = { get, getOrCreate, all, remove, reset, DB_FILE };
