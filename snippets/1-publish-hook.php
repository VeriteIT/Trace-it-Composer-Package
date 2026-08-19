<?php

/**
 * SNIPPET 1 of 3 — call this when an article is published.
 *
 * Copy the marked block into your existing publish routine. Nothing else in this
 * file needs to ship; it is a worked example, not a class to install.
 */

declare(strict_types=1);

use VeriteIt\TraceItQr\TraceIt;

/* --- build it once, wherever you wire up services ------------------------- */

$traceIt = new TraceIt([
    'apiKey'   => getenv('TRACEIT_API_KEY'),   // sk_live_… — server-side only
    'baseUrl'  => getenv('TRACEIT_BASE'),      // https://<your-subdomain>.trace-it.io
    'cacheDir' => '/var/lib/trace-it',         // must be writable, should persist
]);

/* --- in your publish routine --------------------------------------------- */

$postId = $cms->publish($draft);               // your existing code

// ↓↓↓ THE ADDITION ↓↓↓
$traceIt->publish(
    $postId,
    'https://www.example.lk/article/' . $postId,      // must be https
    $draft->publishedAt->format(DATE_ATOM)            // optional but recommended
);
// ↑↑↑ THE ADDITION ↑↑↑

/*
 * Notes, so nobody has to guess later:
 *
 * - Call it on EVERY publish, re-publishes included. It is idempotent, and only
 *   the first call for a given post ID creates anything or costs quota.
 *
 * - It never throws. If Trace-It is unreachable it returns null and logs through
 *   trigger_error. A QR code is not worth failing an editor's publish over; the
 *   code gets created on the next publish or on first page view instead.
 *
 * - The URL must be https. Trace-It rejects http, and this package drops the field
 *   rather than failing the call — so you would still get a working code, just
 *   without the "Original Source" button on its landing page.
 *
 * - The third argument is the ARTICLE's publication date, not the code's. Trace-It
 *   shows it as "Date Published" on the verification page; omit it and that falls
 *   back to when the code was created, which is only the same thing if you publish
 *   live. Backfilling an archive without it would claim every old story was
 *   published on the day you imported it. An unreadable date is rejected with
 *   400 invalid_published_at rather than silently replaced.
 *
 * - The IMAGE url is a fourth argument, and normally you do not need it: the page
 *   script already knows the thumbnail (it is the src it replaces) and sends it
 *   once on first render. Pass it only to make og:image carry the code, since a
 *   social crawler never runs the page script:
 *
 *       $traceIt->publish($postId, $url, $publishedAt, $draft->thumbUrl);
 */

/* --- if you want the outcome in your logs -------------------------------- */

$code = $traceIt->publish(
    $postId,
    'https://www.example.lk/article/' . $postId,
    $draft->publishedAt->format(DATE_ATOM)
);

if ($code === null) {
    // Already logged. Nothing to do — publishing succeeded regardless.
} else {
    error_log(sprintf(
        '[traceit] %s → %s (%s)',
        $code->postId,
        $code->shortUrl,
        $code->created ? 'new code, one quota unit' : 'reused, no quota'
    ));
}
