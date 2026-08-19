# Trace-It QR on article thumbnails — integration guide

For the publisher's development team. Everything in this document is on **your** side;
the QR service itself is hosted and run by Verite IT.

**What you get:** every article thumbnail carries a scannable Trace-It code, and a reader
who saves, copies or prints that photo gets the code with it. Scans are attributed back to
the article in the Trace-It dashboard.

**What it costs you:** four small changes, none of them structural. No CDN
reconfiguration, no bucket permission changes, no work in your image pipeline.

---

## Before you start

Verite IT will give you three things. Ask if you do not have them:

| | Example | Where it goes |
|---|---|---|
| API key | `sk_live_…` | your server config — **never in a page** |
| Your Trace-It base URL | `https://acme.trace-it.io` | your server config |
| Script URL | Verite IT will give you the real host | one `<script>` tag |

> `YOUR-TRACEIT-HOST` throughout this document is a placeholder. Replace it with the
> host Verite IT gives you — it is not a real address and will not resolve.

And tell them, in return:

- **The hostname(s) your article images are served from** — e.g. `cdn.example.lk`.
  Required: the service refuses to fetch images from anywhere else.
- **The format of your post IDs.** See step 2. A numeric ID is ideal.

---

## Step 0 — install

This package is not on Packagist, so point Composer at the repository once:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/VeriteIT/Trace-it-Composer-Package" }
  ]
}
```

```bash
composer require veriteit/trace-it-qr:^1.0
```

If your build has no network access to GitHub, copy this directory into your project and use
a path repository instead — same result, no fetch:

```json
{
  "repositories": [
    { "type": "path", "url": "vendor-src/trace-it-qr" }
  ]
}
```

PHP 8.1+ and `ext-curl`. You also need `ext-gd` only if you choose to host the composite
endpoint yourself (step 4) rather than letting us serve it.

Configure it once, wherever you wire up services:

```php
use VeriteIt\TraceItQr\TraceIt;

$traceIt = new TraceIt([
    'apiKey'   => getenv('TRACEIT_API_KEY'),   // sk_live_…
    'baseUrl'  => getenv('TRACEIT_BASE'),      // https://acme.trace-it.io
    'cacheDir' => '/var/lib/trace-it',         // must be writable
]);
```

> **The key is server-side only.** If it reaches a page, anyone can read it out and create
> codes against your account.

---

## Step 1 — tell us when an article is published

One call at the end of your existing publish routine.

```php
$postId = $cms->publish($draft);                  // your existing code

$traceIt->publish(
    $postId,
    'https://www.example.lk/article/' . $postId,  // must be https
    $draft->publishedAt->format(DATE_ATOM)        // optional, see below
);
```

That is the whole payload: the post ID, the live article URL, and optionally when the
article was published. No article body, no images, no credentials.

### Send the publication date if you have it

Trace-It shows it as **Date Published** on the verification page a scan lands on. Leave it
out and that falls back to when the code was created — correct if you are publishing live,
wrong if you ever backfill an archive, which would then claim every old story was published
on the day you imported it.

Any ISO 8601 form works: `2026-02-14` or `2026-02-14T09:30:00Z`.

A date it cannot read is **rejected** (`400 invalid_published_at`) rather than quietly
replaced, because it is rendered as a factual claim. A mistyped year like `20226` is caught
by the same check.

**It never throws.** If our service is unreachable it returns `null` and logs — a QR code
is not worth failing an editor's publish over. The code gets created on the next publish,
or on first page view.

**Call it on every publish, re-publishes included.** It is idempotent: only the first call
for a given post ID creates anything.

---

## Step 2 — check your post IDs are usable

Letters, digits, underscore and hyphen. Must start **and end** with a letter or digit.
Case-insensitive. 48 characters maximum. **No dots, no slashes.**

```php
use VeriteIt\TraceItQr\PostId;

PostId::isValid('108347979');                  // true
PostId::isValid('108-347979');                 // true
PostId::isValid('108.347979');                 // false — dots
PostId::isValid('news/politics/budget-2026');  // false — slashes
```

If your IDs are slugs, pass the numeric post ID instead. IDs are rejected rather than
rewritten, because rewriting could quietly map two different articles onto one QR code.

---

## Step 3 — mark the thumbnails in your template

Two attributes: which article the image belongs to, and where its code lives.

```php
<img src="<?= htmlspecialchars($article->thumbUrl) ?>"
     class="story-thumb"
     data-article-id="<?= htmlspecialchars($article->id) ?>"
     data-traceit-qr="<?= htmlspecialchars($traceIt->qrPngUrl($article->id) ?? '') ?>">
