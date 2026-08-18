<?php
/**
 * router-publisher.php — the PUBLISHER's site, in PHP. Port of
 * server/publisher-site.js.
 * ===========================================================================
 * Run with:
 *   php -S 127.0.0.1:3000 -t public php/demo/router-publisher.php
 *
 * Stands in for the client's custom PHP news CMS. Everything here is code THEY
 * own; we do not deploy it and, in production, we cannot read it. It exists so
 * the demo has a realistic thing to integrate against.
 *
 * The entire integration surface on their side is one HTTP call — see
 * notify_traceit() below, which is php/publish-hook.php inlined. When an article
 * is published, POST its ID and public URL to our service. Note what is NOT
 * sent: no article body, no image bytes, no S3 credentials, no database access.
 *
 * Their images are served from the simulated S3 origin on :3001, so the article
 * pages load thumbnails cross-origin exactly as production does.
 * ===========================================================================
 */

declare(strict_types=1);

$PUBLIC_DIR = dirname(__DIR__, 2) . '/public';
$DATA_DIR   = getenv('TRACEIT_DATA_DIR') ?: dirname(__DIR__, 2) . '/data';
$ARTICLES_F = $DATA_DIR . '/publisher-articles.json';

$S3_BASE         = getenv('S3_BASE') ?: 'http://localhost:3001/media';
$TRACEIT_SERVICE = getenv('TRACEIT_SERVICE') ?: 'http://localhost:3002';
$WEBHOOK_SECRET  = getenv('TRACEIT_WEBHOOK_SECRET') ?: 'dev-webhook-secret';
$PUBLIC_BASE     = getenv('PUBLIC_BASE') ?: 'http://localhost:3000';

/* --- Their article storage ------------------------------------------------- */

function thumb_url(string $file, string $s3Base): string
{
    return rtrim($s3Base, '/') . '/' . $file;
}

function load_articles(string $file, string $s3Base): array
{
    if (is_readable($file)) {
        $j = json_decode((string) file_get_contents($file), true);
        if (is_array($j)) {
            return $j;
        }
    }
    // Seed from the bundled fixtures, rewriting the local /assets/... paths onto
    // the simulated S3 origin so every page loads its thumbnail cross-origin.
    $seed = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/server/articles.json'), true) ?: [];
    foreach ($seed as &$a) {
        $a['thumb'] = thumb_url(basename((string) $a['thumb']), $s3Base);
    }
    unset($a);
    save_articles($file, $seed);
    return $seed;
}

function save_articles(string $file, array $list): void
{
    @mkdir(dirname($file), 0770, true);
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
        @rename($tmp, $file);
    } else {
        @unlink($tmp);
    }
}

/** Mirrors the ID format seen on the live site, e.g. 108-347979. */
function next_article_id(): string
{
    return '108-' . random_int(100000, 999999);
}

/* --- THE INTEGRATION: one call, at publish time ---------------------------- */

/**
 * Tell Trace-It that an article went live. This is php/publish-hook.php.
 *
 * Deliberately fire-and-forget: if our service is down, publishing must still
 * succeed — a QR code is not worth failing an editor's publish action over. On
 * failure the code is created on first page view instead (the lazy path).
 */
function notify_traceit(array $article, string $service, string $secret, string $publicBase): array
{
    $payload = json_encode([
        'articleId' => $article['id'],
        'url'       => $publicBase . '/article/' . $article['id'],
        // Only needed for embed mode. The same public S3 URL every reader's
        // browser already fetches — not privileged data.
        'imageUrl'  => $article['thumb'],
    ], JSON_UNESCAPED_SLASHES);

    $ch = curl_init(rtrim($service, '/') . '/v1/hooks/article-published');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        // Short timeouts: this runs inside the editor's publish request and must
        // never be able to hang the CMS.
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $secret,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        error_log(sprintf('[cms] Trace-It notify failed for %s (HTTP %d) %s',
            $article['id'], $status, $err !== '' ? $err : (string) $body));
        return ['ok' => false, 'status' => $status, 'error' => $err !== '' ? $err : (string) $body];
    }

    return ['ok' => true, 'status' => $status, 'payload' => json_decode((string) $body, true)];
}

/* --- Routing -------------------------------------------------------------- */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

function send_json(int $status, $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
}

// GET /api/articles
if ($path === '/api/articles') {
    send_json(200, load_articles($ARTICLES_F, $S3_BASE));
    exit;
}

// GET /api/article/:id
if (preg_match('#^/api/article/([^/]+)$#', $path, $m)) {
    $id = urldecode($m[1]);
    foreach (load_articles($ARTICLES_F, $S3_BASE) as $a) {
        if (($a['id'] ?? null) === $id) {
            send_json(200, $a);
            exit;
        }
    }
    send_json(404, ['error' => 'not found']);
    exit;
}

// GET /api/config
if ($path === '/api/config') {
    send_json(200, [
        'traceItService' => $TRACEIT_SERVICE,
        's3Base'         => $S3_BASE,
        'publicBase'     => $PUBLIC_BASE,
        'impl'           => 'php',
    ]);
    exit;
}

// POST /api/publish — in their CMS this is the editor clicking "Publish".
if ($path === '/api/publish') {
    if ($method !== 'POST') {
        send_json(405, ['error' => 'POST required']);
        exit;
    }
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $headline = trim((string) ($in['headline'] ?? ''));
    if ($headline === '') {
        send_json(400, ['error' => 'headline required']);
        exit;
    }

    $id = next_article_id();
    $article = [
        'id'        => $id,
        'section'   => (string) ($in['section'] ?? 'Breaking News'),
        'headline'  => $headline,
        'byline'    => (string) ($in['byline'] ?? 'Staff Reporter'),
        'dateline'  => 'Colombo (Island Chronicle)',
        'published' => gmdate('c'),
        'thumb'     => thumb_url((string) ($in['image'] ?? 'article-1.jpg'), $S3_BASE),
        'caption'   => $headline,
        'body'      => [
            'This article was created by the demo publish flow to show the article ID '
                . 'being captured at publish time and turned into a Trace-It code.',
            'Right-click the thumbnail and choose “Save image as…” — the QR code is '
                . 'in the downloaded file, because the image itself was composited '
                . 'server-side from this article’s ID.',
        ],
    ];

    $articles = load_articles($ARTICLES_F, $S3_BASE);
    array_unshift($articles, $article);
    save_articles($ARTICLES_F, $articles);

    // Capture the ID -> hand it to Trace-It.
    $hook = notify_traceit($article, $TRACEIT_SERVICE, $WEBHOOK_SECRET, $PUBLIC_BASE);

    send_json(201, ['article' => $article, 'traceIt' => $hook]);
    exit;
}

// GET /article/:id  — where a QR scan lands. Serves the SPA shell.
if (preg_match('#^/article/[^/]+$#', $path)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($PUBLIC_DIR . '/article.html');
    exit;
}

// GET /cms
if ($path === '/cms') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($PUBLIC_DIR . '/cms.html');
    exit;
}

// GET /
if ($path === '/' || $path === '') {
    header('Content-Type: text/html; charset=utf-8');
    readfile($PUBLIC_DIR . '/home.html');
    exit;
}

/*
 * Anything else: let php -S serve it from the document root (-t public), which
 * covers /css/site.css, /js/*, /assets/*. Returning false from a router script
 * is how you hand the request back to the built-in server's static handler.
 */
return false;
