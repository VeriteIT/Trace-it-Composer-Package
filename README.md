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

**Check the server first, in one file, before installing anything.**
`examples/server-check.php` is self-contained — no Composer, no package, no `ext-gd` —
so it runs on the server that cannot yet install the package and tells you why:

```bash
php server-check.php <oneOfYourArticleIds> <aRealArticleImageUrl>
```

It reports the PHP version and which build applies, the extensions, whether outbound HTTPS
and your photo host actually work from that machine, and whether your article IDs are usable.
It ends by saying whether that server can serve composited images or only register codes.
Written in deliberately old PHP so an outdated interpreter gets a version report rather than
a parse error. Send us the output.

Verite IT will give you three things. Ask if you do not have them:

| | Example | Where it goes |
|---|---|---|
| API key | `sk_live_…` | your server config — **never in a page** |
| Your Trace-It base URL | `https://acme.trace-it.io` | your server config |
| Script URL | `https://qr.trace-it.io/js/traceit-qr.js` | one `<script>` tag |

Only the first two are issued per publisher. The script URL is the same for everyone and is
already live, so you can paste it as written.

> **`example.lk` in this document is a placeholder for your own site.** Every
> `www.example.lk` and `cdn.example.lk` needs replacing with your real hostnames. The one
> that matters most is `allowedImageHosts` — it is a security control, and a placeholder
> left in it authorises fetching from a domain that is not yours.

And tell them, in return:

- **The hostname(s) your article images are served from** — e.g. `cdn.example.lk`.
  Required: the service refuses to fetch images from anywhere else.
- **The format of your post IDs.** See step 2. A numeric ID is ideal.

> **What this integration is for.** The goal is that a reader who does *Save image as…* on
> one of your thumbnails gets a file with the Trace-It code **in it**. That only works if
> the code is in the pixels, so you will need **`ext-gd`** and one small endpoint of your
> own (Step 4). All five steps are one integration; there is no partial mode that puts a
> code on screen without putting it in the file.

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

**PHP 8.1+, `ext-curl` and `ext-json` are required. `ext-gd` is listed as a suggestion,
but Step 4 does not work without it** — it is what composites the code into the photo,
which is the whole point.

It is a suggestion rather than a requirement for one reason: only `framedImage()` touches
GD, so a server that just registers codes with `publish()` never needs it, and making
Composer refuse there helps nobody. It also means a server *missing* GD can still install
the package and run `preflight.php`, which then tells you GD is missing — whereas a hard
requirement blocked the very tool that diagnoses it.

So on whichever server hosts the Step 4 endpoint, `ext-gd` is not optional. On most systems
it is one package away (`php8.1-gd` or similar) and a restart. If it is genuinely a problem
on your hosting, tell us before you start — there are other ways to arrange this.

Configure it once, wherever you wire up services:

```php
use VeriteIt\TraceItQr\TraceIt;

$traceIt = new TraceIt([
    'apiKey'            => getenv('TRACEIT_API_KEY'),   // sk_live_…
    'baseUrl'           => getenv('TRACEIT_BASE'),      // https://acme.trace-it.io
    'cacheDir'          => '/var/lib/trace-it',         // must be writable
    'allowedImageHosts' => ['cdn.example.lk'],          // required by Step 4
    'logger'            => [$yourLogger, 'log'],        // optional, see below
]);
```

> **The key is server-side only.** If it reaches a page, anyone can read it out and create
> codes against your account.

### Send our warnings somewhere you will read them

Nothing in this package throws for a *degradation*. `publish()` returns `null` rather than
failing an editor's action, a non-https article URL is dropped rather than rejected, and a
lock that cannot be taken proceeds without one. Each is the right call on its own — together
they mean a feature can stop working with no exception raised anywhere.

By default those messages go to `trigger_error`, which on a production `php.ini` reaches
only the PHP error log. Pass `logger` and they are yours. The signature is PSR-3's, so a
`LoggerInterface` needs no adapter:

```php
'logger' => [$psr3Logger, 'log'],   // fn (string $level, string $message)
```

