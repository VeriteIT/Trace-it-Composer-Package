/*!
 * traceit-qr.js — put the Trace-It QR into the article thumbnail's pixels.
 * ===========================================================================
 * THE DELIVERABLE. One script tag on the publisher's page; nothing else.
 *
 * WHAT IT DOES
 *   Finds each article thumbnail, reads the publisher's article ID out of the
 *   DOM, and repoints the <img> at a copy of that photo with the QR composited
 *   in by our service.
 *
 *   That is the only way a native "Save image as…" can produce a QR-embedded
 *   file. Save-as is a browser menu item: no DOM event fires for it, no script
 *   runs during it, and it writes the bytes of the resource the <img> is
 *   displaying. So the QR has to BE in those bytes. It cannot be faked from the
 *   page, in any browser. "Copy image", drag-to-desktop and printing follow from
 *   the same fact, and og:image can point at the same URL.
 *
 * WHAT IT CHANGES ON THE PAGE: ONE ATTRIBUTE
 *   Only `src`, and only to an image with identical pixel dimensions. It adds no
 *   elements, injects no CSS, and wraps nothing — so there is nothing for the
 *   layout to react to, and no interaction with the publisher's own styles. An
 *   earlier revision also had an overlay mode that wrapped the <img> in a
 *   positioned span and had to move the float and margins onto it; that is all
 *   gone, and with it every layout risk it carried.
 *
 * WHY THE COMPOSITING IS ON THE SERVER
 *   Doing it here would mean drawing the photo into a <canvas> and reading it
 *   back, and canvas readback of a cross-origin image throws SecurityError
 *   unless the image host sends CORS headers — which we cannot set on someone
 *   else's bucket. But same-origin policy is a BROWSER rule about what page
 *   scripts may read. It does not apply to our server fetching a public URL. So
 *   the drawing happens there and this script only swaps an attribute.
 *
 * NO CORS IS REQUIRED, ANYWHERE
 *   This only ever DISPLAYS images, preloaded with `new Image()`. Displaying an
 *   image cross-origin has never required CORS; only reading its pixels does, and
 *   this never does that.
 *
 * IT DEGRADES SAFELY
 *   The publisher's own photo stays on screen until the composite has actually
 *   loaded. If our service is slow, unreachable, or has no code for an article,
 *   the swap simply never happens: the reader sees the original photo and the
 *   page is unaffected. Nothing to undo, because nothing was changed.
 *
 * Dependencies: none. jQuery is used only if it already happens to be present.
 * ===========================================================================
 */
