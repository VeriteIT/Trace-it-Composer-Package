# `veriteit/trace-it-qr` — API reference

Companion to [README.md](README.md), which is the integration guide. Start there; this
file is for when you need the details of a method, an error, or a setting.

---

## The three methods

```php
use VeriteIt\TraceItQr\TraceIt;

$traceIt = new TraceIt([ /* see Configuration */ ]);
```

### `publish(string|int $postId, ?string $articleUrl, ?string $publishedAt, ?string $imageUrl): ?Code`

Registers a post with Trace-It. Call on every publish, re-publishes included — it is
idempotent, and only the first call per post ID creates anything or costs quota.

**Never throws.** Returns `null` and logs via `trigger_error` on failure, because a QR code
is not worth failing an editor's publish over. The code gets created on the next publish, or
on first render by `qr()`.

- `$articleUrl` must be `https`. A non-https URL is dropped, not fatal — you keep a working
  code and lose only the "Original Source" button on its landing page.
- `$publishedAt` is the **article's** publication date, ISO 8601. Omit it and the landing
  page falls back to the code's creation date, which is only the same thing when you publish
  live. An unreadable date is rejected (`invalid_published_at`), not silently replaced.
- `$imageUrl` is **optional, and usually unnecessary**. Prefer passing the photo URL to
  `framedImage()` directly: that endpoint runs in your CMS and already has the post ID, so
  it is a local lookup, and it cannot go stale. This argument records the URL in your local
  cache as a fallback for a composite endpoint that cannot reach your CMS, and it is only
  refreshed when `publish()` is next called with a different value — so replacing a photo
  without re-publishing leaves it pointing at the old file. Never sent to Trace-It either
  way.

### `qr(string|int $postId, …): Code`

The code for a post, for rendering. Cache hit on the common path, so no network call at all.
On a miss it asks Trace-It — a free read — and only creates if nothing exists.

Throws when there is no cached code **and** Trace-It cannot be reached. In a template you
usually want **`qrOrNull()`**, which returns `null` instead: a missing badge beats a broken
page.

`qrPngUrl($postId)` is shorthand for the hosted PNG URL, or `null`. The page script does not
need it — the code reaches readers through the composited image — but it is there when you
want the bare QR for a dashboard, an email or a print layout.

### `framedImage(string|int $postId, ?string $imageUrl, string $version, ?Layout $layout): FramedImage`

The photo with the QR composited into its pixels — the only thing that makes a native
"Save image as…" carry the code. Needs `ext-gd`, and the image host must be in
`allowedImageHosts`.

`FramedImage::send($postId)` writes the correct headers and body. `->bytes`, `->mime`,
`->width`, `->height` and `->hasBadge` are available if you would rather do it yourself.

Bump `$version` whenever the badge design changes: the response is `immutable`, so browsers
will not re-ask, and a redesign would otherwise be invisible to anyone who already loaded the
page.

---

## `Code`

| Property | |
|---|---|
| `id` | Trace-It's own id, `{tenantPrefix}-{postId}`. You never need to store it. |
| `postId` | Your post ID. Everything is addressed by this. |
| `shortUrl` | What the QR encodes. A scan hits this, which redirects and attributes the visit. |
| `targetUrl` | The "Original Source" button, or `null`. |
| `publishedAt` | The article's publication date, or `null`. |
| `pngUrl` | Durable, **public**, hosted 1024px branded PNG. Safe in an `<img src>`. |
| `created` | **True only when that call minted a code and charged quota.** |

`created` describes a single call, not the code — a value read from cache reports `false`,
which is correct, because reading a cache costs nothing. Do not infer quota use from your own
cache being cold.

`pngBytes()` returns the raw image, fetching it if needed. Use it rather than reading the API
field directly: Trace-It populates the inline PNG **only** when `created` is true, so code
that reads it works on first publish and silently breaks on the second.

---

## Errors

```php
use VeriteIt\TraceItQr\{ApiError, InvalidPostId, Misconfigured, TransportError};

try {
    $code = $traceIt->qr($postId);
} catch (InvalidPostId|Misconfigured $e) {
    // A bug or a bad setting. Retrying will not help.
} catch (ApiError $e) {
    if ($e->isQuotaExceeded()) { /* ask Verite IT to raise the monthly cap */ }
    if ($e->isTransient())     { /* back off; $e->retryAfter may be set */ }
} catch (TransportError $e) {
    // Never got an answer. Render without the badge.
}
```

