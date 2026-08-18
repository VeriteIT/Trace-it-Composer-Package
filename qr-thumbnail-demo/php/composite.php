<?php
/**
 * composite.php — SUPERSEDED. Use framed.php instead.
 * ===========================================================================
 * This belongs to the parked first prototype (see README-composite.md).
 * php/framed.php does the same job and is the one to deploy, because this file
 * has two known defects that were fixed there:
 *
 *   1. BADGE PLACEMENT BUG. The minimum-size floor is applied before a clamp
 *      that checks WIDTH only, and nothing constrains height. On a wide short
 *      thumbnail the badge lands off the top edge — a 400x120 photo puts it at
 *      y = -62 and the code is silently clipped to something unscannable.
 *   2. No SSRF allowlist on the QR fetch path, no source size cap, and it
 *      always re-encodes to JPEG even when the source is a lossless PNG.
 *
 * Kept only so the parked prototype still reads as a complete thing. Do not
 * ship it.
 * ===========================================================================
 *
 * Original description follows.
 *
 * server-side compositing with GD.
 *
 * The frontend approach (canvas in the browser) and this one produce the same
 * image. The difference is where the work happens, and that difference matters:
 *
 *   Frontend canvas            This (PHP + GD)
 *   ---------------            ---------------
 *   Needs CORS or a proxy      No CORS question at all
 *   QR absent from og:image    QR present in og:image / social previews
 *   Per-request CPU in browser  Composite once, cache, serve as a static file
 *   Zero backend change        Runs inside the existing PHP app
 *
 * Because their backend is PHP, this is available to them and is the better
 * permanent answer. Use it at upload/publish time, or as a cached derivative
 * endpoint as shown here.
 *
 * Usage: /composite.php?img=<urlencoded image url>&url=<destination url>
 * Returns: image/jpeg (the thumbnail with the QR baked in)
 *
 * Requires: ext-gd, ext-curl.
 */

declare(strict_types=1);

$ALLOWED_HOSTS = [
    'bmkltsly13vb.compat.objectstorage.ap-singapore-1.oraclecloud.com',
];

$CACHE_DIR = sys_get_temp_dir() . '/traceit-composite-cache';
$CACHE_TTL = 60 * 60 * 24 * 30;

// Layout knobs — same defaults as the JavaScript implementation.
$QR_SCALE    = 0.28;  // QR width as a fraction of the image's short side
$QR_MIN      = 120;
$QR_MAX      = 420;
$PAD_SCALE   = 0.035;
$PLATE_PAD   = 0.07;  // white plate padding, fraction of QR width
$JPEG_QUALITY = 90;

// --------------------------------------------------------------------------

$imgUrl  = $_GET['img'] ?? '';
$destUrl = $_GET['url'] ?? '';

if (!filter_var($imgUrl, FILTER_VALIDATE_URL) || !filter_var($destUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit('bad parameters');
}

$host = strtolower(parse_url($imgUrl, PHP_URL_HOST) ?: '');
if (!in_array($host, array_map('strtolower', $ALLOWED_HOSTS), true)) {
    http_response_code(403);
    exit('host not allowed');
}

if (!is_dir($CACHE_DIR)) {
    @mkdir($CACHE_DIR, 0770, true);
}
$cacheFile = $CACHE_DIR . '/' . hash('sha256', $imgUrl . '|' . $destUrl) . '.jpg';

// Serve from cache — compositing every request would be wasteful.
if (is_readable($cacheFile) && (time() - filemtime($cacheFile)) < $CACHE_TTL) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    header('X-TraceIt-Cache: hit');
    readfile($cacheFile);
    exit;
}

function fetch_bytes(string $url, array $headers = []): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($body === false || $status < 200 || $status >= 300) ? null : $body;
}

