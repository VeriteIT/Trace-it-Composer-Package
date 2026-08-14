/*
 * fake-s3.js — stands in for the publisher's S3 image layer.
 *
 * Runs on its own port so it is a genuinely DIFFERENT ORIGIN from the article
 * page. That is the whole reason this file exists: it lets the demo prove,
 * rather than assert, that the overlay approach needs nothing from the image
 * host.
 *
 * It deliberately sends NO Access-Control-Allow-Origin header, because we have
 * no permission to configure the publisher's bucket and must assume we never
 * will. Any approach that reads these pixels back out of a <canvas> will fail
 * against this server with a SecurityError — see tools/verify-approaches.js,
 * which asserts exactly that.
 *
 * Do not "fix" the missing CORS header. It is the test condition.
 */

'use strict';

const path = require('path');
const express = require('express');

const app = express();
const PORT = process.env.S3_PORT || 3001;

app.use(
  '/media',
  express.static(path.join(__dirname, '..', 'public', 'assets'), {
    setHeaders(res) {
      // Mimics a default-configured bucket: cacheable, public, and with no
      // cross-origin permission granted to anybody.
      res.set('Cache-Control', 'public, max-age=86400');
      res.set('X-Simulated-S3', 'true');
      res.removeHeader('Access-Control-Allow-Origin');
    },
  })
);

app.get('/', (_req, res) => {
  res.type('text/plain').send(
    'Simulated S3 image origin.\n' +
      'Serves /media/<file> with no CORS headers, by design.\n'
  );
});

if (require.main === module) {
  app.listen(PORT, () => {
    console.log(`  [fake-s3]  image origin  → http://localhost:${PORT}/media/  (no CORS, by design)`);
  });
}

module.exports = app;
