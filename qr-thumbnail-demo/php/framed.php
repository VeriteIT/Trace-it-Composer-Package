<?php
/**
 * framed.php — the thumbnail with the QR baked into the pixels.
 * ===========================================================================
 * This is the endpoint that makes the browser's native "Save image as…" produce
 * a QR-embedded file. There is no JavaScript trick involved and none is
 * possible: save-as writes the bytes of whatever the <img> is displaying, fires
 * no DOM event and runs no script. So the QR has to BE part of the image. It is,
 * because this composited it.
 *
 * Right-click → Copy image, drag-to-desktop and printing all get the QR too, for
 * the same reason. So does a social crawler, if the publisher points og:image at
 * this URL — crawlers never run page JavaScript, so an overlay can never reach
 * them but this can.
 *
 * WHOSE SERVER DOES THIS RUN ON?
 *   Ours, not the publisher's. It exists in PHP because the Trace-It platform
 *   may be PHP: if our service is PHP, deploy this instead of the Node
 *   implementation and nothing else about the integration changes. The
 *   publisher's own site needs only publish-hook.php and one script tag.
 *
 *   It CAN be deployed on the publisher's server instead, if they would rather
 *   keep image bytes on their own infrastructure. It never writes to their
 *   storage either way — it reads a public URL and returns a new image.
 *
 * Usage:  /framed.php?id=<articleId>&src=<public image url>[&corner=&scale=]
 * Returns: image/jpeg or image/png
 *
 * Requires: ext-gd, ext-curl. No framework, no Composer.
 *
 * ---------------------------------------------------------------------------
 * VERIFIED on PHP 8.4.24 (ZTS, Windows) with ext-gd and ext-curl:
 *   - php -l clean
 *   - served over `php -S` against the demo's S3 origin and QR endpoint
 *   - output identical in dimensions to the source (1200x800)
 *   - a QR decodes back out of the delivered JPEG and resolves to the right
 *     article URL — the same payload the Node implementation produces
 *   - ?src= pointed at 169.254.169.254 refused with 403
 *   - a traversal-shaped article id refused with 400
 *
 * GD's JPEG encoder differs slightly between builds, so expect the byte size to
 * move a little (98 KB here vs 88 KB from node-canvas for the same source).
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/traceit-compositor.php';

/* --- Configuration -------------------------------------------------------- */

// Where to get the QR PNG for an article. {id} is replaced with the article ID.
// Keeping minting separate from compositing is deliberate: this file never
// spends quota, it only draws.
$QR_URL_TEMPLATE = getenv('TRACEIT_QR_URL_TEMPLATE')
    ?: (rtrim(getenv('TRACEIT_SERVICE') ?: 'https://traceit.example.com', '/') . '/v1/qr/{id}.png');

/*
 * Optional: read the QR from local disk instead of over HTTP. Set this when the
 * QR store lives on the same host — it avoids a pointless network round trip,
 * and it is REQUIRED under PHP's single-threaded built-in server, where a script
 * that requests its own server deadlocks.
 *   e.g. TRACEIT_QR_FILE_TEMPLATE=/var/lib/traceit/qr/{sha256}.png
 * {sha256} is replaced with hash('sha256', articleId); {id} also works.
 */
$QR_FILE_TEMPLATE = getenv('TRACEIT_QR_FILE_TEMPLATE') ?: '';

$CACHE_DIR = (getenv('TRACEIT_DATA_DIR') ?: sys_get_temp_dir()) . '/traceit-framed-cache';
$CACHE_TTL = 60 * 60 * 24 * 30;   // 30 days

// Must match ARTICLE_ID_RE in server/traceit-service.js.
$ARTICLE_ID_RE = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

/* --- Request -------------------------------------------------------------- */

$articleId = (string) ($_GET['id'] ?? '');
if (!preg_match($ARTICLE_ID_RE, $articleId)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    exit('bad article id');
}

$layout = traceit_layout_from_query($_GET);

/*
 * The source image URL. In production prefer looking this up from your own store
 * by article ID (recorded by the publish webhook) rather than trusting the query
 * string — then it is not client-controlled at all. The ?src= path exists for
 * the no-webhook deployment, and is allowlisted either way.
 */
$imageUrl = (string) ($_GET['src'] ?? '');
if ($imageUrl === '') {
    http_response_code(400);
    header('Content-Type: text/plain');
    exit('no source image known for this article');
}

if (!traceit_image_host_allowed($imageUrl)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('source image host not allowed');
}

/* --- Cache ---------------------------------------------------------------- */

if (!is_dir($CACHE_DIR)) {
    @mkdir($CACHE_DIR, 0770, true);
}

$key = hash('sha256', implode('|', [
    $articleId, $imageUrl,
    $layout['corner'] ?? 'default',
    (string) ($layout['scale'] ?? 'default'),
]));
$cacheBin  = $CACHE_DIR . '/' . $key . '.bin';
$cacheMeta = $CACHE_DIR . '/' . $key . '.mime';

if (is_readable($cacheBin) && (time() - filemtime($cacheBin)) < $CACHE_TTL) {
    $mime = is_readable($cacheMeta) ? trim((string) file_get_contents($cacheMeta)) : 'image/jpeg';
    traceit_serve_image((string) file_get_contents($cacheBin), $mime, $articleId, 'hit', 'embedded');
    exit;
}

/* --- Fetch the photo ------------------------------------------------------ */

[$photoBytes, $photoType] = traceit_fetch_bytes($imageUrl);

if ($photoBytes === null) {
    http_response_code(502);
    header('Content-Type: text/plain');
    exit('could not fetch the source image');
}
if (strncmp($photoType, 'image/', 6) !== 0) {
    http_response_code(415);
    header('Content-Type: text/plain');
    exit('source is not an image');
}

/* --- Fetch the QR --------------------------------------------------------- */

$qrBytes = null;

if ($QR_FILE_TEMPLATE !== '') {
    $file = str_replace(
        ['{sha256}', '{id}'],
        [hash('sha256', $articleId), $articleId],
        $QR_FILE_TEMPLATE
    );
    if (is_readable($file)) {
        $qrBytes = file_get_contents($file) ?: null;
    }
}

if ($qrBytes === null) {
    $qrUrl = str_replace('{id}', rawurlencode($articleId), $QR_URL_TEMPLATE);
    [$qrBytes] = traceit_fetch_bytes($qrUrl, 12, 4 * 1024 * 1024);
}

/* --- Composite ------------------------------------------------------------ */

try {
    $out = traceit_composite($photoBytes, $photoType, $qrBytes, $layout);
} catch (RuntimeException $e) {
    http_response_code(415);
    header('Content-Type: text/plain');
    exit($e->getMessage());
}

// Only cache a real composite. Caching a degraded response for 30 days would
// outlive whatever transient problem caused it.
if ($out['badge']) {
    $tmp = $cacheBin . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $out['bytes']) !== false) {
        @rename($tmp, $cacheBin);
        @file_put_contents($cacheMeta, $out['mime']);
    } else {
        @unlink($tmp);
    }
}

traceit_serve_image(
    $out['bytes'],
    $out['mime'],
    $articleId,
    'miss',
    $out['badge'] ? 'embedded' : 'absent (' . ($out['note'] ?? 'unknown') . ')'
);
