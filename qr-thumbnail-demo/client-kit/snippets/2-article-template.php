<?php
/**
 * SNIPPET 2 of 3 — the template changes.
 *
 * Two things: one attribute on the thumbnails you want coded, and one script tag
 * in your layout. That is the whole frontend integration.
 */
?>

<!-- ===================================================================== -->
<!-- A. The thumbnail. Add data-article-id; change nothing else.            -->
<!-- ===================================================================== -->

<img src="<?= htmlspecialchars($article->thumbUrl) ?>"
     class="story-thumb"
     alt="<?= htmlspecialchars($article->caption) ?>"
     data-article-id="<?= htmlspecialchars($article->id) ?>">

<!--
  Your existing classes, inline styles, float, sizing and position all stay as
  they are. The script changes ONE attribute — src — to an image with identical
  pixel dimensions. It adds no elements, injects no CSS and wraps nothing.
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

<script src="https://qr.trace-it.io/js/traceit-qr.js"
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
    data-auto="off"              do not run automatically; call
                                 window.TraceItQR.embedAll() yourself
    data-service="https://…"     point at your own origin if you host the
                                 composite endpoint (see snippet 3)
-->


<!-- ===================================================================== -->
<!-- D. Single-article template: you can skip the attribute entirely        -->
<!-- ===================================================================== -->

<!-- If adding data-article-id is awkward, let the script read the ID from the
     URL instead. Adjust the pattern to match your article routes. -->

<script src="https://qr.trace-it.io/js/traceit-qr.js"
        data-selector="img.story-thumb"
        data-id-from-path="/article/([A-Za-z0-9._-]+)"></script>


<!-- ===================================================================== -->
<!-- E. Optional: the code in social share previews                         -->
<!-- ===================================================================== -->

<!-- Facebook, X and WhatsApp read og:image and never run page JavaScript, so
     they need the composite URL directly. Goes in <head>. -->

<meta property="og:image"
      content="https://qr.trace-it.io/v1/framed/<?= htmlspecialchars($article->id) ?>.jpg?src=<?= urlencode($article->thumbUrl) ?>">

<!--
  The ?src= matters here. A crawler has never run your page, so it may be the
  first thing ever to ask for this article's composite and we might not know which
  photo it is yet. Pass the image URL as a third argument to publish() and you can
  drop the parameter.
-->
