<?php
/**
 * traceit-compositor.php — the compositing library. PHP port of
 * server/compositor.js.
 * ===========================================================================
 * Pure functions, no side effects on include, NO DEPENDENCIES beyond ext-gd and
 * ext-curl. framed.php is a thin HTTP endpoint over this; the demo router calls
 * it directly.
 *
 * Calling it directly matters: PHP's built-in dev server (`php -S`) is
 * single-threaded, so a script that HTTP-requests its own server deadlocks —
 * the request sits in the accept queue behind the handler that is waiting on it.
 * Keeping the drawing in a library the caller can invoke in-process avoids that
 * entirely, and is better structure regardless of the SAPI.
 *
 * WHY A SERVER MAY DO THIS AT ALL
 *   Same-origin policy and CORS are BROWSER mechanisms governing what a page's
 *   JavaScript may read. They do not apply to a server making an HTTP request.
 *   Fetching the publisher's thumbnail here is an ordinary GET of a URL already
 *   public to every reader — no CORS, no credentials, no privileged access, and
 *   nothing written back to their storage.
 * ===========================================================================
 */

declare(strict_types=1);

/** Layout defaults — the same numbers as the JavaScript implementation. */
function traceit_layout_defaults(): array
{
    return [
        'scale'   => 0.28,   // QR width as a fraction of the image's SHORT side
        'minPx'   => 96,
        'maxPx'   => 420,
        'padFrac' => 0.035,
        'corner'  => 'bottom-right',

        /*
         * White plate behind the code. OFF by default.
         *
         * A Trace-It branded PNG already IS a white rounded card — the code sits
         * on white, inside its own rounded border, above the label banner. Our own
         * plate behind that produced a visible second border: a white ring with a
         * drop shadow around a badge that already had an edge.
         *
         * The plate existed for scannability over busy photography, which the
         * branded PNG covers on its own. Turn it back on only for a bare,
         * transparent QR with no quiet zone.
         */
        'plate'        => false,
        'platePadFrac' => 0.07, // used only when plate is true
        'radiusFrac'   => 0.06,
    ];
}

/**
 * JPEG re-encode quality.
 *
 * A JPEG in means a decode and a re-encode, so there is one extra generation of
 * loss. Measured on the Node implementation, simulating publisher originals at
 * several qualities (PSNR; higher is better, >40 dB is imperceptible):
 *
 *     publisher q | ours q=90 | ours q=95 | ours q=98
 *              75 |   53.0 dB |   55.3 dB |   55.6 dB
 *              82 |   50.9 dB |   54.9 dB |   54.9 dB
 *              88 |   52.8 dB |   53.7 dB |   55.0 dB
 *              94 |   51.5 dB |   56.3 dB |   56.1 dB
 *
 * 95 is the sweet spot. q=98 buys nothing measurable and inflates the file.
 * A PNG source stays PNG and stays lossless.
 */
function traceit_jpeg_quality(): int
{
    return (int) (getenv('TRACEIT_JPEG_QUALITY') ?: 95);
}

/**
 * Hosts we will fetch a source image from.
 *
 * THIS IS A SECURITY CONTROL, not a convenience. The source URL can arrive in a
 * query parameter, so without an allowlist this is an open proxy: point it at
 * 169.254.169.254, at a localhost admin port, or at anything else inside the
 * network and it returns the bytes wrapped in an image. Set this to the
 * publisher's real S3/CDN hostnames before deploying.
 *
 * @return list<string>
 */
