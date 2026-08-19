<?php

/**
 * smoke-test.php — exercises all three steps against the live Trace-It API.
 *
 * Doubles as the shortest possible usage example. Run it after installing to
 * confirm the key, the base URL and (optionally) GD all work:
 *
 *   TRACEIT_API_KEY=sk_live_…  \
 *   TRACEIT_BASE=https://<subdomain>.trace-it.io \
 *   TRACEIT_ALLOWED_IMAGE_HOSTS=cdn.example.lk \
 *   php examples/smoke-test.php <postId> <articleUrl> [imageUrl]
 *
 * Cheap on quota: the first run for a given postId creates one code; every later
 * run reuses it and charges nothing.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use VeriteIt\TraceItQr\TraceIt;
use VeriteIt\TraceItQr\TraceItException;

$postId     = $argv[1] ?? 'smoke-test-1';
$articleUrl = $argv[2] ?? null;
$imageUrl   = $argv[3] ?? null;

$cacheDir = getenv('TRACEIT_CACHE_DIR') ?: (sys_get_temp_dir() . '/trace-it-smoke');

try {
    $traceIt = new TraceIt(['cacheDir' => $cacheDir]);
} catch (TraceItException $e) {
    fwrite(STDERR, "config: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "cache: $cacheDir" . PHP_EOL . PHP_EOL;

/* --- STEP 1: on publish --------------------------------------------------- */

$code = $traceIt->publish($postId, $articleUrl, $imageUrl);

if ($code === null) {
    fwrite(STDERR, "publish() returned null — see the warning above." . PHP_EOL);
    exit(1);
}

printf("1. publish()%s", PHP_EOL);
printf("     id        %s%s", $code->id, PHP_EOL);
printf("     postId    %s%s", $code->postId, PHP_EOL);
printf("     title     %s%s", $code->title, PHP_EOL);
printf("     shortUrl  %s%s", $code->shortUrl, PHP_EOL);
printf("     targetUrl %s%s", $code->targetUrl ?? '(none — omitted unless https)', PHP_EOL);
printf("     created   %s%s", $code->created ? 'true (one quota unit charged)' : 'false (reused)', PHP_EOL);

/* --- STEP 2: on render ---------------------------------------------------- */

$start = microtime(true);
$again = $traceIt->qr($postId);
printf("%s2. qr()%s", PHP_EOL, PHP_EOL);
printf("     %s, %.1f ms (cache hit, no network)%s",
    $again->id === $code->id ? 'same code' : 'MISMATCH', (microtime(true) - $start) * 1000, PHP_EOL);
printf("     pngUrl    %s%s", $again->pngUrl, PHP_EOL);

/* --- STEP 3: optional compositing ---------------------------------------- */

if ($imageUrl === null) {
    printf("%s3. framedImage() skipped — pass an imageUrl to test compositing.%s", PHP_EOL, PHP_EOL);
    exit(0);
}

try {
    $framed = $traceIt->framedImage($postId);
} catch (TraceItException $e) {
    printf("%s3. framedImage() failed: %s%s", PHP_EOL, $e->getMessage(), PHP_EOL);
    exit(1);
}

$out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $postId . '-framed.' . $framed->extension();
file_put_contents($out, $framed->bytes);

printf("%s3. framedImage()%s", PHP_EOL, PHP_EOL);
printf("     %dx%d %s, %d KB%s", $framed->width, $framed->height, $framed->mime,
    (int) round(strlen($framed->bytes) / 1024), PHP_EOL);
printf("     badge     %s%s", $framed->hasBadge ? 'embedded' : 'absent (' . $framed->reason . ')', PHP_EOL);
printf("     written   %s%s", $out, PHP_EOL);
printf("%sOpen that file — the QR is in the pixels, which is what makes Save-as carry it.%s", PHP_EOL, PHP_EOL);
