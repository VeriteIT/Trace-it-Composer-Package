<?php
/**
 * SNIPPET 2 of 3 — the template changes.
 *
 * Two things: one attribute on the thumbnails you want coded, and one script tag
 * in your layout. That is the whole frontend integration.
 */
?>

<!-- ===================================================================== -->
<!-- A. The thumbnail. Add one attribute; change nothing else.              -->
<!-- ===================================================================== -->

<img src="<?= htmlspecialchars($article->thumbUrl) ?>"
     class="story-thumb"
     alt="<?= htmlspecialchars($article->caption) ?>"
     data-article-id="<?= htmlspecialchars($article->id) ?>">

<!--
  data-article-id is all the page needs. No API key and no lookup happens here:
  your key is a secret that must never reach a browser, and the script issues no
  API call at all — the only request it makes is for the coded image itself,
  from YOUR endpoint (snippet 3).

  Your existing classes, inline styles, float, sizing and position all stay as
  they are. The script changes ONE attribute — src — to an image with identical
  pixel dimensions. It adds no elements, injects no CSS and wraps nothing, so
  there is nothing for your layout to react to.

  WHY THE CODE IS IN THE PIXELS AND NOT DRAWN OVER THEM. "Save image as…" is a
  browser menu item: no DOM event fires for it and no script runs during it. It
  writes the bytes of the resource this <img> is displaying. So a code drawn over
  the photo is on the screen but never in the saved file. Compositing is the only
  thing that satisfies the requirement, which is why it is the only mode.
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
        data-selector="img.story-thumb"
        data-service="https://www.example.lk/traceit"></script>

<!--
  data-service is REQUIRED. It points at your composite endpoint from snippet 3,
  and it is where every coded image comes from. Without it the script has nothing
  to fetch: it logs one console error and leaves every photo untouched.

  data-selector is the control. Nothing outside it is ever touched, so logos,
  adverts, author portraits and inline body images need no work from you.

    default                              img[data-article-id]
    one template's images                img.story-thumb
    narrower still                       img.story-thumb[data-article-id]
    a container                          .lead-image img

  Optional attributes:

    data-auto="off"              do not run automatically; call
                                 window.TraceItQR.embedAll() yourself
    data-version="1"             cache-buster; bump it when the badge design
                                 changes, so browsers stop serving the old render

  Badge corner and size are decided when the image is composited, so they are
  server-side settings — see Layout in PACKAGE-REFERENCE.md, not attributes here.

  Coded images are fetched lazily as thumbnails approach the viewport, so a
  homepage with fifty of them does not request fifty composites a reader may
  never scroll to.

  Rendering thumbnails at runtime — infinite scroll, a lightbox, a client-rendered
  section? Call window.TraceItQR.embedAll() again afterwards. It never
  double-applies to an image it has already done.

  window.TraceItQR.config.service reports the endpoint it resolved, which is the
  quickest way to confirm it is pointed where you think.
-->


<!-- ===================================================================== -->
<!-- D. Single-article template: you can skip data-article-id entirely      -->
<!-- ===================================================================== -->

<!-- If adding data-article-id is awkward, let the script read the ID from the
     URL instead. Adjust the pattern to match your article routes. -->

<script src="https://YOUR-TRACEIT-HOST/js/traceit-qr.js"
        data-selector="img.story-thumb"
        data-service="https://www.example.lk/traceit"
        data-id-from-path="/article/([A-Za-z0-9._-]+)"></script>


<!-- ===================================================================== -->
<!-- E. Optional: the code in social share previews                         -->
<!-- ===================================================================== -->

<!-- REQUIRES SNIPPET 3. Facebook, X and WhatsApp read og:image and never run
     page JavaScript, so a crawler never sees the src swap. The tag has to point
     at a composite that exists as a real file, which means your own endpoint.
     Goes in <head>. -->

<meta property="og:image"
      content="https://www.example.lk/traceit/v1/framed/<?= htmlspecialchars($article->id) ?>.jpg?v=1">

<!--
  Nothing extra is needed. A crawler hitting this URL reaches the same endpoint
  from snippet 3, which looks the article up and composites it. A crawler's first
  request is no different from a reader's — neither has run your page.

  The one exception is if your composite endpoint cannot reach your CMS and you
  took the publish()-fourth-argument fallback. Then that value is the only thing
  it has to work from, and without it the tag 404s and the crawler falls back to
  your plain photo.
-->
