# Trace-It QR inside article thumbnails — proof of concept

A dummy news site ("Island Chronicle") demonstrating that a Trace-It QR code can be placed
**inside the article thumbnail's frame**, driven by nothing but the publisher's article ID,
with **no read or write access to the publisher's data or their S3 bucket**.

The requirement this was built against is recorded in [REQUIREMENT.md](REQUIREMENT.md).

```bash
npm install
npm start          # http://localhost:3000
npm run verify     # proves the claims below, in real Chromium
```

## The demo is three separate origins

That is deliberate. The trust boundary is the whole point, so the demo makes it real rather
than describing it:

| | Origin | Whose | What it is |
|---|---|---|---|
| `:3000` | publisher site | **theirs** | The CMS and article pages. We cannot read this. |
| `:3001` | S3 image layer | **theirs** | Thumbnails. Sends **no CORS headers**, by design. |
| `:3002` | Trace-It service | **ours** | Mints and serves QR codes. The only thing we deploy. |

Open <http://localhost:3000>, then <http://localhost:3000/cms> to publish an article and
watch its ID get captured.

## How it works

```
  PUBLISH TIME
  publisher's CMS ──POST {articleId, url, title}──▶ our service ──▶ Trace-It
                                                        │
                                                   store: articleId → code
  PAGE VIEW
  reader's browser ──GET /v1/qr/<articleId>.png──▶ our service
                   ──GET thumbnail─────────────▶ their S3   (untouched, no CORS needed)
                   overlay script places the QR inside the image frame
```

Only the article ID ever crosses the boundary. We never read their database, never touch
their bucket, and never receive the image itself.

## The integration, in full

**One script tag**, served from our domain:

```html
<script src="https://traceit.example.com/js/traceit-qr-overlay.js"
        data-selector="img.story-thumb"></script>
```

**One attribute** on the thumbnail, carrying the ID they already have:

```html
<img src="https://their-s3/…/photo.jpg" class="story-thumb" data-article-id="108-347979">
```

If adding that attribute is awkward in their templates, drop it — on a single-article page
the script recovers the ID from the URL instead:

```html
<script src="…/traceit-qr-overlay.js"
        data-selector="img.story-thumb"
        data-id-from-path="/article/([A-Za-z0-9._-]+)"></script>
```

**Ten lines in their publish routine**, to capture the ID
([`php/publish-hook.php`](php/publish-hook.php)):

```php
$articleId = $cms->publish($draft);                  // their existing code
traceit_notify_published(                            // the addition
    $articleId,
    'https://www.example.lk/article/' . $articleId,
    $draft->headline
);
```

That is the entire publisher-side footprint.

## Why an overlay and not baking the QR into the image

Because we cannot read their images, and that is not a preference — it is enforced by the
browser. Drawing a cross-origin image into a `<canvas>` and reading it back throws
`SecurityError` unless the image host sends `Access-Control-Allow-Origin`. Setting that
header means changing their bucket configuration, which the access constraints forbid.

`npm run verify` proves this rather than asserting it: on the same page, in real Chromium,
it confirms the canvas readback throws `SecurityError` while the overlay renders correctly.

### What this cannot do

Two limitations follow directly from not touching the image bytes. **Both should be raised
with the client**, because neither can be fixed from the frontend:

1. **"Save image as…" gives the clean photo.** The QR is a DOM element, not part of the
   JPEG, so a saved or copied image has no code in it.
2. **The QR is not in `og:image`.** Facebook, X and WhatsApp read the meta tag and fetch
   the untouched S3 file, so share previews show no code.

If either matters, the QR has to be written into the image at publish time, server-side.
That needs write access to the image derivative — a different project. The earlier
composite-based prototype is still in this repo; see
[README-composite.md](README-composite.md).

## Two ways to capture the ID

Both are implemented. The choice is the client's — see REQUIREMENT.md §8.

**Path A — publish webhook (recommended).** Their CMS POSTs the ID to
`/v1/hooks/article-published` with a bearer secret. One mint per article, at a predictable
moment, authenticated. Once this is live, set `ALLOW_LAZY_MINT=false` and the public
endpoint can no longer create anything — only serve. Quota exposure becomes zero.

**Path B — lazy mint (no CMS change).** The frontend sends the ID it found in the DOM and
we mint on first request for an unknown ID. Zero integration work, but an unauthenticated
endpoint can cause a mint, so it is gated by an origin allowlist, a rate limit, and a strict
ID pattern.

Trace-It codes count against a monthly quota, so minting exactly once per article matters.
[`server/store.js`](server/store.js) guarantees it: a cache miss is the only thing that
calls Trace-It, and concurrent first-views for the same article are deduplicated into a
single mint. Verified — 50 simultaneous requests produce 1 mint.

## Layout safety

The publisher's thumbnails carry inline layout (`style="float:left; max-width:300px
!important"`) under a global `img { max-width:100% !important; height:auto !important }`
rule. Dropping an element into that without moving the page is the hard part.

- If they already have a frame element around the image, the script uses it and **adds no
  nodes at all** — set `data-frame=".img-frame"`.