```

`data-traceit-qr` is the QR's public PNG URL. It is public by design and carries no
credentials, which is the point: your API key is a secret and must never reach a browser,
so the lookup happens in PHP where the key already lives. The script then makes no API
call at all — the only request is the image itself.

Use `qrPngUrl()` here, not `qr()`. `qr()` throws, and a template is the last place you want
an exception: `qrPngUrl()` returns `null` on any failure, so a Trace-It outage costs you a
badge rather than the article page. It reads from the local cache after the first call, so
this is not a request per render. When it returns `null` the attribute renders empty and
that thumbnail simply keeps its plain photo.

> Using composite mode (Step 4) instead? Then `data-traceit-qr` is not needed — drop it and
> keep only `data-article-id`.

Then one script tag, once, in your layout:

```html
<script src="https://YOUR-TRACEIT-HOST/js/traceit-qr.js"
        data-selector="img.story-thumb"></script>
```

That is the entire frontend integration.

### Only the images you want

The script touches **nothing** outside `data-selector`. Logos, adverts, author portraits
and inline body images are unaffected, with no extra work from you.

| You want | Do this |
|---|---|
| Only tagged thumbnails | leave the default, `img[data-article-id]` |
| Only one template's images | `data-selector="img.story-thumb"` |
| Exclude one image that otherwise matches | `data-traceit="off"` on that `<img>` |

The last one is for a sponsored photo, a wire-service image you may not alter, or a
graphic where a badge would cover something that matters.

### Two modes, and what each does to your page

Which one you get depends on a single attribute, `data-service`.

**Overlay — the default.** Nothing for you to host and no `ext-gd`. The script draws the
hosted QR PNG over your photo in the browser. To position a badge it has to add one element,
so it inserts the code as an absolutely-positioned `<img>` and wraps your thumbnail in a
`position:relative` `<span>` — unless your theme already puts the image alone inside a
positioned element, such as a `<figure>` or a fixed-ratio box, in which case it uses that and
leaves the DOM as it found it. Everything it inserts is styled inline; no stylesheet is
injected, and your own classes, sizing and position are untouched.

**Composite — when you set `data-service`.** Changes **one attribute**, `src`, to an image
with identical pixel dimensions. Adds no elements, injects no CSS and wraps nothing. It is
also the only mode where the code survives a right-click → *Save image*, because the pixels
are burned in. It requires you to host the endpoint in Step 4.

| | Overlay | Composite |
|---|---|---|
| Anything for you to host | no | yes, Step 4 |
| Needs `ext-gd` | no | yes |
| Touches your DOM | one wrapper + one `<img>` | one attribute |
| Code survives Save-as | no | yes |
| Code in social previews | no | yes, Step 5 |

If your layout is pixel-sensitive enough that a wrapper is a problem, that is the reason to
choose composite mode.

Either way, if a code is missing or our service is slow or unreachable, your original photo
simply stays. Nothing is ever half-applied.

### Every option

On the `<script>` tag:

| Attribute | Default | What it does |
|---|---|---|
| `data-selector` | `img[data-article-id]` | Which images to touch. Nothing outside it is ever modified. |
| `data-service` | *(unset)* | Set it to switch to composite mode, pointing at your own origin. |
| `data-corner` | `bottom-right` | Which corner the badge sits in. Also `bottom-left`, `top-right`, `top-left`. Overlay only. |
| `data-badge-size` | `0.22` | Badge width as a fraction of the image, clamped to `0.08`–`0.5`. Overlay only. |
| `data-version` | `1` | Cache-buster for composite mode. Bump it when the badge design changes. |
| `data-id-from-path` | *(unset)* | Regex to take the article ID from the URL instead of an attribute. |
| `data-auto` | *(on)* | `off` stops it running automatically; call `window.TraceItQR.embedAll()` yourself. |

On each `<img>`:

| Attribute | What it does |
|---|---|
| `data-article-id` | The article this image belongs to. |
| `data-traceit-qr` | The code's public PNG URL. Overlay mode only. |
| `data-traceit="off"` | Leave this one image alone. |

`window.TraceItQR` also exposes `embed(img)` for a single image and `config` for what the
script actually resolved, which is the quickest way to confirm the mode you think you are in:

```js
TraceItQR.config.mode   // "overlay" or "composite"
```

Call `TraceItQR.embedAll()` again after you inject thumbnails at runtime — infinite scroll,
a lightbox, a client-rendered section. It never double-applies to an image it has done.

Badges are applied lazily, as thumbnails approach the viewport, so a homepage carrying fifty
of them does not fetch fifty codes a reader may never scroll to.

### On a single-article template you can skip the attribute

If adding `data-article-id` is awkward, the script can take the ID from the URL instead:

```html
<script src="https://YOUR-TRACEIT-HOST/js/traceit-qr.js"
        data-selector="img.story-thumb"
        data-id-from-path="/article/([A-Za-z0-9._-]+)"></script>
