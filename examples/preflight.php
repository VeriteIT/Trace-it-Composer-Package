<?php

/**
 * preflight.php — check a Trace-It integration before going live.
 *
 * Run it on the machine that will actually make the calls, with the same config:
 *
 *   TRACEIT_API_KEY=sk_live_… \
 *   TRACEIT_BASE=https://acme.trace-it.io \
 *   php preflight.php [postId] [articleUrl] [imageUrl]
 *
 * Every check prints PASS, FAIL or SKIP with what to do about it. Exit code is 1
 * if anything failed, so it can gate a deploy.
 *
 * Send the output to Verite IT with any support question — it reports versions,
 * configuration and the exact failure, which is usually the whole answer.
 *
 * Costs at most one unit of monthly quota, and only the first time you run it for
 * a given post ID.
 */

declare(strict_types=1);

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

use VeriteIt\TraceItQr\ApiError;
use VeriteIt\TraceItQr\Client;
use VeriteIt\TraceItQr\PostId;
use VeriteIt\TraceItQr\TraceIt;
use VeriteIt\TraceItQr\TraceItException;

$postId     = $argv[1] ?? 'preflight-check';
$articleUrl = $argv[2] ?? null;
$imageUrl   = $argv[3] ?? null;

$pass = 0;
$fail = 0;
$skip = 0;

function result(string $status, string $name, string $detail = '', string $fix = ''): void
{
    global $pass, $fail, $skip;
    $status === 'PASS' ? $pass++ : ($status === 'FAIL' ? $fail++ : $skip++);

    printf("  %-4s  %-38s %s%s", $status, $name, $detail, PHP_EOL);
    if ($fix !== '' && $status === 'FAIL') {
        printf("        → %s%s", $fix, PHP_EOL);
    }
}

function section(string $title): void
{
    printf("%s%s%s%s", PHP_EOL, $title, PHP_EOL, str_repeat('-', strlen($title)) . PHP_EOL);
}

echo PHP_EOL . 'Trace-It integration preflight' . PHP_EOL;
echo '==============================' . PHP_EOL;

/* --- environment ---------------------------------------------------------- */

section('Environment');

PHP_VERSION_ID >= 80100
    ? result('PASS', 'PHP version', PHP_VERSION)
    : result('FAIL', 'PHP version', PHP_VERSION, 'This package needs PHP 8.1 or newer.');

extension_loaded('curl')
    ? result('PASS', 'ext-curl', (string) (curl_version()['version'] ?? ''))
    : result('FAIL', 'ext-curl', 'not loaded', 'Enable extension=curl in php.ini. Required.');

extension_loaded('gd')
    ? result('PASS', 'ext-gd', 'loaded')
    : result('FAIL', 'ext-gd', 'not loaded',
        'Enable extension=gd in php.ini. Required: compositing is what puts the code into '
        . 'the file a reader saves, and there is no mode that works without it.');

/*
 * The single most common failure in a fresh PHP install, and the error it produces
 * ("unable to get local issuer certificate") reads like a Trace-It problem rather
 * than a local one. Check it explicitly against a host we do not control.
 */
$cainfo = ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: '';
$tls = curl_init('https://www.php.net/');
curl_setopt_array($tls, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_NOBODY         => true,
    CURLOPT_TIMEOUT        => 15,
]);
curl_exec($tls);
$tlsErr = curl_error($tls);
curl_close($tls);

if ($tlsErr === '') {
    result('PASS', 'TLS trust store', $cainfo !== '' ? basename($cainfo) : 'system default');
} else {
    result('FAIL', 'TLS trust store', $tlsErr,
        'Download https://curl.se/ca/cacert.pem and set curl.cainfo and openssl.cafile to it '
        . 'in php.ini. Do NOT disable certificate verification.');
}

/* --- configuration -------------------------------------------------------- */

section('Configuration');

$apiKey  = (string) (getenv('TRACEIT_API_KEY') ?: '');
$baseUrl = (string) (getenv('TRACEIT_BASE') ?: '');

if ($apiKey === '') {
    result('FAIL', 'TRACEIT_API_KEY', 'not set', 'Set it to the sk_live_… key Verite IT issued.');
} elseif (!str_starts_with($apiKey, 'sk_live_')) {
    result('FAIL', 'TRACEIT_API_KEY', 'set, but does not look like a key',
        'Keys begin with sk_live_. Check you have not pasted a URL or a secret of another kind.');
} else {
    // Never print the key. The prefix is enough to tell two keys apart.
    result('PASS', 'TRACEIT_API_KEY', 'set (' . substr($apiKey, 0, 16) . '…)');
}

if ($baseUrl === '') {
    result('FAIL', 'TRACEIT_BASE', 'not set',
        'Set it to your tenant subdomain, for example https://acme.trace-it.io');
} elseif (!Client::isUsableBaseUrl($baseUrl)) {
    result('FAIL', 'TRACEIT_BASE', $baseUrl,
        'This is a placeholder, plain http, or malformed. Use your real https tenant '
        . 'subdomain. An API key must never be sent to an unverified host.');
} else {
    result('PASS', 'TRACEIT_BASE', $baseUrl);
}

$allowed = array_filter(array_map('trim', explode(',', (string) (getenv('TRACEIT_ALLOWED_IMAGE_HOSTS') ?: ''))));
if ($allowed === []) {
    result('SKIP', 'TRACEIT_ALLOWED_IMAGE_HOSTS', 'not set — only needed if you composite locally');
} else {
    result('PASS', 'TRACEIT_ALLOWED_IMAGE_HOSTS', implode(', ', $allowed));
}

