<?php
/**
 * framed.php — the thumbnail with the QR baked into the pixels, in PHP/GD.
 * ===========================================================================
 * A line-for-line PHP port of server/compositor.js plus the /v1/framed/:id
 * endpoint. This is what makes the browser's native "Save image as…" produce a
 * QR-embedded file.
 *
 * WHOSE SERVER DOES THIS RUN ON?
 *   Ours, not the publisher's. This file exists because the Trace-It platform
 *   may be PHP: if our service is PHP, use this instead of the Node
 *   implementation and nothing else about the integration changes. The
 *   publisher's own site needs only publish-hook.php and one script tag.
 *
 *   It CAN be deployed on the publisher's server instead, if they would rather
 *   host the compositing themselves and keep the image bytes on their own
 *   infrastructure. It never writes to their storage either way — it reads the
 *   public thumbnail URL and returns a new image in the HTTP response.
 *
 * WHY THIS IS ALLOWED WHEN THE BROWSER VERSION IS NOT
 *   Same-origin policy and CORS are BROWSER mechanisms governing what a page's
 *   JavaScript may read. They do not apply to a server making an HTTP request.
 *   Fetching the publisher's thumbnail here is an ordinary GET of a URL already
 *   public to every reader — no CORS, no credentials, no privileged access.
 *
 * Usage:  /framed.php?id=<articleId>[&src=<public image url>][&corner=...]
 * Returns: image/jpeg or image/png — the photo with the code in it.
 *
 * Requires: ext-gd, ext-curl.
 *
 * ---------------------------------------------------------------------------
 * VERIFIED on PHP 8.4.24 (ZTS, Windows) with ext-gd and ext-curl:
 *   - php -l clean
 *   - served over `php -S` against the demo's S3 origin and QR endpoint
 *   - output is 1200x800, identical dimensions to the source
 *   - a QR decodes back out of the delivered JPEG and resolves to the right
 *     article URL — the same payload the Node implementation produces
 *   - ?src= pointed at 169.254.169.254 is refused with 403
 *   - a traversal-shaped article id is refused with 400
 *
 * Re-check against the target PHP version and GD build before shipping; GD's
 * JPEG encoder differs slightly between builds, so expect the byte size to move
 * a little (98 KB here vs 88 KB from node-canvas for the same source).
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

/* --- Configuration -------------------------------------------------------- */

/**
 * Hosts we will fetch a source image from.
 *
 * THIS IS A SECURITY CONTROL, not a convenience. The source URL can arrive in a
 * query parameter, so without an allowlist this endpoint is an open proxy:
 * point it at 169.254.169.254, at a localhost admin port, or at anything else
 * inside the network and it returns the bytes. Put the publisher's real S3/CDN
 * hostnames here before deploying.
 */
$ALLOWED_IMAGE_HOSTS = array_filter(array_map('trim', explode(',',
    getenv('TRACEIT_ALLOWED_IMAGE_HOSTS') ?: 'bmkltsly13vb.compat.objectstorage.ap-singapore-1.oraclecloud.com'
)));

// Where to get the QR PNG for an article. {id} is replaced with the article ID.
// Keeping minting separate from compositing is deliberate: this file never
// spends quota, it only draws.
$QR_URL_TEMPLATE = getenv('TRACEIT_QR_URL_TEMPLATE')
    ?: (rtrim(getenv('TRACEIT_SERVICE') ?: 'https://traceit.example.com', '/') . '/v1/qr/{id}.png');

$CACHE_DIR = sys_get_temp_dir() . '/traceit-framed-cache';
$CACHE_TTL = 60 * 60 * 24 * 30;   // 30 days
$MAX_SOURCE_BYTES = 16 * 1024 * 1024;
$FETCH_TIMEOUT = 12;

/*
 * JPEG re-encode quality. A JPEG in means a decode and a re-encode, so there is
 * one extra generation of loss. Measured on the Node implementation, simulating
 * publisher originals at several qualities (PSNR, higher is better; >40 dB is
 * generally imperceptible):
 *
 *     publisher q | ours q=90 | ours q=95 | ours q=98
 *              75 |   53.0 dB |   55.3 dB |   55.6 dB
 *              82 |   50.9 dB |   54.9 dB |   54.9 dB
 *              88 |   52.8 dB |   53.7 dB |   55.0 dB
 *              94 |   51.5 dB |   56.3 dB |   56.1 dB
 *
 * 95 is the sweet spot. A PNG source stays PNG and stays lossless.
 */
$JPEG_QUALITY = (int) (getenv('TRACEIT_JPEG_QUALITY') ?: 95);

// Layout. Same numbers as the JavaScript implementation.
$L = [
    'scale'        => 0.28,   // QR width as a fraction of the image's SHORT side
    'minPx'        => 96,
    'maxPx'        => 420,
    'padFrac'      => 0.035,
    'platePadFrac' => 0.07,
    'radiusFrac'   => 0.06,
    'corner'       => 'bottom-right',
];

// Must match ARTICLE_ID_RE in server/traceit-service.js.
$ARTICLE_ID_RE = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