function traceit_allowed_image_hosts(): array
{
    $raw = getenv('TRACEIT_ALLOWED_IMAGE_HOSTS')
        ?: 'bmkltsly13vb.compat.objectstorage.ap-singapore-1.oraclecloud.com';

    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function traceit_image_host_allowed(string $url, ?array $allowed = null): bool
{
    $allowed = $allowed ?? traceit_allowed_image_hosts();

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

/**
 * @return array{0:?string,1:string} [body, contentType]
 */
function traceit_fetch_bytes(string $url, int $timeout = 12, int $maxBytes = 16777216): array
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

    if ($body === false || $status < 200 || $status >= 300 || strlen($body) > $maxBytes) {
        return [null, ''];
    }

    return [$body, $ctype];
}

/**
 * Works out the QR size and position so the plate ALWAYS fits inside the image.
 *
 * The first prototype got this wrong in a way that is easy to reintroduce: it
 * applied the minimum-size floor and only then clamped against the image WIDTH,
 * with nothing constraining height. On a wide short thumbnail that put the badge
 * off the top edge — a 400x120 photo placed it at y = -62, silently clipping the
 * code. Here the floor is an intent, both axes constrain it, and if the result
 * would be too small to scan we say so and skip the badge rather than drawing
 * something unusable.
 *
 * @return array{fits:bool,reason?:string,qrW?:int,qrH?:int,plateW?:int,
 *               plateH?:int,platePad?:int,px?:int,py?:int,radius?:int}
 */
function traceit_plan_badge(int $W, int $H, float $qrAspect, array $L = []): array
{
    $L = array_merge(traceit_layout_defaults(), $L);

    // With no plate there is no plate padding: the badge box IS the QR box.
    $platePadFrac = !empty($L['plate']) ? $L['platePadFrac'] : 0.0;

    $shortSide = min($W, $H);
    $pad = (int) round($shortSide * $L['padFrac']);

    $qrW = (int) round($shortSide * $L['scale']);
    $qrW = max($L['minPx'], min($L['maxPx'], $qrW));

    // The plate is what has to fit, not the QR: it is larger on every side.
    $plateFactorW = 1 + 2 * $platePadFrac;
    $plateFactorH = $qrAspect + 2 * $platePadFrac;

    $fitW = ($W - 2 * $pad) / $plateFactorW;
    $fitH = ($H - 2 * $pad) / $plateFactorH;
    $qrW  = (int) floor(min($qrW, $fitW, $fitH));

    if ($qrW < 40) {
        return ['fits' => false, 'reason' => "image {$W}x{$H} too small for a scannable code"];
    }

    $platePad = (int) round($qrW * $platePadFrac);
    $qrH      = (int) round($qrW * $qrAspect);
    $plateW   = $qrW + $platePad * 2;
    $plateH   = $qrH + $platePad * 2;

    $right  = $W - $plateW - $pad;
    $bottom = $H - $plateH - $pad;

    switch ($L['corner']) {
        case 'bottom-left': $px = $pad;   $py = $bottom; break;
        case 'top-right':   $px = $right; $py = $pad;    break;
        case 'top-left':    $px = $pad;   $py = $pad;    break;
        default:            $px = $right; $py = $bottom; break;
    }

    return [
        'fits'   => true,
        'plate'  => !empty($L['plate']),
        'qrW'    => $qrW,    'qrH'    => $qrH,
        'plateW' => $plateW, 'plateH' => $plateH,
        'platePad' => $platePad,
        'px'     => $px,     'py'     => $py,
        'radius' => max(2, (int) round($qrW * $L['radiusFrac'])),
    ];
}

/** Rounded-rectangle fill. GD has no primitive for this. */
function traceit_filled_rounded_rect($im, int $x, int $y, int $w, int $h, int $r, int $color): void
{
    $r = (int) min($r, (int) ($w / 2), (int) ($h / 2));
    imagefilledrectangle($im, $x + $r, $y, $x + $w - $r, $y + $h, $color);
    imagefilledrectangle($im, $x, $y + $r, $x + $w, $y + $h - $r, $color);
    $d = $r * 2;
    imagefilledellipse($im, $x + $r,      $y + $r,      $d, $d, $color);
    imagefilledellipse($im, $x + $w - $r, $y + $r,      $d, $d, $color);
    imagefilledellipse($im, $x + $r,      $y + $h - $r, $d, $d, $color);
    imagefilledellipse($im, $x + $w - $r, $y + $h - $r, $d, $d, $color);
}

/**
 * Draws the QR into the photo at the photo's NATIVE resolution, so the file a
 * reader saves is full quality and the code stays scannable in print.
 *
 * Degrades rather than failing: with no usable QR it returns the untouched photo
 * so an image request never leaves a hole in the publisher's article page.
 *
 * @param  string      $photoBytes Raw source image bytes
 * @param  string      $photoType  Source content-type (decides output format)
 * @param  string|null $qrBytes    Raw QR PNG bytes, or null
 * @return array{bytes:string,mime:string,width:int,height:int,badge:bool,note:?string}
 */
function traceit_composite(
    string $photoBytes,
    string $photoType,
    ?string $qrBytes,
    array $layout = []
): array {
    $photo = @imagecreatefromstring($photoBytes);
    if ($photo === false) {
        throw new RuntimeException('unsupported image format');
    }

    // A PNG source stays PNG and therefore stays lossless. Only JPEG is
    // re-encoded, and re-encoding a JPEG as PNG would inflate it several times.
    $asPng   = stripos($photoType, 'png') !== false;
    $mime    = $asPng ? 'image/png' : 'image/jpeg';
    $quality = traceit_jpeg_quality();

    $encode = static function ($im) use ($asPng, $quality): string {
        ob_start();
        if ($asPng) {
            imagepng($im);
        } else {
            imagejpeg($im, null, $quality);
        }
        return (string) ob_get_clean();
    };

    $W = imagesx($photo);
    $H = imagesy($photo);

    $finish = static function (string $bytes, bool $badge, ?string $note) use ($mime, $W, $H): array {
        return ['bytes' => $bytes, 'mime' => $mime, 'width' => $W, 'height' => $H,
                'badge' => $badge, 'note' => $note];
    };

    if ($qrBytes === null || $qrBytes === '') {
        $out = $finish($encode($photo), false, 'no QR available');
        imagedestroy($photo);
        return $out;
    }

    $qr = @imagecreatefromstring($qrBytes);
    if ($qr === false) {
        $out = $finish($encode($photo), false, 'QR undecodable');
        imagedestroy($photo);
        return $out;
    }

    $plan = traceit_plan_badge($W, $H, imagesy($qr) / imagesx($qr), $layout);
    if (!$plan['fits']) {
        $out = $finish($encode($photo), false, $plan['reason']);
        imagedestroy($photo);
        imagedestroy($qr);
        return $out;
    }

    // No plate by default: the branded PNG is already a white rounded card, so a
    // plate behind it reads as a second border around the badge.
    if (!empty($plan['plate'])) {
        $white = imagecolorallocate($photo, 255, 255, 255);
        traceit_filled_rounded_rect(
            $photo, $plan['px'], $plan['py'], $plan['plateW'], $plan['plateH'], $plan['radius'], $white
        );
    }

    // The QR PNG carries an alpha channel; blend it onto the plate rather than
    // replacing pixels, or the transparent quiet zone comes out black.
    imagealphablending($photo, true);
    imagecopyresampled(
        $photo, $qr,
        $plan['px'] + $plan['platePad'], $plan['py'] + $plan['platePad'],
        0, 0,
        $plan['qrW'], $plan['qrH'],
        imagesx($qr), imagesy($qr)
    );

    $out = $finish($encode($photo), true, null);
    imagedestroy($photo);
    imagedestroy($qr);

    return $out;
}

/** Reads layout overrides off a query string, ignoring anything out of range. */
function traceit_layout_from_query(array $q): array
{
    $L = [];
    if (isset($q['corner']) &&
        in_array($q['corner'], ['bottom-right', 'bottom-left', 'top-right', 'top-left'], true)) {
        $L['corner'] = (string) $q['corner'];
    }
    if (isset($q['scale'])) {
        $s = (float) $q['scale'];
        if ($s > 0.02 && $s <= 0.6) {
            $L['scale'] = $s;
        }
    }
    return $L;
}

/** Response headers shared by every framed-image response. */
function traceit_serve_image(
    string $bytes,
    string $mime,
    string $articleId,
    string $cacheState,
    string $badge
): void {
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
