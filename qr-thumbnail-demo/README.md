# Trace-It QR inside article thumbnails — proof of concept

A dummy news site ("Island Chronicle") demonstrating that a Trace-It QR code can be placed
**inside the article thumbnail's frame**, driven by nothing but the publisher's article ID,
with **no read or write access to the publisher's data or their S3 bucket**.

The requirement this was built against is recorded in [REQUIREMENT.md](REQUIREMENT.md).

## Start here, depending on who you are

| You are | Read |
|---|---|
| **the publisher's dev team** | **[client-kit/README.md](client-kit/README.md)** — the integration guide. Four steps, copy-paste snippets, no internal detail. |
| deploying the service | [DEPLOYMENT.md](DEPLOYMENT.md) — what to run, what to configure, and the settings that are unsafe by default. |
| integrating in PHP | [packages/traceit-qr-php/](packages/traceit-qr-php/) — `composer require veriteit/trace-it-qr`. |
| evaluating the approach | this file — how it works, what it costs, what was measured. |

This README keeps the reasoning, the alternatives considered and the measurements. It is
not what you hand a client.

## The three moving parts

| | What happens | Where |
|---|---|---|
| **1. On publish** | The CMS POSTs `postId` + `targetUrl` (the live article URL). That is the whole payload | [`php/publish-hook.php`](php/publish-hook.php) → `POST /api/v1/qr` |
| **2. On render** | The frontend component fetches the matching QR for that article ID and puts it on the thumbnail | [`public/js/traceit-qr.js`](public/js/traceit-qr.js) → our service → `GET /api/v1/qr/by-post/{postId}` |
| **3. On download** | "Save image as…" writes a file with the QR baked into the pixels | [`server/compositor.js`](server/compositor.js) / [`php/framed.php`](php/framed.php) |

**On (2), one thing is not negotiable:** `by-post` authenticates with the `sk_live_…` secret,
so it is **server-to-server only**. The browser must never hold that key — anyone could read
it out of the page and mint against your account. So the component calls *our* service by
article ID, and our service holds the key and calls Trace-It. The page never sees it.

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
  publisher's CMS ──POST {postId, targetUrl}────────────▶ our service ──▶ Trace-It
                                                              │
                                                         store: postId → code

  PAGE VIEW
  reader's browser ──GET thumbnail──────────────────────▶ their S3   (shown immediately)
                   ──GET /v1/framed/<postId>.jpg────────▶ our service
                                                              │  fetches that same
                                                              │  public photo, draws
                                                              ▼  the QR into it
                   script repoints @src at the composite ──── done
