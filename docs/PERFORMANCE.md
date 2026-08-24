# Performance

NimbusCMS is **fast by construction**, not by tuning. A public page is
server-rendered HTML plus one small stylesheet — no client-side framework, no
render-blocking JavaScript, no web fonts, no third-party requests. The result is a
perfect Lighthouse score out of the box, before any operator optimization.

## Measured baseline

A real content page — a `blog` collection index with 15 published entries on the
shipped **starter theme**, served by the dev stack (PHP 8.3, MySQL 8), **page cache
off** (the default):

| Metric (Lighthouse, mobile profile) | Result |
|---|---|
| **Performance score** | **100 / 100** |
| First Contentful Paint | 0.8 s |
| Largest Contentful Paint | 0.8 s |
| Total Blocking Time | 0 ms |
| Cumulative Layout Shift | 0 |
| Speed Index | 0.8 s |
| Time to Interactive | 0.8 s |

| Page composition | Result |
|---|---|
| Total transferred | ~5.6 KB (4.6 KB HTML + 1.0 KB CSS) |
| Requests | 2 (document + stylesheet) |
| JavaScript | **0 bytes** |
| Web fonts | 0 |
| Images | 0 (starter theme) |
| Third-party hosts | 0 |
| Server response (TTFB, warm, uncached) | ~30–80 ms |

Lighthouse 12.8.2, default **mobile** emulation (4× CPU throttle, slow-4G) — the
*harder* profile; desktop scores are at least as good.

## Why it's fast

- **Server-rendered, zero mandatory JS.** Themes are plain PHP templates handed a
  data-only view-model. The page paints as soon as the HTML + one small CSS file
  arrive; there is no hydration step and nothing blocks the main thread (TBT 0 ms).
- **No layout shift.** Static server HTML with no late-loading widgets → CLS 0.
- **Optional page cache.** With `PAGE_CACHE_TTL > 0`, a public GET is served from a
  filesystem file — skipping PHP *and* the database entirely on a hit.
- **Cache-friendly assets.** Static theme assets are served with
  `Cache-Control: public, max-age=3600`.

## Honest caveats

- The score reflects the **starter theme**, which is intentionally a skeleton. A
  real content site adds images, and maybe fonts or a little JS — the numbers then
  depend on **your** theme's choices (right-size and lazy-load images, subset
  fonts, keep JS off the critical path). Nimbus gives you a 100 to *start* from;
  it doesn't stop a theme from spending it.
- **Compression (gzip/brotli) and the `/uploads/*` `nosniff` header are the
  webserver's job** — see the deployment notes in
  [COMPATIBILITY.md](COMPATIBILITY.md).
- The **JSON API** list path has a known N+1 for per-row media/relation expansion
  (backlog DATA-5); it does not affect the themed front end measured above.

## Reproduce it

```bash
docker compose up -d
docker compose exec app php bin/nimbus install --email=you@example.com --password='a long unique passphrase'
# seed a collection with some published entries and set it as site.home, then:
npx lighthouse http://localhost:8080/ --only-categories=performance \
  --chrome-flags="--headless=new"
```
