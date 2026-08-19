# Deploying the QR service — Verite IT side

Internal. The client-facing document is [`client-kit/README.md`](client-kit/README.md).

This covers the service **we** run: the thing that holds the API key, mints codes, and
serves the composited images.

---

## What we deploy

One HTTP service with four endpoints. Either implementation is complete; pick by what fits
the Trace-It platform.

| | Node | PHP |
|---|---|---|
| entry point | `server/traceit-service.js` | `php/demo/router-traceit.php` |
| compositing | `server/compositor.js` | `php/traceit-compositor.php` |
| API client | `server/traceit-client.js` | `php/demo/qr-store.php` |
| store | `server/store.js` | `php/demo/qr-store.php` |

```
POST /v1/hooks/article-published   the CMS calls this on publish (authenticated)
GET  /v1/qr/{postId}.png           the branded QR on its own
GET  /v1/framed/{postId}.jpg       the photo with the QR in the pixels
GET  /js/traceit-qr.js             the page script — we host it, they do not vendor it
```

`GET /v1/health` reports mode and configuration; useful as a readiness probe.

> The `php/demo/` and `server/publisher-site.js`, `server/fake-s3.js` files are demo
> scaffolding — the mock CMS and the fake S3. **Do not deploy those.**

---

## Required configuration

Nothing here has a safe default for production. The service starts without them and
behaves plausibly, which is exactly why each one needs checking.

| Variable | Set it to | If you get it wrong |
|---|---|---|
| `TRACEIT_API_KEY` | the tenant's `sk_live_…` | unset ⇒ QR codes are generated locally and are **not tracked links**. Looks fine, attributes nothing. |
| `TRACEIT_BASE` | `https://<tenant>.trace-it.io` | a placeholder host ⇒ the key is deliberately **not sent** and codes fall back to local generation. Check `/v1/health` says `mode: live`. |
| `TRACEIT_WEBHOOK_SECRET` | a long random string | weak ⇒ anyone can register articles and spend the tenant's quota. |
| `TRACEIT_ALLOWED_IMAGE_HOSTS` | the client's real image hostnames | unset or wide ⇒ **SSRF.** The compositor fetches URLs server-side; see below. |
| `TRACEIT_ALLOWED_ORIGINS` | the client's real page origins | wrong ⇒ their pages cannot read our JSON endpoints. |
| `ARTICLE_URL_TEMPLATE` | `https://www.example.lk/article/{id}` | wrong ⇒ lazily-minted codes point at the wrong place. |
| `ALLOW_LAZY_MINT` | **`false`**, once the webhook is live | `true` ⇒ an unauthenticated request can create a code and spend quota. |

### `ALLOW_LAZY_MINT=false` is the one people forget

While it is `true`, `GET /v1/qr/{anything}` and `GET /v1/framed/{anything}` will create a
code for any well-formed ID they have not seen. That is deliberate — it makes a zero-CMS-change
deployment possible — but once the publish webhook is wired up it is pure downside:
the endpoints only ever need to *serve*.

This is not theoretical. During testing, a validation probe against a handful of IDs
silently created real codes on the live account.

### `TRACEIT_ALLOWED_IMAGE_HOSTS` is a security control

The compositor fetches an image URL server-side, and that URL can arrive in a query
parameter (`?src=`), which is how the page supplies it when the webhook did not. Without an
allowlist that is Server-Side Request Forgery: the service can be pointed at cloud metadata
endpoints, localhost admin ports, or anything else on the internal network.

Demonstrated in this repo: with the host off the allowlist the internal service was
contacted **0 times**; with it on, **1 time**, and a stand-in metadata endpoint handed over
credentials. The content-type check limits what comes back but does not stop the request
being made. **The allowlist is the control.**

---

## Before handing the client their key

- [ ] Tenant has a `subdomain` in `user_metadata`, or `shortUrl` falls back to
      `qr.trace-it.io` and looks unbranded.
- [ ] `qr_prefix` is assigned and **will not be edited afterwards** — changing it orphans
      every code already minted. Observed during testing: a prefix went from `ub112dec4` to
      `test` mid-session, which changes the id every later call computes.
- [ ] Monthly quota suits their publishing volume — one unit per *article*, not per view.
- [ ] Rate limit suits their traffic.
- [ ] The key was sent over a secure channel. It is stored only as a SHA-256 hash and
      cannot be recovered.

## Ask the client for

- [ ] The hostname(s) their article images are served from → `TRACEIT_ALLOWED_IMAGE_HOSTS`.
- [ ] Their page origins → `TRACEIT_ALLOWED_ORIGINS`.
- [ ] Their article URL pattern → `ARTICLE_URL_TEMPLATE`.
- [ ] A sample post ID, to confirm the format is usable (letters, digits, `_`, `-`; starts
      and ends alphanumeric; ≤48 chars; **no dots or slashes**).

---

## Operational notes

**The store is a cache, not a system of record.** Trace-It holds the truth; ours maps post
ID → code so the common path makes no network call. Losing it costs one free `by-post`
lookup per article, not quota, because a read precedes every create.

**Composites are cached and served `immutable`.** Bump `BADGE_VERSION` in
`public/js/traceit-qr.js` whenever the badge design changes — it is part of both the URL and
the cache key, so a bump busts browser and server caches together. Without that, a redesign
is invisible to anyone who has already loaded the page.

**Put a CDN in front of `/v1/framed/`.** Composites roughly double the source file size,
and in embed mode we serve every article image. The responses are `immutable` and safe to
cache indefinitely.

**PHP needs a CA bundle.** A fresh Windows PHP and some minimal Linux images ship none, and
every HTTPS call fails with `unable to get local issuer certificate`. Set `curl.cainfo` and
`openssl.cafile`. Never disable verification.

**`php -S` is a single-threaded dev server.** Fine for the demo, not for anything real. It
is also why `router-traceit.php` composites in-process rather than HTTP-fetching its own
`/v1/qr` endpoint — a self-request on a single-threaded server deadlocks.

---

## Verifying a deployment

```bash
npm run verify        # 56 checks in real Chromium against the demo stack
npm run lint:php      # all 11 PHP files
```

Against a real deployment, the client-facing preflight is the faster check — it exercises
the same path their CMS will:

```bash
TRACEIT_API_KEY=sk_live_… TRACEIT_BASE=https://acme.trace-it.io \
  php packages/traceit-qr-php/examples/preflight.php <postId> <https article url> <image url>
```

## Known gaps worth naming

- **Post-ID-derived code IDs are enumerable.** `{prefix}-{postId}` is guessable, so
  `GET /{prefix}-{n}` reveals which posts have codes, and Trace-It's scan endpoint is
  unauthenticated — scan counts can be inflated. Flag it to clients who care.
- **No delete.** Unpublishing is a dashboard action; re-publishing the same post
  un-trashes the existing code for free.
- **The rate limiter is per-process and in-memory.** Fine on one instance; behind a load
  balancer each instance limits separately. Move it to Redis if that matters.
