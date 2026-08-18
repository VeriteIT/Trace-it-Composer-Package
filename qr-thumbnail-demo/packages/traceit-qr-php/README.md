# veriteit/trace-it-qr

Put a Trace-It QR code on your article thumbnails, from the article ID alone.

```bash
composer require veriteit/trace-it-qr
```

Needs PHP 8.1+, `ext-curl`, and `ext-gd` **only** if you want the QR baked into the
image pixels. No framework. No other dependencies.

---

## Three steps, three methods

```php
use VeriteIt\TraceItQr\TraceIt;

$traceIt = new TraceIt([
    'apiKey'            => getenv('TRACEIT_API_KEY'),      // sk_live_…
    'baseUrl'           => 'https://acme.trace-it.io',     // your tenant subdomain
    'cacheDir'          => '/var/lib/trace-it',
    'allowedImageHosts' => ['cdn.example.lk'],             // only for step 3
]);

// 1. when an article is published
$traceIt->publish($post->id, $post->url, $post->thumbUrl);

// 2. when rendering it
$code = $traceIt->qr($post->id);
echo '<img src="' . htmlspecialchars($code->pngUrl) . '" alt="">';

// 3. optional — the photo with the QR IN the pixels, so Save-as carries it
$traceIt->framedImage($post->id)->send($post->id);
```

Everything is addressed by **your** post ID. Trace-It's own id is never something you
have to store.

---

## Wiring it into a custom PHP CMS

**Publish routine** — the whole change is one call:

```php
$postId = $cms->publish($draft);              // your existing code

$traceIt->publish(                            // the addition
    $postId,
    'https://www.example.lk/article/' . $postId,   // must be https, see below
    $draft->thumbUrl                               // only needed for step 3
);
```

`publish()` **never throws.** A QR code is not worth failing an editor's publish
action over, so it returns `null` and logs on failure. The code gets created on the
next publish, or on first render by `qr()`.

**Article template** — one attribute, so the frontend script can find the ID:

```php
<img src="<?= htmlspecialchars($article->thumbUrl) ?>"
     class="story-thumb"
     data-article-id="<?= htmlspecialchars($article->id) ?>">
```

If adding that attribute is awkward, skip it — the frontend script can read the ID
out of the URL instead.

**A composite endpoint**, if you want Save-as to include the code:

```php
// GET /qr-image.php?id=108-347979
$traceIt->framedImage($_GET['id'])->send($_GET['id']);
```

---

## `targetUrl` must be https

Trace-It rejects any other scheme outright:

```
400 invalid_target_url — targetUrl must use https (got "http:")
```

The field is optional, so this package **drops a non-https URL rather than failing the
whole call**: the QR still works and still tracks, because it encodes Trace-It's short
URL, not `targetUrl`. You lose only the "Original Source" button on the landing page,
and you get an `E_USER_NOTICE` saying so.

This mainly bites in local development on `http://localhost`. Production article URLs
are https and pass through normally.

## Post ID rules

Letters, digits, underscore, hyphen. Must start **and end** alphanumeric. Lowercased,
so IDs are case-insensitive. 48 characters max. **No dots, no slashes.**

Numeric CMS post IDs are ideal. If yours are slugs, pass the numeric ID instead —
`PostId` rejects rather than rewrites, because rewriting could map two different posts
onto one QR.

```php
use VeriteIt\TraceItQr\PostId;

PostId::isValid('108-347979');   // true
PostId::isValid('108.347979');   // false — dots are not allowed
```

## Quota

Only **creating** a code charges your monthly quota. Reads are free.

This package is built around that:

1. Cache hit → no network call at all.
2. Cache miss → a free `by-post` read first.
3. Nothing there → create, once.

Step 2 is the one people skip. Without it, a cache wipe or a redeploy re-creates every
article and burns quota for codes that already exist.

Concurrent first-views are serialised, so an article going live to 500 readers at once
produces **one** create, not 500.

`$code->created` tells you whether a call actually charged quota — do not infer it from
your own cache being cold.

## Two ways to show the code

