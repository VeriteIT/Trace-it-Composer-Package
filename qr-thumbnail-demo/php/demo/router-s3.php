<?php
/**
 * router-s3.php — stands in for the publisher's S3 image layer. Port of
 * server/fake-s3.js.
 * ===========================================================================
 * Run with:
 *   php -S 127.0.0.1:3001 -t public/assets php/demo/router-s3.php
 *
 * Serves /media/<file>, matching the URL shape of the Node version so stored
 * article thumbnails keep resolving across a switch between the two.
 *
 * It deliberately sends NO Access-Control-Allow-Origin header, because we have
 * no permission to configure the publisher's bucket and must assume we never
 * will. Any approach that reads these pixels back out of a <canvas> fails
 * against this server with a SecurityError — that is the test condition, not an
 * oversight. Do not "fix" it.
 * ===========================================================================
 */

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/' || $path === '') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Simulated S3 image origin (PHP).\n";
    echo "Serves /media/<file> with no CORS headers, by design.\n";
    exit;
}

if (!preg_match('#^/media/([A-Za-z0-9._-]+)$#', $path, $m)) {
    http_response_code(404);
    exit;
}

// The document root is public/assets, so resolve inside it and confirm the
// result is still under it — a filename regex alone is not a containment check.
$root = realpath(dirname(__DIR__, 2) . '/public/assets');
$file = realpath($root . '/' . $m[1]);

if ($root === false || $file === false || !is_file($file) || strncmp($file, $root, strlen($root)) !== 0) {
    http_response_code(404);
    exit;
}

$types = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',  'webp' => 'image/webp',  'gif' => 'image/gif',
];
$ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime = $types[$ext] ?? 'application/octet-stream';

// Mimics a default-configured bucket: cacheable, public, and with no
// cross-origin permission granted to anybody.
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($file));
header('Cache-Control: public, max-age=86400');
header('X-Simulated-S3: true');
header_remove('Access-Control-Allow-Origin');

readfile($file);
