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
                // Trace-It's own id, {tenantPrefix}-{postId}. Kept for support
                // questions; nothing addresses a code by it, postId is enough.
                'qrId'      => $minted['qrId'] ?? null,
                'postId'    => $minted['postId'] ?? null,
                // Where a scan actually goes: the Trace-It short link, which
                // redirects and attributes the visit. This is what the QR encodes.
                'shortUrl'  => $minted['shortUrl'] ?? null,
                // The "Original Source" button on the Trace-It landing page.
                'targetUrl' => $minted['targetUrl'] ?? null,
                // Trace-It's durable public PNG.
                'pngUrl'    => $minted['pngUrl'] ?? null,
                // Did Trace-It actually mint (and charge quota), or did we adopt
                // a code that already existed? Distinct from our cache state.
                'traceItCreated' => ($minted['created'] ?? false) === true,
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
         * Trace-It API, VERIFIED against its source (src/app/api/v1/qr/route.ts,
         * src/lib/api-qr.ts) and public/docs/api.html.
         *
         *   POST {base}/api/v1/qr   { postId, title?, targetUrl?, folder? }
         *     -> 201 { …, created: true }   first publish, charges quota
         *     -> 200 { …, created: false }  later publishes, free
         *
         * Idempotent, so calling it on every publish is safe and intended.
         * Mirrors server/traceit-client.js — correct both together.
         */
        $postId = self::normalisePostId($articleId);

        // Before creating, ask whether it already exists: by-post reads never
        // charge monthly quota, creates do. Our store is only a cache, so after
        // a redeploy this is what stops us re-minting every article.
        $existing = $this->fetchByPostId($postId);
        if ($existing !== null) {
            return $existing;
        }

        $body = ['postId' => $postId];
        // Only send fields we have: Trace-It treats a present key as an update,
        // so sending an empty title would blank a title set earlier.
        if ($title) {
            $body['title'] = $title;
        }
        if ($destinationUrl) {
            $body['targetUrl'] = $destinationUrl;
        }
        if ($folder = (getenv('TRACEIT_FOLDER') ?: 'Newsroom thumbnails')) {
            $body['folder'] = $folder;
        }

        $ch = curl_init($base . '/api/v1/qr');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Trace-It create failed: ' . self::describeError($raw, $status, $err));
        }

        return $this->toRecord(json_decode((string) $raw, true) ?: []);
    }

    /**
     * Trace-It's postId rules, from sanitizePostId() in src/lib/api-qr.ts:
     * letters, digits, underscore and hyphen; must start and end alphanumeric;
     * lowercased (post IDs are case-insensitive); 48 characters max. No dots.
     *
     * @throws RuntimeException if the ID cannot be used as-is. Deliberately not
     *         rewritten — rewriting could map two distinct posts onto one QR.
     */
    public static function normalisePostId($raw): string
    {
        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            throw new RuntimeException('postId is required');
        }
        if (strlen($trimmed) > 48) {
            throw new RuntimeException('postId must be 48 characters or fewer (got ' . strlen($trimmed) . ')');
        }
        $postId = strtolower($trimmed);
        if (!preg_match('/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/', $postId)) {
            throw new RuntimeException(
                'postId "' . $trimmed . '" is not valid: only letters, digits, underscore and '
                . 'hyphen, and it must start and end with a letter or digit'
            );
        }
        return $postId;
    }

    /**
     * GET /api/v1/qr/by-post/{postId} — free of monthly quota, still rate-limited.
     * SERVER-TO-SERVER ONLY: it sends the secret key.
     *
     * @return array{qrId:?string,postId:?string,shortUrl:?string,targetUrl:?string,pngUrl:?string,png:string,source:string}|null
     */
    private function fetchByPostId(string $postId): ?array
    {
        $key  = getenv('TRACEIT_API_KEY') ?: '';
        $base = rtrim(getenv('TRACEIT_BASE') ?: '', '/');
        if ($key === '' || $base === '' || !self::baseIsConfigured($base)) {
            return null;
        }

        $ch = curl_init($base . '/api/v1/qr/by-post/' . rawurlencode($postId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Accept: application/json',
            ],
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 404) {
            return null;   // no code for that post yet; the caller will create one
        }
        if ($raw === false || $status < 200 || $status >= 300) {
            // A failed lookup must not block a create — fall through and mint.
            error_log('[traceit] by-post lookup failed (HTTP ' . $status . '); will attempt create');
            return null;
        }

        return $this->toRecord(json_decode((string) $raw, true) ?: []);
    }

    /**
     * Normalises a Trace-It response.
     *
     * The subtle bit: `qr.png` (a base64 data URI) is populated ONLY when
     * `created` is true. Updates and by-post reads leave it as an empty string,
     * because the QR encodes shortUrl and that has not changed. Code that assumes
     * qr.png is always present works on first publish and breaks on the second —
     * so fall back to the public qr.pngUrl.
     *
     * @param array<string,mixed> $data
     */
    private function toRecord(array $data): array
    {
        $png = null;
        $uri = $data['qr']['png'] ?? null;

        if (is_string($uri) && $uri !== '' && ($p = strpos($uri, 'base64,')) !== false) {
            $decoded = base64_decode(substr($uri, $p + 7), true);
            if ($decoded !== false) {
                $png = $decoded;
            }
        }

        if ($png === null && !empty($data['qr']['pngUrl'])) {
            // qr.pngUrl is public, so this deliberately sends no auth header.
            $png = $this->fetch((string) $data['qr']['pngUrl']);
        }

        if ($png === null) {
            throw new RuntimeException('Trace-It response contained no usable QR image');
        }

        return [
            'qrId'      => $data['id'] ?? null,
            'postId'    => $data['postId'] ?? null,
            'shortUrl'  => $data['shortUrl'] ?? null,
            'targetUrl' => $data['targetUrl'] ?? null,
            'pngUrl'    => $data['qr']['pngUrl'] ?? null,
            'png'       => $png,
            // Trace-It's OWN created flag: true only when it actually minted a new
            // code and charged one unit of monthly quota. 201 sets it; a repeat
            // POST returns 200 with false; by-post omits it entirely. This is the
            // only thing that answers "did that cost quota?" — do not confuse it
            // with our local cache being cold, which is a different question.
            'created'   => ($data['created'] ?? false) === true,
            'source'    => 'trace-it',
        ];
    }

    /** Surfaces Trace-It's { error: { code, message } } instead of a bare status. */
    private static function describeError($raw, int $status, string $curlErr): string
    {
        if ($curlErr !== '') {
            return $curlErr;
        }
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if (isset($j['error']['code'])) {
            return $j['error']['code'] . ': ' . ($j['error']['message'] ?? '') . " (HTTP $status)";
        }
        return "HTTP $status";
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
