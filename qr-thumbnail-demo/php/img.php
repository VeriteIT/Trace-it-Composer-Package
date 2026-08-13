<?php
/**
 * img.php — PHP equivalent of the demo's GET /img endpoint.
 *
 * Streams a CDN image through the site's own origin so that drawing it into a
 * <canvas> does not taint the canvas. Without this (or CORS headers on the
 * bucket) canvas.toBlob() throws a SecurityError and the QR cannot be baked in.
 *
 * ONLY NEEDED IF the image CDN does not send Access-Control-Allow-Origin.
 * If you can set that header on the Oracle Object Storage bucket, delete this
 * file and use data-cross-origin="true" instead — it is cheaper and faster.
 *
 * Usage: /img.php?url=<urlencoded absolute image URL>
 */

declare(strict_types=1);

/*
 * SSRF guard. This endpoint takes a URL from the client, so it MUST NOT be an
 * open proxy — otherwise it can be used to reach internal services behind the
 * firewall (169.254.169.254 metadata, localhost admin panels, private ranges).
 *
 * Only hosts on this allowlist are fetchable. Add the real CDN hosts here.
 */
$ALLOWED_HOSTS = [
    'bmkltsly13vb.compat.objectstorage.ap-singapore-1.oraclecloud.com',
    // 'cdn.example.com',
];

$CACHE_SECONDS = 3600;
$MAX_BYTES     = 12 * 1024 * 1024; // refuse absurdly large payloads

$url = $_GET['url'] ?? '';

if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit('bad url');
}

$parts  = parse_url($url);
$scheme = strtolower($parts['scheme'] ?? '');
$host   = strtolower($parts['host'] ?? '');

if (!in_array($scheme, ['http', 'https'], true)) {
    http_response_code(400);
    exit('bad scheme');
}

if (!in_array($host, array_map('strtolower', $ALLOWED_HOSTS), true)) {
    http_response_code(403);
    exit('host not allowed');
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_FOLLOWLOCATION => false, // a redirect could escape the allowlist
    CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
]);

$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($body === false || $status < 200 || $status >= 300) {
    http_response_code(502);
    exit('upstream fetch failed');
}

if (strlen($body) > $MAX_BYTES) {
    http_response_code(413);
    exit('image too large');
}

// Never echo back an upstream content type unchecked — pin it to image/*.
if (!is_string($ctype) || strpos($ctype, 'image/') !== 0) {
    http_response_code(415);
    exit('not an image');
}

header('Content-Type: ' . $ctype);
header('Content-Length: ' . strlen($body));
header('Cache-Control: public, max-age=' . $CACHE_SECONDS);
header('X-Content-Type-Options: nosniff');
echo $body;
