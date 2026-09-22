<?php

declare(strict_types=1);

/**
 * prewarm.php — composite ahead of time and write the result to disk.
 * ===========================================================================
 * THE ALTERNATIVE TO STEP 4, for when the server that faces the public cannot
 * run the compositing endpoint — no ext-gd, no PHP, a static deployment, or a
 * CDN origin you do not control.
 *
 * Step 4 composites on demand: a request arrives, the endpoint fetches the photo,
 * draws the code and returns the bytes. That needs ext-gd on whichever machine
 * answers reader traffic. This script moves that work to publish time and to a
 * machine you choose — normally the CMS, which already has the photo, the article
 * data and (usually) ext-gd.
 *
 * WHAT IT PRODUCES. Files laid out exactly where the page script looks:
 *
 *   <outdir>/v1/framed/<postId>.jpg
 *
 * Point data-service at the directory that maps to <outdir> and nothing else is
 * needed on the public side — no PHP, no ext-gd, no endpoint:
 *
 *   <script src="https://qr.trace-it.io/js/traceit-qr.js"
 *           data-selector="img.story-thumb"
 *           data-service="https://www.example.lk/traceit"></script>
 *
 * …serving https://www.example.lk/traceit/v1/framed/123.jpg from <outdir>.
 *
 * GETTING THE FILES THERE is yours, because it depends on your hosting: write
 * straight into the public docroot if the two share a filesystem, rsync after
 * each run, or upload to the object storage or CDN origin your images already
 * come from. This script only writes the tree.
 *
 * CACHE BUSTING STILL WORKS. The script requests ?v=<version>, and a query string
 * is part of the cache key for browsers and every CDN worth the name — so bumping
 * the version fetches afresh even though the filename never changes. Overwrite the
 * file and bump the version together when a badge design or a photo changes.
 *
 * THE TRADE-OFF, stated plainly: a pre-generated file is a snapshot. Replace an
 * article's photo and the composite keeps the old picture until this runs again
 * for that article. The on-demand endpoint cannot go stale that way. Run this from
 * your publish hook, not on a nightly cron, and the window stays small.
 *
 * Usage
 *   One article:
 *     php prewarm.php <outdir> <postId> <imageUrl> [version]
 *
 *   Many, from stdin — one "postId<TAB>imageUrl" per line, for a backfill:
 *     php prewarm.php <outdir> --stdin [version] < articles.tsv
 *
 * Exit code is 1 if any article failed, so it can gate a deploy or a cron job.
 * ===========================================================================
 */

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

use VeriteIt\TraceItQr\TraceIt;
use VeriteIt\TraceItQr\TraceItException;

$outDir  = $argv[1] ?? '';
$second  = $argv[2] ?? '';

if ($outDir === '' || $second === '') {
    fwrite(STDERR, "usage: php prewarm.php <outdir> <postId> <imageUrl> [version]\n");
    fwrite(STDERR, "       php prewarm.php <outdir> --stdin [version] < articles.tsv\n");
    exit(2);
}

$traceIt = new TraceIt([
    'apiKey'   => getenv('TRACEIT_API_KEY'),
    'baseUrl'  => getenv('TRACEIT_BASE'),
    'cacheDir' => getenv('TRACEIT_CACHE_DIR') ?: sys_get_temp_dir() . '/trace-it',

    /*
     * Same security control as the endpoint. This script takes image URLs from a
     * file or your CMS rather than from a request, so the immediate SSRF risk is
     * smaller — but the allowlist costs nothing and a batch job that will fetch
     * any URL handed to it is still worth refusing to build.
     */
    'allowedImageHosts' => array_filter(
        explode(',', (string) getenv('TRACEIT_ALLOWED_IMAGE_HOSTS'))
    ),
]);

/** Write-then-rename, so a reader never sees a half-written composite. */
function writeAtomic(string $target, string $bytes): bool
{
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $tmp = $target . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $bytes) === false) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

$done = 0;
$failed = 0;

/**
 * The page script always asks for .jpg, whatever the source format, because the
 * endpoint it was written for sets Content-Type from the bytes. A static file
 * server sets it from the extension instead — so a PNG composite written here
 * would be served as image/jpeg. Browsers sniff and render it anyway, but it is
 * mislabelled, and that is worth telling you about rather than hiding.
 */
function emit(TraceIt $traceIt, string $outDir, string $postId, string $imageUrl, string $version): bool
{
    try {
        $framed = $traceIt->framedImage($postId, $imageUrl, $version);
    } catch (TraceItException $e) {
        printf("  FAIL  %-24s %s\n", $postId, $e->getMessage());

        return false;
    }

    $target = rtrim($outDir, '/\\') . '/v1/framed/' . $postId . '.jpg';
    if (!writeAtomic($target, $framed->bytes)) {
        printf("  FAIL  %-24s could not write %s\n", $postId, $target);

        return false;
    }

    $note = '';
    if (!$framed->hasBadge) {
        // Not an error: the photo came back untouched on purpose. Serving it is
        // still correct — the reader sees the picture, just without a code.
        $note = ' — NO BADGE: ' . (string) $framed->reason;
    } elseif ($framed->mime !== 'image/jpeg') {
        $note = ' — written as .jpg but the bytes are ' . $framed->mime
              . '; set a Content-Type override for this directory, or use JPEG sources';
    }

    printf(
        "  OK    %-24s %dx%d %s, %d KB%s\n",
        $postId,
        $framed->width,
        $framed->height,
        $framed->mime,
        (int) round(strlen($framed->bytes) / 1024),
        $note
    );

    return true;
}

if ($second === '--stdin') {
    $version = (string) ($argv[3] ?? '1');
    $line = 0;
    while (($raw = fgets(STDIN)) !== false) {
        $line++;
        $raw = trim($raw);
        if ($raw === '' || $raw[0] === '#') {
            continue;
        }
        $parts = preg_split('~\t+~', $raw);
        if (count($parts) < 2) {
            printf("  FAIL  line %-19d expected \"postId<TAB>imageUrl\"\n", $line);
            $failed++;
            continue;
        }
        emit($traceIt, $outDir, trim($parts[0]), trim($parts[1]), $version) ? $done++ : $failed++;
    }
} else {
    $imageUrl = $argv[3] ?? '';
    if ($imageUrl === '') {
        fwrite(STDERR, "an image URL is required: php prewarm.php <outdir> <postId> <imageUrl> [version]\n");
        exit(2);
    }
    emit($traceIt, $outDir, $second, $imageUrl, (string) ($argv[4] ?? '1')) ? $done++ : $failed++;
}

printf("\n%d written, %d failed\n", $done, $failed);

exit($failed > 0 ? 1 : 0);