```

---

## Step 4 — optional: serve the composite yourself

Skip this unless you need one of the things only compositing gives you:

- the code baked into the file a reader gets from **Save image**,
- the code in **social share previews** (Step 5),
- image traffic kept on **your own CDN**, or
- a **Content-Security-Policy** that forbids third-party `img-src`.

Compositing happens on your server because that is where the photo already is. Trace-It
never receives your image URLs, so it cannot burn a code into a photo it has never seen —
and keeping it this way means you are not handing us your image bandwidth or a copy of
every thumbnail you publish.

```php
// GET /qr-image.php?id=108-347979&v=1
$traceIt->framedImage($_GET['id'], null, $_GET['v'] ?? '1')->send($_GET['id']);
```

Then point the script at your own origin:

```html
<script src="https://YOUR-TRACEIT-HOST/js/traceit-qr.js"
        data-selector="img.story-thumb"
        data-service="https://www.example.lk/traceit"></script>
```

…with `/traceit/v1/framed/{id}.jpg` rewritten to that script. Needs `ext-gd`, and
`allowedImageHosts` set in your config to the hostnames your photos come from.

---

## Step 5 — optional: put the code in social share previews

**Requires Step 4.** Facebook, X and WhatsApp read `og:image` and never run page
JavaScript, so a crawler never sees an overlay. The tag has to point at a composite that
already exists as a file, which means your own endpoint:

```php
<meta property="og:image"
      content="https://www.example.lk/traceit/v1/framed/<?= htmlspecialchars($article->id) ?>.jpg?v=1">
```

For this to resolve, your publish hook must have recorded which photo belongs to the
article — pass the image URL as the fourth argument to `publish()`:

```php
$traceIt->publish($article->id, $article->url, $article->publishedAt, $article->thumbUrl);
```

`framedImage()` then reads that URL back from its local cache, so the crawler's very first
request composites correctly even though it has never run your page. Without it there is
nothing to composite from and the tag 404s, so the crawler falls back to your plain photo.

---

## Verify your setup before going live

```bash
TRACEIT_API_KEY=sk_live_… \
TRACEIT_BASE=https://acme.trace-it.io \
php vendor/veriteit/trace-it-qr/examples/preflight.php 108-347979
```

It checks your PHP version and extensions, your TLS trust store, the key, the base URL,
your post ID format, cache writability and a live round trip — and names exactly what to
fix for anything that fails.

---

## Things that will bite you

**`targetUrl` must be https.** Trace-It rejects `http` outright. A non-https URL is
dropped rather than failing the call, so you still get a working code — you only lose the
"Original Source" button on the landing page. This normally shows up only in local
development.

**PHP with no CA bundle.** On a fresh Windows PHP, and on some minimal Linux images, every
HTTPS call fails with `unable to get local issuer certificate`. Point `curl.cainfo` and
`openssl.cafile` at a [cacert.pem](https://curl.se/ca/cacert.pem) in `php.ini`. Do **not**
work around it by disabling certificate verification.

**The cache directory must be writable, and should persist.** It holds the post ID → code
mapping. If it is wiped nothing breaks and no quota is spent — codes are looked up from
Trace-It again rather than re-created — but every article pays one extra round trip until
it warms up.

**Changing the badge needs a cache bust.** Composites are served `immutable`, so browsers
do not re-ask. When we change the badge design we bump a version in the URL; if you host
the endpoint yourself (step 4), bump the `v` parameter.

---

## What happens to image quality

Nothing you will see. The composite is built at the photo's **native resolution** — nothing
is scaled, and the saved file has exactly the source's dimensions. A JPEG is re-encoded
once at quality 95, measured at 53–56 dB PSNR, which is imperceptible. A PNG stays PNG and
stays lossless.

The delivered file roughly doubles in size, because a QR's hard black-and-white edges are
expensive for JPEG to encode. If that matters on listing pages, tag only the article-page
thumbnail and leave the listings alone.

---

## Reference

[PACKAGE-REFERENCE.md](PACKAGE-REFERENCE.md) has the method signatures, every error code,
the configuration table and the badge layout options. This guide is the path through;
that is the detail.

---

## Support

Send Verite IT the output of `preflight.php`. It reports versions, configuration and the
exact failure, which is usually enough to answer the question straight away.
