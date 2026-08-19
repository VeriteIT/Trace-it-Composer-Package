<?php

declare(strict_types=1);

namespace VeriteIt\TraceItQr;

/**
 * One Trace-It QR code, as returned by the API.
 *
 * @see Client for the endpoints this comes from.
 */
final class Code implements \JsonSerializable
{
    /**
     * @param string      $id        Trace-It's own id, {tenantPrefix}-{postId}.
     * @param string      $postId    Your post ID — everything is addressable by this.
     * @param string      $shortUrl  What the QR encodes. Scanning it hits this,
     *                               which redirects and attributes the visit.
     * @param string|null $targetUrl The "Original Source" button on the landing page.
     * @param string      $pngUrl    Durable, PUBLIC, hosted 1024px branded PNG.
     * @param string      $pngData   Base64 data URI of the same image, or '' —
     *                               see $this->pngBytes() for why it is often empty.
     * @param bool        $created   True only when this call actually minted a new
     *                               code and charged one unit of monthly quota.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $postId,
        public readonly string $shortUrl,
        public readonly ?string $targetUrl,
        public readonly string $pngUrl,
        public readonly string $pngData,
        public readonly string $title = '',
        public readonly string $folder = '',
        public readonly ?string $createdAt = null,
        public readonly bool $created = false,
    ) {
    }

    /** @param array<string,mixed> $data A decoded Trace-It API response. */
    public static function fromApi(array $data): self
    {
        return new self(
            id:        (string) ($data['id'] ?? ''),
            postId:    (string) ($data['postId'] ?? ''),
            shortUrl:  (string) ($data['shortUrl'] ?? ''),
            targetUrl: isset($data['targetUrl']) ? (string) $data['targetUrl'] : null,
            pngUrl:    (string) ($data['qr']['pngUrl'] ?? ''),
            pngData:   (string) ($data['qr']['png'] ?? ''),
            title:     (string) ($data['title'] ?? ''),
            folder:    (string) ($data['folder'] ?? ''),
            createdAt: isset($data['createdAt']) ? (string) $data['createdAt'] : null,
            created:   ($data['created'] ?? false) === true,
        );
    }

    /**
     * The PNG bytes, fetching them if the response did not inline them.
     *
     * THE TRAP THIS EXISTS TO CLOSE: Trace-It populates `qr.png` only when
     * `created` is true. An update, or any by-post read, leaves it an empty
     * string — the QR encodes shortUrl, which has not changed, so there is
     * nothing to re-render. Code that reads `qr.png` directly therefore works on
     * first publish and silently breaks on the second.
     *
     * `pngUrl` is public, so this fetch sends no credentials.
     *
     * @param callable(string):string|null $fetch Injected for testing.
     * @throws TransportError
     */
    public function pngBytes(?callable $fetch = null): string
    {
        if ($this->pngData !== '' && ($p = strpos($this->pngData, 'base64,')) !== false) {
            $decoded = base64_decode(substr($this->pngData, $p + 7), true);
            if ($decoded !== false && $decoded !== '') {
                return $decoded;
            }
        }

        if ($this->pngUrl === '') {
            throw new TransportError('This code carries neither inline PNG data nor a pngUrl.');
        }

        $fetch ??= static function (string $url): string {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err    = curl_error($ch);
            curl_close($ch);

            if ($body === false || $status < 200 || $status >= 300) {
                throw new TransportError(sprintf(
                    'Could not fetch the QR image (%s).',
                    $err !== '' ? $err : "HTTP $status"
                ));
            }
            return (string) $body;
        };

        return $fetch($this->pngUrl);
    }

    /**
     * Serialises to the SAME shape fromApi() reads, so a value can round-trip
     * through a cache and come back whole.
     *
     * This deliberately keeps `qr` nested rather than flattening pngUrl to the top
     * level. A flat form looks tidier but then fromApi() cannot read it back: it
     * looks for $data['qr']['pngUrl'], finds nothing, and the restored object has
     * no image at all — which surfaces much later as "this code carries neither
     * inline PNG data nor a pngUrl" from something entirely unrelated to caching.
     * One shape, read and written by one pair of methods.
     *
     * `png` is emitted empty on purpose: the base64 data URI is ~90 KB and there is
     * no reason to keep it in a JSON record when pngUrl fetches the same bytes and
     * a Store can cache them as a file.
     *
     * `created` is deliberately NOT emitted either, and that one is load-bearing. It
     * describes a single API call — whether THAT request minted a code and charged
     * quota — not a property of the code. Persisting it means every later read out
     * of the cache reports created = true, which is precisely the wrong answer to
     * "did this cost me anything?". A value restored from cache therefore comes back
     * with created = false, which is the truth: reading a cache costs nothing.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'        => $this->id,
            'postId'    => $this->postId,
            'shortUrl'  => $this->shortUrl,
            'targetUrl' => $this->targetUrl,
            'title'     => $this->title,
            'folder'    => $this->folder,
            'createdAt' => $this->createdAt,
            'qr'        => ['pngUrl' => $this->pngUrl, 'png' => ''],
        ];
    }
}
