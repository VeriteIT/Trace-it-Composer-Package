<?php
/**
 * qr-store.php — articleId -> QR mapping, held on OUR side. PHP port of
 * server/store.js + server/traceit-client.js.
 * ===========================================================================
 * This is the answer to the access constraint. We have no read or write
 * permission on the publisher's database or their S3 bucket; the only thing we
 * are given is the article ID. So the mapping
 *
 *     publisher's article ID -> { Trace-It code, short URL, QR PNG }
 *
 * lives here, in our own storage, keyed by their ID. Nothing is ever written
 * back to them and nothing of theirs is read. Their ID is a lookup key.
 *
 * A directory of files is obviously not the production store — swap for
 * Postgres/Redis and nothing else changes. What matters is the behaviour:
 *   1. Mint once per article, ever. QR creation costs monthly quota, so only a
 *      cache miss may call Trace-It.
 *   2. Serialise concurrent first-views for the same article, so an article
 *      going live to 500 readers is ONE mint, not 500. flock() below.
 *
 * LOCAL QR GENERATION IS DEMO-ONLY
 *   Without TRACEIT_API_KEY this generates real, scannable codes locally with
 *   endroid/qr-code, so the demo works offline. That is the ONLY reason Composer
 *   appears anywhere in this project. In production this file calls the Trace-It
 *   API and the dependency is not needed — and the drop-in integration files
 *   (publish-hook.php, framed.php, qr-proxy.php) have no dependencies at all.
 * ===========================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

final class QrStore
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? (getenv('TRACEIT_DATA_DIR') ?: dirname(__DIR__, 2) . '/data') . '/php-qr-store';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0770, true);
        }
    }

    private function path(string $articleId, string $ext): string
    {
        return $this->dir . '/' . hash('sha256', $articleId) . '.' . $ext;
    }

    /** The raw QR PNG bytes for an article, or null if we hold none. */
    public function pngBytes(string $articleId): ?string
    {
        $f = $this->path($articleId, 'png');
        if (!is_readable($f)) {
            return null;
        }
        $b = file_get_contents($f);
        return $b === false ? null : $b;
    }

    /** @return array<string,mixed>|null */
    public function get(string $articleId): ?array
    {
        $f = $this->path($articleId, 'json');
        if (!is_readable($f)) {
            return null;
        }
        $j = json_decode((string) file_get_contents($f), true);
        return is_array($j) ? $j : null;
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            $j = json_decode((string) file_get_contents($f), true);
            if (is_array($j)) {
                $out[] = $j;
            }
        }
        usort($out, static fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));
        return $out;
    }

    public function setImageUrl(string $articleId, string $imageUrl): bool
    {
        $rec = $this->get($articleId);
        if ($rec === null) {
            return false;
        }
        if (($rec['imageUrl'] ?? null) === $imageUrl) {
            return true;   // no write, no disk churn
        }
        $rec['imageUrl'] = $imageUrl;
        $this->writeMeta($articleId, $rec);
        return true;
    }

    /**
     * Returns the record for an article, minting on first request only.
     *
     * @param  array{imageUrl?:string,title?:string} $extra
     * @return array<string,mixed>
     * @throws RuntimeException if minting fails
     */
    public function getOrCreate(string $articleId, string $destinationUrl, array $extra = []): array
    {
        $existing = $this->get($articleId);
        if ($existing !== null) {
            // A later call may know the image URL when the first one did not.
            if (!empty($extra['imageUrl']) && empty($existing['imageUrl'])) {
                $this->setImageUrl($articleId, $extra['imageUrl']);
                $existing['imageUrl'] = $extra['imageUrl'];
            }
            return $existing;
        }

        // Serialise concurrent first-views. Without this lock, N simultaneous
        // requests for a brand-new article each fire their own mint and burn N
        // times the quota. The lock file is separate from the data files so a
        // reader never blocks on a half-written record.
        $lock = fopen($this->path($articleId, 'lock'), 'c');
        if ($lock === false) {
            throw new RuntimeException('could not open mint lock');
        }

        try {
            flock($lock, LOCK_EX);

            // Re-check: whoever held the lock before us has probably done the work.
            $again = $this->get($articleId);
            if ($again !== null) {
                return $again;
            }

            $minted = $this->mint($articleId, $destinationUrl, $extra['title'] ?? null);

            $record = [
                'articleId' => $articleId,
                'qrId'      => $minted['qrId'],
                'shortUrl'  => $minted['shortUrl'],
                'source'    => $minted['source'],
                'createdAt' => gmdate('c'),
            ];
            if (!empty($extra['imageUrl'])) {
                $record['imageUrl'] = $extra['imageUrl'];
            }

            // PNG first, then metadata. get() keys off the JSON, so writing the
            // image before the record means a reader can never see a record
            // whose PNG is not on disk yet.
            $this->writeAtomic($this->path($articleId, 'png'), $minted['png']);
            $this->writeMeta($articleId, $record);

            return $record;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string,mixed> $record */
    private function writeMeta(string $articleId, array $record): void
    {
        $this->writeAtomic(
            $this->path($articleId, 'json'),
            (string) json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    private function writeAtomic(string $target, string $bytes): void
    {
        $tmp = $target . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $bytes) === false) {
            @unlink($tmp);
            throw new RuntimeException("could not write $target");
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException("could not rename into $target");
        }
    }

    /**
     * @return array{qrId:?string,shortUrl:?string,png:string,source:string}
     */
    /**
     * Hostnames that ship as placeholders in this repo. A bearer token must never
     * be sent to one of them: they are not known to belong to Trace-It, and an
     * Authorization header goes out on the very first request, before any
     * response could tell us we guessed wrong. Handing a live credential to
     * whoever happens to own a guessed domain is not a recoverable mistake.
     */
    private const PLACEHOLDER_HOSTS = [
        'demo.trace-it.io',
        'your-subdomain.trace-it.io',
        'traceit.example.com',
        'example.com',
    ];

    /** True if $base is safe to send a live API key to. */
    public static function baseIsConfigured(string $base): bool
    {
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        if ($host === '' || str_contains($host, '<') || str_contains($host, '>')) {
            return false;
        }
        return !in_array($host, self::PLACEHOLDER_HOSTS, true);
    }

    private function mint(string $articleId, string $destinationUrl, ?string $title): array
    {
        $key  = getenv('TRACEIT_API_KEY') ?: '';
        $base = rtrim(getenv('TRACEIT_BASE') ?: 'https://demo.trace-it.io', '/');

        // Refuse to leak the key to a placeholder host. Fall back to local
        // generation so the demo still works, and say why, loudly.
        if ($key !== '' && !self::baseIsConfigured($base)) {
            error_log(
                '[traceit] TRACEIT_API_KEY is set but TRACEIT_BASE is "' . $base . '", which is a '
                . 'placeholder. Refusing to send the key there. Set TRACEIT_BASE to the real '
                . 'Trace-It host to enable live minting; generating locally for now.'
            );
            return [
                'qrId'     => null,
                'shortUrl' => null,
                'png'      => $this->generateLocalPng($destinationUrl),
                'source'   => 'local (live refused: TRACEIT_BASE not configured)',
            ];
        }

        if ($key === '') {
            return [
                'qrId'     => null,
                'shortUrl' => null,
                'png'      => $this->generateLocalPng($destinationUrl),
                'source'   => 'local',
            ];
        }

        /*
         * ASSUMED Trace-It API shape — the repo is private and could not be read.
         * This mirrors server/traceit-client.js; correct both together.
         *   POST {base}/api/v1/qr  { sourceUrls, name, folder, reference }
         *   -> { id, shortUrl, qr: { png: "data:image/png;base64,...", pngUrl } }
         */
        $payload = json_encode([
            'sourceUrls' => [$destinationUrl],
            'name'       => $title ?: $destinationUrl,
            'folder'     => getenv('TRACEIT_FOLDER') ?: 'Newsroom thumbnails',
            'reference'  => $articleId,
        ], JSON_UNESCAPED_SLASHES);

        $ch = curl_init($base . '/api/v1/qr');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Trace-It mint failed: ' . ($err !== '' ? $err : "HTTP $status"));
        }

        $data = json_decode((string) $body, true);
        $uri  = $data['qr']['png'] ?? null;

        if (is_string($uri) && ($p = strpos($uri, 'base64,')) !== false) {
            $png = base64_decode(substr($uri, $p + 7), true);
            if ($png === false) {
                throw new RuntimeException('Trace-It returned an undecodable QR');
            }
        } elseif (!empty($data['qr']['pngUrl'])) {
            $png = $this->fetch((string) $data['qr']['pngUrl']);
            if ($png === null) {
                throw new RuntimeException('could not fetch the hosted QR PNG');
            }
        } else {
            throw new RuntimeException('Trace-It response contained no QR image');
        }

        return [
            'qrId'     => $data['id'] ?? null,
            'shortUrl' => $data['shortUrl'] ?? null,
            'png'      => $png,
            'source'   => 'trace-it',
        ];
    }

    /** Demo-only. A real, scannable code so the phone test works offline. */
    private function generateLocalPng(string $destinationUrl): string
    {
        $builder = new Builder(
            writer: new PngWriter(),
            data: $destinationUrl,
            encoding: new Encoding('UTF-8'),
            // M keeps the code readable with some print damage without inflating
            // the module count the way H would.
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 600,
            // A QR with no quiet zone is much harder for phones to lock onto.
            margin: 16,
        );

        return $builder->build()->getString();
    }

    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body === false || $status < 200 || $status >= 300) ? null : $body;
    }

    public function mode(): string
    {
        $key = getenv('TRACEIT_API_KEY') ?: '';
        if ($key === '') {
            return 'local';
        }
        $base = rtrim(getenv('TRACEIT_BASE') ?: 'https://demo.trace-it.io', '/');
        return self::baseIsConfigured($base) ? 'live' : 'local (TRACEIT_BASE not configured)';
    }

    public function dir(): string
    {
        return $this->dir;
    }
}
