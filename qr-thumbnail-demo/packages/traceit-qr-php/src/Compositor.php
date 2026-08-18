<?php

declare(strict_types=1);

namespace VeriteIt\TraceItQr;

/**
 * Draws the QR into the article photo, server-side, with GD.
 * ===========================================================================
 * This is what makes the browser's native "Save image as…" produce a
 * QR-embedded file. Save-as writes the bytes of whatever the <img> is showing;
 * it fires no DOM event and runs no script, so the code has to BE part of the
 * image. Copy-image, drag-to-desktop, printing and og:image all follow from the
 * same fact.
 *
 * WHY A SERVER MAY DO THIS WHEN A BROWSER CANNOT
 *   Compositing in the browser means drawing the photo into a <canvas> and
 *   reading it back, and canvas readback of a cross-origin image throws
 *   SecurityError unless the image host sends CORS headers. Same-origin policy is
 *   a BROWSER rule about what page scripts may read; it does not apply to a
 *   server making an HTTP request. Fetching the photo here is an ordinary GET of
 *   a URL already public to every reader — no CORS, no credentials, and nothing
 *   written back to wherever the image lives.
 *
 * Requires ext-gd. Overlay mode does not need this class at all.
 * ===========================================================================
 */
final class Compositor
{
    /** @param list<string> $allowedImageHosts */
    public function __construct(
        private readonly array $allowedImageHosts,
        private readonly int $jpegQuality = 95,
        private readonly Layout $layout = new Layout(),
        private readonly int $maxSourceBytes = 16 * 1024 * 1024,
        private readonly int $timeout = 12,
    ) {
        if (!\extension_loaded('gd')) {
            throw new Misconfigured(
                'Server-side compositing needs ext-gd, which is not loaded. Either enable it, '
                . 'or use overlay mode, which composites nothing.'
            );
        }
    }

    /**
     * Hosts we are willing to fetch a source image from.
     *
     * THIS IS A SECURITY CONTROL. Compositing takes an image URL, and if that URL
     * can reach a request parameter then an unrestricted fetcher is an SSRF hole:
     * point it at 169.254.169.254, at a localhost admin port, or at anything else
     * inside the network and it returns those bytes wrapped in a JPEG. Set this to
     * the hostnames your images actually come from.
     */
    public function imageHostAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');

        foreach ($this->allowedImageHosts as $allowed) {
            if ($host === strtolower(trim($allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fetches the photo and returns it with the QR drawn in.
     *
     * Degrades rather than failing: with no usable QR it returns the untouched
     * photo, so an image request never leaves a hole in an article page.
     *
     * @param string $imageUrl Public URL of the article photo.
     * @param string $qrPng    Raw PNG bytes of the QR (see Code::pngBytes()).
     *
     * @throws Misconfigured  if the host is not allowlisted
     * @throws TransportError if the photo cannot be fetched
     */
    public function compose(string $imageUrl, string $qrPng, ?Layout $layout = null): FramedImage
    {
        if (!$this->imageHostAllowed($imageUrl)) {
            throw new Misconfigured(sprintf(
                'Refusing to fetch "%s": its host is not in allowedImageHosts. Add the hostname '
                . 'your images are served from. This check is what stops this becoming an open '
                . 'proxy into your own network.',
                $imageUrl
            ));
        }

        [$bytes, $contentType] = $this->fetch($imageUrl);

        $photo = @imagecreatefromstring($bytes);
        if ($photo === false) {
            throw new TransportError(sprintf('"%s" is not an image GD can read.', $imageUrl));
        }

        try {
            return $this->draw($photo, $contentType, $qrPng, $layout ?? $this->layout);
        } finally {
            imagedestroy($photo);
        }
    }

    /** @param \GdImage $photo */
    private function draw($photo, string $contentType, string $qrPng, Layout $layout): FramedImage
    {
        $width  = imagesx($photo);
        $height = imagesy($photo);

        // A PNG source stays PNG, and therefore stays lossless. Only JPEG is
        // re-encoded — and re-encoding a JPEG as PNG would inflate it several
        // times over for no benefit.
        $asPng = stripos($contentType, 'png') !== false;
        $mime  = $asPng ? 'image/png' : 'image/jpeg';

        $encode = function () use ($photo, $asPng): string {
            ob_start();
            $asPng ? imagepng($photo) : imagejpeg($photo, null, $this->jpegQuality);
            return (string) ob_get_clean();
        };

        if ($qrPng === '') {
            return new FramedImage($encode(), $mime, $width, $height, false, 'no QR supplied');
        }

        $qr = @imagecreatefromstring($qrPng);
        if ($qr === false) {
            return new FramedImage($encode(), $mime, $width, $height, false, 'QR not decodable');
        }

        try {
            $plan = $layout->plan($width, $height, imagesy($qr) / imagesx($qr));

            if (!$plan->fits) {
                return new FramedImage($encode(), $mime, $width, $height, false, $plan->reason);
            }

            // A plate is off by default: a branded Trace-It PNG is already a white
            // rounded card, so a plate behind it draws a second border with a
            // shadow around a badge that already has an edge.
            if ($plan->plate) {
                $white = (int) imagecolorallocate($photo, 255, 255, 255);
                $this->roundedRect(
                    $photo, $plan->x, $plan->y, $plan->plateWidth, $plan->plateHeight,
                    $plan->radius, $white
                );
            }

            // The QR PNG carries an alpha channel. Blend it rather than replacing
            // pixels, or a transparent quiet zone comes out black.
            imagealphablending($photo, true);
            imagecopyresampled(
                $photo, $qr,
                $plan->x + $plan->platePadding, $plan->y + $plan->platePadding,
                0, 0,
                $plan->qrWidth, $plan->qrHeight,
                imagesx($qr), imagesy($qr)
            );

            return new FramedImage($encode(), $mime, $width, $height, true, null);
        } finally {
            imagedestroy($qr);
        }
    }

    /** GD has no rounded-rectangle primitive. */
    private function roundedRect($im, int $x, int $y, int $w, int $h, int $r, int $color): void
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
     * @return array{0:string,1:string} [bytes, contentType]
     * @throws TransportError
     */
    private function fetch(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            // A redirect could hop off the allowlist we just checked.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new TransportError(sprintf(
                'Could not fetch the source image %s: %s.',
                $url,
                $err !== '' ? $err : "HTTP $status"
            ));
        }

        if (strncmp($type, 'image/', 6) !== 0) {
            throw new TransportError(sprintf('%s returned "%s", not an image.', $url, $type));
        }

        if (strlen((string) $body) > $this->maxSourceBytes) {
            throw new TransportError(sprintf(
                'Source image %s is larger than the %d byte limit.',
                $url,
                $this->maxSourceBytes
            ));
        }

        return [(string) $body, $type];
    }
}
