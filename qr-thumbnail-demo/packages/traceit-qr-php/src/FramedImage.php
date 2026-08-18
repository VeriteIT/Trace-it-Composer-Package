<?php

declare(strict_types=1);

namespace VeriteIt\TraceItQr;

/**
 * An article photo with the QR composited into it — the bytes a "Save image as…"
 * would write.
 */
final class FramedImage
{
    /**
     * @param string      $bytes  Encoded image data.
     * @param string      $mime   image/jpeg or image/png, matching the source.
     * @param bool        $hasBadge False when the photo came back untouched, which
     *                    is a deliberate degradation rather than an error — see
     *                    $reason. Callers should usually still serve it.
     * @param string|null $reason Why the badge is missing, when it is.
     */
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
        public readonly int $width,
        public readonly int $height,
        public readonly bool $hasBadge,
        public readonly ?string $reason = null,
    ) {
    }

    public function extension(): string
    {
        return $this->mime === 'image/png' ? 'png' : 'jpg';
    }

    /**
     * Headers to send with this image.
     *
     * `immutable` is right — the composite for a given post never changes — but it
     * means a browser will not ask again, so the URL you serve this from must carry
     * a version you can bump when the badge design changes. Otherwise a redesign is
     * invisible to everyone who has already loaded the page.
     *
     * @return array<string,string>
     */
    public function headers(string $postId, bool $immutable = true): array
    {
        return [
            'Content-Type'        => $this->mime,
            'Content-Length'      => (string) strlen($this->bytes),
            'Cache-Control'       => $immutable
                ? 'public, max-age=31536000, immutable'
                : 'public, max-age=3600',
            // Gives the save dialog a sensible filename instead of a random string.
            'Content-Disposition' => sprintf('inline; filename="%s-qr.%s"', $postId, $this->extension()),
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    /** Sends the headers and the body. Convenience for a plain PHP endpoint. */
    public function send(string $postId, bool $immutable = true): void
    {
        foreach ($this->headers($postId, $immutable) as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->bytes;
    }
}