/* --- your post IDs -------------------------------------------------------- */

section('Your post IDs');

if (PostId::isValid($postId)) {
    result('PASS', 'post ID format', $postId . ' → ' . PostId::from($postId)->value());
} else {
    $why = '';
    try {
        PostId::from($postId);
    } catch (TraceItException $e) {
        $why = $e->getMessage();
    }
    result('FAIL', 'post ID format', $postId, $why);
}

if ($articleUrl === null) {
    result('SKIP', 'article URL', 'not given — pass one as the 2nd argument to check it');
} elseif (str_starts_with(strtolower($articleUrl), 'https:')) {
    result('PASS', 'article URL is https', $articleUrl);
} else {
    result('FAIL', 'article URL is https', $articleUrl,
        'Trace-It only accepts https for targetUrl. A non-https URL is dropped, so the code '
        . 'still works but its landing page gets no "Original Source" button.');
}

/* --- live round trip ------------------------------------------------------ */

section('Live round trip');

if ($fail > 0) {
    result('SKIP', 'Trace-It API', 'skipped — fix the failures above first');
} else {
    $cacheDir = (string) (getenv('TRACEIT_CACHE_DIR') ?: sys_get_temp_dir() . '/trace-it-preflight');

    try {
        $traceIt = new TraceIt([
            'cacheDir'          => $cacheDir,
            'allowedImageHosts' => $allowed,
        ]);
        result('PASS', 'cache directory writable', $cacheDir);
    } catch (TraceItException $e) {
        result('FAIL', 'cache directory writable', $cacheDir, $e->getMessage());
        $traceIt = null;
    }

    if ($traceIt !== null) {
        try {
            $code = $traceIt->client()->ensure($postId, $articleUrl);
            result('PASS', 'create or fetch a code',
                $code->created ? 'created (one quota unit used)' : 'reused an existing code — no quota');
            result('PASS', 'code identity', $code->id);
            result('PASS', 'scan destination', $code->shortUrl);

            // pngUrl is public by design: the browser loads it with no credentials.
            $probe = curl_init($code->pngUrl);
            curl_setopt_array($probe, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_NOBODY => true,
            ]);
            curl_exec($probe);
            $imgStatus = (int) curl_getinfo($probe, CURLINFO_HTTP_CODE);
            curl_close($probe);

            $imgStatus === 200
                ? result('PASS', 'QR image is publicly reachable', 'HTTP 200')
                : result('FAIL', 'QR image is publicly reachable', 'HTTP ' . $imgStatus,
                    'The hosted QR should need no auth. Report this to Verite IT.');
        } catch (ApiError $e) {
            $fix = match (true) {
                $e->errorCode === 'unauthorized' => 'The key is wrong, revoked, or from a different environment.',
                $e->isQuotaExceeded()            => 'Monthly creation quota is exhausted. Ask Verite IT to raise it.',
                $e->isTransient()                => 'Transient. Retry'
                    . ($e->retryAfter !== null ? " after {$e->retryAfter}s." : ' shortly.'),
                default                          => 'Send this output to Verite IT.',
            };
            result('FAIL', 'create or fetch a code', $e->getMessage(), $fix);
        } catch (TraceItException $e) {
            result('FAIL', 'create or fetch a code', $e->getMessage(), 'Send this output to Verite IT.');
        }
    }

    /* --- compositing, only if asked ---------------------------------------- */

    if ($traceIt !== null && $imageUrl !== null) {
        section('Compositing (Save-as carries the code)');

        if (!extension_loaded('gd')) {
            result('FAIL', 'ext-gd', 'not loaded', 'Enable extension=gd to composite locally.');
        } elseif (!$traceIt->compositor()->imageHostAllowed($imageUrl)) {
            result('FAIL', 'image host allowed', $imageUrl,
                'Add its hostname to TRACEIT_ALLOWED_IMAGE_HOSTS. This allowlist is what stops '
                . 'the endpoint being used to fetch anything else your server can reach.');
        } else {
            try {
                $framed = $traceIt->framedImage($postId, $imageUrl);
                result($framed->hasBadge ? 'PASS' : 'FAIL', 'composite produced',
                    sprintf('%dx%d %s, %d KB%s', $framed->width, $framed->height, $framed->mime,
                        (int) round(strlen($framed->bytes) / 1024),
                        $framed->hasBadge ? '' : ' — no badge: ' . ($framed->reason ?? '')),
                    'Send this output to Verite IT.');

                $out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'preflight-composite.' . $framed->extension();
                file_put_contents($out, $framed->bytes);
                result('PASS', 'written for inspection', $out);
            } catch (TraceItException $e) {
                result('FAIL', 'composite produced', $e->getMessage(), 'Send this output to Verite IT.');
            }
        }
    } elseif ($traceIt !== null) {
        section('Compositing (Save-as carries the code)');
        result('SKIP', 'composite', 'pass an image URL as the 3rd argument to test this');
    }
}

/* --- summary -------------------------------------------------------------- */

printf('%s%s%s', PHP_EOL, str_repeat('=', 30), PHP_EOL);
printf('  %d passed, %d failed, %d skipped%s', $pass, $fail, $skip, PHP_EOL);

if ($fail > 0) {
    printf('%s  Not ready. Fix the FAIL lines above, then run this again.%s%s', PHP_EOL, PHP_EOL, PHP_EOL);
    exit(1);
}

printf('%s  Ready. Wire up publish() and the script tag — see the integration guide.%s%s',
    PHP_EOL, PHP_EOL, PHP_EOL);
exit(0);
