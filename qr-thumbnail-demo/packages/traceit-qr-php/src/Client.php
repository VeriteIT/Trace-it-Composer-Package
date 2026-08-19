<?php

declare(strict_types=1);

namespace VeriteIt\TraceItQr;

/**
 * The Trace-It API. THE ONLY CLASS THAT KNOWS THE WIRE FORMAT.
 * ===========================================================================
 * Contract verified against the Trace-It source (src/app/api/v1/qr/*,
 * src/lib/api-qr.ts) and exercised against the live service:
 *
 *   Base   https://<tenant-subdomain>.trace-it.io
 *   Auth   Authorization: Bearer sk_live_…
 *
 *   POST /api/v1/qr                    { postId, title?, targetUrl?, folder? }
 *                                      201 created:true   charges 1 quota unit
 *                                      200 created:false  repeat, charges nothing
 *   GET  /api/v1/qr/by-post/{postId}   200 the code, qr.png empty
 *                                      404 { error: { code: 'not_found' } }
 *
 *   Errors { error: { code, message } } — codes seen in practice:
 *          invalid_post_id, invalid_target_url, invalid_published_at,
 *          unauthorized, rate_limited, quota_exceeded, post_id_conflict,
 *          id_conflict, server_misconfigured, internal_error
 *
 * SERVER-SIDE ONLY. Every call here sends the secret key, so this class must
 * never be reachable from a browser. Anyone who can read the key can mint
 * against your Trace-It account.
 * ===========================================================================
 */
final class Client
{
    /**
     * Hostnames that ship as placeholders in documentation and examples.
     *
     * A bearer token must never be sent to one. The Authorization header goes out
     * on the very first request — before any response could reveal that the host
     * is wrong — so a mistyped or unset base URL would hand a live credential to
     * whoever owns that domain. Not a recoverable mistake.
     */
    private const PLACEHOLDER_HOSTS = [
        'demo.trace-it.io',
        'your-subdomain.trace-it.io',
        'traceit.example.com',
        'example.com',
        'localhost',
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $folder = '',
        private readonly int $timeout = 15,
        private readonly int $connectTimeout = 5,
    ) {
        if (trim($this->apiKey) === '') {
            throw new Misconfigured(
                'No Trace-It API key. Set TRACEIT_API_KEY, or pass apiKey in the config. '
                . 'Keys are issued per tenant and look like sk_live_… .'
            );
        }
        self::assertUsableBaseUrl($this->baseUrl);
    }