(function (window, document) {
  'use strict';

  var ATTR_STATE = 'data-tqr-state';
  var ATTR_SRC0 = 'data-tqr-src0'; // the image's original src, so it can be put back

  /*
   * Badge design version, appended to the composite URL as ?v=.
   *
   * BUMP THIS whenever the composited badge changes — size, corner, padding,
   * anything visual. The composite is served `immutable` with a one-year
   * max-age, which is right for a file that never changes, but it means a
   * browser that has fetched it once will never ask again. Without a version in
   * the URL a design change is invisible to every reader who has already loaded
   * the page — and to you, which is how "the border is back" happens after the
   * server has already stopped drawing one.
   */
  var BADGE_VERSION = '2';

  var DEFAULTS = {
    /*
     * Which images get a code. THIS IS THE CONTROL — nothing outside this
     * selector is ever touched.
     *
     * The default is opt-in per image: `img[data-article-id]` matches only
     * thumbnails the template has explicitly tagged, so logos, ads, author
     * portraits and inline body images are left alone with no extra work.
     *
     * Narrow it further if a template tags more than you want:
     *     data-selector="img.story-thumb[data-article-id]"
     *     data-selector=".lead-image img"
     * or point it at a class the editor controls:
     *     data-selector="img.traceit"
     */
    selector: 'img[data-article-id]',

    /*
     * Per-image opt-out, for when one image matches the selector but should not
     * get a code — a sponsored photo, a wire-service image the publisher cannot
     * modify, a graphic where the badge would cover something. Set
     * data-traceit="off" on it and this script skips it.
     *
     * Cheaper than maintaining an ever-more-specific selector, and the reason
     * lives on the image where an editor can see it.
     */
    skipAttr: 'data-traceit',

    // Base URL of our service. Cross-origin in production; that is fine.
    service: '',

    // Where to find the publisher's article ID, in priority order:
    //   1. this attribute on the <img>
    //   2. the same attribute on any ancestor
    //   3. `idFromPath` matched against location.pathname
    idAttr: 'data-article-id',
    idFromPath: '/article/([A-Za-z0-9._-]+)',

    // Which corner the composited badge goes in. Sizing is decided server-side,
    // in source pixels — see embedScale.
    corner: 'bottom-right',

    // QR width as a fraction of the source image's SHORT side. null = server
    // default. Deliberately not a percentage of the displayed frame: the
    // composite is built at native resolution, so a 300px-wide thumbnail of a
    // 1200px photo would make one number mean four different things.
    embedScale: null,
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
   * literal "{{a.id}}". Requesting a composite for that string would ask our
   * service to mint against a garbage ID, so those are skipped and picked up on a
   * later sweep once the real values land.
   */
  function isUninterpolated(value) {
    return /\{\{|\}\}|^\s*$/.test(value);
  }

  /* --- URL ---------------------------------------------------------------- */

  /**
   * URL of the photo with the QR composited in.
   *
   * The .jpg suffix is cosmetic but worth having: it is what the browser offers
   * as the default filename in the save dialog. The endpoint also sends a
   * Content-Disposition filename.
   *
   * `src` is sent only so our service knows which image to composite when the
   * publish webhook did not tell it. Once sent, the service remembers it, and
   * later requests are just the ID.
   */
  function framedUrl(articleId, photoUrl, opts) {
    var base = (opts.service || '').replace(/\/+$/, '');
    var u = base + '/v1/framed/' + encodeURIComponent(articleId) + '.jpg';
    var q = [];

    if (photoUrl) q.push('src=' + encodeURIComponent(photoUrl));
    if (opts.corner) q.push('corner=' + encodeURIComponent(opts.corner));
    if (opts.embedScale) q.push('scale=' + encodeURIComponent(opts.embedScale));
    q.push('v=' + encodeURIComponent(BADGE_VERSION));

    return u + '?' + q.join('&');
  }

  /**
   * Loads an image and resolves only once it has actually decoded.
   *
   * `new Image()` needs no CORS, warms the HTTP cache so the swap is a single
   * repaint with no flash, AND gives us a failure signal. Setting src directly on
   * the visible <img> instead would blank the thumbnail while the composite
   * downloads, and leave a broken image if our service were unreachable.
   */
  function preloadImage(url) {
    return new Promise(function (resolve, reject) {
      var probe = new Image();
      probe.decoding = 'async';
      probe.onload = function () {
        resolve({ url: url, w: probe.naturalWidth || 1, h: probe.naturalHeight || 1 });
      };
      probe.onerror = function () { reject(new Error('composite not available: ' + url)); };
      probe.src = url;
    });
  }

  /* --- main --------------------------------------------------------------- */

  var pending = {};

  /**
   * Repoints one <img> at its composited copy.
   * @returns {Promise<HTMLElement|null>} the img, or null if skipped or failed.
   */
  function embed(img, options) {
    var opts = assign({}, DEFAULTS, options || {});

    var state = img.getAttribute(ATTR_STATE);
    // Skip both 'done' AND 'pending'. Checking only 'done' would let a
    // MutationObserver sweep re-enter an in-flight swap and request the composite
    // twice for the same thumbnail.
    if (state === 'done' || state === 'pending') return Promise.resolve(null);

    // Explicit per-image opt-out. Checked before anything else, so an excluded
    // image costs no request and is never marked.
    if (opts.skipAttr && img.getAttribute(opts.skipAttr) === 'off') {
      img.setAttribute(ATTR_STATE, 'skipped-opted-out');
      return Promise.resolve(null);
    }

    var articleId = resolveArticleId(img, opts);
    if (!articleId) return Promise.resolve(null);

    var original = img.getAttribute(ATTR_SRC0) || img.currentSrc || img.getAttribute('src');
    if (!original) {
      img.setAttribute(ATTR_STATE, 'skipped-no-src');
      return Promise.resolve(null);
    }

    img.setAttribute(ATTR_STATE, 'pending');
    img.setAttribute(ATTR_SRC0, original);

    // Absolute, so our service receives a URL it can actually fetch even when the
    // markup used a relative or protocol-relative path.
    var absolute;
    try {
      absolute = new URL(original, window.location.href).href;
    } catch (e) {
      absolute = original;
    }

    var url = framedUrl(articleId, absolute, opts);

    if (!pending[url]) {
      // Drop the memo if the load fails, or a transient outage would poison the
      // URL for the rest of the page's life and every later retry — including the
      // MutationObserver's — would reject instantly without a request.
      pending[url] = preloadImage(url).catch(function (err) {
        delete pending[url];
        throw err;
      });
    }

    return pending[url]
      .then(function (framed) {
        img.src = framed.url;
        img.setAttribute(ATTR_STATE, 'done');
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
        // The publisher's photo is untouched and still on screen. Nothing to undo.
        img.setAttribute(ATTR_STATE, 'error');
        img.dispatchEvent(new CustomEvent('traceit:error', { bubbles: true, detail: err }));
        return null;
      });
  }

  /** Embeds into every image matching the selector. Safe to call repeatedly. */
  function embedAll(options) {
    var opts = assign({}, DEFAULTS, options || {});
    var nodes = document.querySelectorAll(opts.selector);
    return Promise.all(Array.prototype.map.call(nodes, function (img) {
      return embed(img, opts);
    }));
  }

  /** Puts every original photo back. Restores the page exactly as it was. */
  function teardown() {
    var touched = document.querySelectorAll('[' + ATTR_SRC0 + ']');
    Array.prototype.forEach.call(touched, function (img) {
      img.src = img.getAttribute(ATTR_SRC0);
      img.removeAttribute(ATTR_SRC0);
      img.removeAttribute(ATTR_STATE);
    });
  }

  window.TraceItQR = {
    embed: embed,
    embedAll: embedAll,
    teardown: teardown,
    defaults: DEFAULTS,
    version: BADGE_VERSION
  };

  if (window.jQuery) {
    window.jQuery.fn.traceItQr = function (options) {
      return this.each(function () { embed(this, options); });
    };
  }

  /* --- auto-init ---------------------------------------------------------- */

  function currentScript() {
    if (document.currentScript) return document.currentScript;
    var all = document.getElementsByTagName('script');
    for (var i = all.length - 1; i >= 0; i--) {
      if (/traceit-qr\.js/.test(all[i].src || '')) return all[i];
    }
    return null;
  }

  var NUMERIC = { embedScale: 1 };

  function optionsFromScript(el) {
    if (!el) return {};
    var d = el.dataset || {};
    var o = {};
    var map = {
      selector: 'selector', service: 'service', idAttr: 'idAttr',
      idFromPath: 'idFromPath', corner: 'corner', embedScale: 'embedScale',
      skipAttr: 'skipAttr'
    };
    for (var key in map) {
      var raw = d[map[key]];
      if (raw === undefined || raw === '') continue;
      o[key] = NUMERIC[key] ? parseFloat(raw) : raw;
    }

    // Default the service base to wherever this script was served from, so the
    // common case needs no data-service at all.
    if (!o.service && el.src) {
      try { o.service = new URL(el.src, window.location.href).origin; } catch (e) {}
    }
    return o;
  }

  var scriptEl = currentScript();
  var autoOpts = optionsFromScript(scriptEl);
  if (window.TraceItQRConfig) {
    autoOpts = assign({}, autoOpts, window.TraceItQRConfig);
  }

  function watch(opts) {
    if (!window.MutationObserver) return;
    var timer = null;

    var observer = new MutationObserver(function (records) {
      var interesting = records.some(function (r) {
        return Array.prototype.some.call(r.addedNodes, function (n) {
          return n.nodeType === 1;
        });
      });
      if (!interesting) return;

      clearTimeout(timer);
      timer = setTimeout(function () { embedAll(opts); }, 150);
    });

    // childList only. This script adds no elements and changes only an attribute,
    // so its own writes cannot reach this callback and it cannot feed itself.
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  function autoRun() {
    embedAll(autoOpts);
    watch(autoOpts);
  }

  if (!scriptEl || scriptEl.getAttribute('data-auto') !== 'off') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', autoRun);
    } else {
      autoRun();
    }
  }
})(window, document);