| | Overlay | Embed (`framedImage()`) |
|---|---|---|
| How | `<img>` of `$code->pngUrl` positioned over the photo | QR composited into the photo's pixels |
| **Save image as… includes it** | No | **Yes** |
| Copy image / drag out | No | **Yes** |
| `og:image` can carry it | No | **Yes** |
| Image bytes served by | your CDN | you, from this endpoint |
| Needs `ext-gd` | No | Yes |
| Re-encodes the photo | No | Once |

Embed re-encodes a JPEG once, at quality 95 — measured at 53–56 dB PSNR, which is
imperceptible, though the file roughly doubles because a QR's hard edges are expensive
to encode. A PNG source stays PNG and stays lossless. Resolution never changes.

**Cache-bust when you change the badge.** `FramedImage::headers()` sends
`immutable`, which is correct for a file that never changes but means browsers will
not re-ask. Put a version in the URL you serve it from and bump it, or a redesign
stays invisible to everyone who has already loaded the page.

## Errors

```php
use VeriteIt\TraceItQr\{ApiError, InvalidPostId, Misconfigured, TransportError};

try {
    $code = $traceIt->qr($postId);
} catch (InvalidPostId|Misconfigured $e) {
    // A bug or a bad setting. Retrying will not help.
} catch (ApiError $e) {
    if ($e->isQuotaExceeded()) { /* … */ }
    if ($e->isTransient())     { /* back off; $e->retryAfter */ }
} catch (TransportError $e) {
    // Never got an answer. Render without the badge.
}
```

`qrOrNull()` is the same as `qr()` but returns `null` instead of throwing — usually what
you want in a template, where a missing badge is far better than a broken page.

## Configuration

| Key | Env | Default |
|---|---|---|
| `apiKey` | `TRACEIT_API_KEY` | — (required) |
| `baseUrl` | `TRACEIT_BASE` | — (required) |
| `folder` | `TRACEIT_FOLDER` | none |
| `cacheDir` | — | system temp |
| `store` | — | `FilesystemStore` |
| `allowedImageHosts` | `TRACEIT_ALLOWED_IMAGE_HOSTS` | none |
| `jpegQuality` | `TRACEIT_JPEG_QUALITY` | `95` |
| `layout` | — | see `Layout` |

**`allowedImageHosts` is a security control, not a convenience.** Compositing fetches
an image URL server-side. If that URL can come from a request parameter, an
unrestricted fetcher is an SSRF hole — point it at `169.254.169.254` or a localhost
admin port and it returns those bytes wrapped in a JPEG. Set it to the hostnames your
images actually come from.

**Swap the cache** by implementing `Cache\Store` — Redis, your CMS's own cache,
anything. `FilesystemStore` is the default so that install-and-go works.

## Server-side only

Every call here sends the secret key. **Never expose this to a browser.** Anyone who
can read the key can mint against your Trace-It account.

That is why the frontend asks *your* server for a code by article ID, and your server
talks to Trace-It.

## Smoke test

```bash
TRACEIT_API_KEY=sk_live_… \
TRACEIT_BASE=https://acme.trace-it.io \
TRACEIT_ALLOWED_IMAGE_HOSTS=cdn.example.lk \
php examples/smoke-test.php 108-347979 https://www.example.lk/article/108-347979 https://cdn.example.lk/photo.jpg
```

Exercises all three steps and writes the composited image to your temp directory. The
first run for a post ID creates one code; later runs reuse it and cost nothing.

> **If cURL reports `unable to get local issuer certificate`:** your PHP has no CA
> bundle. Download [cacert.pem](https://curl.se/ca/cacert.pem) and point `curl.cainfo`
> and `openssl.cafile` at it in `php.ini`. Do not work around it by disabling
> certificate verification.

## A Node package is a port, not a rewrite

The same contract is already implemented in JavaScript in this repo
(`server/traceit-client.js`, `server/compositor.js`, `server/store.js`) and exercised
by its test suite. An npm package would repackage working code, and the class names
here were chosen to match it so the two stay one documentation job rather than two.

The one thing worth deciding **before** writing it: whether the Node package targets
the same server-side role as this one, or a serverless/edge runtime. The compositor is
the only part that would need real work there, since `ext-gd` has no direct equivalent
and you would reach for `sharp` or WebAssembly instead.