```

Only the article ID ever crosses the boundary. We never read their database, never touch
their bucket, and never receive the image itself.

## "Their site is PHP" — what that changes

Nothing on their side, and nothing about whether this works. **All the publisher
touches is PHP**, and the browser script does not care what generated the HTML.

| Piece | Whose | Language |
|---|---|---|
| Capture the article ID at publish | **theirs** | **PHP** — [`php/publish-hook.php`](php/publish-hook.php) |
| `data-article-id` on the thumbnail | **theirs** | **PHP** — one `htmlspecialchars` echo |
| The `<script>` tag | **theirs** | one line of HTML |
| Mint + serve QR codes, composite images | **ours** | Node here, **or PHP** — see below |

The compositing service runs on **our** infrastructure, so its language is our choice,
not a constraint on them — they never install it. The demo implements it in Node because
that is what ran in this environment. If the Trace-It platform is PHP, there is a
complete PHP port:

| Node (demo) | PHP equivalent | Purpose |
|---|---|---|
| `GET /v1/framed/:id` | [`php/framed.php`](php/framed.php) | **The QR baked into the pixels** — what makes save-as work |
| `GET /v1/qr/:id.png` | [`php/qr-proxy.php`](php/qr-proxy.php) | Serve the QR PNG (also usable on their origin, for strict CSP) |
| `POST /v1/hooks/article-published` | thin wrapper over the Trace-It API | Mint once per article |

`framed.php` is a line-for-line port of `server/compositor.js` — same layout maths, same
host allowlist, same disk cache, same graceful degradation, same `Content-Disposition`
filename. It needs only `ext-gd` and `ext-curl`; no framework, no Composer.

**Status of the PHP files** — all six are `php -l` clean on PHP 8.4.24:

| File | State |
|---|---|
| `php/framed.php` | **Executed and verified.** Served over `php -S`, output is 1200×800 (identical to source), a QR decodes back out of the delivered JPEG with the same payload the Node version produces, SSRF and bad-id guards both refuse correctly. |
| `php/publish-hook.php` | Lint clean; not executed — it is a ten-line cURL POST mirroring the verified Node call. |
| `php/qr-proxy.php` | Lint clean; not executed. |
| `php/composite.php` | **Superseded by `framed.php`** — kept only for the parked prototype, and has a known placement bug. Do not ship it. |

### Running the whole demo on PHP, with no Node at all

```powershell
winget install PHP.PHP.8.4                  # then enable gd + curl in php.ini
cd qr-thumbnail-demo
php ../.tools/composer.phar install -d php  # demo-only, for local QR generation
.\php\demo\start-all.ps1                    # all three origins
.\php\demo\start-all.ps1 -Stop              # shut them down
```

Same three origins, same URLs, same behaviour — `/v1/health` reports `"impl":"php"`.
Verified running: publish → webhook → mint → composite → a QR decoding back out of the
delivered JPEG at 1200×800.

| File | Role |
|---|---|
| `php/demo/router-publisher.php` | :3000 — their CMS and article pages |
| `php/demo/router-s3.php` | :3001 — their images, no CORS |
| `php/demo/router-traceit.php` | :3002 — our service: mint, serve, composite |
| `php/demo/qr-store.php` | articleId → QR, held on our side |
| `php/demo/start-all.ps1` | launcher; loads `.env`, waits for health |

Two things about this layer specifically:

- **Composer appears only here.** It is used for local QR generation so the demo works
  offline without an API key. In production our service calls the Trace-It API instead.
  The drop-in integration files — `publish-hook.php`, `framed.php`, `traceit-compositor.php`,
  `qr-proxy.php` — have **no dependencies** beyond `ext-gd` and `ext-curl`.
- **`php -S` is a single-threaded dev server.** Fine for this; do not load-test with it.
  It is also why `router-traceit.php` composites in-process via `traceit-compositor.php`
  rather than HTTP-fetching its own `/v1/qr` endpoint — a self-request on a single-threaded
  server deadlocks.

To run just the compositing endpoint standalone, without the demo layer:

```powershell
cd qr-thumbnail-demo/php
$env:TRACEIT_ALLOWED_IMAGE_HOSTS = "localhost:3001,127.0.0.1:3001"
$env:TRACEIT_QR_URL_TEMPLATE     = "http://localhost:3002/v1/qr/{id}.png"
php -S 127.0.0.1:3003
# http://127.0.0.1:3003/framed.php?id=<id>&src=<public image url>
```

### Secrets

`.env` is gitignored and is where a real key belongs; `start-all.ps1` loads it. **Never put
a key in `.env.example`** — that file is tracked, so a key written there lands in git
history on the next commit.

Both implementations refuse to send an API key to a placeholder host (`demo.trace-it.io`,
`traceit.example.com`, …) and fall back to local generation with a warning. The
`Authorization` header goes out on the very first request, so a guessed hostname would hand
the credential to whoever owns that domain before any response could reveal the mistake.
Set `TRACEIT_BASE` to the real host to enable live minting.

## The integration, in full

**One script tag**, served from our domain:

```html
<script src="https://traceit.example.com/js/traceit-qr.js"
        data-selector="img.story-thumb"></script>
