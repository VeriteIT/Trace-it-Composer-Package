<?php
/**
 * qr-proxy.php — OPTIONAL. Serves the QR from the publisher's own domain.
 *
 * The overlay script fetches the QR straight from our service by default, which
 * needs nothing from the publisher. Use this file only if they would rather the
 * browser never requested an asset from a third-party domain — typically because
 * of a strict Content-Security-Policy (`img-src 'self'`), or because they want
 * the QR served from their existing CDN.
 *
 * With this in place, point the script at their own origin:
 *
 *     <script src="https://traceit.example.com/js/traceit-qr-overlay.js"
 *             data-service="https://www.example.lk/traceit"></script>
 *
 * ...with /traceit/v1/qr/<id>.png rewritten to this file. The script's URL shape
 * is `{service}/v1/qr/{id}.png`, so a rewrite rule is all that is needed:
 *
 *     RewriteRule ^traceit/v1/qr/([A-Za-z0-9._-]+)\.png$ /qr-proxy.php?id=$1 [L]
 *
 * This is a cache in front of us, not a second source of truth: it never mints
 * anything, it only relays and stores bytes we already produced.
 *
 * Requires: ext-curl.
 *
 * ---------------------------------------------------------------------------
 * NOT EXECUTED. PHP was not available where this demo was built. It mirrors the
 * behaviour of the verified Node endpoint. Lint with `php -l` before shipping.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

$TRACEIT_SERVICE = getenv('TRACEIT_SERVICE') ?: 'https://traceit.example.com';
$CACHE_DIR       = sys_get_temp_dir() . '/traceit-qr-png-cache';
$CACHE_TTL       = 60 * 60 * 24 * 30;   // 30 days
$MAX_BYTES       = 2 * 1024 * 1024;

// Must match the ARTICLE_ID_RE in server/traceit-service.js. A loose pattern
// here would turn this endpoint into a way to fan arbitrary requests at us.
$ARTICLE_ID_RE = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

$id = $_GET['id'] ?? '';

if (!is_string($id) || !preg_match($ARTICLE_ID_RE, $id)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    exit('bad article id');
}

/* --- Serve from local cache ---------------------------------------------- */

if (!is_dir($CACHE_DIR)) {
    @mkdir($CACHE_DIR, 0770, true);
}
// hash() the id even though it is already validated — belt and braces against
// ever constructing a path from request input.
$cacheFile = $CACHE_DIR . '/' . hash('sha256', $id) . '.png';

if (is_readable($cacheFile) && (time() - filemtime($cacheFile)) < $CACHE_TTL) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('X-TraceIt-Cache: hit');
    header('Content-Length: ' . (string) filesize($cacheFile));
    readfile($cacheFile);
    exit;
}

/* --- Relay from our service ---------------------------------------------- */

$url = rtrim($TRACEIT_SERVICE, '/') . '/v1/qr/' . rawurlencode($id) . '.png';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER     => ['Accept: image/png'],
]);

$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

// A missing code is a normal outcome, not an error: the overlay script treats a
// failed image load as "render nothing" and the article page is unaffected.
if ($body === false || $status === 404) {
    http_response_code(404);
    exit;
}

if ($status < 200 || $status >= 300) {
    http_response_code(502);
    exit;
}

if (strncmp($ctype, 'image/', 6) !== 0 || strlen($body) > $MAX_BYTES) {
    http_response_code(502);
    exit;
}

/* --- Cache atomically, then serve ---------------------------------------- */

$tmp = $cacheFile . '.' . getmypid() . '.tmp';
if (@file_put_contents($tmp, $body) !== false) {
    @rename($tmp, $cacheFile);
} else {
    @unlink($tmp);
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
header('X-TraceIt-Cache: miss');
header('Content-Length: ' . (string) strlen($body));
echo $body;