/* --- Helpers -------------------------------------------------------------- */

function image_host_allowed(string $url, array $allowed): bool
{
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        return false;
    }
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return false;
    }
    $host = strtolower($parts['host']);
    if (isset($parts['port'])) {
        $host .= ':' . $parts['port'];
    }
    return in_array($host, array_map('strtolower', $allowed), true);
}

/** @return array{0:?string,1:string} [body, contentType] */
function fetch_bytes(string $url, int $timeout, int $maxBytes): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false, // a redirect could hop off the allowlist
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        return [null, ''];
    }
    if (strlen($body) > $maxBytes) {
        return [null, ''];
    }
    return [$body, $ctype];
}

/**
 * Works out the QR size and position so the plate ALWAYS fits inside the image.
 *
 * The original prototype got this wrong in a way that is easy to reintroduce:
 * it applied the minimum-size floor and only then clamped against the image
 * WIDTH, with nothing constraining height. On a wide short thumbnail that put
 * the badge off the top edge — a 400x120 photo placed it at y = -62, silently
 * clipping the code. Here the floor is an intent, both axes constrain it, and
 * if the result is too small to scan we report that and skip the badge rather
 * than drawing something unusable.
 *
 * @return array{fits:bool,reason?:string,qrW?:int,qrH?:int,plateW?:int,
 *               plateH?:int,platePad?:int,px?:int,py?:int,radius?:int}
 */
function plan_badge(int $W, int $H, float $qrAspect, array $L): array
{
    $shortSide = min($W, $H);
    $pad = (int) round($shortSide * $L['padFrac']);

    $qrW = (int) round($shortSide * $L['scale']);
    $qrW = max($L['minPx'], min($L['maxPx'], $qrW));

    // The plate is what has to fit, not the QR: it is larger on every side.
    $plateFactorW = 1 + 2 * $L['platePadFrac'];
    $plateFactorH = $qrAspect + 2 * $L['platePadFrac'];

    $fitW = ($W - 2 * $pad) / $plateFactorW;
    $fitH = ($H - 2 * $pad) / $plateFactorH;
    $qrW = (int) floor(min($qrW, $fitW, $fitH));

    if ($qrW < 40) {
        return ['fits' => false, 'reason' => "image {$W}x{$H} too small for a scannable code"];
    }

    $platePad = (int) round($qrW * $L['platePadFrac']);
    $qrH      = (int) round($qrW * $qrAspect);
    $plateW   = $qrW + $platePad * 2;
    $plateH   = $qrH + $platePad * 2;

    $right  = $W - $plateW - $pad;
    $bottom = $H - $plateH - $pad;

    switch ($L['corner']) {
        case 'bottom-left':  $px = $pad;    $py = $bottom; break;
        case 'top-right':    $px = $right;  $py = $pad;    break;
        case 'top-left':     $px = $pad;    $py = $pad;    break;
        default:             $px = $right;  $py = $bottom; break;
    }

    return [
        'fits' => true, 'qrW' => $qrW, 'qrH' => $qrH,
        'plateW' => $plateW, 'plateH' => $plateH, 'platePad' => $platePad,
        'px' => $px, 'py' => $py,
        'radius' => max(2, (int) round($qrW * $L['radiusFrac'])),
    ];
}

/** Rounded-rectangle fill. GD has no primitive for this. */
function filled_rounded_rect($im, int $x, int $y, int $w, int $h, int $r, int $color): void
{
    $r = (int) min($r, $w / 2, $h / 2);
    imagefilledrectangle($im, $x + $r, $y, $x + $w - $r, $y + $h, $color);
    imagefilledrectangle($im, $x, $y + $r, $x + $w, $y + $h - $r, $color);
    $d = $r * 2;
    imagefilledellipse($im, $x + $r,          $y + $r,          $d, $d, $color);
    imagefilledellipse($im, $x + $w - $r,     $y + $r,          $d, $d, $color);
    imagefilledellipse($im, $x + $r,          $y + $h - $r,     $d, $d, $color);
    imagefilledellipse($im, $x + $w - $r,     $y + $h - $r,     $d, $d, $color);
}

function serve(string $bytes, string $mime, string $articleId, string $cacheState, string $badge): void
{
    $ext = $mime === 'image/png' ? 'png' : 'jpg';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) strlen($bytes));
    // The composite for a given article never changes.
    header('Cache-Control: public, max-age=31536000, immutable');
    // Gives save-as a meaningful default filename instead of a random string.
    header('Content-Disposition: inline; filename="article-' . $articleId . '-qr.' . $ext . '"');
    header('X-Content-Type-Options: nosniff');
    header('X-TraceIt-Cache: ' . $cacheState);
    header('X-TraceIt-Badge: ' . $badge);
    echo $bytes;
}

/* --- Request -------------------------------------------------------------- */

$articleId = (string) ($_GET['id'] ?? '');
if (!preg_match($ARTICLE_ID_RE, $articleId)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    exit('bad article id');
}

