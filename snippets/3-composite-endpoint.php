<?php

/**
 * SNIPPET 3 of 3 — OPTIONAL: serve the composited image from your own domain.
 * ===========================================================================
 * You do not need this to put codes on your thumbnails. The page script's
 * default overlay mode draws the hosted QR over your photo in the browser, with
 * nothing for you to host.
 *
 * Take this on if you need something only real compositing can give you:
 *   - the code baked into the file a reader gets from "Save image",
 *   - the code in social share previews, which never run JavaScript,
 *   - image traffic kept on your own CDN, or
 *   - a Content-Security-Policy that forbids third-party img-src.
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
 * Badge design version. Composites are served `immutable`, so a browser that has
 * one will never ask again. Bump this whenever the badge design changes, or a
 * redesign stays invisible to everyone who has already loaded the page.
 */
$version = (string) ($_GET['v'] ?? '1');

try {
    $framed = $traceIt->framedImage($postId, null, $version);

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
