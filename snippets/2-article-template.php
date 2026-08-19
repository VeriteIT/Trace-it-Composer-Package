<?php
/**
 * SNIPPET 2 of 3 — the template changes.
 *
 * Two things: a couple of attributes on the thumbnails you want coded, and one
 * script tag in your layout. That is the whole frontend integration.
 */
?>

<!-- ===================================================================== -->
<!-- A. The thumbnail. Add two attributes; change nothing else.             -->
<!-- ===================================================================== -->

<img src="<?= htmlspecialchars($article->thumbUrl) ?>"
     class="story-thumb"
     alt="<?= htmlspecialchars($article->caption) ?>"
     data-article-id="<?= htmlspecialchars($article->id) ?>"
     data-traceit-qr="<?= htmlspecialchars($traceIt->qrPngUrl($article->id) ?? '') ?>">

<!--
  data-traceit-qr is the code's public PNG URL, and it is public by design. That
  is the whole reason the lookup happens here in PHP: your API key is a secret
  and must never reach a browser. The script therefore makes no API call — the
  only request it issues is for the image.

  Use qrPngUrl(), not qr(). qr() throws, and a template is the last place you
  want an exception: qrPngUrl() returns null on any failure, so a Trace-It outage
  costs you a badge rather than the whole article page.

  It is served from the local cache after the first call, so this is not a request
  per render. When it returns null the attribute renders empty and this thumbnail
  simply keeps its plain photo.

  Your existing classes, inline styles, float, sizing and position all stay as
  they are. In the default overlay mode the script adds one absolutely-positioned
  <img> for the code and wraps this thumbnail in a position:relative <span>,
  unless it already sits alone inside a positioned element. Styling is inline on
  what it inserts; no stylesheet is injected.

  In composite mode (set data-service, see snippet 3) it instead changes ONE
  attribute — src — adds no elements and wraps nothing. Drop data-traceit-qr in
  that mode; it is not used.
-->


<!-- ===================================================================== -->
<!-- B. Excluding an image that would otherwise match                       -->
<!-- ===================================================================== -->

<!-- A sponsored photo, a wire-service image you may not alter, or a graphic where
     a badge would cover something that matters. Keeps its plain photo. -->

<img src="<?= htmlspecialchars($sponsored->thumbUrl) ?>"
     class="story-thumb"
     data-article-id="<?= htmlspecialchars($sponsored->id) ?>"
     data-traceit="off">


<!-- ===================================================================== -->
<!-- C. The script tag. Once, in your layout, before </body>.              -->
<!-- ===================================================================== -->

<script src="https://YOUR-TRACEIT-HOST/js/traceit-qr.js"
        data-selector="img.story-thumb"></script>

<!--
  data-selector is the control. Nothing outside it is ever touched, so logos,
  adverts, author portraits and inline body images need no work from you.

    default                              img[data-article-id]
    one template's images                img.story-thumb
    narrower still                       img.story-thumb[data-article-id]
    a container                          .lead-image img

  Optional attributes:

    data-corner="bottom-left"    which corner the badge sits in
                                 (bottom-right | bottom-left | top-right | top-left)
    data-badge-size="0.22"       badge width as a fraction of the image,
                                 clamped to 0.08–0.5. Overlay mode only.
    data-auto="off"              do not run automatically; call
                                 window.TraceItQR.embedAll() yourself
    data-version="1"             composite cache-buster; bump it when the badge
                                 design changes (composite mode only)
    data-service="https://…"     point at your own origin if you host the
                                 composite endpoint (see snippet 3). Setting this
                                 switches from overlay to composite mode.

  Badges are applied lazily as thumbnails approach the viewport, so a homepage
  with fifty of them does not fetch fifty codes a reader may never scroll to.

  Rendering thumbnails at runtime — infinite scroll, a lightbox, a client-rendered
  section? Call window.TraceItQR.embedAll() again afterwards. It never
  double-applies to an image it has already done.

  window.TraceItQR.config.mode reports "overlay" or "composite", which is the
  quickest way to confirm you are in the mode you think you are.
-->


<!-- ===================================================================== -->
<!-- D. Single-article template: you can skip data-article-id entirely      -->
<!-- ===================================================================== -->

<!-- If adding data-article-id is awkward, let the script read the ID from the
     URL instead. Adjust the pattern to match your article routes.

     In overlay mode you still need data-traceit-qr on the image — this replaces
     only the ID attribute, not the code URL. -->

<script src="https://YOUR-TRACEIT-HOST/js/traceit-qr.js"
        data-selector="img.story-thumb"
        data-id-from-path="/article/([A-Za-z0-9._-]+)"></script>


<!-- ===================================================================== -->
<!-- E. Optional: the code in social share previews                         -->
<!-- ===================================================================== -->

<!-- REQUIRES SNIPPET 3. Facebook, X and WhatsApp read og:image and never run
     page JavaScript, so a crawler never sees an overlay. The tag has to point at
     a composite that exists as a real file, which means your own endpoint.
     Goes in <head>. -->

<meta property="og:image"
      content="https://www.example.lk/traceit/v1/framed/<?= htmlspecialchars($article->id) ?>.jpg?v=1">

<!--
  For this to resolve, your publish hook must already know which photo belongs to
  the article — pass the image URL as the fourth argument to publish():

    $traceIt->publish($article->id, $article->url, $article->publishedAt,
                      $article->thumbUrl);

  framedImage() reads that URL back out of its local cache, so a crawler's very
  first request composites correctly even though it has never run your page.
  Without it there is nothing to composite from: the tag 404s and the crawler
  falls back to your plain photo.
-->
