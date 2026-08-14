/*
 * publisher-site.js — the PUBLISHER's site. Runs on :3000.
 * ===========================================================================
 * Stands in for the client's custom PHP news CMS. Everything in this file is
 * code THEY own; we do not deploy it and, in production, we cannot read it.
 * It exists so the demo has a realistic thing to integrate against.
 *
 * The entire integration surface on their side is one HTTP call, in
 * notifyTraceIt() below: when an article is published, POST its ID and public
 * URL to our service. That is the "capture the ID" step. Note what is NOT sent
 * — no article body, no image bytes, no S3 credentials, no database access.
 * Just the ID and a URL that is already public.
 *
 * Their images are served from the simulated S3 origin on :3001, so the article
 * pages here load thumbnails cross-origin exactly as production does.
 * ===========================================================================
 */

'use strict';

const fs = require('fs');
const path = require('path');
const express = require('express');

const app = express();
const PORT = process.env.PORT || 3000;

const S3_BASE = process.env.S3_BASE || 'http://localhost:3001/media';
const TRACEIT_SERVICE = process.env.TRACEIT_SERVICE || 'http://localhost:3002';
const WEBHOOK_SECRET = process.env.TRACEIT_WEBHOOK_SECRET || 'dev-webhook-secret';
const PUBLIC_BASE = process.env.PUBLIC_BASE || `http://localhost:${PORT}`;

const DATA_DIR = process.env.TRACEIT_DATA_DIR || path.join(__dirname, '..', 'data');
const ARTICLES_FILE = path.join(DATA_DIR, 'publisher-articles.json');

app.use(express.json({ limit: '256kb' }));

// index:false matters. The public/ directory still contains index.html from the
// parked composite prototype, and express.static would otherwise serve it for
// "/" before the explicit route below ever runs — silently showing the wrong
// demo. It stays reachable at /index.html on purpose.
app.use(express.static(path.join(__dirname, '..', 'public'), { index: false }));

/* --- The publisher's own article storage --------------------------------- */

function loadArticles() {
  try {
    return JSON.parse(fs.readFileSync(ARTICLES_FILE, 'utf8'));
  } catch {
    // Seed from the bundled fixtures on first boot, rewriting the local
    // /assets/... paths onto the simulated S3 origin so that every article page
    // loads its thumbnail cross-origin, as production does.
    const seed = require('./articles.json').map((a) => ({
      ...a,
      thumb: thumbUrl(path.basename(a.thumb)),
    }));
    saveArticles(seed);
    return seed;
  }
}

function saveArticles(list) {
  fs.mkdirSync(DATA_DIR, { recursive: true });
  const tmp = `${ARTICLES_FILE}.${process.pid}.tmp`;
  fs.writeFileSync(tmp, JSON.stringify(list, null, 2));
  fs.renameSync(tmp, ARTICLES_FILE);
}

let articles = loadArticles();

/** Mirrors the ID format seen on the live site, e.g. 108-347979. */
function nextArticleId() {
  const n = 100000 + Math.floor(Math.random() * 900000);
  return `108-${n}`;
}

/** Their thumbnails live on S3, so the URL is absolute and cross-origin. */
function thumbUrl(file) {
  return `${S3_BASE}/${file}`;
}

/* --- THE INTEGRATION: one call, at publish time -------------------------- */

/**
 * Tells our Trace-It service that an article went live.
 *
 * This is the whole of the publisher-side integration — roughly ten lines of
 * PHP in their real publish hook. It is fire-and-forget: if our service is
 * down, publishing must still succeed, so failures are logged and swallowed.
 * The article's QR will then be created on first page view instead (Path B),
 * or on the next retry, so nothing is permanently lost.
 */
async function notifyTraceIt(article) {
  const body = {
    articleId: article.id,
    url: `${PUBLIC_BASE}/article/${article.id}`,
    title: article.headline,
    // Only needed for embed mode (QR baked into the pixels so save-as includes
    // it). This is the same public S3 URL every reader's browser fetches — not
    // privileged data, and we still send no credentials and no article content.
    imageUrl: article.thumb,
  };

  try {
    const res = await fetch(`${TRACEIT_SERVICE}/v1/hooks/article-published`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${WEBHOOK_SECRET}`,
      },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(8000),
    });
    const payload = await res.json().catch(() => ({}));
    if (!res.ok) {
      console.warn(`  [cms] Trace-It webhook returned ${res.status}:`, payload);
      return { ok: false, status: res.status, payload };
    }
    console.log(`  [cms] Trace-It notified for ${article.id} (created=${payload.created})`);
    return { ok: true, status: res.status, payload };
  } catch (err) {
    console.warn('  [cms] Trace-It webhook unreachable:', err.message);
    return { ok: false, error: err.message };
  }
}

/* --- Their API ------------------------------------------------------------ */

app.get('/api/articles', (_req, res) => res.json(articles));

app.get('/api/config', (_req, res) =>
  res.json({ traceItService: TRACEIT_SERVICE, s3Base: S3_BASE, publicBase: PUBLIC_BASE })
);

/** Mock publish. In their CMS this is the editor clicking "Publish". */
app.post('/api/publish', async (req, res) => {
  const { headline, section, byline, image } = req.body || {};
  if (!headline || !String(headline).trim()) {
    return res.status(400).json({ error: 'headline required' });
  }

  const id = nextArticleId();
  const article = {
    id,
    section: section || 'Breaking News',
    headline: String(headline).trim(),
    byline: byline || 'Staff Reporter',
    dateline: 'Colombo (Island Chronicle)',
    published: new Date().toISOString(),
    thumb: thumbUrl(image || 'article-1.jpg'),
    caption: String(headline).trim(),
    body: [
      'This article was created by the demo publish flow to show the article ID ' +
        'being captured at publish time and turned into a Trace-It code.',
      'Scroll down to the thumbnail: the QR code sitting inside the image frame ' +
        'was fetched from the Trace-It service using only this article’s ID.',
    ],
  };

  articles = [article, ...articles];
  saveArticles(articles);

  // Capture the ID -> hand it to Trace-It.
  const hook = await notifyTraceIt(article);

  res.status(201).json({ article, traceIt: hook });
});

/* --- Article pages -------------------------------------------------------- */

/** Where a QR scan lands. Serves the same SPA shell; the id is read client-side. */
app.get('/article/:id', (_req, res) => {
  res.sendFile(path.join(__dirname, '..', 'public', 'article.html'));
});

app.get('/api/article/:id', (req, res) => {
  const found = articles.find((a) => a.id === req.params.id);
  if (!found) return res.status(404).json({ error: 'not found' });
  res.json(found);
});

app.get('/cms', (_req, res) => {
  res.sendFile(path.join(__dirname, '..', 'public', 'cms.html'));
});

app.get('/', (_req, res) => {
  res.sendFile(path.join(__dirname, '..', 'public', 'home.html'));
});

if (require.main === module) {
  app.listen(PORT, () => {
    console.log(`  [cms]      publisher site → ${PUBLIC_BASE}`);
    console.log(`             newsroom (publish flow) → ${PUBLIC_BASE}/cms`);
  });
}

module.exports = app;
