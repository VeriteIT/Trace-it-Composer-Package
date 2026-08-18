/*!
 * traceit-qr-overlay.js — Trace-It QR inside the article image frame.
 * ===========================================================================
 * THE DELIVERABLE. One script tag on the publisher's page; nothing else.
 *
 * WHAT IT DOES
 *   Finds the article thumbnail, reads the publisher's article ID out of the
 *   DOM, and puts a Trace-It QR code on that image. Everything comes from OUR
 *   service, keyed only by that ID.
 *
 * TWO MODES — pick per template with data-mode
 *
 *   data-mode="overlay"  (default)
 *     The QR is a separate element positioned inside the image frame. Their
 *     photo still loads from their own CDN and we serve no image bytes at all.
 *     But the QR is not part of the image file, so a native "Save image as…"
 *     gives the clean photo, and og:image has no QR either.
 *
 *   data-mode="embed"
 *     Our service fetches the photo, composites the QR into the pixels, and
 *     this script repoints the <img> at that result. Now "Save image as…",
 *     "Copy image", drag-to-desktop and printing all produce a QR-embedded
 *     file, and og:image can be pointed at the same URL. The cost is that the
 *     image bytes come from us instead of their CDN.
 *
 * WHY EMBED NEEDS A SERVER AND CANNOT BE DONE HERE
 *   Compositing in the browser means drawing their photo into a <canvas> and
 *   reading it back out, and canvas readback of a cross-origin image throws
 *   SecurityError unless their bucket sends CORS headers — which we have no
 *   permission to set. But same-origin policy is a BROWSER rule about what page
 *   scripts may read. It does not apply to our server fetching a public URL. So
 *   the compositing happens there, and this script only swaps an attribute.
 *
 * NO CORS IS REQUIRED, ANYWHERE, IN EITHER MODE
 *   Both modes only ever DISPLAY images (a CSS background or an <img src>),
 *   preloaded with `new Image()`. Displaying an image cross-origin has never
 *   required CORS; only reading its pixels does, and this script never does
 *   that. So it works against their S3 with default settings and against our
 *   own API with no Access-Control-Allow-Origin header at all.
 *
 * LAYOUT SAFETY
 *   Embed mode adds no elements and changes no styles — only `src`, to an image
 *   with identical pixel dimensions, so there is nothing to reflow. Overlay mode
 *   either uses a frame element the publisher already has (zero DOM change) or
 *   wraps the <img> in a shrink-wrapping span that takes over its float and
 *   margins so the page reflows identically. See ensureFrame().
 *
 * Dependencies: none. jQuery is used only if it already happens to be present.
 * ===========================================================================
 */