    /**
     * Refuses to talk to anything that looks like an unconfigured example.
     *
     * @throws Misconfigured
     */
    public static function assertUsableBaseUrl(string $baseUrl): void
    {
        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));

        if ($host === '' || str_contains($host, '<') || str_contains($host, '>')) {
            throw new Misconfigured(sprintf(
                'TRACEIT_BASE "%s" is not a usable URL. Use your tenant subdomain, '
                . 'for example https://acme.trace-it.io .',
                $baseUrl
            ));
        }

        if ($scheme !== 'https' && !str_starts_with($host, '127.') && $host !== 'localhost') {
            throw new Misconfigured(sprintf(
                'TRACEIT_BASE "%s" must use https. An API key sent over plain http is '
                . 'readable by anything on the network path.',
                $baseUrl
            ));
        }

        if (in_array($host, self::PLACEHOLDER_HOSTS, true) && !str_starts_with($host, '127.')) {
            throw new Misconfigured(sprintf(
                'TRACEIT_BASE "%s" is a placeholder host, so the API key is not being sent. '
                . 'Set it to your real tenant subdomain, for example https://acme.trace-it.io .',
                $baseUrl
            ));
        }
    }

    /** True if this base URL can be used, without throwing. */
    public static function isUsableBaseUrl(string $baseUrl): bool
    {
        try {
            self::assertUsableBaseUrl($baseUrl);
            return true;
        } catch (Misconfigured) {
            return false;
        }
    }

    /**
     * Creates or refreshes the code for one post. Idempotent on Trace-It's side:
     * safe to call on every publish, and only the first call per postId costs
     * quota.
     *
     * @param string|null $targetUrl The live article URL, used as the "Original
     *        Source" button on the landing page. MUST be https — Trace-It rejects
     *        anything else with 400 invalid_target_url. It is optional, so a
     *        non-https URL is dropped rather than allowed to fail the whole call:
     *        the QR still works, because it encodes shortUrl, not this.
     * @param string|null $publishedAt When the ARTICLE was published, ISO 8601
     *        (2026-02-14, or 2026-02-14T09:30:00Z). Shown as "Date Published" on the
     *        verification page. Omit it and that falls back to the code's creation
     *        date, which is only the same thing for a CMS publishing live.
     * @param list<string> $followUpPages
     *
     * @throws ApiError|TransportError|InvalidPostId
     */
    public function create(
        string|int $postId,
        ?string $targetUrl = null,
        ?string $publishedAt = null,
        array $followUpPages = [],
    ): Code {
        $id = PostId::from($postId);

        /*
         * `title` is the POST ID, not a headline.
         *
         * It names the code in the Trace-It dashboard, and naming by post ID keeps
         * a row there mapped to a CMS post with no wording to match. Headlines get
         * edited after publishing, and Trace-It treats a present `title` as an
         * update, so sending one would rewrite the name on every re-publish. The
         * post ID never changes.
         */
        $body = ['postId' => $id->value(), 'title' => $id->value()];

        if ($targetUrl !== null && $targetUrl !== '') {
            if (str_starts_with(strtolower($targetUrl), 'https:')) {
                $body['targetUrl'] = $targetUrl;
            } else {
                trigger_error(
                    sprintf(
                        'trace-it: targetUrl "%s" is not https, so it was omitted. Trace-It only '
                        . 'accepts https. The QR still works and still tracks; the landing page '
                        . 'just has no "Original Source" button.',
                        $targetUrl
                    ),
                    E_USER_NOTICE
                );
            }
        }

        /*
         * When the ARTICLE was published, as opposed to when we registered it.
         * Trace-It renders this as "Date Published" on the verification page, and
         * without it that falls back to the code's creation date — fine for a CMS
         * publishing live, wrong for a backfilled archive, which would otherwise
         * claim every old story was published on the day it was imported.
         *
         * Passed straight through rather than parsed here. Trace-It rejects an
         * unreadable date with 400 invalid_published_at rather than quietly
         * substituting one, and a second opinion in this package could only
         * disagree with it.
         */
        if ($publishedAt !== null && $publishedAt !== '') {
            $body['publishedAt'] = $publishedAt;
        }

        if ($this->folder !== '') {
            $body['folder'] = $this->folder;
        }
        if ($followUpPages !== []) {
            $body['followUpPages'] = array_values($followUpPages);
        }

        $payload = $this->request('POST', '/api/v1/qr', $body);

        return Code::fromApi($payload);
    }

    /**
     * Looks up an existing code by your post ID. Never charges monthly quota
     * (still rate limited), so this is the cheap path — call it freely.
     *
     * @return Code|null null when Trace-It holds no code for that post.
     * @throws ApiError|TransportError|InvalidPostId
     */
    public function findByPostId(string|int $postId): ?Code
    {
        $id = PostId::from($postId);

        $payload = $this->request(
            'GET',
            '/api/v1/qr/by-post/' . rawurlencode($id->value()),
            null,
            allow404: true
        );

        return $payload === null ? null : Code::fromApi($payload);
    }

    /**
     * The cheapest way to guarantee a code exists: read first, create only on a
     * miss. A create charges quota; a read does not.
     *
     * @throws ApiError|TransportError|InvalidPostId
     */
    public function ensure(
        string|int $postId,
        ?string $targetUrl = null,
        ?string $publishedAt = null,
        array $followUpPages = [],
    ): Code {
        $found = $this->findByPostId($postId);
        if ($found !== null) {
            return $found;
        }

        return $this->create($postId, $targetUrl, $publishedAt, $followUpPages);
    }

    /**
     * @param  array<string,mixed>|null $body
     * @return array<string,mixed>|null null only when $allow404 and the API 404s.
     * @throws ApiError|TransportError
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        bool $allow404 = false,
    ): ?array {
        $url = rtrim($this->baseUrl, '/') . $path;

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;

        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $raw        = curl_exec($ch);
        $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlErr    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new TransportError(sprintf(
                'Could not reach Trace-It at %s: %s. If this says "unable to get local issuer '
                . 'certificate", PHP has no CA bundle configured — set curl.cainfo in php.ini.',
                $url,
                $curlErr !== '' ? $curlErr : 'unknown transport error'
            ));
        }

        $rawHeaders = substr((string) $raw, 0, $headerSize);
        $bodyText   = substr((string) $raw, $headerSize);

        if ($status === 404 && $allow404) {
            return null;
        }

        $decoded = json_decode($bodyText, true);

        if ($status < 200 || $status >= 300) {
            $code    = is_array($decoded) ? (string) ($decoded['error']['code'] ?? '') : '';
            $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';

            throw new ApiError(
                sprintf(
                    'Trace-It %s %s failed: %s (HTTP %d)',
                    $method,
                    $path,
                    $code !== '' ? trim("$code: $message") : ($message ?: 'no error body'),
                    $status
                ),
                status: $status,
                errorCode: $code,
                retryAfter: self::retryAfter($rawHeaders),
            );
        }

        if (!is_array($decoded)) {
            throw new ApiError(
                'Trace-It returned a non-JSON body where a code was expected.',
                status: $status
            );
        }

        return $decoded;
    }

    /** Honour Retry-After on 429 so callers can back off correctly. */
    private static function retryAfter(string $rawHeaders): ?int
    {
        if (preg_match('/^retry-after:\s*(\d+)/im', $rawHeaders, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }
}
