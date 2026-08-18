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
 * `php -l` CLEAN on PHP 8.4.24, but not executed here — it POSTs into a live
 * publish flow, which is exercised end to end by notifyTraceIt() in
 * server/publisher-site.js instead. Re-lint against the target PHP version.
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
 * No headline is sent. Trace-It names the code by post ID, which keeps a
 * dashboard row mapped to a CMS post without anyone matching wording, and
 * survives the headline being edited after publishing.
 *
 * @param string      $articleId  The CMS's own article ID. Becomes the postId.
 * @param string      $articleUrl Public URL of the article. Becomes targetUrl.
 * @param string|null $imageUrl   OPTIONAL. Public thumbnail URL. Normally you do
 *                                not send this: the frontend supplies it on first
 *                                render. Send it only to make og:image work, where
 *                                a crawler asks for the composite without ever
 *                                running the page script.
 * @return bool  true if Trace-It acknowledged.
 */
function traceit_notify_published(string $articleId, string $articleUrl, ?string $imageUrl = null): bool
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
        // Only needed for embed mode, where the QR is composited into the photo
        // so that Save-as includes it. Same public URL readers already fetch.
        'imageUrl'  => $imageUrl,
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
 *         'https://www.example.lk/article/' . $articleId    // must be https
 *     );
 *
 * That is the whole payload: the post ID and the live article URL. The image URL
 * is NOT needed — the frontend already knows it (it is the src of the <img> it
 * replaces) and passes it once on the first render, after which our service
 * remembers it. Pass it as a third argument only if you want og:image to carry
 * the code, since a social crawler never runs the page script.
 *
 * And in the article template, the thumbnail gains one attribute:
 *
 *     <img src="<?= htmlspecialchars($article->thumbUrl) ?>"
 *          class="story-thumb"
 *          data-article-id="<?= htmlspecialchars($article->id) ?>">
 *
 * plus one script tag, once, in the layout:
 *
 *     <script src="https://traceit.example.com/js/traceit-qr.js"
 *             data-selector="img.story-thumb"></script>
 *
 * That is the complete publisher-side footprint. If adding the data attribute
 * is awkward in their templates, drop it and let the script recover the ID from
 * the URL instead with data-id-from-path.
 */
