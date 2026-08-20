<?php

/**
 * SNIPPET 3 of 3 — serve the composited image from your own domain.
 * ===========================================================================
 * THIS IS THE ONE THAT MAKES SAVE-AS WORK, so it is not optional. Snippets 1
 * and 2 register the code and point the page at this endpoint; this is what
 * actually puts the code into the pixels a reader downloads.
 *
 * "Save image as…" is a browser menu item: no DOM event fires for it and no
 * script runs during it. It writes the bytes of the resource the <img> is
 * displaying. So a code drawn OVER a photo is on the screen but never in the
 * saved file, and no amount of frontend work changes that. Compositing is the
 * only thing that satisfies the requirement.
 *
 * It also gives you:
 *   - the code in social share previews, which never run JavaScript,
 *   - image traffic kept on your own CDN, and
 *   - compatibility with a Content-Security-Policy that forbids third-party
 *     img-src.
 *
 * It runs on your server because that is where the photo already is. Trace-It
 * never receives your image URLs, so it cannot composite a photo it has never
 * seen — which also means you are not handing us your image bandwidth or a copy
 * of every thumbnail you publish.
 *
 * Requires ext-gd.
 *
 * Deploy as e.g. /qr-image.php, then rewrite your service path onto it:
 *
 *   RewriteRule ^traceit/v1/framed/([A-Za-z0-9_-]+)\.jpg$ /qr-image.php?id=$1 [QSA,L]
 *
 * …and point the page script at your own origin:
 *
 *   <script src="https://YOUR-TRACEIT-HOST/js/traceit-qr.js"
 *           data-selector="img.story-thumb"
 *           data-service="https://www.example.lk/traceit"></script>
 * ===========================================================================
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';   // adjust to your project's autoloader

use VeriteIt\TraceItQr\TraceIt;
use VeriteIt\TraceItQr\TraceItException;

$traceIt = new TraceIt([
    'apiKey'   => getenv('TRACEIT_API_KEY'),
    'baseUrl'  => getenv('TRACEIT_BASE'),
    'cacheDir' => '/var/lib/trace-it',

    /*
     * REQUIRED HERE, AND IT IS A SECURITY CONTROL.
     *
     * This endpoint fetches an image URL server-side, and that URL can arrive in a
     * query parameter. Without an allowlist it is a Server-Side Request Forgery
     * hole: anyone could point it at 169.254.169.254 (cloud instance credentials),
     * at a localhost admin port, or at anything else your server can reach but the
     * internet cannot.
     *
     * List the hostnames your article photos actually come from. Nothing else will
     * be fetched — the check runs before any connection is made.
     */
    'allowedImageHosts' => ['cdn.example.lk', 'images.example.lk'],
]);

$postId = (string) ($_GET['id'] ?? '');

/*
 * Look the photo up here, from your own data.
 *
 * This endpoint runs inside your CMS and the post ID is right there in the query
 * string, so this is a local lookup you are effectively already making. Passing
 * the URL explicitly is better than letting the package remember one for you, and
 * the reason is not tidiness:
 *
 * A remembered URL is only refreshed when publish() is next called with a
 * different one. Replace an article's photo without re-publishing and the
 * remembered value still points at the old file, so every composite keeps
 * carrying the old picture. A lookup cannot go stale.
 *
 * Adapt this line to your CMS. If this endpoint genuinely cannot reach it — a
 * separate host, a static deployment — pass null instead and give publish() the
 * URL as its fourth argument; framedImage() falls back to that.
 */
$article = $cms->findByPostId($postId);   // <- your existing lookup

/*
 * Badge design version. Composites are served `immutable`, so a browser that has
 * one will never ask again. Bump this whenever the badge design changes, or a
 * redesign stays invisible to everyone who has already loaded the page.
 *
 * The same applies to a REPLACED PHOTO: readers holding a composite will not
 * re-request it. If swapping photos after publication is common for you, put
 * something per-article in here — a photo id, or a hash of the URL — rather than
 * one global number, or a swap stays invisible until you bump it for everybody.
 */
$version = (string) ($_GET['v'] ?? '1');

try {
    $framed = $traceIt->framedImage($postId, $article->thumbUrl, $version);

    // Sends Content-Type, Content-Length, Cache-Control: immutable, and a
    // Content-Disposition filename so the save dialog offers something sensible.
    $framed->send($postId);
} catch (TraceItException $e) {
    /*
     * Degrade, do not break the page. A broken image is far worse than a photo
     * without a code, and the page script handles a failed composite by simply
     * leaving the publisher's original photo in place.
     *
     * 404 rather than 500: there is no composite for this request, and there is
     * nothing a retry would fix.
     */
    error_log('[traceit] composite failed for ' . $postId . ': ' . $e->getMessage());
    http_response_code(404);
}
