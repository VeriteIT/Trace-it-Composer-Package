<?php
/**
 * publish-hook.php — THE PUBLISHER'S SIDE OF THE INTEGRATION.
 *
 * This is the only code the client has to add to their CMS. Call
 * traceit_notify_published() from wherever their publish routine finishes —
 * right after the article row is committed and its ID exists.
 *
 * What leaves their server: the article ID, the public article URL, and the
 * headline (for our dashboard; optional, drop it if they would rather not).
 * What does NOT leave: article body, image bytes, S3 credentials, database
 * access. We never call back into their systems.
 *
 * Requires: ext-curl. No framework, no Composer.
 *
 * ---------------------------------------------------------------------------
 * NOT EXECUTED. PHP was not available in the environment where this demo was
 * built, so this file has never been run. It mirrors server/publisher-site.js,
 * which IS exercised by the demo. Lint it (`php -l publish-hook.php`) against
 * the target PHP version before shipping.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

/**
 * Tell Trace-It that an article went live.
 *
 * Deliberately fire-and-forget. If our service is slow or down, publishing an
 * article must still succeed — a QR code is not worth failing an editor's
 * publish action over. On failure the code is created on first page view
 * instead (the lazy path), so nothing is permanently lost.
 *
 * @param string      $articleId  The CMS's own article ID.
 * @param string      $articleUrl Public URL of the article.
 * @param string|null $title      Optional label for the Trace-It dashboard.
 * @return bool  true if Trace-It acknowledged.
 */
function traceit_notify_published(string $articleId, string $articleUrl, ?string $title = null): bool
{
    $endpoint = getenv('TRACEIT_SERVICE') ?: 'https://traceit.example.com';
    $secret   = getenv('TRACEIT_WEBHOOK_SECRET') ?: '';

    if ($secret === '') {
        error_log('[traceit] TRACEIT_WEBHOOK_SECRET not configured; skipping notify');
        return false;
    }

    $payload = json_encode([
        'articleId' => $articleId,
        'url'       => $articleUrl,
        'title'     => $title,
    ], JSON_UNESCAPED_SLASHES);

    $ch = curl_init(rtrim($endpoint, '/') . '/v1/hooks/article-published');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        // Short timeouts on purpose: this runs inside the editor's publish
        // request, so it must never be able to hang the CMS.
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $secret,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        // Log and move on. Never rethrow into the publish flow.
        error_log(sprintf(
            '[traceit] notify failed for article %s (HTTP %d) %s',
            $articleId, $status, $err !== '' ? $err : (string) $body
        ));
        return false;
    }

    return true;
}

/*
 * Wiring it in — the whole change, in their existing publish routine:
 *
 *     $articleId = $cms->publish($draft);           // their existing code
 *
 *     traceit_notify_published(                     // <- the addition
 *         $articleId,
 *         'https://www.example.lk/article/' . $articleId,
 *         $draft->headline
 *     );
 *
 * And in the article template, the thumbnail gains one attribute:
 *
 *     <img src="<?= htmlspecialchars($article->thumbUrl) ?>"
 *          class="story-thumb"
 *          data-article-id="<?= htmlspecialchars($article->id) ?>">
 *
 * plus one script tag, once, in the layout:
 *
 *     <script src="https://traceit.example.com/js/traceit-qr-overlay.js"
 *             data-selector="img.story-thumb"></script>
 *
 * That is the complete publisher-side footprint. If adding the data attribute
 * is awkward in their templates, drop it and let the script recover the ID from
 * the URL instead with data-id-from-path.
 */
