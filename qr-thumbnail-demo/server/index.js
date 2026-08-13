const path = require('path');
const express = require('express');
const fs = require('fs');

const app = express();
const PORT = process.env.PORT || 3000;

// Trace-It credentials stay server-side. Without them the demo falls back to a
// bundled sample QR so the compositing story is still demonstrable offline.
const TRACEIT_BASE = process.env.TRACEIT_BASE || 'https://demo.trace-it.io';
const TRACEIT_KEY = process.env.TRACEIT_API_KEY || '';

const SAMPLE_QR = path.join(__dirname, '..', 'public', 'assets', 'sample-qr.png');

app.use(express.json());
app.use(express.static(path.join(__dirname, '..', 'public')));

const articles = require('./articles.json');

app.get('/api/articles', (_req, res) => res.json(articles));

/**
 * Same-origin image proxy.
 *
 * This is the crux of the whole proof of concept. Daily Mirror thumbnails are
 * served from Oracle Object Storage — a different origin from the page. Drawing
 * a cross-origin image into a <canvas> taints it, and the subsequent
 * canvas.toBlob() throws a SecurityError. Streaming the bytes through our own
 * origin makes the canvas clean, so the composite can be read back.
 *
 * The alternative, preferable in production, is to set
 * Access-Control-Allow-Origin on the CDN bucket and use <img crossOrigin>.
 * See README for both paths.
 */
app.get('/img', async (req, res) => {
  const target = req.query.url;
  if (!target) return res.status(400).json({ error: 'missing url' });

  let parsed;
  try {
    parsed = new URL(target);
  } catch {
    return res.status(400).json({ error: 'bad url' });
  }
  if (!['http:', 'https:'].includes(parsed.protocol)) {
    return res.status(400).json({ error: 'bad protocol' });
  }

  try {
    const upstream = await fetch(target);
    if (!upstream.ok) return res.status(upstream.status).end();
    res.set('Content-Type', upstream.headers.get('content-type') || 'image/jpeg');
    res.set('Cache-Control', 'public, max-age=3600');
    res.send(Buffer.from(await upstream.arrayBuffer()));
  } catch (err) {
    res.status(502).json({ error: 'upstream fetch failed', detail: String(err) });
  }
});

/**
 * Creates (or returns) the Trace-It QR for an article URL.
 * Responds with { pngDataUri, shortUrl, source } — the frontend never sees the
 * API key, and never talks to trace-it.io directly.
 */
app.post('/api/qr', async (req, res) => {
  const { sourceUrl, name } = req.body || {};
  if (!sourceUrl) return res.status(400).json({ error: 'sourceUrl required' });

  if (!TRACEIT_KEY) {
    const png = fs.readFileSync(SAMPLE_QR).toString('base64');
    return res.json({
      source: 'sample',
      shortUrl: null,
      pngDataUri: `data:image/png;base64,${png}`,
      note: 'TRACEIT_API_KEY not set — serving bundled sample QR.',
    });
  }

  try {
    const upstream = await fetch(`${TRACEIT_BASE}/api/v1/qr`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${TRACEIT_KEY}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        sourceUrls: [sourceUrl],
        name: name || sourceUrl,
        folder: 'Newsroom thumbnails',
      }),
    });

    const payload = await upstream.json();
    if (!upstream.ok) return res.status(upstream.status).json(payload);

    res.json({
      source: 'trace-it',
      id: payload.id,
      shortUrl: payload.shortUrl,
      // Prefer the inline data URI: it keeps the canvas clean with no extra
      // CORS negotiation against the QR host.
      pngDataUri: payload.qr?.png || null,
      pngUrl: payload.qr?.pngUrl || null,
    });
  } catch (err) {
    res.status(502).json({ error: 'trace-it request failed', detail: String(err) });
  }
});

app.listen(PORT, () => {
  console.log(`\n  Island Chronicle demo → http://localhost:${PORT}`);
  console.log(`  Trace-It mode: ${TRACEIT_KEY ? 'live API' : 'sample QR (no API key set)'}\n`);
});
