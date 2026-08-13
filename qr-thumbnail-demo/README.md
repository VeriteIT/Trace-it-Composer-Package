# Trace-It QR → article thumbnail (proof of concept)

A small mock news site ("Island Chronicle") demonstrating that a Trace-It QR code can be
embedded into article thumbnails **entirely from the frontend**, such that the browser's
native **"Save image as…"** downloads the QR-embedded file.

## Run it

```bash
npm install
npm start          # http://localhost:3000
```

Optional — use the live Trace-It API instead of the bundled sample QR:

```bash
# PowerShell
$env:TRACEIT_API_KEY="sk_live_xxxxxxxxxxxx"
$env:TRACEIT_BASE="https://<subdomain>.trace-it.io"
npm start
```

Without a key the server serves a bundled sample QR, so the compositing behaviour is
fully demonstrable offline.

## The demo

Open the page. The QR codes embed themselves — **no button, no user action**. Then
right-click any thumbnail → **Save image as…**; the saved file contains the QR code.

## The key insight

**"Save image as…" cannot be intercepted by JavaScript.** It is a native browser menu
item; no DOM event fires for it, and no script runs during the save.

So the only approach that survives a native save is to make the QR *part of the image
the `<img>` is displaying*:

1. Server calls `POST /api/v1/qr` on Trace-It, receives a PNG as a base64 data URI.
2. Browser loads the article photo through a same-origin path (canvas stays untainted).
3. Photo + QR are drawn to a `<canvas>` at the photo's **native** resolution.
4. Canvas → `Blob` → `URL.createObjectURL()`.
5. The original `<img>`'s `src` is pointed at that Blob URL.

From step 5 onward the browser's own save-as writes the composite, because the composite
*is* the image. This works in every browser, and needs no extension or custom menu.

A "Download with QR" button is also included, but it is a convenience — the native
save-as path is the one that matters.

## Matching the live site's stack

The demo page deliberately runs the same frontend stack observed on the target site, so
the script is a realistic drop-in rather than a greenfield prototype:

| Piece | Version used |
|---|---|
| jQuery | 3.7.1 |
| AngularJS | 1.5.8 (legacy 1.x, not Angular 2+) |
| Bootstrap | 5.3.2 |
| Font Awesome | 6.4.2 |
| Fonts | Faustina (body serif), Merriweather Sans (furniture) |

The thumbnail markup carries the same inline styling as production:

```html
<img src="…" style="float: left; max-width: 300px !important">
```

The script **only rewrites `@src`**. Class list, inline styles, float, sizing, alt text
and DOM position are all untouched, so layout is bit-identical — including under the
site's global `img { max-width:100% !important; height:auto !important }` rule.

## The one real blocker: CORS

Production thumbnails are served from Oracle Object Storage:

```
https://bmkltsly13vb.compat.objectstorage.ap-singapore-1.oraclecloud.com/cdn.sg.dailymirror.lk/…
```

That is a **different origin** from the page. Drawing a cross-origin image into a canvas
*taints* it, and the subsequent `toBlob()` / `toDataURL()` throws a `SecurityError`.

Two supported fixes — pick one:

**Option A — enable CORS on the bucket (recommended).**
Set `Access-Control-Allow-Origin` on the Object Storage bucket, then:

```js
TraceItQR.enhanceAll('img.story-thumb', { useCrossOriginAttr: true });
```

No proxy, no extra bandwidth, scales cleanly. Requires one CDN config change.

**Option B — same-origin image proxy (no CDN change).**
Stream the bytes through the publisher's own origin; `server/index.js` implements this at
`GET /img?url=…`. Costs an extra hop and bandwidth, but needs nothing from the CDN.

The script auto-selects: same-origin URLs load directly, cross-origin URLs go through
`proxyPath` unless `useCrossOriginAttr` is set. If the canvas is tainted anyway, the
error message states explicitly which fix to apply.

## Integration — what their team actually adds

**One script tag. That is the entire integration.**

```html
<script src="/js/traceit-qr-thumbnail.js"
        data-selector="img.article-thumb"
        data-corner="bottom-right"
        data-qr-scale="0.28"
        data-cross-origin="true"></script>
```

The script self-configures from those `data-*` attributes, waits for DOM ready, and
embeds the QR into every matching thumbnail on its own. No button, no user action, and
**no change to the publisher's application code** — the demo's AngularJS controller
contains zero QR logic.

Each thumbnail declares its destination URL:

```html
<img src="…" class="article-thumb"
     data-traceit-url="https://example.com/article/108-347979">
```

If omitted, the current page URL is used — which is the correct destination on an
article page anyway, so single-article templates need no attribute at all.

### Late-rendered thumbnails

A `MutationObserver` picks up images added after load — `ng-repeat` renders, AJAX
section loads, infinite scroll, lazy-loading. Images whose bindings are still
uninterpolated (`src` containing `{{…}}`) are skipped and retried once the real values
land, so nothing is composited against a placeholder URL.

### Manual control (optional)

Set `data-auto="off"` to suppress auto-init and drive the API yourself:

```js
TraceItQR.enhanceAll('img.article-thumb', { corner: 'top-right' });
TraceItQR.enhance(imgElement, 'https://example.com/article/123');
$('img.article-thumb').traceItQr(url);   // jQuery sugar, already on their page
```

### Config via template

A CMS template can set options server-side without touching the script tag:

```html
<script>window.TraceItQRConfig = { qrScale: 0.32, corner: 'bottom-left' };</script>
```

### Options

| Option | Default | Meaning |
|---|---|---|
| `qrScale` | `0.28` | QR width as fraction of the image's short side |
| `qrMinPx` / `qrMaxPx` | `120` / `420` | Clamps, keeps the code scannable |
| `corner` | `'bottom-right'` | Also `bottom-left`, `top-right`, `top-left` |
| `plate` | `true` | White rounded plate behind the QR |
| `mime` | `'image/png'` | `image/jpeg` is smaller but can ring the modules |
| `useCrossOriginAttr` | `false` | `true` once the CDN sends CORS headers |
| `proxyPath` | `'/img?url='` | Same-origin proxy endpoint |

Every option has a `data-*` equivalent on the script tag, in kebab-case:
`data-qr-scale`, `data-corner`, `data-cross-origin`, `data-selector`, `data-auto`.

Failure is non-fatal by design: if the QR fetch or compositing fails, the original photo
stays visible and a `traceit:error` event fires.

## PHP backend

**The backend language is irrelevant to whether this works.** All compositing happens in
the browser; the script never knows what generated the HTML. The Node server here is only
doing two small jobs, both provided as drop-in PHP in [`php/`](php/):

| Node (demo) | PHP equivalent | Purpose |
|---|---|---|
| `POST /api/qr` | [`php/qr.php`](php/qr.php) | Calls Trace-It, keeps the API key server-side |
| `GET /img` | [`php/img.php`](php/img.php) | Same-origin image proxy (only if CDN lacks CORS) |

Point the script at the PHP endpoint — nothing else changes:

```html
<script src="/js/traceit-qr-thumbnail.js"
        data-selector="img.article-thumb"
        data-qr-endpoint="/qr.php"
        data-proxy-path="/img.php?url="></script>
```

Both files are plain PHP with no framework and no Composer dependencies — they need only
`ext-curl`. `qr.php` caches per source URL on disk (30 days), which matters because QR
creation counts against the monthly quota; without caching every page view burns quota.

> **Security note on `img.php`:** it accepts a URL from the client, so it is locked to an
> explicit `$ALLOWED_HOSTS` allowlist and does not follow redirects. Without that it would
> be an open proxy usable to reach internal services (cloud metadata endpoints, localhost
> admin panels). **Add the real CDN hostnames to that array before deploying.**

### Better still on PHP: composite server-side with GD

Since the backend is PHP, [`php/composite.php`](php/composite.php) does the same
compositing with **GD** instead of canvas. This is the stronger option:

| Frontend canvas | PHP + GD |
|---|---|
| Needs CDN CORS or a proxy | **No CORS question at all** |
| QR absent from `og:image` | **QR appears in social share previews** |
| Recomputed per page view | Composite once, cache, serve as a static file |
| Zero backend change | Runs inside the existing PHP app |

The `og:image` difference is the one worth raising with the client: social crawlers read
the meta tag, not the DOM, so the **frontend approach cannot put a QR in Facebook/X/
WhatsApp previews.** Only server-side compositing does that. If the goal includes traced
links on shared articles, GD is the answer.

Best used at upload/publish time, writing the QR into the stored derivative. Degrades
gracefully: if the Trace-It call fails, it serves the untouched photo rather than
breaking the image request.

> These PHP files have not been executed — PHP was not available in the environment where
> this demo was built. The logic mirrors the verified JavaScript implementation
> line-for-line, but lint them (`php -l`) against the target PHP version before shipping.

## Which approach to recommend

Frontend is the right call for a fast rollout and per-article control — one script tag,
no backend change, nothing to migrate. Server-side (GD) is the right call once it's
permanent, and is the only option that reaches `og:image`. The compositing math is
identical either way, so starting on the frontend and moving server-side later is not
a rewrite.

## Verification

```bash
npm run verify     # composites all three thumbnails headlessly, checks bounds
```

Output lands in `verify-output/`.

## Files

| Path | Purpose |
|---|---|
| `public/js/traceit-qr-thumbnail.js` | **The drop-in script** — the deliverable |
| `public/js/app.js` | AngularJS 1.5.8 controller for the demo page |
| `public/index.html` | Mock article page on the matching stack |
| `public/css/site.css` | Typography matching the target site |
| `server/index.js` | Trace-It proxy (`/api/qr`) + image proxy (`/img`) |
| `php/qr.php` | **PHP** Trace-It proxy — drop-in replacement for `/api/qr` |
| `php/img.php` | **PHP** same-origin image proxy — replacement for `/img` |
| `php/composite.php` | **PHP** server-side compositing with GD (reaches `og:image`) |
| `server/articles.json` | Three fictional articles |
| `tools/make-thumbnails.js` | Generates placeholder photography |
| `tools/verify-composite.js` | Headless correctness check |

All content is fictional. The site is branded "Island Chronicle" and is not presented as
any real publication.