(function (window, document) {
  'use strict';

  var NS = 'tqr';
  var ATTR_EL = 'data-tqr-el'; // marks elements WE created, so we ignore our own mutations
  var ATTR_STATE = 'data-tqr-state';
  var ATTR_STYLE0 = 'data-tqr-style0'; // the image's original inline style, verbatim
  var ATTR_SRC0 = 'data-tqr-src0';     // the image's original src, for embed mode

  var DEFAULTS = {
    /*
     * 'embed'    (default) The QR is composited into the image pixels by our
     *            service and the <img src> is repointed at the result. "Save
     *            image as…", "Copy image", drag-to-desktop and printing all
     *            produce the QR-embedded file, because the QR IS the image.
     *            Cost: the image bytes come from us rather than their CDN, and
     *            a JPEG source is re-encoded once.
     *
     * 'overlay'  The QR is a separate element positioned over the photo. Their
     *            image still comes from their own CDN and we serve no image
     *            bytes at all. But the QR is not part of the file, so "Save
     *            image as…" gives the clean photo. Use this on index and list
     *            pages, where there are many thumbnails and nobody is saving.
     *
     * Embed degrades twice over, so there is no configuration in which the page
     * gets worse than it started: the S3 photo stays on screen until the
     * composite has actually loaded, and if the composite cannot be produced at
     * all the script falls back to drawing an overlay badge instead.
     */
    mode: 'embed',

    // Which images to decorate.
    selector: 'img[data-article-id]',

    // If an ancestor matching this exists, it is used as the positioning
    // context and NO wrapper is created — the preferred, zero-DOM-change path.
    frameSelector: null,

    // Base URL of our Trace-It service. Cross-origin in production; fine.
    service: '',

    // Where to find the publisher's article ID, in priority order:
    //   1. this attribute on the <img> or on the frame
    //   2. the same attribute on any ancestor
    //   3. `idFromPath` matched against location.pathname
    idAttr: 'data-article-id',
    idFromPath: '/article/([A-Za-z0-9._-]+)',

    // Geometry. Percentages are of the frame width, so the badge scales with
    // the thumbnail and needs no resize listener.
    corner: 'bottom-right',
    sizePct: 28,
    minPx: 48,
    maxPx: 160,
    padPct: 3.5,

    // Embed mode only: QR width as a fraction of the SOURCE image's short side.
    // Deliberately separate from sizePct, which is a percentage of the displayed
    // frame — the two are measured against different things. null = server default.
    embedScale: null,

    /*
     * White plate behind the code. OFF by default.
     *
     * A Trace-It branded PNG is already a white rounded card — code on white,
     * inside its own rounded border, above the label banner. A plate behind that
     * shows up as a second border with a drop shadow around a badge that already
     * has an edge. The plate was there for scannability over busy photography,
     * which the branded PNG handles itself.
     *
     * Turn it back on for a bare, transparent QR with no quiet zone of its own.
     */
    plate: false,
    platePadPct: 7, // of the QR's own width; used only when plate is true
    radiusPx: 4,

    // Overlay ignores the pointer by default, so the publisher's existing
    // click/right-click behaviour on the thumbnail is completely unaffected.
    clickable: false,

    // Hide the badge on very small frames, where a QR would be unscannable
    // anyway and would just cover the photo.
    minFrameWidth: 120,

    fadeMs: 220,
  };

  var CORNERS = {
    'bottom-right': ['flex-end', 'flex-end'],
    'bottom-left': ['flex-end', 'flex-start'],
    'top-right': ['flex-start', 'flex-end'],
    'top-left': ['flex-start', 'flex-start']
  };

  function assign(target) {
    for (var i = 1; i < arguments.length; i++) {
      var src = arguments[i] || {};
      for (var k in src) {
        if (Object.prototype.hasOwnProperty.call(src, k)) target[k] = src[k];
      }
    }
    return target;
  }

  /* --- one-time stylesheet ------------------------------------------------- */

  var styleInjected = false;
  function injectStyle() {
    if (styleInjected) return;
    styleInjected = true;
    var css =
      '.' + NS + '-frame{position:relative;display:inline-block;line-height:0;max-width:100%}' +
      '.' + NS + '-layer{position:absolute;inset:0;display:flex;box-sizing:border-box;' +
        'pointer-events:none;z-index:2}' +
      '.' + NS + '-code{display:block;background-repeat:no-repeat;background-position:center;' +
        'background-size:contain;box-sizing:border-box;opacity:0;' +
        'transition:opacity var(--' + NS + '-fade,220ms) ease-out}' +
      '.' + NS + '-code.is-in{opacity:1}' +
      '.' + NS + '-code.has-plate{background-color:#fff;' +
        'box-shadow:0 1px 6px rgba(0,0,0,.32)}' +
      '.' + NS + '-clickable{pointer-events:auto;cursor:pointer}' +
      // Defensive: publishers often ship `img{max-width:100%!important}`. Our
      // badge is a <span> with a background image, so that rule cannot reach it.
      '@media print{.' + NS + '-layer{display:flex}}';

    var el = document.createElement('style');
    el.setAttribute(ATTR_EL, 'style');
    el.appendChild(document.createTextNode(css));
    (document.head || document.documentElement).appendChild(el);
  }

  /* --- article id resolution ---------------------------------------------- */

  function resolveArticleId(img, opts) {
    var direct = img.getAttribute(opts.idAttr);
    if (direct && !isUninterpolated(direct)) return direct;

    var host = img.closest ? img.closest('[' + opts.idAttr + ']') : null;
    if (host) {
      var inherited = host.getAttribute(opts.idAttr);
      if (inherited && !isUninterpolated(inherited)) return inherited;
    }

    if (opts.idFromPath) {
      var m = new RegExp(opts.idFromPath).exec(window.location.pathname);
      if (m && m[1]) return m[1];
    }
    return null;
  }

  /**
   * Template bindings that have not been evaluated yet still sit in the DOM as
   * literal "{{a.id}}". Compositing or minting against that string would create
   * a junk code, so those elements are skipped and picked up on a later sweep.
   */
  function isUninterpolated(value) {
    return /\{\{|\}\}|^\s*$/.test(value);
  }

  /* --- frame ------------------------------------------------------------- */

  /**
   * Returns an element that (a) is positioned and (b) has exactly the same box
   * as the rendered image, for the overlay to be positioned against.
   *
   * Preferred: the publisher already has a frame element around the image, named
   * by `frameSelector`. We only ensure it is positioned, and change nothing
   * else — no new nodes at all.
   *
   * Fallback: wrap the <img>. The wrapper is an inline-block with line-height:0,
   * so it shrink-wraps the image to the pixel, including under the image's own
   * `max-width:300px !important`. Layout-affecting properties are MOVED from the
   * image to the wrapper — float, margins, vertical-align — so the surrounding
   * text wraps exactly as before. Without moving the float, a floated thumbnail
   * would suddenly become inline and the article text would reflow.
   */
  function ensureFrame(img, opts) {
    if (opts.frameSelector && img.closest) {
      var existing = img.closest(opts.frameSelector);
      if (existing) {
        if (getComputedStyle(existing).position === 'static') {
          existing.style.position = 'relative';
        }
        return existing;
      }
    }

    var parent = img.parentNode;
    if (parent && parent.getAttribute && parent.getAttribute(ATTR_EL) === 'frame') {
      return parent; // already wrapped by us on an earlier pass
    }

    // Their thumbnails carry layout in an INLINE style attribute
    // (`style="float:left; max-width:300px !important"`). Writing to
    // img.style.float would overwrite the author's own declaration and we could
    // never give it back, so stash the original attribute verbatim first;
    // teardown() restores this exact string.
    if (!img.hasAttribute(ATTR_STYLE0)) {
      img.setAttribute(ATTR_STYLE0, img.getAttribute('style') || '');
    }

    var wrap = document.createElement('span');
    wrap.className = NS + '-frame';
    wrap.setAttribute(ATTR_EL, 'frame');

    parent.insertBefore(wrap, img);
    wrap.appendChild(img);

    syncFrameLayout(wrap, img);
    return wrap;
  }

  /**
   * Moves the image's layout participation (float, margins, vertical-align)
   * onto the wrapper, and neutralises it on the image so it is not applied
   * twice. Without moving the float, a left-floated thumbnail would become
   * inline the moment it was wrapped and the article text would reflow.
   *
   * Re-runnable: it restores the image's original inline style before measuring,
   * so it reads the CSS-intended values every time rather than its own previous
   * output. That is what makes the resize re-sync below correct.
   */
  function syncFrameLayout(wrap, img) {
    var original = img.getAttribute(ATTR_STYLE0) || '';

    // Measure against the author's own styling, not ours.
    if (original) img.setAttribute('style', original);
    else img.removeAttribute('style');
    wrap.style.float = '';
    wrap.style.margin = '';
    wrap.style.verticalAlign = '';

    var cs = getComputedStyle(img);
    // `cssFloat` is the standard property name; `.float` is a newer alias.
    var cssFloat = cs.cssFloat || cs.float;
    var margin = cs.margin;
    var valign = cs.verticalAlign;

    if (cssFloat && cssFloat !== 'none') wrap.style.float = cssFloat;
    if (margin) wrap.style.margin = margin;
    if (valign) wrap.style.verticalAlign = valign;

    img.style.float = 'none';
    img.style.margin = '0';
    img.style.verticalAlign = 'top';
  }

  /**
   * The float we copied onto the wrapper is a snapshot, but the publisher's
   * float is media-query driven — site.css un-floats thumbnails under 575px. So
   * a reader rotating a phone, or dragging a window across the breakpoint, would
   * otherwise be left with a wrapper floating when the image no longer should.
   * Re-measure on resize, debounced.
   */
  var resizeBound = false;
  function bindResizeSync() {
    if (resizeBound || !window.addEventListener) return;
    resizeBound = true;
    var timer = null;
    window.addEventListener('resize', function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        var frames = document.querySelectorAll('[' + ATTR_EL + '="frame"]');
        Array.prototype.forEach.call(frames, function (wrap) {
          var img = wrap.querySelector('img');
          if (img) syncFrameLayout(wrap, img);
        });
      }, 200);
    }, { passive: true });
  }

  /* --- QR badge ----------------------------------------------------------- */

  function serviceBase(opts) {
    return (opts.service || '').replace(/\/+$/, '');
  }

  function qrUrl(articleId, opts) {
    return serviceBase(opts) + '/v1/qr/' + encodeURIComponent(articleId) + '.png';
  }

  /**
   * URL of the photo with the QR composited in, for embed mode.
   *
   * The .jpg suffix is cosmetic but worth having: it is what the browser offers
   * as the default filename in the save dialog, and the endpoint also sends a
   * Content-Disposition filename.
   *
   * `src` is sent only when our service does not already know the image URL for
   * this article (i.e. the publish webhook did not include it). Once sent, the
   * service remembers it, so later requests are just the ID.
   */
  function framedUrl(articleId, photoUrl, opts) {
    var u = serviceBase(opts) + '/v1/framed/' + encodeURIComponent(articleId) + '.jpg';
    var q = [];
    if (photoUrl) q.push('src=' + encodeURIComponent(photoUrl));

    // `corner` means exactly the same thing on both sides, so it forwards.
    if (opts.corner) q.push('corner=' + encodeURIComponent(opts.corner));

    // Size does NOT forward. The overlay's sizePct/minPx/maxPx are in CSS pixels
    // against the DISPLAYED frame; the composite is built in SOURCE pixels at
    // native resolution, so a 300px-wide thumbnail of a 1200px photo makes the
    // same number mean four different things. `embedScale` is the separate,
    // honestly-named knob for the server side: a fraction of the source image's
    // short side. Left unset, the server default applies.
    if (opts.embedScale) q.push('scale=' + encodeURIComponent(opts.embedScale));

    return q.length ? u + '?' + q.join('&') : u;
  }

  /**
   * Loads the QR as a plain image first, then paints it.
   *
   * `new Image()` needs no CORS, tells us the real aspect ratio (a Trace-It
   * badge with a label under the code is taller than it is wide), warms the HTTP
   * cache so the background paints with no flash, AND gives us a failure signal.
   * If the code does not exist for this ID, onerror fires and we render nothing
   * rather than leaving a broken-image box on a publisher's article page.
   */
  function preloadImage(url) {
    return new Promise(function (resolve, reject) {
      var probe = new Image();
      probe.decoding = 'async';
      probe.onload = function () {
        resolve({
          url: url,
          w: probe.naturalWidth || 1,
          h: probe.naturalHeight || 1
        });
      };
      probe.onerror = function () { reject(new Error('QR not available: ' + url)); };
      probe.src = url;
    });
  }

  function buildBadge(qr, opts) {
    var layer = document.createElement('span');
    layer.className = NS + '-layer';
    layer.setAttribute(ATTR_EL, 'layer');
    layer.style.padding = opts.padPct + '%';

    var align = CORNERS[opts.corner] || CORNERS['bottom-right'];
    layer.style.alignItems = align[0];
    layer.style.justifyContent = align[1];

    var code = document.createElement('span');
    code.className = NS + '-code' + (opts.plate ? ' has-plate' : '');
    code.setAttribute(ATTR_EL, 'code');
    // Decorative: the article link this code points at is already on the page,
    // so announcing it again is noise for screen-reader users.
    code.setAttribute('aria-hidden', 'true');

    code.style.setProperty('--' + NS + '-fade', opts.fadeMs + 'ms');
    code.style.width =
      'clamp(' + opts.minPx + 'px, ' + opts.sizePct + '%, ' + opts.maxPx + 'px)';
    // Real aspect ratio from the loaded PNG, so a labelled badge is not squashed.
    code.style.aspectRatio = qr.w + ' / ' + qr.h;
    code.style.backgroundImage = 'url("' + qr.url.replace(/"/g, '%22') + '")';

    if (opts.plate) {
      code.style.padding = opts.platePadPct + '%';
      code.style.borderRadius = opts.radiusPx + 'px';
    }

    layer.appendChild(code);
    return { layer: layer, code: code };
  }

  /* --- main --------------------------------------------------------------- */

  var pending = {};

  /**
   * Places a QR badge inside one image's frame.
   * @returns {Promise<HTMLElement|null>} the badge, or null if skipped.
   */
  function decorate(img, options) {
    var opts = assign({}, DEFAULTS, options || {});
    injectStyle();

    var state = img.getAttribute(ATTR_STATE);
    // Skip both 'done' AND 'pending'. Checking only 'done' would let a
    // MutationObserver sweep re-enter an in-flight decoration and paint two
    // badges on the same thumbnail.
    if (state === 'done' || state === 'pending') return Promise.resolve(null);

    var articleId = resolveArticleId(img, opts);
    if (!articleId) return Promise.resolve(null);

    img.setAttribute(ATTR_STATE, 'pending');

    if (opts.mode === 'embed') {
      // Fall back to an overlay if the composite cannot be produced — a
      // misconfigured image-host allowlist, our service down, an image host we
      // do not know. Better a QR the reader can still scan than no QR at all.
      return embed(img, articleId, opts).then(function (result) {
        if (result) return result;
        img.setAttribute(ATTR_STATE, 'pending');
        return overlayBadge(img, articleId, opts);
      });
    }

    return overlayBadge(img, articleId, opts);
  }

  /** The overlay path: a QR badge positioned inside the image frame. */
  function overlayBadge(img, articleId, opts) {
    var url = qrUrl(articleId, opts);
    if (!pending[url]) {
      // Drop the memo if the load fails, or a transient outage would poison the
      // URL for the rest of the page's life and every later retry — including
      // the MutationObserver's — would reject instantly without a request.
      pending[url] = preloadImage(url).catch(function (err) {
        delete pending[url];
        throw err;
      });
    }

    return pending[url]
      .then(function (qr) {
        var frame = ensureFrame(img, opts);

        // Too small for a scannable code: leave the photo alone.
        var fw = frame.getBoundingClientRect().width || img.width || 0;
        if (fw && fw < opts.minFrameWidth) {
          img.setAttribute(ATTR_STATE, 'skipped-small');
          return null;
        }

        var built = buildBadge(qr, opts);
        if (opts.clickable) built.code.className += ' ' + NS + '-clickable';
        frame.appendChild(built.layer);

        // Next frame, so the opacity transition actually runs.
        requestAnimationFrame(function () { built.code.className += ' is-in'; });

        img.setAttribute(ATTR_STATE, 'done');
        img.dispatchEvent(new CustomEvent('traceit:overlay', {
          bubbles: true,
          detail: { articleId: articleId, qrUrl: url, frame: frame }
        }));
        return built.code;
      })
      .catch(function (err) {
        // Non-fatal by design. No badge, original photo untouched, page fine.
        img.setAttribute(ATTR_STATE, 'error');
        img.dispatchEvent(new CustomEvent('traceit:error', {
          bubbles: true, detail: err
        }));
        return null;
      });
  }

  /**
   * EMBED MODE — repoints the <img> at a server-composited copy of the photo
   * with the QR already in the pixels.
   *
   * This is the only way a native "Save image as…" can produce a QR-embedded
   * file. Save-as is a browser menu item: no DOM event fires for it, no script
   * runs during it, and it writes the bytes of the resource the <img> is
   * displaying. So the QR has to be in those bytes. It cannot be faked from the
   * page, in any browser.
   *
   * Note what this does NOT do: it adds no elements and changes no styles. Only
   * the src attribute changes, and it changes to an image with identical pixel
   * dimensions, so there is nothing for the layout to react to.
   */
  function embed(img, articleId, opts) {
    var original = img.getAttribute(ATTR_SRC0) || img.currentSrc || img.getAttribute('src');
    if (!original) {
      img.setAttribute(ATTR_STATE, 'skipped-no-src');
      return Promise.resolve(null);
    }
    img.setAttribute(ATTR_SRC0, original);

    // Absolute, so our service receives a URL it can actually fetch even when
    // the markup used a relative or protocol-relative path.
    var absolute;
    try {
      absolute = new URL(original, window.location.href).href;
    } catch (e) {
      absolute = original;
    }

    var url = framedUrl(articleId, absolute, opts);

    // Preload before swapping. Setting src directly would blank the thumbnail
    // while the composite downloads, and would leave a broken image if our
    // service were unreachable. This way the reader keeps seeing the S3 photo
    // and the swap is a single repaint of identical geometry.
    return preloadImage(url)
      .then(function (framed) {
        img.src = framed.url;
        img.setAttribute(ATTR_STATE, 'done');
        img.setAttribute('data-tqr-mode', 'embed');
        img.dispatchEvent(new CustomEvent('traceit:embedded', {
          bubbles: true,
          detail: {
            articleId: articleId,
            framedUrl: framed.url,
            originalUrl: absolute,
            width: framed.w,
            height: framed.h
          }
        }));
        return img;
      })
      .catch(function (err) {
        // The S3 photo is untouched and still on screen. Nothing to undo.
        img.setAttribute(ATTR_STATE, 'error');
        img.dispatchEvent(new CustomEvent('traceit:error', {
          bubbles: true, detail: err
        }));
        return null;
      });
  }

  /** Decorates every image matching the selector. Safe to call repeatedly. */
  function decorateAll(options) {
    var opts = assign({}, DEFAULTS, options || {});
    injectStyle();
    var nodes = document.querySelectorAll(opts.selector);
    return Promise.all(Array.prototype.map.call(nodes, function (img) {
      return decorate(img, opts);
    }));
  }

  /** Removes every badge and unwraps, restoring the page to its original DOM. */
  function teardown() {
    var layers = document.querySelectorAll('[' + ATTR_EL + '="layer"]');
    Array.prototype.forEach.call(layers, function (n) { n.parentNode.removeChild(n); });

    // Embed mode: put the publisher's own image back.
    var embedded = document.querySelectorAll('[' + ATTR_SRC0 + ']');
    Array.prototype.forEach.call(embedded, function (img) {
      img.src = img.getAttribute(ATTR_SRC0);
      img.removeAttribute(ATTR_SRC0);
      img.removeAttribute('data-tqr-mode');
    });

    var frames = document.querySelectorAll('[' + ATTR_EL + '="frame"]');
    Array.prototype.forEach.call(frames, function (wrap) {
      var img = wrap.querySelector('img');
      if (!img) return wrap.parentNode.removeChild(wrap);
      // Put back the author's exact inline style attribute, including the
      // `float:left` and `max-width:...!important` we had to work around.
      var original = img.getAttribute(ATTR_STYLE0);
      if (original !== null) {
        if (original) img.setAttribute('style', original);
        else img.removeAttribute('style');
        img.removeAttribute(ATTR_STYLE0);
      }
      wrap.parentNode.insertBefore(img, wrap);
      wrap.parentNode.removeChild(wrap);
    });

    var marked = document.querySelectorAll('[' + ATTR_STATE + ']');
    Array.prototype.forEach.call(marked, function (n) { n.removeAttribute(ATTR_STATE); });
  }

  window.TraceItQROverlay = {
    decorate: decorate,
    decorateAll: decorateAll,
    teardown: teardown,
    defaults: DEFAULTS
  };

  if (window.jQuery) {
    window.jQuery.fn.traceItQrOverlay = function (options) {
      return this.each(function () { decorate(this, options); });
    };
  }

  /* --- auto-init ---------------------------------------------------------- */

  function currentScript() {
    if (document.currentScript) return document.currentScript;
    var all = document.getElementsByTagName('script');
    for (var i = all.length - 1; i >= 0; i--) {
      if (/traceit-qr-overlay\.js/.test(all[i].src || '')) return all[i];
    }
    return null;
  }

  var NUMERIC = { sizePct: 1, minPx: 1, maxPx: 1, padPct: 1, platePadPct: 1,
                  radiusPx: 1, minFrameWidth: 1, fadeMs: 1, embedScale: 1 };

  function optionsFromScript(el) {
    if (!el) return {};
    var d = el.dataset || {};
    var o = {};
    var map = {
      mode: 'mode',
      selector: 'selector', frameSelector: 'frame', service: 'service',
      idAttr: 'idAttr', idFromPath: 'idFromPath', corner: 'corner',
      sizePct: 'size', minPx: 'min', maxPx: 'max', padPct: 'pad',
      embedScale: 'embedScale',
      platePadPct: 'platePad', radiusPx: 'radius',
      minFrameWidth: 'minFrame', fadeMs: 'fade'
    };
    for (var key in map) {
      var raw = d[map[key]];
      if (raw === undefined || raw === '') continue;
      o[key] = NUMERIC[key] ? parseFloat(raw) : raw;
    }
    if (d.plate !== undefined) o.plate = d.plate !== 'false';
    if (d.clickable !== undefined) o.clickable = d.clickable === 'true';

    // Default the service base to wherever this script was served from, so the
    // common case needs no data-service at all.
    if (!o.service && el.src) {
      try { o.service = new URL(el.src, window.location.href).origin; } catch (e) {}
    }
    return o;
  }

  var scriptEl = currentScript();
  var autoOpts = optionsFromScript(scriptEl);
  if (window.TraceItQROverlayConfig) {
    autoOpts = assign({}, autoOpts, window.TraceItQROverlayConfig);
  }

  function watch(opts) {
    if (!window.MutationObserver) return;
    var timer = null;

    var observer = new MutationObserver(function (records) {
      // CRITICAL: this script adds nodes (a wrapper and a badge), and those
      // additions are themselves childList mutations. Without filtering our own
      // elements out here, every decoration would retrigger the sweep and the
      // observer would feed itself forever.
      var interesting = records.some(function (r) {
        return Array.prototype.some.call(r.addedNodes, function (n) {
          if (n.nodeType !== 1) return false;
          if (n.hasAttribute && n.hasAttribute(ATTR_EL)) return false;
          if (n.closest && n.closest('[' + ATTR_EL + '="layer"]')) return false;
          return true;
        });
      });
      if (!interesting) return;

      clearTimeout(timer);
      timer = setTimeout(function () { decorateAll(opts); }, 150);
    });

    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  function autoRun() {
    decorateAll(autoOpts);
    watch(autoOpts);
    bindResizeSync();
  }

  if (!scriptEl || scriptEl.getAttribute('data-auto') !== 'off') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', autoRun);
    } else {
      autoRun();
    }
  }
})(window, document);
