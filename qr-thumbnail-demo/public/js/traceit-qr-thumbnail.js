/*!
 * traceit-qr-thumbnail.js
 * ---------------------------------------------------------------------------
 * Composites a Trace-It QR code into an article thumbnail, in the browser,
 * and swaps the result back into the original <img>.
 *
 * Why this approach:
 *   "Save image as..." is a NATIVE browser menu item. No JavaScript event
 *   fires for it and it cannot be intercepted. The only reliable way to make
 *   a native save produce a QR-embedded file is to make the QR part of the
 *   image the <img> is actually displaying. That is what this script does:
 *   it draws photo + QR onto a <canvas>, exports a Blob, and points the
 *   original <img> at that Blob URL. From that moment the browser's own
 *   save-as writes the composite, because the composite IS the image.
 *
 * Constraints this script respects (from the live Daily Mirror markup):
 *   - Thumbnails are plain <img> tags carrying inline styles such as
 *     `float: left; max-width: 300px !important`. We never touch geometry —
 *     only the `src` attribute changes, so layout is bit-identical.
 *   - A global stylesheet rule forces `img { max-width:100% !important;
 *     height:auto !important }`. Because we swap src rather than wrapping the
 *     element, that rule keeps applying exactly as before.
 *   - Thumbnails come from a different origin (Oracle Object Storage), which
 *     taints the canvas. See resolveImageUrl() for the two supported fixes.
 *
 * Dependencies: none. jQuery is optional and only used if already present.
 */