All four extend `TraceItException`, so one `catch` covers everything.

`ApiError::$errorCode` carries Trace-It's machine-readable code, so you can branch without
matching on message text:

| Code | Means |
|---|---|
| `unauthorized` | Key wrong, revoked, or from another environment. |
| `invalid_post_id` | Fails the post ID rules. Validate with `PostId::isValid()` first. |
| `invalid_target_url` | Not https, or not an absolute URL. |
| `invalid_published_at` | Not a readable ISO 8601 date, or the year is implausible. |
| `quota_exceeded` | Monthly creation cap reached. |
| `rate_limited` | Too many requests this minute. `retryAfter` is set. |
| `post_id_conflict` | Two of your post IDs differ only by case. |

---

## Quota

Only **creating** a code charges your monthly quota. Reads are free. The package is built
around that:

1. Cache hit → no network call at all.
2. Cache miss → a free `by-post` read first.
3. Nothing there → create, once.

Step 2 is the one people skip. Without it, a cache wipe or a redeploy re-creates every
article and spends quota on codes that already exist.

Concurrent first-views are serialised, so an article going live to 500 readers at once
produces **one** create, not 500.

---

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
| `timeout` | — | `15` seconds |
| `logger` | — | `trigger_error` |

### `logger` is how you find out about silent degradation

Nothing in this package throws for a *degradation*. `publish()` returns `null` rather
than failing an editor's action, `qrOrNull()` returns `null` rather than breaking a
template, a non-https `targetUrl` is dropped rather than rejected, and a lock that
cannot be taken proceeds unlocked. Each is the right call on its own. Together they
mean a feature can stop working with no exception anywhere.

The default sink is `trigger_error`, which is dependency-free and honours
`error_reporting` — but a production `php.ini` has `display_errors` off, so these
messages only reach the PHP error log, which on a busy site nobody reads.

The signature is PSR-3's, so a logger can be handed over with no adapter:

```php
$traceIt = new TraceIt([
    'logger' => [$psr3Logger, 'log'],   // fn (string $level, string $message)
]);
```

Levels are `warning` and `notice`. Messages arrive prefixed `trace-it: ` so they stay
greppable. A logger that throws is caught and reported through `trigger_error` — a
reporting channel must never take down the publish it was reporting on.

**Worth alerting on:** `targetUrl … is not https`. It means those articles' verification
pages have no *Original Source* button, and will not until the CMS passes an https URL.

### `allowedImageHosts` is a security control

Compositing fetches an image URL **server-side**, and that URL can arrive in a request
parameter. Without an allowlist that is Server-Side Request Forgery: point it at
`169.254.169.254` for cloud instance credentials, at a localhost admin port, or at anything
else your server can reach but the internet cannot.

The check runs **before** any connection is made. List the hostnames your article photos
actually come from.

### `cacheDir` should persist

It holds the post ID → code mapping. Losing it breaks nothing and spends no quota — codes are
looked up from Trace-It again rather than re-created — but every article pays one extra round
trip until it warms up.

### Swapping the cache

Implement `Cache\Store` for Redis, your CMS's own cache, or anything else. `FilesystemStore`
is the default so that install-and-go works; nothing outside it assumes files.

---

## Server-side only

Every call sends the secret key. **Never expose this to a browser.** Anyone who can read the
key can create codes against your Trace-It account.

That is why the page script asks *your* server for a code by article ID, and your server
talks to Trace-It.

---

## Layout

`framedImage()` takes an optional `Layout` controlling where the badge sits and how large it
is. Sizes are fractions, not pixels, because compositing happens at the photo's native
resolution — a pixel size would be huge on a thumbnail and invisible on a full-size photo.

| | Default | |
|---|---|---|
| `scale` | `0.28` | QR width as a fraction of the image's **short** side |
| `minPx` / `maxPx` | `96` / `420` | Below `minPx` the badge is skipped rather than drawn unscannable |
| `padding` | `0.035` | Inset from the edge, fraction of the short side |
| `corner` | `bottom-right` | Also `bottom-left`, `top-right`, `top-left` |
| `plate` | `false` | White card behind the code. Off because a branded PNG already is one |

`Layout::plan()` constrains **both** axes, so the badge cannot land outside the image on an
unusual aspect ratio. `BadgePlan::isInside()` exposes that for your own assertions.