```

**One attribute** on the thumbnail, carrying the ID they already have:

```html
<img src="https://their-s3/…/photo.jpg" class="story-thumb" data-article-id="108-347979">
```

If adding that attribute is awkward in their templates, drop it — on a single-article page
the script recovers the ID from the URL instead:

```html
<script src="…/traceit-qr.js"
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

## Which images get a code

Opt-in per image. The script only touches what `data-selector` matches, and the default
matches only thumbnails the template has tagged — so logos, ads, author portraits and
inline body images are left alone with no extra work.

```html
<script src="https://traceit.example.com/js/traceit-qr.js"
        data-selector="img.story-thumb"></script>
```

Three levels of control:

| Want | Do |
|---|---|
| Only tagged thumbnails | default — `img[data-article-id]` |
| Only one template's images | `data-selector="img.story-thumb"` |
| Exclude one image that matches | `data-traceit="off"` on that `<img>` |

The opt-out exists for a sponsored photo, a wire-service image, or a graphic where the badge
would cover something. It is cheaper than maintaining an ever-more-specific selector, and the
reason lives on the image where an editor can see it. The demo's Environment story uses it, so
its photo stays plain.

## What it changes on the page: one attribute

Only `src`, and only to an image with identical pixel dimensions. **No elements are added, no
CSS is injected, nothing is wrapped.** So there is nothing for the layout to react to, and no
interaction with the publisher's own styles.

`npm run verify` measures every thumbnail, paragraph and article block with the script
blocked, then again with it running, and fails if anything moves by more than 1px. It also
asserts the DOM footprint is literally zero.

**It degrades safely.** The publisher's own photo stays on screen until the composite has
actually loaded. If our service is slow, unreachable, or holds no code for an article, the swap
simply never happens and the reader sees the original photo. Verified by blocking the
compositor and confirming every thumbnail still renders from their CDN with no broken images.

### Why embed needs a server, and why that is still within the constraints

Compositing in the *browser* is impossible here: it requires canvas readback of a
cross-origin image, which throws `SecurityError` without CORS headers on their bucket.
`npm run verify` proves that in real Chromium rather than asserting it.

But **same-origin policy is a browser rule about what page scripts may read.** It does not
apply to our server fetching a public URL. Our service GETs the thumbnail from the same
public URL every reader's browser already requests, draws the QR in, and serves the result.
No CORS, no credentials, no privileged access, and nothing written to their storage — so
"no read or write permissions on their data" still holds.

There is no way to make save-as include the QR *without* this. Save-as is a native browser
menu item: no DOM event fires, no script runs, and it writes the bytes of whatever the
`<img>` is displaying. So the QR must be in those bytes. That cannot be faked from the page
in any browser.

### What embed mode costs — measured

On the three 1200×800 demo thumbnails:

| | size | PSNR vs source |
|---|---|---|
| their original | ~48 KB | — |
| re-encoded at q=95, no QR | ~65 KB | 53–56 dB |
| **delivered composite** | **~100 KB** | 53–56 dB |

- **Resolution is unchanged.** Compositing happens at native size; nothing is rescaled.
  Verified: the composite's dimensions must equal the source's or the check fails.
- **One extra generation of JPEG loss**, at 53–56 dB — imperceptible, but real. Measured
  across sources encoded at q=75/82/88/94, so it is not an artifact of one image. A PNG
  source stays PNG and stays lossless.
- **The file roughly doubles.** About 35% is the re-encode; the rest is the QR itself — a
  grid of hard black-and-white edges is close to the most expensive thing a JPEG can carry.
- Tune with `TRACEIT_JPEG_QUALITY` (default 95; q=98 measurably buys nothing).

Zero-loss embedding is possible only if they give us the pre-compression master at publish
time. Worth asking about, not worth blocking on.

### Bonus: the QR in social share previews

Facebook, X and WhatsApp read `og:image` from the HTML and fetch that URL directly — they
never run the page's JavaScript, so an overlay can never reach them. But embed mode's URL is
a plain image endpoint, so pointing the tag at it puts the QR in share previews:

