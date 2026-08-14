# Requirement — Trace-It QR in publisher article thumbnails

**Recorded:** 14 August 2026
**Product:** [Trace-It](https://github.com/VeriteIT/Trace-It) (Verite IT) — QR platform
**Client:** news publisher running a custom PHP news CMS
**Status:** proof of concept built and verified in this repo

---

## 1. As stated

Quoted verbatim from the request, so the original wording is preserved:

> So the requirnment is like this. we made this dummy site as a requirnment to one of
> our clients request testing. it's a custom PHP-style news CMS with a Bootstrap/jQuery
> frontend. we have our product that is https://github.com/VeriteIT/Trace-It qr platform.
> what we trying to achive is when publisher publish an article it will generate a ID.
> we want to capture that and make the qr code. and on their front end where the image
> frame is this qr also should appear in side. they are using S3 layer to save those
> images. so on front end if we can have a component inside that image frame that
> fetches the qr from our system it would be great/ also we don't have write or read
> permissions to their data only getting that id is allowed. so make a dummy site to
> test that out in here

---

## 2. The client's environment

| Piece | What we are told |
|---|---|
| CMS | Custom, PHP-based |
| Frontend | Bootstrap + jQuery |
| Image storage | S3 layer (article thumbnails) |
| Article identity | CMS generates an ID at publish time |

## 3. Functional requirements

- **R1 — Capture the article ID at publish time.** When the publisher publishes an
  article, the CMS generates an ID. That ID must reach our system.
- **R2 — Mint a Trace-It QR code for that article.** One code per article,
  associated with that article's ID.
- **R3 — Display the QR inside the image frame on their frontend.** The QR appears
  within the bounds of the article thumbnail, as a component in the image frame.
- **R4 — The component fetches the QR from our system.** The frontend pulls the code
  from a Trace-It endpoint at render time, keyed by the article ID.

## 4. Constraints

- **C1 — No read permission on the client's data.** We cannot read their database.
- **C2 — No write permission on the client's data.** We cannot write to their
  database or their S3 bucket.
- **C3 — The article ID is the only thing we get.** Nothing else about the article
  is available to us.
- **C4 — Their stack is PHP / Bootstrap / jQuery.** Anything we ask them to add has
  to be droppable into that.

**C1–C3 are the binding constraints and they determine the architecture.** See §6.

## 5. Deliverable

A dummy site, in this repo, that demonstrates R1–R4 end to end under C1–C3.

---

## 6. What the constraints rule out

The QR can be put on a thumbnail two ways. The constraints eliminate one of them.

| | Overlay (what we built) | Composite (bake into the pixels) |
|---|---|---|
| Reads the S3 image bytes | No | **Yes — required** |
| Needs CORS on their bucket | No | **Yes** |
| Needs any change to their S3 | No | **Yes** |
| QR is in the downloaded file | No | Yes |
| QR reaches `og:image` | No | Yes |

Compositing requires drawing their photo into a `<canvas>` and reading it back out.
Canvas readback of a cross-origin image throws `SecurityError` unless the image host
sends `Access-Control-Allow-Origin`. Setting that header on their bucket is a write to
their infrastructure, which **C2** forbids. So compositing is not available, and the
requirement's own wording ("a component inside that image frame that fetches the qr")
already describes the overlay.

This is asserted, not assumed: `npm run verify` loads the demo in real Chromium and
confirms the readback throws `SecurityError` against an image origin that sends no CORS
header, on the same page where the overlay renders correctly.

### Where the wall actually is

The wall is the **browser**, not the permissions. Same-origin policy and CORS govern what
a *page's JavaScript* may read. They say nothing about a server making an HTTP request.

So there are two compositing options, not one, and only the first is blocked:

- **Compositing in the browser** — blocked. Requires canvas readback of a cross-origin
  image, which throws `SecurityError` without CORS headers on their bucket.
- **Compositing on our server** — **available.** Our service fetches the thumbnail from
  its public URL, draws the QR in, and serves the result. This is an ordinary GET of a URL
  already public to every reader. No CORS, no credentials, no privileged access, and
  nothing written to their storage. C1–C3 are all satisfied.

That means the two limitations of a pure overlay **can** be lifted:

| | Overlay mode | Embed mode (server composite) |
|---|---|---|
| QR in the file from "Save image as…" | No | **Yes** |
| QR in "Copy image" / drag-out / print | Print only | **Yes** |
| QR reachable by `og:image` | No | **Yes**, if they point the tag at our URL |
| Image bytes served by | their CDN | **us** |
| Their photo re-encoded | No | Yes, once (see below) |

**Cost of embed mode, measured** (three 1200×800 demo thumbnails):

- Resolution is unchanged — compositing happens at native size, nothing is rescaled.
- One extra generation of JPEG loss, at ~53–56 dB PSNR: imperceptible, but real. A PNG
  source stays PNG and stays lossless.
- The delivered file roughly **doubles** (~48 KB → ~100 KB): about 35% from the re-encode,
  the rest because a grid of hard black-and-white edges is expensive to JPEG-encode.
- Those bytes come from us, so we are in the image delivery path.

**Recommended pattern:** embed on article pages (one image; where readers actually save
and share), overlay on index and list pages (many thumbnails; nobody is saving). The demo
does exactly this — compare `/` with `/article/<id>`.

Zero-loss embedding is possible only if they hand us the pre-compression master at publish
time, since then there is no second generation. Worth asking, not worth blocking on.

---

## 7. How each requirement is met

| Req | Mechanism | Where |
|---|---|---|
| R1 | CMS POSTs `{articleId, url, title}` to our webhook on publish | `php/publish-hook.php`, `server/publisher-site.js` |
| R1 (fallback) | Frontend sends the ID it found in the DOM; we mint on first view | `server/traceit-service.js` |
| R2 | One mint per ID, cached forever, concurrent-safe | `server/store.js`, `server/traceit-client.js` |
| R3 | Badge positioned inside the image frame, layout preserved | `public/js/traceit-qr-overlay.js` |
| R4 | `GET {service}/v1/qr/{articleId}.png` | `server/traceit-service.js` |
| C1–C3 | Only the ID crosses the boundary; mapping is held on our side | `server/store.js` |

Two ways to satisfy R1 are implemented because it is not yet settled which the client
will accept — see §8.

## 8. Open questions for the client

1. **Which capture path?** A publish-time webhook (ten lines in their publish routine,
   authenticated, one mint per article) or lazy minting on first page view (no CMS
   change at all, but an unauthenticated endpoint can trigger a mint). Both are built;
   the webhook is the recommendation, and lazy minting can then be switched off with
   `ALLOW_LAZY_MINT=false`.
2. **Can the thumbnail carry `data-article-id`?** If yes, list pages work. If not, only
   single-article pages can be handled, via the ID in the URL. Both are implemented.
3. **Does their CSP restrict `img-src`?** If it does, the QR must be served from their
   own domain — `php/qr-proxy.php` does that.
4. **Where should the QR sit, and how large?** Currently bottom-right at 26% of the
   thumbnail width. Corner, size, and padding are all configurable per deployment.

## 9. Unverified assumption

**The Trace-It API contract in `server/traceit-client.js` is a guess.** The Trace-It
repo is private and could not be read while building this, so the request and response
shapes for minting a code were assumed. Every Trace-It call goes through that one file,
so correcting it is a change to that file alone.

Confirm before showing this to the client:

- Endpoint and method for creating a code (assumed `POST /api/v1/qr`)
- Auth scheme (assumed `Authorization: Bearer <key>`)
- Response field holding the PNG (assumed `qr.png` as a base64 data URI)
- Whether a code can carry an external reference such as the publisher's article ID
- Whether a code can be looked up by that reference

Without an API key the demo generates real, scannable QR codes locally, so the
integration is fully testable offline — those codes are not tracked links.
