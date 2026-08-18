/*
 * traceit-client.js
 * ===========================================================================
 * THE ONLY FILE IN THIS PROJECT THAT KNOWS THE TRACE-IT API SHAPE.
 *
 * >>> READ THIS BEFORE SHIPPING <<<
 * The Trace-It repo (github.com/VeriteIT/Trace-It) is private, so the request
 * and response shapes below could not be verified against it. They are an
 * ASSUMPTION. Everything else in this demo talks to Trace-It only through the
 * two functions exported here, so correcting the contract is a change to this
 * file alone — no other file needs to be touched.
 *
 * Assumed contract:
 *   POST {TRACEIT_BASE}/api/v1/qr
 *     Authorization: Bearer {TRACEIT_API_KEY}
 *     { sourceUrls: [string], name: string, folder: string, reference: string }
 *   ->  { id, shortUrl, qr: { png: "data:image/png;base64,...", pngUrl } }
 *
 * The `reference` field is the important one for this integration: it carries
 * the publisher's article ID so a Trace-It code can be looked up later by the
 * publisher's own identifier. If the real API has no such field, drop it — this
 * demo keeps its own articleId -> qrId map in store.js precisely so that the
 * integration does not depend on Trace-It storing the publisher's ID.
 *
 * LOCAL MODE
 * Without TRACEIT_API_KEY the client generates a real, scannable QR locally
 * with the `qrcode` package. Those codes genuinely encode the destination URL,
 * so you can scan them with a phone and watch the redirect. They are not
 * tracked links — no Trace-It account is involved — but every other part of
 * the integration behaves identically, which is what makes the demo testable
 * offline.
 * ===========================================================================
 */

'use strict';

const QRCode = require('qrcode');

const TRACEIT_BASE = process.env.TRACEIT_BASE || 'https://demo.trace-it.io';
const TRACEIT_KEY = process.env.TRACEIT_API_KEY || '';
const TRACEIT_FOLDER = process.env.TRACEIT_FOLDER || 'Newsroom thumbnails';

/*
 * Hostnames that ship as placeholders in this repo. A bearer token must never be
 * sent to one of them: they are not known to belong to Trace-It, and the
 * Authorization header goes out on the very first request — before any response
 * could tell us we guessed wrong. Handing a live credential to whoever happens
 * to own a guessed domain is not a recoverable mistake.
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
      '            placeholder. Refusing to send the key there. Set TRACEIT_BASE to the real\n' +
      '            Trace-It host to enable live minting; generating QR codes locally for now.\n'
  );
}

/** Quiet zone matters: a QR with no margin is much harder for phones to lock on. */
const LOCAL_QR_OPTS = {
  errorCorrectionLevel: 'M',
  margin: 2,
  width: 600,
  color: { dark: '#000000ff', light: '#ffffffff' },
};

/**
 * Mints (or re-mints) a Trace-It code for one published article.
 *
 * @param {object}  a
 * @param {string}  a.articleId       Publisher's own article ID. The only piece
 *                                    of publisher data this integration needs.
 * @param {string}  a.destinationUrl  Where the QR should send a scanner.
 * @param {string} [a.title]          Human label, for the Trace-It dashboard.
 * @returns {Promise<{qrId:string|null, shortUrl:string|null,
 *                    pngDataUri:string, source:'trace-it'|'local'}>}
 */
async function mintQr({ articleId, destinationUrl, title }) {
  if (!articleId) throw new Error('articleId is required');
  if (!destinationUrl) throw new Error('destinationUrl is required');

  if (MODE === 'local') {
    return {
      qrId: null,
      shortUrl: null,
      pngDataUri: await QRCode.toDataURL(destinationUrl, LOCAL_QR_OPTS),
      source: 'local',
    };
  }

  const res = await fetch(`${TRACEIT_BASE}/api/v1/qr`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${TRACEIT_KEY}`,
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify({
      sourceUrls: [destinationUrl],
      name: title || destinationUrl,
      folder: TRACEIT_FOLDER,
      reference: articleId,
    }),
    signal: AbortSignal.timeout(15000),
  });

  const payload = await res.json().catch(() => null);
  if (!res.ok) {
    const detail = payload ? JSON.stringify(payload) : `HTTP ${res.status}`;
    throw new Error(`Trace-It mint failed: ${detail}`);
  }

  // Prefer the inline data URI. Serving the QR as base64 from our own origin
  // means the publisher's page makes exactly one cross-origin request (to us),
  // and none to a third QR host.
  let pngDataUri = payload?.qr?.png || null;

  if (!pngDataUri && payload?.qr?.pngUrl) {
    pngDataUri = await fetchAsDataUri(payload.qr.pngUrl);
  }
  if (!pngDataUri) {
    throw new Error('Trace-It response contained no QR image');
  }

  return {
    qrId: payload.id ?? null,
    shortUrl: payload.shortUrl ?? null,
    pngDataUri,
    source: 'trace-it',
  };
}

async function fetchAsDataUri(url) {
  const res = await fetch(url, { signal: AbortSignal.timeout(15000) });
  if (!res.ok) throw new Error(`QR image fetch failed: HTTP ${res.status}`);
  const type = res.headers.get('content-type') || 'image/png';
  if (!type.startsWith('image/')) throw new Error(`QR image had type ${type}`);
  const b64 = Buffer.from(await res.arrayBuffer()).toString('base64');
  return `data:${type};base64,${b64}`;
}

module.exports = { mintQr, MODE, TRACEIT_BASE };
