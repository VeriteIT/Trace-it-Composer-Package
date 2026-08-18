<?php
/**
 * router-traceit.php — OUR service, in PHP. Port of server/traceit-service.js.
 * ===========================================================================
 * Run with:
 *   php -S 127.0.0.1:3002 -t . php/demo/router-traceit.php
 *
 * This is the only component we own and deploy. It is a different origin from
 * the publisher's site on purpose, because in production it will be: their page
 * is on their domain, this API is on ours. That means this service — and only
 * this service — has to send CORS headers, which is fine, because it is ours to
 * configure. We never ask the publisher to change anything about their bucket or
 * their app.
 *
 * Two ways in, matching the two ways an article ID can reach us:
 *
 *   PATH A (preferred) — publish-time webhook
 *     POST /v1/hooks/article-published  { articleId, url, title, imageUrl }
 *     Authorization: Bearer <TRACEIT_WEBHOOK_SECRET>
 *     One mint per article, at a predictable time, authenticated.
 *
 *   PATH B (fallback) — lazy get-or-create on first page view
 *     GET /v1/qr/:id.png  or  /v1/framed/:id.jpg
 *     Zero CMS changes, but an unauthenticated endpoint can cause a mint, so it
 *     is gated and can be switched off with ALLOW_LAZY_MINT=false.
 *
 * Reading either path needs nothing from the publisher but the article ID.
 * ===========================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../traceit-compositor.php';
require_once __DIR__ . '/qr-store.php';

/*
 * Article IDs we accept, matching Trace-It's postId rules EXACTLY (see
 * sanitizePostId in the Trace-It repo, src/lib/api-qr.ts): letters, digits,
 * underscore and hyphen; must start AND end alphanumeric; 48 characters max.
 *
 * Note there is no dot, and the trailing character is constrained. An earlier
 * revision used [A-Za-z0-9._-]{0,63} which let "108.347979", "trail-" and
 * 60-character IDs through, so instead of a clean 400 here they reached the
 * mint path and surfaced as a 502 "mint failed" — a confusing error that blamed
 * the upstream for our own bad input.
 */
const ARTICLE_ID_RE = '/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,46}[A-Za-z0-9])?$/';

$store = new QrStore();

/* --- Configuration -------------------------------------------------------- */

$ALLOWED_ORIGINS = array_values(array_filter(array_map('trim', explode(',',
    getenv('TRACEIT_ALLOWED_ORIGINS') ?: 'http://localhost:3000,http://127.0.0.1:3000'
))));

$WEBHOOK_SECRET  = getenv('TRACEIT_WEBHOOK_SECRET') ?: 'dev-webhook-secret';
$ALLOW_LAZY_MINT = (getenv('ALLOW_LAZY_MINT') ?: 'true') !== 'false';
$ARTICLE_URL_TPL = getenv('ARTICLE_URL_TEMPLATE') ?: 'http://localhost:3000/article/{id}';

/**
 * Hosts a minted QR may point at. Defaults to the host in ARTICLE_URL_TEMPLATE.
 * A leaked webhook secret should not become a way to mint tracked links to
 * arbitrary destinations under the client's Trace-It account.
 */
$ALLOWED_DEST_HOSTS = array_values(array_filter(array_map('trim', explode(',',
    getenv('TRACEIT_ALLOWED_DESTINATION_HOSTS')
        ?: (string) parse_url(str_replace('{id}', 'x', $ARTICLE_URL_TPL), PHP_URL_HOST)
            . (($p = parse_url(str_replace('{id}', 'x', $ARTICLE_URL_TPL), PHP_URL_PORT)) ? ':' . $p : '')
))));

/* --- Helpers -------------------------------------------------------------- */

function article_url(string $id, string $tpl): string
{
    return str_replace('{id}', rawurlencode($id), $tpl);
}

function destination_allowed(string $url, array $allowed): bool
{
    if ($allowed === []) {
        return true;   // not configured: allow
    }
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        return false;
    }
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return false;
    }
    $host = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    return in_array($host, array_map('strtolower', $allowed), true);
}

function send_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/* --- CORS ----------------------------------------------------------------- */

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type,Authorization');
    header('Access-Control-Max-Age: 600');
    http_response_code(204);
    exit;
}

/* --- Static: we host the overlay script ----------------------------------- */

/*
 * WE serve the script, not the publisher. That is what reduces their integration
 * to a single <script src="https://our-service/js/…"> tag: they never vendor our
 * file, and we can ship fixes without asking them to redeploy. A <script src>
 * needs no CORS headers.
 */
