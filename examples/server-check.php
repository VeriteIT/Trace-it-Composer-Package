<?php

/**
 * server-check.php — can this server run the integration? Run it BEFORE installing.
 * ===========================================================================
 * SELF-CONTAINED ON PURPOSE. No Composer, no autoloader, no package, no ext-gd.
 * Download this one file, drop it on the server, run it, send us the output.
 *
 * It exists because of a chicken-and-egg: the package's own preflight.php needs
 * the package installed, so it could never tell you about a server that cannot
 * install it. This can.
 *
 * It is also written in deliberately old-fashioned PHP — no type declarations, no
 * null coalescing, nothing newer than PHP 5.4. A checker that dies with a parse
 * error on an old interpreter has failed at the one job it has, which is to tell
 * you the interpreter is too old.
 *
 * Usage
 *   php server-check.php
 *   php server-check.php <samplePostId>
 *   php server-check.php <samplePostId> <anImageUrl>
 *
 * The image URL is worth passing: it is fetched exactly as the compositor would,
 * which proves the photo host is reachable from THIS server and needs no signed
 * URL. Use a real article image.
 *
 * Nothing is written anywhere except a temporary file that is removed again, and
 * no credentials are needed or read.
 * ===========================================================================
 */

$pass = 0;
$warn = 0;
$fail = 0;

function line($status, $name, $detail, $fix)
{
    global $pass, $warn, $fail;
    if ($status === 'PASS') { $pass++; } elseif ($status === 'WARN') { $warn++; } else { $fail++; }

    printf("  %-4s  %-26s %s%s", $status, $name, $detail, PHP_EOL);
    if ($fix !== '' && $status !== 'PASS') {
        printf("        -> %s%s", $fix, PHP_EOL);
    }
}

function heading($text)
{
    printf("%s%s%s%s", PHP_EOL, $text, PHP_EOL, str_repeat('-', strlen($text)) . PHP_EOL);
}

echo PHP_EOL . 'Trace-It QR — server check' . PHP_EOL;
echo '==========================' . PHP_EOL;
echo 'Run before installing. Send this whole output to Verite IT.' . PHP_EOL;

/* --- the interpreter ------------------------------------------------------ */

heading('PHP');

if (PHP_VERSION_ID >= 80100) {
    line('PASS', 'PHP version', PHP_VERSION . ' — use the standard package, veriteit/trace-it-qr', '');
} elseif (PHP_VERSION_ID >= 70400) {
    line('PASS', 'PHP version', PHP_VERSION . ' — use the 7.4 build, veriteit/trace-it-qr-php74', '');
} else {
    line('FAIL', 'PHP version', PHP_VERSION,
        'The package needs PHP 7.4 or newer. Nothing else in this report matters until that is fixed.');
}

echo '        ' . 'SAPI: ' . PHP_SAPI . ', ' . (PHP_INT_SIZE * 8) . '-bit' . PHP_EOL;

/* --- extensions ----------------------------------------------------------- */

heading('Extensions');

if (extension_loaded('json')) {
    line('PASS', 'ext-json', 'loaded', '');
} else {
    line('FAIL', 'ext-json', 'not loaded', 'Required. Enable extension=json in php.ini.');
}

if (extension_loaded('curl')) {
    $v = function_exists('curl_version') ? curl_version() : array();
    line('PASS', 'ext-curl', isset($v['version']) ? $v['version'] : 'loaded', '');
    if (isset($v['ssl_version'])) {
        echo '        ' . 'TLS: ' . $v['ssl_version'] . PHP_EOL;
    }
} else {
    line('FAIL', 'ext-curl', 'not loaded', 'Required. Enable extension=curl in php.ini.');
}

/*
 * ext-gd is the one that decides what this server can do, so it is reported as a
 * WARN rather than a FAIL: a server without it can still register codes, it just
 * cannot be the one that composites.
 */
if (extension_loaded('gd')) {
    $info = function_exists('gd_info') ? gd_info() : array();
    $jpeg = isset($info['JPEG Support']) && $info['JPEG Support'];
    $png  = isset($info['PNG Support']) && $info['PNG Support'];
    line('PASS', 'ext-gd', 'loaded' . ($jpeg ? ', JPEG' : ', NO JPEG') . ($png ? ', PNG' : ', NO PNG'), '');
    if (!$jpeg || !$png) {
        line('WARN', 'gd image formats', 'JPEG and PNG should both be supported',
            'Rebuild gd with libjpeg and libpng, or article photos in the missing format cannot be composited.');
    }
} else {
    line('WARN', 'ext-gd', 'not loaded',
        'Only needed on the server that serves the composited image. This server could still '
        . 'register codes. If it is meant to serve images, install it (php-gd) — or ask us about '
        . 'generating the images ahead of time on a machine that has it.');
}

/* --- can it reach the internet, and with a working trust store? ------------ */

heading('Outbound HTTPS');

if (!extension_loaded('curl')) {
    line('WARN', 'HTTPS reachability', 'skipped — ext-curl is not loaded', '');
} else {
    $ch = curl_init('https://qr.trace-it.io/js/traceit-qr.js');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code >= 200 && $code < 400) {
        line('PASS', 'HTTPS out', 'reached qr.trace-it.io (HTTP ' . $code . ')', '');
    } elseif (strpos($err, 'certificate') !== false) {
        line('FAIL', 'HTTPS out', $err,
            'PHP has no CA bundle configured. Point curl.cainfo and openssl.cafile at a current '
            . 'cacert.pem in php.ini. Do not disable certificate verification.');
    } else {
        line('WARN', 'HTTPS out', $err !== '' ? $err : ('HTTP ' . $code),
            'This server could not reach our script host. If outbound traffic is firewalled, the '
            . 'publish step will not work from here.');
    }
}