- Otherwise it wraps the image in an inline-block span that shrink-wraps it exactly, and
  **moves** the float, margins and vertical-align onto the wrapper so text wraps as before.
- The original inline `style` attribute is stored verbatim and restored by `teardown()`.
- The badge is a `<span>` with a background image, not an `<img>`, so the publisher's
  global `img` rule cannot reach it.
- `pointer-events: none`, so existing click and right-click behaviour on the photo is
  untouched.
- Float is re-synced on resize, because their float is media-query driven (thumbnails
  un-float below 575px) while a copied value would be frozen at page load.

`npm run verify` measures every thumbnail, paragraph and article block with the script
blocked, then again with it running, and fails if anything moves by more than 1px.

## Options

Every option has a `data-*` equivalent on the script tag.

| Option | `data-*` | Default | Meaning |
|---|---|---|---|
| `selector` | `data-selector` | `img[data-article-id]` | Which images to decorate |
| `frameSelector` | `data-frame` | — | Use an existing frame instead of wrapping |
| `service` | `data-service` | script's own origin | Base URL of our service |
| `idAttr` | `data-id-attr` | `data-article-id` | Attribute holding the article ID |
| `idFromPath` | `data-id-from-path` | `/article/([A-Za-z0-9._-]+)` | Recover the ID from the URL |
| `corner` | `data-corner` | `bottom-right` | Also `bottom-left`, `top-right`, `top-left` |
| `sizePct` | `data-size` | `28` | QR width as % of frame width |
| `minPx` / `maxPx` | `data-min` / `data-max` | `48` / `160` | Clamps, keeps it scannable |
| `padPct` | `data-pad` | `3.5` | Inset from the frame edge |
| `plate` | `data-plate` | `true` | White plate behind the code |
| `clickable` | `data-clickable` | `false` | Let the badge take pointer events |
| `minFrameWidth` | `data-min-frame` | `120` | Skip frames too small to scan |

Failure is non-fatal by design: if the code does not exist or the request fails, no badge is
rendered, the photo is untouched, and a `traceit:error` event fires.

## Running against the live Trace-It API

Without a key the demo generates **real, scannable** QR codes locally, so everything is
testable offline. Those codes encode the article URL directly and are not tracked links.

```powershell
$env:TRACEIT_API_KEY="sk_live_xxxxxxxxxxxx"
$env:TRACEIT_BASE="https://<subdomain>.trace-it.io"
npm start
```

> **The Trace-It API contract in [`server/traceit-client.js`](server/traceit-client.js) is
> an assumption.** The Trace-It repo is private and could not be read while building this.
> Every call to Trace-It goes through that one file, so correcting the shapes is a change to
> that file alone. See REQUIREMENT.md §9 for exactly what to confirm.

## Environment

| Variable | Default | Purpose |
|---|---|---|
| `TRACEIT_API_KEY` | — | Live API key. Unset ⇒ local QR generation. |
| `TRACEIT_BASE` | `https://demo.trace-it.io` | Trace-It API base URL |
| `TRACEIT_WEBHOOK_SECRET` | `dev-webhook-secret` | Bearer secret for the publish webhook |
| `TRACEIT_ALLOWED_ORIGINS` | `http://localhost:3000,…` | CORS allowlist for our service |
| `ALLOW_LAZY_MINT` | `true` | Set `false` once the webhook is live |
| `ARTICLE_URL_TEMPLATE` | `http://localhost:3000/article/{id}` | Where a scan lands |
| `TRACEIT_ALLOWED_DESTINATION_HOSTS` | host of the template above | Hosts a QR may point at |
| `PORT` / `S3_PORT` / `TRACEIT_PORT` | `3000` / `3001` / `3002` | Ports |

## Files

| Path | Purpose |
|---|---|
| `public/js/traceit-qr-overlay.js` | **The deliverable** — the overlay component |
| `server/traceit-service.js` | **Ours.** Webhook, QR endpoints, serves the script |
| `server/traceit-client.js` | **The only file that knows the Trace-It API shape** |
| `server/store.js` | articleId → code, held on our side; mint-once guarantee |
| `server/publisher-site.js` | **Theirs.** Mock CMS; contains the one integration call |
| `server/fake-s3.js` | **Theirs.** Image origin with no CORS, by design |
| `server/start-all.js` | Boots all three origins |
| `public/home.html` | Article list — ID from `data-article-id` |
| `public/article.html` | Single article — ID from the URL |
| `public/cms.html` | Newsroom: publish an article, watch the ID get captured |
| `php/publish-hook.php` | **PHP** — the client-side integration, drop into their CMS |
| `php/qr-proxy.php` | **PHP** — optional, serves the QR from their own domain (CSP) |
| `tools/verify-approaches.js` | Headless proof of every claim above |
| `REQUIREMENT.md` | The requirement, constraints and open questions |
| `README-composite.md` | The earlier bake-into-pixels prototype, and why it is parked |

> The PHP files have **not been executed** — PHP was not available in this environment.
> They mirror the Node implementations, which are exercised by `npm run verify`. Lint them
> (`php -l`) against the target PHP version before shipping.

All content is fictional. "Island Chronicle" is not a real publication.
