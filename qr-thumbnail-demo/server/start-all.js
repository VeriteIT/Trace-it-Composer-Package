/*
 * start-all.js — boots the three origins the demo needs.
 *
 * They are separate origins because that is the situation being tested:
 *
 *   :3000  the publisher's site      (their CMS, their article pages)  THEIRS
 *   :3001  their S3 image layer      (no CORS headers, by design)      THEIRS
 *   :3002  our Trace-It service      (QR mint + serve + the script)    OURS
 *
 * Run separately with `npm run cms`, `npm run s3`, `npm run traceit` if you want
 * three sets of logs, or all together here with one Ctrl+C to stop.
 */

'use strict';

const { MODE } = require('./traceit-client');

const S3_PORT = Number(process.env.S3_PORT || 3001);
const CMS_PORT = Number(process.env.PORT || 3000);
const TRACEIT_PORT = Number(process.env.TRACEIT_PORT || 3002);

const servers = [
  ['fake-s3 (their images)', require('./fake-s3'), S3_PORT],
  ['trace-it (our service)', require('./traceit-service'), TRACEIT_PORT],
  ['cms (their site)', require('./publisher-site'), CMS_PORT],
];

const listening = [];
let up = 0;

for (const [label, app, port] of servers) {
  const server = app.listen(port);

  server.on('error', (err) => {
    if (err.code === 'EADDRINUSE') {
      console.error(`\n  ✗ Port ${port} is already in use (${label}).`);
      console.error('    Stop whatever is on it, or set S3_PORT / PORT / TRACEIT_PORT.\n');
    } else {
      console.error(`\n  ✗ ${label} failed:`, err.message, '\n');
    }
    process.exit(1);
  });

  // Only print the banner once every port is actually bound — announcing
  // success before the last listen has settled is how you end up reading
  // "ready" directly above an EADDRINUSE.
  server.on('listening', () => {
    if (++up === servers.length) banner();
  });

  listening.push(server);
}

function banner() {
  console.log(`
  Island Chronicle — Trace-It QR overlay demo
  ───────────────────────────────────────────────────────────────
    Publisher site   http://localhost:${CMS_PORT}          (start here)
    Newsroom         http://localhost:${CMS_PORT}/cms      (publish an article)
    Their S3         http://localhost:${S3_PORT}/media/  (no CORS, by design)
    Our service      http://localhost:${TRACEIT_PORT}          (QR mint + serve)

    QR source        ${MODE === 'live' ? 'live Trace-It API' : 'generated locally (no TRACEIT_API_KEY set)'}
  ───────────────────────────────────────────────────────────────
`);
}

function shutdown() {
  console.log('\n  shutting down…');
  let left = listening.length;
  if (!left) process.exit(0);
  for (const s of listening) s.close(() => { if (--left === 0) process.exit(0); });
  // Do not hang forever on a keep-alive connection.
  setTimeout(() => process.exit(0), 3000).unref();
}

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