```html
<meta property="og:image"
      content="https://traceit.example.com/v1/framed/<?= $article->id ?>.jpg">
```

One line in their article template. Worth raising: a scanned code on a
WhatsApp-forwarded article is exactly the attribution a publisher cannot otherwise get.

The earlier browser-side composite prototype is still in the repo; see
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

The contract in [`server/traceit-client.js`](server/traceit-client.js) is **verified** —
read from the Trace-It source and exercised against the live API. See REQUIREMENT.md §9 for
the confirmed shapes and the four findings that changed the implementation.

```
POST /api/v1/qr                    { postId, title?, targetUrl? }
                                   201 created:true (charges 1 quota) / 200 created:false
GET  /api/v1/qr/by-post/{postId}   never charges quota; qr.png is empty here
```

Two traps worth knowing:

- **`qr.png` is only populated when `created` is true.** Updates and `by-post` reads return
  an empty string, because the QR encodes `shortUrl` and that has not changed. Always fall
  back to the public `qr.pngUrl`.
- **The branded QR is 1024×1362, not square** — the "Trace-it" label banner makes it taller
  than wide. The compositor reads the real aspect off the PNG.

> **`by-post` takes the secret key, so it is server-to-server only.** The browser must never
> hold it. That is why the page calls *our* service and our service calls Trace-It — see
> "the component fetches the QR" below.

> **Windows PHP gotcha:** a fresh `winget` PHP install ships no CA bundle, so every HTTPS
> call fails with `unable to get local issuer certificate`. Download
> [cacert.pem](https://curl.se/ca/cacert.pem) and set `curl.cainfo` and `openssl.cafile` to
> it in `php.ini`. Do **not** work around it by disabling certificate verification.

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
| `TRACEIT_ALLOWED_IMAGE_HOSTS` | `localhost:3001,…` | **Their S3/CDN hosts.** Embed mode fetches images server-side, so this allowlist is what stops it being an SSRF hole |
| `TRACEIT_JPEG_QUALITY` | `95` | Re-encode quality for embed mode |
| `PORT` / `S3_PORT` / `TRACEIT_PORT` | `3000` / `3001` / `3002` | Ports |

## Files

| Path | Purpose |
|---|---|
| `public/js/traceit-qr.js` | **The deliverable** — the overlay component |
| `server/traceit-service.js` | **Ours.** Webhook, QR endpoints, serves the script |
| `server/compositor.js` | Server-side compositing — what makes save-as work |
| `server/traceit-client.js` | **The only file that knows the Trace-It API shape** |
| `server/store.js` | articleId → code, held on our side; mint-once guarantee |
| `server/publisher-site.js` | **Theirs.** Mock CMS; contains the one integration call |
| `server/fake-s3.js` | **Theirs.** Image origin with no CORS, by design |
| `server/start-all.js` | Boots all three origins |
| `public/home.html` | Article list — ID from `data-article-id` |
| `public/article.html` | Single article — ID from the URL |
| `public/cms.html` | Newsroom: publish an article, watch the ID get captured |
| `php/publish-hook.php` | **PHP, theirs** — the whole publisher-side integration |
| `php/framed.php` | **PHP, ours** — compositing endpoint; port of `server/compositor.js` |
| `php/qr-proxy.php` | **PHP** — optional, serves the QR from their own domain (CSP) |
| `tools/verify-approaches.js` | Headless proof of every claim above (55 checks) |
| `REQUIREMENT.md` | The requirement, constraints and open questions |
| `README-composite.md` | The earlier bake-into-pixels prototype, and why it is parked |

> All PHP files are `php -l` clean on 8.4.24. `framed.php` is additionally executed and
> verified — see "Status of the PHP files" above for what is and is not proven per file.

All content is fictional. "Island Chronicle" is not a real publication.
