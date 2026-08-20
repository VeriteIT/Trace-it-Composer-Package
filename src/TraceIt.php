<?php

declare(strict_types=1);

namespace VeriteIt\TraceItQr;

use VeriteIt\TraceItQr\Cache\FilesystemStore;
use VeriteIt\TraceItQr\Cache\Store;

/**
 * The one class a CMS needs. Three methods, one per step of the integration.
 * ===========================================================================
 *   1. publish()      when an article goes live — registers its ID with Trace-It
 *   2. qr()           when rendering — the code for an article, from its ID
 *   3. framedImage()  optional — the photo with the QR baked into the pixels,
 *                     so that "Save image as…" includes it
 *
 * Everything is addressed by YOUR post ID. Trace-It's own id is never something
 * you have to store.
 *
 * SERVER-SIDE ONLY. This holds the secret API key.
 * ===========================================================================
 */
final class TraceIt
{
    private Client $client;
    private Store $store;
    private ?Compositor $compositor = null;

    /** @var list<string> */
    private array $allowedImageHosts;

    private Layout $layout;
    private int $jpegQuality;
    private Log $log;

    /**
     * @param array{
     *   apiKey?: string,
     *   baseUrl?: string,
     *   folder?: string,
     *   cacheDir?: string,
     *   store?: Store,
     *   allowedImageHosts?: list<string>,
     *   jpegQuality?: int,
     *   layout?: Layout|array<string,mixed>,
     *   timeout?: int,
     *   logger?: callable(string, string): void
     * } $config
     *
     * @throws Misconfigured
     */
    public function __construct(array $config = [])
    {
        $apiKey  = (string) ($config['apiKey'] ?? getenv('TRACEIT_API_KEY') ?: '');
        $baseUrl = (string) ($config['baseUrl'] ?? getenv('TRACEIT_BASE') ?: '');

        if ($baseUrl === '') {
            throw new Misconfigured(
                'No Trace-It base URL. Set TRACEIT_BASE, or pass baseUrl. It is your tenant '
                . 'subdomain, for example https://acme.trace-it.io .'
            );
        }

        /*
         * Built first, because everything below it reports through it. Nothing in
         * this package throws for a degradation, so without a logger the only
         * evidence of one is a trigger_error a production php.ini does not display.
         */
        $this->log = new Log($config['logger'] ?? null);

        $this->client = new Client(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            folder: (string) ($config['folder'] ?? getenv('TRACEIT_FOLDER') ?: ''),
            timeout: (int) ($config['timeout'] ?? 15),
            log: $this->log,
        );

        $this->store = $config['store']
            ?? new FilesystemStore($config['cacheDir'] ?? null, $this->log);

        $this->allowedImageHosts = array_values($config['allowedImageHosts'] ?? array_filter(
            array_map('trim', explode(',', (string) (getenv('TRACEIT_ALLOWED_IMAGE_HOSTS') ?: '')))
        ));

        $this->jpegQuality = (int) ($config['jpegQuality'] ?? getenv('TRACEIT_JPEG_QUALITY') ?: 95);

        $layout = $config['layout'] ?? null;
        $this->layout = $layout instanceof Layout
            ? $layout
            : (new Layout())->with(is_array($layout) ? $layout : []);
    }

    /** Reads everything from environment variables. */
    public static function fromEnv(): self
    {
        return new self();
    }

    public function client(): Client
    {
        return $this->client;
    }

    public function store(): Store
    {
        return $this->store;
    }

