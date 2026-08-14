# The composite approach (earlier prototype — parked)

> **Why this is parked.** This was the first prototype: it bakes the QR into the image
> pixels so that a native "Save image as…" downloads a QR-embedded file. It works, and the
> reasoning below still holds — but it requires **reading the publisher's image bytes**,
> which means either CORS headers on their S3 bucket or proxying their images through a
> server. The access constraints for this client (no read or write on their data, only the
> article ID) rule both out. See [REQUIREMENT.md](REQUIREMENT.md) §6.
>
> The overlay approach in [README.md](README.md) is the one that fits the constraints.
> Come back to this document if the client ever grants CDN configuration access, or if
> QR-in-`og:image` or QR-in-downloaded-file becomes a hard requirement — those are the two
> things only this approach can deliver.
>
> Still runnable: `npm run start:composite` and `npm run verify:composite`.

---

A small mock news site ("Island Chronicle") demonstrating that a Trace-It QR code can be
embedded into article thumbnails **entirely from the frontend**, such that the browser's
native **"Save image as…"** downloads the QR-embedded file.

## Run it

```bash
npm install
npm run start:composite      # http://localhost:3000
```

Optional — use the live Trace-It API instead of the bundled sample QR:

```powershell
$env:TRACEIT_API_KEY="sk_live_xxxxxxxxxxxx"
$env:TRACEIT_BASE="https://<subdomain>.trace-it.io"
npm run start:composite
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

## The one real blocker: CORS

Production thumbnails are served from Oracle Object Storage — a **different origin** from
the page. Drawing a cross-origin image into a canvas *taints* it, and the subsequent
`toBlob()` / `toDataURL()` throws a `SecurityError`.

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

**Both are blocked by this client's access constraints.** Option A is a write to their
infrastructure. Option B means their servers proxy every thumbnail, which is a change to
their application and their bandwidth bill, and still needs their cooperation.

## Integration

```html
<script src="/js/traceit-qr-thumbnail.js"
        data-selector="img.article-thumb"
        data-corner="bottom-right"
        data-qr-scale="0.28"
        data-cross-origin="true"></script>
```

The script self-configures from those `data-*` attributes, waits for DOM ready, and
embeds the QR into every matching thumbnail on its own. A `MutationObserver` picks up
images added after load.

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

## The one thing this does that the overlay cannot

**`og:image`.** Social crawlers read the meta tag, not the DOM, so a frontend overlay
cannot put a QR in Facebook/X/WhatsApp previews. Only compositing can — and only if it
happens server-side, at publish time, writing into the stored derivative.
[`php/composite.php`](php/composite.php) does this with GD.

Likewise, only compositing puts the QR into the file a reader gets from "Save image as…".

## Known issues in this prototype

Carried over honestly rather than quietly fixed, since the approach is parked:

- `server/index.js`'s `/img` endpoint is an **open proxy** — it validates only the
  protocol, so it can be pointed at internal addresses. `php/img.php` has the allowlist
  it needs; the Node one does not. Do not deploy it as-is.
- `POST /api/qr` is unauthenticated and has **no cache**, so every page view mints a new
  code and burns quota — the exact problem this README warns about elsewhere.
- The QR placement math applies its `qrMinPx` floor before the only dimensional clamp,
  and that clamp checks width only. On wide, short images the badge overflows the top
  edge (e.g. a 400×120 photo puts it at y = −62). Fixed in the overlay component; not
  fixed here.
- `tools/verify-composite.js` re-implements the placement math instead of importing it,
  so it validates a copy and cannot catch the bug above.
- The `data-filename` attribute set on composited images does nothing — no browser
  honours it for native save-as. The saved file gets a generated blob name.

## Files

| Path | Purpose |
|---|---|
| `public/js/traceit-qr-thumbnail.js` | The compositing script |
| `public/js/app.js` | AngularJS 1.5.8 controller for the old demo page |
| `public/index.html` | Mock article page on the matching stack |
| `server/index.js` | Trace-It proxy (`/api/qr`) + image proxy (`/img`) |
| `php/qr.php` | **PHP** Trace-It proxy |
| `php/img.php` | **PHP** same-origin image proxy |
| `php/composite.php` | **PHP** server-side compositing with GD (reaches `og:image`) |
| `tools/verify-composite.js` | Headless correctness check |

> The PHP files have not been executed — PHP was not available in the environment where
> this demo was built. Lint them (`php -l`) before shipping.