if (preg_match('#^/js/([A-Za-z0-9._-]+\.js)$#', $path, $m)) {
    $file = dirname(__DIR__, 2) . '/public/js/' . $m[1];
    if (is_readable($file)) {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        header('Access-Control-Allow-Origin: *');
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}

/* --- Resolve: get the record, minting on miss if Path B is enabled -------- */

/**
 * @return array{record:array<string,mixed>,cached:bool}|null
 */
function resolve_article(QrStore $store, string $id, bool $allowLazy, string $tpl): ?array
{
    $existing = $store->get($id);
    if ($existing !== null) {
        return ['record' => $existing, 'cached' => true];
    }
    if (!$allowLazy) {
        return null;
    }
    return ['record' => $store->getOrCreate($id, article_url($id, $tpl)), 'cached' => false];
}

function public_view(array $r, bool $cached, QrStore $store): array
{
    $png = $store->pngBytes($r['articleId']);
    return [
        'articleId'  => $r['articleId'],
        'shortUrl'   => $r['shortUrl'] ?? null,
        'source'     => $r['source'] ?? null,
        'createdAt'  => $r['createdAt'] ?? null,
        'imageUrl'   => $r['imageUrl'] ?? null,
        'pngDataUri' => $png === null ? null : 'data:image/png;base64,' . base64_encode($png),
        'pngUrl'     => '/v1/qr/' . rawurlencode($r['articleId']) . '.png',
        'cached'     => $cached,
    ];
}

/* --- Routes --------------------------------------------------------------- */

// GET /v1/health
if ($path === '/v1/health') {
    send_json(200, [
        'ok'             => true,
        'impl'           => 'php',
        'php'            => PHP_VERSION,
        'mode'           => $store->mode(),
        'lazyMint'       => $ALLOW_LAZY_MINT,
        'allowedOrigins' => $ALLOWED_ORIGINS,
    ]);
    exit;
}

// GET /v1/codes
if ($path === '/v1/codes') {
    $all = $store->all();
    send_json(200, [
        'mode'     => $store->mode(),
        'impl'     => 'php',
        'lazyMint' => $ALLOW_LAZY_MINT,
        'count'    => count($all),
        'codes'    => array_map(static fn($r) => public_view($r, true, $store), $all),
    ]);
    exit;
}

// POST /v1/hooks/article-published
if ($path === '/v1/hooks/article-published') {
    if ($method !== 'POST') {
        send_json(405, ['error' => 'POST required']);
        exit;
    }
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . $WEBHOOK_SECRET) {
        send_json(401, ['error' => 'unauthorized']);
        exit;
    }

    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $id = (string) ($in['articleId'] ?? '');
    if (!preg_match(ARTICLE_ID_RE, $id)) {
        send_json(400, ['error' => 'valid articleId required']);
        exit;
    }

    $dest = (string) ($in['url'] ?? article_url($id, $ARTICLE_URL_TPL));
    if (!destination_allowed($dest, $ALLOWED_DEST_HOSTS)) {
        send_json(400, ['error' => 'destination host not allowed', 'allowed' => $ALLOWED_DEST_HOSTS]);
        exit;
    }

    // Only needed for embed mode. Same public URL every reader already fetches.
    $imageUrl = (string) ($in['imageUrl'] ?? '');
    $extra = ['title' => $in['title'] ?? null];
    if ($imageUrl !== '' && traceit_image_host_allowed($imageUrl)) {
        $extra['imageUrl'] = $imageUrl;
    }

    // Validate the postId shape before minting, so our own bad input is a 400
    // from us rather than a 502 blaming Trace-It.
    try {
        QrStore::normalisePostId($id);
    } catch (Throwable $e) {
        send_json(400, ['error' => 'invalid_post_id', 'detail' => $e->getMessage()]);
        exit;
    }

    $before = $store->get($id);
    try {
        $rec = $store->getOrCreate($id, $dest, $extra);
    } catch (Throwable $e) {
        send_json(502, ['error' => 'mint failed', 'detail' => $e->getMessage()]);
        exit;
    }

    send_json($before ? 200 : 201, [
        'articleId'  => $rec['articleId'],
        'shortUrl'   => $rec['shortUrl'] ?? null,
        'qrId'       => $rec['qrId'] ?? null,
        'source'     => $rec['source'] ?? null,
        // Two different facts, deliberately both reported. Reporting only the
        // second (as an earlier revision did) told the caller "created" every
        // time our cache was cold, which is exactly the wrong signal for
        // "did this cost quota?".
        'created'    => $rec['traceItCreated'] ?? false,  // Trace-It minted; quota charged
        'newToUs'    => $before === null,                 // our cache had no record
        'qrUrl'      => '/v1/qr/' . rawurlencode($id) . '.png',
        'framedUrl'  => isset($rec['imageUrl']) ? '/v1/framed/' . rawurlencode($id) . '.jpg' : null,
    ]);
    exit;
}

/*
 * GET /v1/framed/:id[.jpg] — the photo with the QR baked into the pixels.
 * Registered BEFORE /v1/qr so the more specific path wins.
 *
 * Note it calls traceit_composite() in-process with QR bytes read straight from
 * the store, rather than HTTP-fetching its own /v1/qr endpoint. Under `php -S`
 * that self-request would deadlock: the server is single-threaded, so the inner
 * request waits for a handler that is itself waiting on the inner request.
 */