    /**
     * STEP 1 — call this when an article is published.
     *
     * Registers the post ID with Trace-It and caches the result. Idempotent: safe
     * on every publish and re-publish, and only the first call per post costs
     * monthly quota.
     *
     * NEVER LET THIS BREAK A PUBLISH. A QR code is not worth failing an editor's
     * action over, so this returns null on failure instead of throwing, and reports
     * the reason through the configured `logger` (see Log). The code will be
     * created on the next publish, or on first render via qr().
     *
     * @param string|int  $postId     Your own article ID.
     * @param string|null $articleUrl The live article URL. Must be https to be
     *                                used; a non-https URL is dropped, not fatal.
     * @param string|null $publishedAt When the ARTICLE was published, ISO 8601.
     *                                Shown as "Date Published" on the verification
     *                                page. Omit it and that falls back to the code's
     *                                creation date — right when you publish live,
     *                                wrong when backfilling an archive.
     * @param string|null $imageUrl   Public thumbnail URL. Usually unnecessary —
     *                                prefer handing it to framedImage(), which runs
     *                                inside your CMS and can look it up fresh.
     *                                Supplied here it is remembered locally as a
     *                                fallback for a composite endpoint that cannot
     *                                reach your CMS, and is only refreshed when
     *                                publish() is next called with a different
     *                                value — so it goes stale if a photo is
     *                                replaced without a re-publish.
     */
    public function publish(
        string|int $postId,
        ?string $articleUrl = null,
        ?string $publishedAt = null,
        ?string $imageUrl = null,
    ): ?Code {
        try {
            return $this->remember($postId, $articleUrl, $publishedAt, $imageUrl, forceRefresh: true);
        } catch (TraceItException $e) {
            $this->log->warning('publish failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * STEP 2 — call this when rendering an article.
     *
     * Returns the code from cache when possible, so the common path makes no
     * network request at all. On a miss it asks Trace-It (a free read) and only
     * creates if nothing exists.
     *
     * @throws TraceItException when there is no cached code AND Trace-It cannot be
     *         reached. Rendering code should usually catch this and simply omit the
     *         badge rather than fail the page.
     */
    public function qr(
        string|int $postId,
        ?string $articleUrl = null,
        ?string $publishedAt = null,
        ?string $imageUrl = null,
    ): Code {
        return $this->remember($postId, $articleUrl, $publishedAt, $imageUrl, forceRefresh: false);
    }

    /** Same as qr() but returns null instead of throwing. */
    public function qrOrNull(
        string|int $postId,
        ?string $articleUrl = null,
        ?string $publishedAt = null,
        ?string $imageUrl = null,
    ): ?Code {
        try {
            return $this->qr($postId, $articleUrl, $publishedAt, $imageUrl);
        } catch (TraceItException $e) {
            $this->log->notice($e->getMessage());
            return null;
        }
    }

    /**
     * The public URL of the branded QR PNG for a post. Drop straight into an
     * <img src>. Hosted by Trace-It, needs no auth, cacheable forever.
     */
    public function qrPngUrl(string|int $postId): ?string
    {
        $code = $this->qrOrNull($postId);

        return $code?->pngUrl;
    }

    /**
     * STEP 3 (optional) — the photo with the QR composited into the pixels.
     *
     * This is the only way a native "Save image as…" can produce a QR-embedded
     * file. Needs ext-gd, and the image host must be in allowedImageHosts.
     *
     * PASS $imageUrl. This runs in an endpoint that has the post ID and sits
     * inside your CMS, so resolving the photo is a local lookup — and one that is
     * always current. Omitting it falls back to whatever URL publish() last
     * recorded, which is only refreshed on a re-publish and therefore points at
     * the old file after a photo is replaced. The fallback exists for endpoints
     * with no CMS access, not as the normal path.
     *
     * Responses are served `immutable`, so bump $version whenever the badge design
     * changes — and note that a REPLACED PHOTO is invisible to anyone already
     * holding a composite for the same reason. If photo swaps are routine, make
     * $version carry something per-article rather than one global number.
     *
     * @throws TraceItException
     */
    public function framedImage(
        string|int $postId,
        ?string $imageUrl = null,
        string $version = '1',
        ?Layout $layout = null,
    ): FramedImage {
        $id   = PostId::from($postId);
        $code = $this->qr($id->value());

        $imageUrl ??= (string) ($this->store->get($id->value())['imageUrl'] ?? '');
        if ($imageUrl === '') {
            throw new Misconfigured(sprintf(
                'No source image known for post "%s". Pass imageUrl here, or supply it to '
                . 'publish() so it is remembered.',
                $id->value()
            ));
        }

        // Prefer PNG bytes already on disk. The alternative is refetching the same
        // public image from Trace-It on every composite, which is a needless hop.
        $qrBytes = null;
        if ($this->store instanceof FilesystemStore) {
            $qrBytes = $this->store->getPng($id->value());
        }
        $qrBytes ??= $code->pngBytes();

        return $this->compositor()->compose(
            $imageUrl,
            $qrBytes,
            ($layout ?? $this->layout)
        );
    }

    public function compositor(): Compositor
    {
        return $this->compositor ??= new Compositor(
            allowedImageHosts: $this->allowedImageHosts,
            jpegQuality: $this->jpegQuality,
            layout: $this->layout,
        );
    }

    /**
     * Cache-then-read-then-create. The ordering is the whole point: a create is
     * the only operation that charges quota, so it must be the last resort.
     *
     * @throws TraceItException
     */
    private function remember(
        string|int $postId,
        ?string $articleUrl,
        ?string $publishedAt,
        ?string $imageUrl,
        bool $forceRefresh,
    ): Code {
        $id  = PostId::from($postId);
        $key = $id->value();

        if (!$forceRefresh) {
            $cached = $this->store->get($key);
            if ($cached !== null && isset($cached['code'])) {
                $this->rememberImageUrl($key, $cached, $imageUrl);
                return Code::fromApi($cached['code']);
            }
        }

        // Locked, because an article going live to many readers at once must
        // produce ONE create, not one per request.
        return $this->store->lock($key, function () use ($key, $articleUrl, $publishedAt, $imageUrl): Code {
            // Re-check inside the lock: whoever held it before us has usually
            // already done the work, and creating again would spend quota twice.
            $cached = $this->store->get($key);
            if ($cached !== null && isset($cached['code'])) {
                $this->rememberImageUrl($key, $cached, $imageUrl);
                return Code::fromApi($cached['code']);
            }

            $code = $this->client->ensure($key, $articleUrl, $publishedAt);

            $record = [
                'postId'    => $key,
                'code'      => $code->jsonSerialize(),
                'imageUrl'  => $imageUrl,
                'createdAt' => gmdate('c'),
            ];
            $this->store->put($key, $record);

            // Cache the PNG bytes too, so rendering and compositing need no
            // network round trip later.
            if ($this->store instanceof FilesystemStore) {
                try {
                    $this->store->putPng($key, $code->pngBytes());
                } catch (TraceItException) {
                    // Not fatal: pngUrl still works, it is just one hop slower.
                }
            }

            return $code;
        });
    }

    /** @param array<string,mixed> $cached */
    private function rememberImageUrl(string $key, array $cached, ?string $imageUrl): void
    {
        if ($imageUrl === null || $imageUrl === '' || ($cached['imageUrl'] ?? null) === $imageUrl) {
            return;
        }
        $cached['imageUrl'] = $imageUrl;
        $this->store->put($key, $cached);
    }
}