if (isset($_GET['corner'])) {
    $corner = (string) $_GET['corner'];
    if (in_array($corner, ['bottom-right', 'bottom-left', 'top-right', 'top-left'], true)) {
        $L['corner'] = $corner;
    }
}
if (isset($_GET['scale'])) {
    $scale = (float) $_GET['scale'];
    if ($scale > 0.02 && $scale <= 0.6) {
        $L['scale'] = $scale;
    }
}

/*
 * The source image URL. In production prefer looking this up from your own
 * store by article ID (set from the publish webhook) rather than trusting the
 * query string — then it is not client-controlled at all. The ?src= path exists
 * for the no-webhook deployment, and is allowlisted either way.
 */
$imageUrl = (string) ($_GET['src'] ?? '');
if ($imageUrl === '') {
    http_response_code(400);
    header('Content-Type: text/plain');
    exit('no source image known for this article');
}

if (!image_host_allowed($imageUrl, $ALLOWED_IMAGE_HOSTS)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('source image host not allowed');
}

/* --- Cache ---------------------------------------------------------------- */

if (!is_dir($CACHE_DIR)) {
    @mkdir($CACHE_DIR, 0770, true);
}
$key = hash('sha256', $articleId . '|' . $imageUrl . '|' . $L['corner'] . '|' . $L['scale']);
$cacheBin  = $CACHE_DIR . '/' . $key . '.bin';
$cacheMeta = $CACHE_DIR . '/' . $key . '.mime';

if (is_readable($cacheBin) && (time() - filemtime($cacheBin)) < $CACHE_TTL) {
    $mime = is_readable($cacheMeta) ? trim((string) file_get_contents($cacheMeta)) : 'image/jpeg';
    serve((string) file_get_contents($cacheBin), $mime, $articleId, 'hit', 'embedded');
    exit;
}

/* --- Fetch the photo ------------------------------------------------------ */

[$photoBytes, $photoType] = fetch_bytes($imageUrl, $FETCH_TIMEOUT, $MAX_SOURCE_BYTES);

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

$photo = @imagecreatefromstring($photoBytes);
if ($photo === false) {
    http_response_code(415);
    header('Content-Type: text/plain');
    exit('unsupported image format');
}

// A PNG source stays PNG, and therefore stays lossless. Only JPEG is re-encoded.
$asPng = stripos($photoType, 'png') !== false;
$mime  = $asPng ? 'image/png' : 'image/jpeg';

$encode = static function ($im) use ($asPng, $JPEG_QUALITY): string {
    ob_start();
    if ($asPng) {
        imagepng($im);
    } else {
        imagejpeg($im, null, $JPEG_QUALITY);
    }
    return (string) ob_get_clean();
};

/* --- Fetch the QR --------------------------------------------------------- */

$qrUrl = str_replace('{id}', rawurlencode($articleId), $QR_URL_TEMPLATE);
[$qrBytes, $qrType] = fetch_bytes($qrUrl, $FETCH_TIMEOUT, 4 * 1024 * 1024);

// Degrade gracefully in both failure cases: serve the untouched photo rather
// than failing the image request and leaving a hole in the article page.
if ($qrBytes === null) {
    serve($encode($photo), $mime, $articleId, 'miss', 'absent (no QR available)');
    imagedestroy($photo);
    exit;
}

$qr = @imagecreatefromstring($qrBytes);
if ($qr === false) {
    serve($encode($photo), $mime, $articleId, 'miss', 'absent (QR undecodable)');
    imagedestroy($photo);
    exit;
}

/* --- Composite ------------------------------------------------------------ */

$W = imagesx($photo);
$H = imagesy($photo);

$plan = plan_badge($W, $H, imagesy($qr) / imagesx($qr), $L);

if (!$plan['fits']) {
    serve($encode($photo), $mime, $articleId, 'miss', 'absent (' . $plan['reason'] . ')');
    imagedestroy($photo);
    imagedestroy($qr);
    exit;
}

// White plate keeps the code scannable over busy photography.
$white = imagecolorallocate($photo, 255, 255, 255);
filled_rounded_rect(
    $photo, $plan['px'], $plan['py'], $plan['plateW'], $plan['plateH'], $plan['radius'], $white
);

// The QR PNG carries an alpha channel; blend it onto the plate rather than
// replacing the pixels, or the transparent quiet zone turns black.
imagealphablending($photo, true);
imagecopyresampled(
    $photo, $qr,
    $plan['px'] + $plan['platePad'], $plan['py'] + $plan['platePad'],
    0, 0,
    $plan['qrW'], $plan['qrH'],
    imagesx($qr), imagesy($qr)
);

$out = $encode($photo);

imagedestroy($photo);
imagedestroy($qr);

/* --- Cache atomically, then serve ----------------------------------------- */

$tmp = $cacheBin . '.' . getmypid() . '.tmp';
if (@file_put_contents($tmp, $out) !== false) {
    @rename($tmp, $cacheBin);
    @file_put_contents($cacheMeta, $mime);
} else {
    @unlink($tmp);
}

serve($out, $mime, $articleId, 'miss', 'embedded');