Levels are `warning` and `notice`, and messages arrive prefixed `trace-it: `. The one worth
alerting on is `targetUrl … is not https` — see [Things that will bite you](#things-that-will-bite-you).

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

You do **not** need to send the photo here. Step 4 runs inside your CMS and is handed the
post ID, so it looks the photo up the way every other page on your site does — which cannot
go stale the way a URL recorded at publish time can.

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

One attribute, so the script knows which article each image belongs to:

```php
<img src="<?= htmlspecialchars($article->thumbUrl) ?>"
     class="story-thumb"
     data-article-id="<?= htmlspecialchars($article->id) ?>">
```

Then one script tag, once, in your layout:

```html
<script src="https://qr.trace-it.io/js/traceit-qr.js"
        data-selector="img.story-thumb"
        data-service="https://www.example.lk/traceit"></script>
```

`data-service` points at **your** composite endpoint from Step 4, and it is required.
Without it the script has nowhere to fetch a coded image from, logs one console error and
leaves every photo exactly as it found it.

That is the entire frontend integration.

### What it does to your page

It changes **one attribute** — `src`, to an image with identical pixel dimensions. It adds
no elements, injects no CSS and wraps nothing, so your layout and your stylesheets are
untouched.

The swap only happens once the coded image has actually loaded, so if a code is missing or
the endpoint is slow or unreachable, your original photo simply stays. Nothing is ever
half-applied.

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

### Why the code goes into the pixels, and not over them

The obvious approach — draw the QR over the photo as a separate element — puts a code on
the screen but not in the file. *Save image as…* is a browser menu item: no DOM event
fires for it and no script runs during it. It writes the bytes of the resource the `<img>`
is displaying. So the code has to **be** in those bytes; it cannot be faked from the page,
in any browser.

Swapping `src` for a composited image is the only thing that satisfies that, and *Copy
image*, drag-to-desktop, printing and `og:image` all follow from the same fact.

This is why there is one mode and not two. An overlay mode existed early on and was
removed: it could never produce a coded file, and it cost a wrapper element around every
thumbnail to position the badge.

### Every option

On the `<script>` tag:

| Attribute | Default | What it does |
|---|---|---|
| `data-selector` | `img[data-article-id]` | Which images to touch. Nothing outside it is ever modified. |
| `data-service` | *(unset)* | **Required.** Your composite endpoint from Step 4. Unset, the script does nothing. |
| `data-version` | `1` | Cache-buster. Bump it when the badge design changes. |
| `data-id-from-path` | *(unset)* | Regex to take the article ID from the URL instead of an attribute. |
| `data-auto` | *(on)* | `off` stops it running automatically; call `window.TraceItQR.embedAll()` yourself. |

Badge position and size are decided when the image is composited, so they are server-side
settings — see `Layout` in [PACKAGE-REFERENCE.md](PACKAGE-REFERENCE.md), not attributes here.

On each `<img>`:

| Attribute | What it does |
|---|---|
| `data-article-id` | The article this image belongs to. |
| `data-traceit="off"` | Leave this one image alone. |

`window.TraceItQR` also exposes `embed(img)` for a single image and `config` for what the
script actually resolved, which is the quickest way to confirm it is pointed where you think:

```js
TraceItQR.config.service   // your endpoint, or "" if data-service is missing
```

Call `TraceItQR.embedAll()` again after you inject thumbnails at runtime — infinite scroll,
a lightbox, a client-rendered section. It never double-applies to an image it has done.

Badges are applied lazily, as thumbnails approach the viewport, so a homepage carrying fifty
of them does not fetch fifty codes a reader may never scroll to.

### On a single-article template you can skip the attribute

If adding `data-article-id` is awkward, the script can take the ID from the URL instead:

```html
<script src="https://qr.trace-it.io/js/traceit-qr.js"
        data-selector="img.story-thumb"
        data-id-from-path="/article/([A-Za-z0-9._-]+)"></script>
```

---

## Step 4 — serve the composite (this is what makes Save-as work)

**Do not skip this one.** Steps 1–3 put a code on the page; this step puts it in the
*file*. Without it, a reader who saves or shares your thumbnail gets the photo with no
code — which is the thing this integration exists to prevent.

It is also what gives you:

- the code in **social share previews** (Step 5),
- image traffic kept on **your own CDN**, and
- compatibility with a **Content-Security-Policy** that forbids third-party `img-src`.

Compositing happens on your server because that is where the photo already is. Trace-It
never receives your image URLs, so it cannot burn a code into a photo it has never seen —
and keeping it this way means you are not handing us your image bandwidth or a copy of
every thumbnail you publish.

The whole file, as `/qr-image.php`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';   // adjust to your autoloader

use VeriteIt\TraceItQr\TraceItException;

// $traceIt is the instance you configured in Step 0.

$postId  = (string) ($_GET['id'] ?? '');
$article = $cms->findByPostId($postId);     // your existing lookup
$version = (string) ($_GET['v'] ?? '1');

try {
    // Sends Content-Type, Content-Length, Cache-Control and a filename for the
    // save dialog, then the bytes.
    $traceIt->framedImage($postId, $article->thumbUrl, $version)->send($postId);
} catch (TraceItException $e) {
    error_log('[traceit] composite failed for ' . $postId . ': ' . $e->getMessage());
    http_response_code(404);
}
```

**Do not drop the `try`/`catch`.** `framedImage()` throws — no code minted yet, an image host
not on the allowlist, a photo that cannot be fetched. Uncaught, that is a 500 with an HTML
error body served where an image was expected, and on a server with `display_errors` on it
puts a stack trace on the public internet.

Caught, it is a clean 404, which is exactly what the page script expects: it leaves the
publisher's original photo on screen and the reader sees nothing wrong. **404 rather than
500** because there is no composite for this request and nothing a retry would fix.

**Pass the photo URL from your own data.** This endpoint runs inside your CMS and already
has the post ID, so looking the article up is a local query you are effectively already
making. That is better than having us remember the URL for you, for a reason that bites in
practice: if an editor replaces an article's photo, a remembered URL keeps pointing at the
old file until the article is published again, and composites keep carrying the old picture.
A lookup is always current.

Then route `/traceit/v1/framed/{id}.jpg` onto it — that path is what `data-service` in
Step 3 points at, so without this rule the script gets a 404 and no codes appear anywhere:

```apache
RewriteRule ^traceit/v1/framed/([A-Za-z0-9_-]+)\.jpg$ /qr-image.php?id=$1 [QSA,L]
```

```nginx
location ~ ^/traceit/v1/framed/([A-Za-z0-9_-]+)\.jpg$ {
    try_files /dev/null /qr-image.php?id=$1&$args;
}
```

Needs `ext-gd`, and `allowedImageHosts` set in your config to the hostnames your photos come
from — without it the fetcher would take any URL a caller supplies, which is an SSRF hole.

### On a framework, build the response rather than calling `send()`

`send()` calls `header()` and echoes the body itself. That is right for the plain endpoint
above and wrong inside CodeIgniter, Laravel or Symfony, all of which buffer the response and
send it themselves at the end of the request. Echoing past them puts the image bytes ahead
of the framework's own output, and the headers you set by hand are then either overwritten
by the framework's or duplicated alongside them. The usual symptom is a broken image whose
`Content-Type` is `text/html`, which looks like a compositing failure and is not one.

Use the two accessors instead. `$framed->bytes` is the encoded image, and
`$framed->headers($postId)` returns exactly what `send()` would have written, as a plain
`name => value` array — which is already the shape Laravel and Symfony want.

```php
$framed = $traceIt->framedImage($postId, $article->thumbUrl, $version);
```

**CodeIgniter 3**

```php
foreach ($framed->headers($postId) as $name => $value) {
    $this->output->set_header($name . ': ' . $value);
}

$this->output->set_output($framed->bytes);
```

`snippets/3-composite-endpoint-codeigniter3.php` is the whole controller, with the
route and the `composer_autoload` line — drop that in rather than assembling it from
the fragment above.

**Laravel**

```php
return response($framed->bytes, 200, $framed->headers($postId));
```

**Symfony**

```php
return new Response($framed->bytes, 200, $framed->headers($postId));
```

Two things carry over from the plain endpoint. **Keep the `try`/`catch`** — but return the
framework's own 404 from the catch block (`throw PageNotFoundException::forPageNotFound()`,
`abort(404)`, `throw new NotFoundHttpException()`) rather than `http_response_code()`, which
is bypassed for the same reason `send()` is. And **skip the rewrite rule** — declare
`/traceit/v1/framed/{id}.jpg` as a route in the framework, pointing at this controller.
It is the path that matters, not how the request reaches you.

### It does real work per request, so put a cache in front

This package deliberately keeps **no copy of your photos**. Each request fetches the source
image, composites in memory, sends the bytes and discards them — nothing is written to disk
but the small code record and our QR PNG.

That means a cache miss costs one outbound fetch plus one GD operation. The response is sent
`immutable` with a one-year max-age, so a CDN or the reader's browser absorbs nearly all of
it in normal traffic — but a cold cache, a crawler sweep or a version bump does real work.
If your traffic warrants it, put this endpoint behind your CDN, or have it write the bytes to
disk and serve later hits from there. We leave that to you because it depends on your
infrastructure, not ours.

### If the public server cannot composite, generate ahead of time instead

Step 4 needs `ext-gd` on whichever machine answers reader traffic. Sometimes that is
the one machine you cannot change — managed hosting, a static deployment, or a CMS that
is admin-only so the endpoint has to live somewhere else entirely.

`examples/prewarm.php` moves the work to publish time and to a machine you choose,
normally the CMS, which already has the photo, the article data and usually `ext-gd`:

```bash
php examples/prewarm.php /var/www/example.lk/traceit 108347979 "https://cdn.example.lk/a.jpg"
```

That writes `/var/www/example.lk/traceit/v1/framed/108347979.jpg` — exactly the path the
page script asks for. Point `data-service` at the directory and **the public side needs no
PHP, no `ext-gd` and no endpoint at all**, only static file serving. Feed it
`postId<TAB>imageUrl` on stdin with `--stdin` to backfill an archive.

Cache busting still works: the script requests `?v=`, and a query string is part of the
cache key for browsers and CDNs, so bumping the version refetches even though the filename
never changes.

**The trade-off, plainly:** a pre-generated file is a snapshot. Replace an article's photo
and the composite keeps the old picture until this runs again for that article — the
on-demand endpoint cannot go stale that way. Call it from your publish hook rather than a
nightly cron and the window stays small.

Getting the files to where the public site serves them is yours, because it depends on your
hosting: write straight into the docroot if they share a filesystem, rsync after each run,
or upload to the object storage your images already come from.

> If your composite endpoint genuinely cannot reach your CMS — a separate host, a static
> deployment — omit the second argument and pass the URL to `publish()` instead, as its
> fourth argument. `framedImage()` falls back to that remembered value. Prefer the lookup
> where you can.

---

## Step 5 — optional: put the code in social share previews

**Requires Step 4.** Facebook, X and WhatsApp read `og:image` and never run page
JavaScript, so a crawler never sees the `src` swap that Step 3 performs. The tag has to
point at a composite that already exists as a file, which means your own endpoint:

```php
<meta property="og:image"
      content="https://www.example.lk/traceit/v1/framed/<?= htmlspecialchars($article->id) ?>.jpg?v=1">
```

Nothing extra is needed for this to work. A crawler hitting that URL reaches the same
endpoint from Step 4, which looks the article up and composites it — a crawler's first
request is no different from a reader's, because neither has run your page.

If you took the fallback route in Step 4 and left the lookup out, then this is the case that
needs `publish()` to have been given the image URL, since there is nothing else for the
endpoint to work from. Otherwise the tag 404s and the crawler falls back to your plain photo.

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

**A replaced photo stays invisible until you bump `v`.** This is the one that surprises
people. Composites are served `immutable` with a one-year max-age, which is right for a file
that never changes — but it means a browser holding one will *never* ask again. So if an
editor swaps an article's photo after publication, readers keep seeing the old picture with
the code on it. Same for a badge redesign.

The `v` parameter is the entire fix: a new value is a new URL, so browsers and caches treat
it as a new file. If photo swaps are routine for you, put something per-article in there —
a photo ID, or a hash of the image URL — rather than one global number, or a single swap
means bumping the version for every reader of every article.

**Badge position and size are server-side.** They are decided when the image is composited,
so they live in your PHP config, not on the script tag — `corner`, `scale`, `padding`,
`minPx`/`maxPx`. See `Layout` in [PACKAGE-REFERENCE.md](PACKAGE-REFERENCE.md). Changing any
of them needs a `v` bump too, for the reason above.

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
exact failure, which is usually enough to answer the question straight away. It never prints
your API key, only its prefix, so the output is safe to paste into an email.

---

## Licence

Proprietary — see [LICENSE](LICENSE). Licensed for use by organisations with a current
Trace-It agreement: install it on as many of your own servers as you like and modify it
freely, but it may not be redistributed. Your API key is confidential and non-transferable.