(function (window, document) {
  'use strict';

  var DEFAULTS = {
    // Fraction of the thumbnail's SHORT side used for the QR badge width.
    qrScale: 0.28,
    // Minimum/maximum QR width in source pixels, keeps it scannable but small.
    qrMinPx: 120,
    qrMaxPx: 420,
    // Padding between QR badge and image edge, as a fraction of short side.
    padScale: 0.035,
    corner: 'bottom-right',
    // White plate behind the QR so it stays scannable over busy photography.
    plate: true,
    plateRadius: 0.06,
    plateOpacity: 0.96,
    // Export settings. PNG keeps the QR crisp; JPEG is smaller but can
    // introduce ringing around the modules at low quality.
    mime: 'image/png',
    quality: 0.92,
    // Route cross-origin images through our own origin to keep canvas clean.
    proxyPath: '/img?url=',
    // Set true if the CDN sends Access-Control-Allow-Origin; then no proxy.
    useCrossOriginAttr: false,
    downloadName: null,
  };

  var CORNERS = {
    'bottom-right': function (W, H, w, h, p) { return [W - w - p, H - h - p]; },
    'bottom-left': function (W, H, w, h, p) { return [p, H - h - p]; },
    'top-right': function (W, H, w, h, p) { return [W - w - p, p]; },
    'top-left': function (W, H, w, h, p) { return [p, p]; }
  };

  function assign(target) {
    for (var i = 1; i < arguments.length; i++) {
      var src = arguments[i] || {};
      for (var k in src) if (Object.prototype.hasOwnProperty.call(src, k)) target[k] = src[k];
    }
    return target;
  }

  function isSameOrigin(url) {
    try {
      return new URL(url, window.location.href).origin === window.location.origin;
    } catch (e) {
      return false;
    }
  }

  /**
   * Cross-origin images taint the canvas and make toBlob() throw. Two fixes:
   *   1. CDN sets Access-Control-Allow-Origin -> use crossOrigin="anonymous".
   *   2. No CDN control -> stream bytes through our own origin.
   * Option 1 is cheaper at scale; option 2 needs no CDN change.
   */
  function resolveImageUrl(url, opts) {
    if (opts.useCrossOriginAttr || isSameOrigin(url)) return url;
    return opts.proxyPath + encodeURIComponent(new URL(url, window.location.href).href);
  }

  function loadImage(url, opts) {
    return new Promise(function (resolve, reject) {
      var img = new Image();
      if (opts.useCrossOriginAttr && !isSameOrigin(url)) img.crossOrigin = 'anonymous';
      img.onload = function () { resolve(img); };
      img.onerror = function () { reject(new Error('Failed to load image: ' + url)); };
      img.src = url;
    });
  }

  function roundedRect(ctx, x, y, w, h, r) {
    r = Math.min(r, w / 2, h / 2);
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }

  /**
   * Draws photo + QR and returns { blob, dataUrl, width, height }.
   * Works at the photo's NATIVE resolution, not its displayed size, so the
   * saved file is full quality and the QR stays scannable when printed.
   */
  function composite(photo, qr, options) {
    var opts = assign({}, DEFAULTS, options || {});
    var W = photo.naturalWidth || photo.width;
    var H = photo.naturalHeight || photo.height;
    var shortSide = Math.min(W, H);

    var canvas = document.createElement('canvas');
    canvas.width = W;
    canvas.height = H;
    var ctx = canvas.getContext('2d');
    ctx.drawImage(photo, 0, 0, W, H);

    // QR badge is taller than wide (the Trace-It label sits under the code),
    // so size from width and derive height from the source aspect ratio.
    var qrW = Math.round(shortSide * opts.qrScale);
    qrW = Math.max(opts.qrMinPx, Math.min(opts.qrMaxPx, qrW));
    qrW = Math.min(qrW, Math.round(W * 0.45));
    var qrAspect = (qr.naturalHeight || qr.height) / (qr.naturalWidth || qr.width);
    var qrH = Math.round(qrW * qrAspect);

    var pad = Math.round(shortSide * opts.padScale);
    var platePad = opts.plate ? Math.round(qrW * 0.07) : 0;
    var plateW = qrW + platePad * 2;
    var plateH = qrH + platePad * 2;

    var place = CORNERS[opts.corner] || CORNERS['bottom-right'];
    var pos = place(W, H, plateW, plateH, pad);
    var px = pos[0];
    var py = pos[1];

    if (opts.plate) {
      ctx.save();
      ctx.globalAlpha = opts.plateOpacity;
      ctx.fillStyle = '#ffffff';
      ctx.shadowColor = 'rgba(0,0,0,0.30)';
      ctx.shadowBlur = Math.round(qrW * 0.10);
      ctx.shadowOffsetY = Math.round(qrW * 0.02);
      roundedRect(ctx, px, py, plateW, plateH, Math.round(qrW * opts.plateRadius));
      ctx.fill();
      ctx.restore();
    }

    ctx.drawImage(qr, px + platePad, py + platePad, qrW, qrH);

    return new Promise(function (resolve, reject) {
      var dataUrl;
      try {
        dataUrl = canvas.toDataURL(opts.mime, opts.quality);
      } catch (e) {
        return reject(new Error(
          'Canvas is tainted — the thumbnail was loaded cross-origin without CORS. ' +
          'Either enable Access-Control-Allow-Origin on the image CDN and set ' +
          'useCrossOriginAttr:true, or route the image through the same-origin proxy. ' +
          'Original error: ' + e.message
        ));
      }
      canvas.toBlob(function (blob) {
        if (!blob) return reject(new Error('canvas.toBlob returned null'));
        resolve({ blob: blob, dataUrl: dataUrl, width: W, height: H });
      }, opts.mime, opts.quality);
    });
  }

  /**
   * Fetches a QR PNG for the given destination URL via our server, which holds
   * the Trace-It API key. Results are memoised per URL for the page lifetime.
   */
  var qrCache = {};
  function fetchQrDataUri(sourceUrl, name, endpoint) {
    if (qrCache[sourceUrl]) return qrCache[sourceUrl];
    qrCache[sourceUrl] = fetch(endpoint || '/api/qr', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ sourceUrl: sourceUrl, name: name })
    })
      .then(function (r) {
        if (!r.ok) throw new Error('QR request failed: HTTP ' + r.status);
        return r.json();
      })
      .then(function (d) {
        if (!d.pngDataUri && !d.pngUrl) throw new Error('QR response contained no image');
        return d;
      });
    return qrCache[sourceUrl];
  }

  /**
   * Main entry point. Give it an <img> and a destination URL; it replaces the
   * img's src with a QR-embedded composite, in place.
   *
   * The element keeps its class list, inline styles, float, sizing, alt text
   * and position in the DOM. Only `src` changes — plus a couple of data-*
   * attributes so the operation is idempotent and inspectable.
   */
  function enhance(img, sourceUrl, options) {
    var opts = assign({}, DEFAULTS, options || {});
    if (img.getAttribute('data-traceit-state') === 'done') return Promise.resolve(img);
    img.setAttribute('data-traceit-state', 'pending');

    var original = img.getAttribute('data-traceit-original') || img.currentSrc || img.src;
    img.setAttribute('data-traceit-original', original);

    return Promise.all([
      loadImage(resolveImageUrl(original, opts), opts),
      fetchQrDataUri(sourceUrl, opts.name || document.title, opts.qrEndpoint)
        .then(function (d) { return loadImage(d.pngDataUri || d.pngUrl, opts); })
    ])
      .then(function (pair) {
        return composite(pair[0], pair[1], opts);
      })
      .then(function (out) {
        var url = URL.createObjectURL(out.blob);
        // Swapping src is the whole trick: the browser's native "Save image
        // as..." now writes this composite, with zero JS involvement.
        img.src = url;
        img.setAttribute('data-traceit-state', 'done');
        img.setAttribute('data-traceit-blob', url);
        // Gives the native save dialog a sensible default filename in
        // browsers that honour it for blob-backed images.
        if (opts.downloadName) img.setAttribute('data-filename', opts.downloadName);
        img.dispatchEvent(new CustomEvent('traceit:composited', {
          bubbles: true,
          detail: { width: out.width, height: out.height, size: out.blob.size }
        }));
        return img;
      })
      .catch(function (err) {
        img.setAttribute('data-traceit-state', 'error');
        img.dispatchEvent(new CustomEvent('traceit:error', { bubbles: true, detail: err }));
        // Non-fatal by design: on failure the original photo stays visible.
        console.error('[traceit-qr]', err);
        throw err;
      });
  }

  /**
   * Convenience sweep: enhances every img matching `selector`, taking the
   * destination URL from a data attribute or falling back to the page URL.
   */
  function enhanceAll(selector, options) {
    var opts = assign({}, DEFAULTS, options || {});
    var nodes = document.querySelectorAll(selector || 'img[data-traceit-url]');
    return Promise.all(Array.prototype.map.call(nodes, function (img) {
      var url = img.getAttribute('data-traceit-url') || window.location.href;
      var src = img.getAttribute('src');

      // Skip templates whose bindings have not been interpolated yet. An
      // AngularJS ng-repeat clone, a Vue/Handlebars template or a lazy-load
      // placeholder can be in the DOM while its attributes still read
      // "{{...}}" or point at a 1px spacer. Compositing those would cache a
      // QR against a garbage URL. The MutationObserver re-runs this sweep
      // once the real values land.
      if (!src || /\{\{|\}\}/.test(src) || /\{\{|\}\}/.test(url)) return null;

      return enhance(img, url, opts).catch(function () { return null; });
    }));
  }

  /** Explicit download, for an optional "Download with QR" button. */
  function download(img, filename) {
    var src = img.getAttribute('data-traceit-blob') || img.src;
    var a = document.createElement('a');
    a.href = src;
    a.download = filename || 'article-image-qr.png';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  }

  window.TraceItQR = {
    enhance: enhance,
    enhanceAll: enhanceAll,
    composite: composite,
    download: download,
    defaults: DEFAULTS
  };

  // Optional jQuery sugar, matching the site's existing jQuery 3.7.1 usage.
  if (window.jQuery) {
    window.jQuery.fn.traceItQr = function (sourceUrl, options) {
      return this.each(function () { enhance(this, sourceUrl, options); });
    };
  }

  /* ---------------------------------------------------------------------
   * Auto-initialisation
   *
   * The script configures and runs itself. Dropping the <script> tag on the
   * page is the entire integration — no button, no manual call, no UI.
   *
   *   <script src="/js/traceit-qr-thumbnail.js"
   *           data-selector="img.article-thumb"
   *           data-corner="bottom-right"
   *           data-qr-scale="0.28"
   *           data-cross-origin="true"></script>
   *
   * Set data-auto="off" to suppress this and drive the API manually.
   * ------------------------------------------------------------------- */

  function currentScript() {
    if (document.currentScript) return document.currentScript;
    var all = document.getElementsByTagName('script');
    for (var i = all.length - 1; i >= 0; i--) {
      if (/traceit-qr-thumbnail\.js/.test(all[i].src)) return all[i];
    }
    return null;
  }

  /** Reads options from the script tag's data-* attributes. */
  function optionsFromScript(el) {
    if (!el) return {};
    var d = el.dataset || {};
    var o = {};
    if (d.selector) o.selector = d.selector;
    if (d.corner) o.corner = d.corner;
    if (d.qrScale) o.qrScale = parseFloat(d.qrScale);
    if (d.qrMinPx) o.qrMinPx = parseInt(d.qrMinPx, 10);
    if (d.qrMaxPx) o.qrMaxPx = parseInt(d.qrMaxPx, 10);
    if (d.padScale) o.padScale = parseFloat(d.padScale);
    if (d.mime) o.mime = d.mime;
    if (d.quality) o.quality = parseFloat(d.quality);
    if (d.proxyPath) o.proxyPath = d.proxyPath;
    if (d.qrEndpoint) o.qrEndpoint = d.qrEndpoint;
    if (d.plate) o.plate = d.plate !== 'false';
    if (d.crossOrigin) o.useCrossOriginAttr = d.crossOrigin === 'true';
    return o;
  }

  var scriptEl = currentScript();
  var autoOpts = optionsFromScript(scriptEl);
  var autoSelector = autoOpts.selector || 'img[data-traceit-url]';
  delete autoOpts.selector;

  // Merge anything the page pre-declared as window.TraceItQRConfig. Lets a CMS
  // template set options server-side without touching the script tag.
  if (window.TraceItQRConfig) autoOpts = assign({}, autoOpts, window.TraceItQRConfig);

  function autoRun() {
    enhanceAll(autoSelector, autoOpts);

    // Thumbnails injected later — infinite scroll, AJAX section loads, or an
    // AngularJS ng-repeat that renders after this script runs — are picked up
    // automatically. enhance() is idempotent, so re-scanning is safe.
    if (!window.MutationObserver) return;

    var pending = null;
    var observer = new MutationObserver(function (records) {
      // Ignore the mutations this script itself causes when it swaps @src,
      // otherwise the observer retriggers on its own writes.
      var external = records.some(function (r) {
        if (r.type !== 'childList') return false;
        return Array.prototype.some.call(r.addedNodes, function (n) {
          return n.nodeType === 1;
        });
      });
      if (!external) return;

      clearTimeout(pending);
      pending = setTimeout(function () { enhanceAll(autoSelector, autoOpts); }, 150);
    });

    // childList only — deliberately not watching attributes, so our own @src
    // writes never enter the observer callback in the first place.
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (!scriptEl || scriptEl.getAttribute('data-auto') !== 'off') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', autoRun);
    } else {
      autoRun();
    }
  }
})(window, document);