if (preg_match('#^/v1/framed/([^/]+?)(?:\.(?:jpe?g|png))?$#', $path, $m)) {
    $id = urldecode($m[1]);
    if (!preg_match(ARTICLE_ID_RE, $id)) {
        http_response_code(400);
        exit;
    }

    try {
        $hit = resolve_article($store, $id, $ALLOW_LAZY_MINT, $ARTICLE_URL_TPL);
    } catch (Throwable $e) {
        send_json(502, ['error' => 'mint failed', 'detail' => $e->getMessage()]);
        exit;
    }
    if ($hit === null) {
        http_response_code(404);
        exit;
    }

    $imageUrl = (string) ($_GET['src'] ?? $hit['record']['imageUrl'] ?? '');
    if ($imageUrl === '') {
        send_json(400, [
            'error' => 'no source image known for this article',
            'hint'  => 'send imageUrl in the publish webhook, or pass ?src=<public image url>',
        ]);
        exit;
    }
    if (!traceit_image_host_allowed($imageUrl)) {
        send_json(403, [
            'error'   => 'source image host not allowed',
            'allowed' => traceit_allowed_image_hosts(),
        ]);
        exit;
    }

    // Remember a src that arrived by query string, so later requests need only
    // the ID and the URL stops being client-controlled.
    if (isset($_GET['src']) && empty($hit['record']['imageUrl'])) {
        $store->setImageUrl($id, $imageUrl);
    }

    $layout = traceit_layout_from_query($_GET);
    $cacheDir = $store->dir() . '/framed';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0770, true);
    }
    $ck = hash('sha256', implode('|', [
        $id, $imageUrl, $layout['corner'] ?? 'd', (string) ($layout['scale'] ?? 'd'),
    ]));
    $binF  = $cacheDir . '/' . $ck . '.bin';
    $mimeF = $cacheDir . '/' . $ck . '.mime';

    if (is_readable($binF)) {
        $mime = is_readable($mimeF) ? trim((string) file_get_contents($mimeF)) : 'image/jpeg';
        traceit_serve_image((string) file_get_contents($binF), $mime, $id, 'hit', 'embedded');
        exit;
    }

    [$photoBytes, $photoType] = traceit_fetch_bytes($imageUrl);
    if ($photoBytes === null) {
        send_json(502, ['error' => 'could not fetch the source image']);
        exit;
    }
    if (strncmp($photoType, 'image/', 6) !== 0) {
        send_json(415, ['error' => 'source is not an image', 'type' => $photoType]);
        exit;
    }

    try {
        $out = traceit_composite($photoBytes, $photoType, $store->pngBytes($id), $layout);
    } catch (Throwable $e) {
        send_json(415, ['error' => $e->getMessage()]);
        exit;
    }

    if ($out['badge']) {
        $tmp = $binF . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $out['bytes']) !== false) {
            @rename($tmp, $binF);
            @file_put_contents($mimeF, $out['mime']);
        } else {
            @unlink($tmp);
        }
    }

    traceit_serve_image(
        $out['bytes'], $out['mime'], $id, 'miss',
        $out['badge'] ? 'embedded' : 'absent (' . ($out['note'] ?? 'unknown') . ')'
    );
    exit;
}

// GET /v1/qr/:id.png — raw PNG, cheapest for repeat views (browser HTTP cache).
if (preg_match('#^/v1/qr/([^/]+?)\.png$#', $path, $m)) {
    $id = urldecode($m[1]);
    if (!preg_match(ARTICLE_ID_RE, $id)) {
        http_response_code(400);
        exit;
    }
    try {
        $hit = resolve_article($store, $id, $ALLOW_LAZY_MINT, $ARTICLE_URL_TPL);
    } catch (Throwable $e) {
        http_response_code(502);
        exit;
    }
    if ($hit === null) {
        http_response_code(404);
        exit;
    }
    $png = $store->pngBytes($id);
    if ($png === null) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/png');
    header('Content-Length: ' . (string) strlen($png));
    header('Cache-Control: public, max-age=31536000, immutable');
    echo $png;
    exit;
}

// GET /v1/qr/:id — JSON form, with the PNG inline as a data URI.
if (preg_match('#^/v1/qr/([^/]+)$#', $path, $m)) {
    $id = urldecode($m[1]);
    if (!preg_match(ARTICLE_ID_RE, $id)) {
        send_json(400, ['error' => 'bad articleId']);
        exit;
    }
    try {
        $hit = resolve_article($store, $id, $ALLOW_LAZY_MINT, $ARTICLE_URL_TPL);
    } catch (Throwable $e) {
        send_json(502, ['error' => 'mint failed', 'detail' => $e->getMessage()]);
        exit;
    }
    if ($hit === null) {
        send_json(404, ['error' => 'no code for that articleId']);
        exit;
    }
    header('Cache-Control: public, max-age=86400');
    send_json(200, public_view($hit['record'], $hit['cached'], $store));
    exit;
}

send_json(404, ['error' => 'not found', 'path' => $path]);
