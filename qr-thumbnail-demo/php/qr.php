<?php
/**
 * qr.php — PHP equivalent of the demo's POST /api/qr endpoint.
 *
 * Calls the Trace-It API server-side so the API key is never exposed to the
 * browser, and returns the QR PNG as a base64 data URI for the frontend to
 * composite onto the thumbnail.
 *
 * Drop this anywhere in the existing PHP app and point the script at it:
 *     <script src="/js/traceit-qr-thumbnail.js"
 *             data-qr-endpoint="/qr.php"></script>
 *
 * Requires: ext-curl (or allow_url_fopen). No framework, no dependencies.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// --- Configuration --------------------------------------------------------
// Keep the key out of source control: environment variable, or the app's
// existing config/secrets mechanism.
$TRACEIT_BASE = getenv('TRACEIT_BASE') ?: 'https://your-subdomain.trace-it.io';
$TRACEIT_KEY  = getenv('TRACEIT_API_KEY') ?: '';

// Cache directory. QR creation counts against the monthly quota, so caching
// per source URL matters — without it every page view burns quota.
$CACHE_DIR = sys_get_temp_dir() . '/traceit-qr-cache';
$CACHE_TTL = 60 * 60 * 24 * 30; // 30 days

// --------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$input     = json_decode(file_get_contents('php://input'), true) ?: [];
$sourceUrl = $input['sourceUrl'] ?? '';
$name      = $input['name'] ?? $sourceUrl;

if ($sourceUrl === '' || !filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'valid sourceUrl required']);
    exit;
}

// --- Cache lookup ---------------------------------------------------------
if (!is_dir($CACHE_DIR)) {
    @mkdir($CACHE_DIR, 0770, true);
}
$cacheFile = $CACHE_DIR . '/' . hash('sha256', $sourceUrl) . '.json';

if (is_readable($cacheFile) && (time() - filemtime($cacheFile)) < $CACHE_TTL) {
    $cached = file_get_contents($cacheFile);
    if ($cached !== false) {
        header('X-TraceIt-Cache: hit');
        echo $cached;
        exit;
    }
}

if ($TRACEIT_KEY === '') {
    http_response_code(500);
    echo json_encode(['error' => 'TRACEIT_API_KEY not configured']);
    exit;
}

// --- Call Trace-It --------------------------------------------------------
$payload = json_encode([
    'sourceUrls' => [$sourceUrl],
    'name'       => $name,
    'folder'     => 'Newsroom thumbnails',
], JSON_UNESCAPED_SLASHES);

$ch = curl_init($TRACEIT_BASE . '/api/v1/qr');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $TRACEIT_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
]);

$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);
curl_close($ch);

if ($body === false) {
    http_response_code(502);
    echo json_encode(['error' => 'trace-it request failed', 'detail' => $err]);
    exit;
}

$data = json_decode($body, true);

if ($status < 200 || $status >= 300) {
    http_response_code($status);
    // Pass the upstream error through; Trace-It returns { error: { code, message } }.
    echo $body;
    exit;
}

// Prefer the inline data URI — it keeps the browser canvas untainted with no
// extra CORS negotiation against the QR host.
$result = json_encode([
    'source'     => 'trace-it',
    'id'         => $data['id'] ?? null,
    'shortUrl'   => $data['shortUrl'] ?? null,
    'pngDataUri' => $data['qr']['png'] ?? null,
    'pngUrl'     => $data['qr']['pngUrl'] ?? null,
], JSON_UNESCAPED_SLASHES);

// Write cache atomically so concurrent requests never read a partial file.
$tmp = $cacheFile . '.' . getmypid() . '.tmp';
if (@file_put_contents($tmp, $result) !== false) {
    @rename($tmp, $cacheFile);
}

header('X-TraceIt-Cache: miss');
echo $result;