/* --- somewhere to cache --------------------------------------------------- */

heading('Cache directory');

$candidate = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'trace-it-server-check';
$made = @mkdir($candidate, 0775, true);
if ($made || is_dir($candidate)) {
    $probe = $candidate . DIRECTORY_SEPARATOR . 'probe.tmp';
    if (@file_put_contents($probe, 'ok') !== false) {
        line('PASS', 'temp directory writable', sys_get_temp_dir(), '');
        @unlink($probe);
    } else {
        line('FAIL', 'temp directory writable', 'cannot write in ' . sys_get_temp_dir(),
            'The package caches codes on disk. Give PHP a writable directory and set cacheDir.');
    }
    @rmdir($candidate);
} else {
    line('FAIL', 'temp directory writable', 'cannot create a directory in ' . sys_get_temp_dir(),
        'The package caches codes on disk. Give PHP a writable directory and set cacheDir.');
}

echo '        ' . 'For production set cacheDir to something that survives deploys, e.g. /var/lib/trace-it' . PHP_EOL;

/* --- article id ----------------------------------------------------------- */

heading('Article ID');

/*
 * This mirrors PostId exactly: trim, reject empty, 48 characters maximum, then
 * lowercase and match. Kept in step with src/PostId.php deliberately — if the two
 * ever disagree this file is wrong, because the package is the authority.
 */
function traceit_id_problem($raw)
{
    $trimmed = trim((string) $raw);

    if ($trimmed === '') {
        return 'it is empty';
    }
    if (strlen($trimmed) > 48) {
        return 'it is ' . strlen($trimmed) . ' characters; the maximum is 48';
    }
    if (!preg_match('/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/', strtolower($trimmed))) {
        return 'it must use only letters, digits, underscore and hyphen, and start and end with a '
             . 'letter or digit — no dots, no slashes';
    }

    return '';
}

$sample = isset($argv[1]) ? $argv[1] : '';

if ($sample === '') {
    line('WARN', 'sample article ID', 'none given',
        'Re-run with one of your real article IDs as the first argument, e.g. '
        . 'php server-check.php 108347979 — it takes a second and settles the question.');
} else {
    $problem = traceit_id_problem($sample);
    if ($problem === '') {
        line('PASS', 'sample article ID', '"' . $sample . '" is usable as-is', '');
    } else {
        line('FAIL', 'sample article ID', '"' . $sample . '" cannot be used: ' . $problem,
            'If your IDs are slugs, pass the underlying numeric post ID to us instead. IDs are '
            . 'rejected rather than rewritten, because rewriting could map two articles onto one code.');
    }
}

/* --- the photo host ------------------------------------------------------- */

heading('Article image host');

$imageUrl = isset($argv[2]) ? $argv[2] : '';

if ($imageUrl === '') {
    line('WARN', 'image fetch', 'no image URL given',
        'Re-run with a real article image URL as the second argument. It proves the photo host is '
        . 'reachable from this server and needs no signed URL, which is the thing most likely to '
        . 'surprise us later.');
} elseif (!extension_loaded('curl')) {
    line('WARN', 'image fetch', 'skipped — ext-curl is not loaded', '');
} else {
    $host = parse_url($imageUrl, PHP_URL_HOST);
    $ch = curl_init($imageUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code === 200 && is_string($body) && strlen($body) > 0) {
        line('PASS', 'image fetch', $host . ' -> HTTP 200, ' . $type . ', '
            . (int) round(strlen($body) / 1024) . ' KB', '');
        echo '        ' . 'Put this hostname in allowedImageHosts: ' . $host . PHP_EOL;

        if (function_exists('imagecreatefromstring') && extension_loaded('gd')) {
            $im = @imagecreatefromstring($body);
            if ($im) {
                line('PASS', 'image decodes', imagesx($im) . 'x' . imagesy($im) . ' — this server could composite it', '');
            } else {
                line('FAIL', 'image decodes', 'gd could not decode what came back',
                    'The URL returned something that is not an image gd understands.');
            }
        }
    } elseif ($code === 403 || $code === 401) {
        line('FAIL', 'image fetch', $host . ' -> HTTP ' . $code,
            'The photo host refused an ordinary request. If the bucket needs a signed URL, tell us — '
            . 'compositing fetches these images with a plain unauthenticated GET.');
    } else {
        line('FAIL', 'image fetch', $host . ' -> ' . ($err !== '' ? $err : ('HTTP ' . $code)),
            'This server could not fetch the article photo. Compositing happens here, so it has to be '
            . 'able to.');
    }
}

/* --- verdict -------------------------------------------------------------- */

heading('Verdict');

if (PHP_VERSION_ID < 70400 || !extension_loaded('curl') || !extension_loaded('json')) {
    echo '  This server cannot run the integration yet. Fix the FAIL lines above.' . PHP_EOL;
} elseif (extension_loaded('gd')) {
    echo '  This server can do both jobs: register codes when an article is published,' . PHP_EOL;
    echo '  and serve the composited image that makes "Save image as..." carry the code.' . PHP_EOL;
} else {
    echo '  This server can register codes, but it cannot composite images without ext-gd.' . PHP_EOL;
    echo '  That is fine if another server serves the images. If this is the public-facing' . PHP_EOL;
    echo '  site, either install ext-gd or ask us about generating the images in advance.' . PHP_EOL;
}

echo PHP_EOL . '==========================' . PHP_EOL;
printf('  %d passed, %d warnings, %d failed%s', $pass, $warn, $fail, PHP_EOL);
echo PHP_EOL;

exit($fail > 0 ? 1 : 0);