/** Fetches the QR PNG for a destination URL from the Trace-It API. */
function fetch_qr_png(string $destUrl): ?string
{
    $base = getenv('TRACEIT_BASE') ?: 'https://your-subdomain.trace-it.io';
    $key  = getenv('TRACEIT_API_KEY') ?: '';
    if ($key === '') {
        return null;
    }

    $ch = curl_init($base . '/api/v1/qr');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => json_encode([
            'sourceUrls' => [$destUrl],
            'folder'     => 'Newsroom thumbnails',
        ], JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        return null;
    }

    $data = json_decode($body, true);

    // Inline base64 data URI if present, otherwise fetch the hosted PNG.
    $dataUri = $data['qr']['png'] ?? null;
    if (is_string($dataUri) && strpos($dataUri, 'base64,') !== false) {
        $decoded = base64_decode(substr($dataUri, strpos($dataUri, 'base64,') + 7), true);
        if ($decoded !== false) {
            return $decoded;
        }
    }

    $pngUrl = $data['qr']['pngUrl'] ?? null;
    return is_string($pngUrl) ? fetch_bytes($pngUrl) : null;
}

/** Rounded-rectangle fill, GD has no primitive for this. */
function filled_rounded_rect($im, int $x, int $y, int $w, int $h, int $r, int $color): void
{
    imagefilledrectangle($im, $x + $r, $y,         $x + $w - $r, $y + $h,     $color);
    imagefilledrectangle($im, $x,     $y + $r,     $x + $w,      $y + $h - $r, $color);
    $d = $r * 2;
    imagefilledellipse($im, $x + $r,          $y + $r,          $d, $d, $color);
    imagefilledellipse($im, $x + $w - $r,     $y + $r,          $d, $d, $color);
    imagefilledellipse($im, $x + $r,          $y + $h - $r,     $d, $d, $color);
    imagefilledellipse($im, $x + $w - $r,     $y + $h - $r,     $d, $d, $color);
}

$photoBytes = fetch_bytes($imgUrl);
$qrBytes    = fetch_qr_png($destUrl);

if ($photoBytes === null) {
    http_response_code(502);
    exit('could not fetch source image');
}

$photo = @imagecreatefromstring($photoBytes);
if ($photo === false) {
    http_response_code(415);
    exit('unsupported image format');
}

// If the QR could not be fetched, degrade gracefully: serve the untouched
// photo rather than failing the image request and breaking the page.
if ($qrBytes === null) {
    header('Content-Type: image/jpeg');
    header('X-TraceIt-QR: unavailable');
    imagejpeg($photo, null, $JPEG_QUALITY);
    imagedestroy($photo);
    exit;
}

$qr = @imagecreatefromstring($qrBytes);
if ($qr === false) {
    header('Content-Type: image/jpeg');
    header('X-TraceIt-QR: undecodable');
    imagejpeg($photo, null, $JPEG_QUALITY);
    imagedestroy($photo);
    exit;
}

$W = imagesx($photo);
$H = imagesy($photo);
$shortSide = min($W, $H);

$qrW = (int) round($shortSide * $QR_SCALE);
$qrW = max($QR_MIN, min($QR_MAX, $qrW));
$qrW = (int) min($qrW, round($W * 0.45));
$qrH = (int) round($qrW * (imagesy($qr) / imagesx($qr)));

$pad      = (int) round($shortSide * $PAD_SCALE);
$platePad = (int) round($qrW * $PLATE_PAD);
$plateW   = $qrW + $platePad * 2;
$plateH   = $qrH + $platePad * 2;

$px = $W - $plateW - $pad;   // bottom-right
$py = $H - $plateH - $pad;

// White plate keeps the code scannable over busy photography.
$white = imagecolorallocate($photo, 255, 255, 255);
filled_rounded_rect($photo, $px, $py, $plateW, $plateH, (int) round($qrW * 0.06), $white);

// The Trace-It PNG has an alpha channel; preserve it when scaling.
imagealphablending($photo, true);
imagecopyresampled(
    $photo, $qr,
    $px + $platePad, $py + $platePad,
    0, 0,
    $qrW, $qrH,
    imagesx($qr), imagesy($qr)
);

// Cache atomically, then serve.
ob_start();
imagejpeg($photo, null, $JPEG_QUALITY);
$out = ob_get_clean();

$tmp = $cacheFile . '.' . getmypid() . '.tmp';
if (@file_put_contents($tmp, $out) !== false) {
    @rename($tmp, $cacheFile);
}

imagedestroy($photo);
imagedestroy($qr);

header('Content-Type: image/jpeg');
header('Content-Length: ' . strlen($out));
header('Cache-Control: public, max-age=86400');
header('X-TraceIt-Cache: miss');
echo $out;
