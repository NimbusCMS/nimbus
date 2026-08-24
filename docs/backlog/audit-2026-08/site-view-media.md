# Public site, themes & media — audit findings (2026-08)

Domain: `src/Site/**`, `src/View/**`, `src/Media/**` (+ `themes/starter`, the
admin `nimbus` theme, and the kernel wiring that drives them). Reviewed through
both disciplines (platform three-hat + Drift Guard; Attacker/Defender/QA + merge
bar) against the running container, the ADRs (0003/0004), `COMPATIBILITY.md`'s
theme contract, and both ledgers.

**Bottom line: no P0/P1 in this domain.** The headline risks are genuinely well
handled: output is escape-by-default in both themes (`View::e` = `htmlspecialchars
ENT_QUOTES`), and I found no core/starter sink echoing a user value raw. Upload is
content-sniffed (finfo) against a fixed allow-list with **SVG and HTML excluded**,
random storage names, an is_uploaded_file-guarded mover, and a derived extension —
so the classic upload→stored-XSS / upload→RCE vectors are closed. Theme-asset
traversal is closed by a `realpath` containment check + extension allow-list, and
template-name resolution is character-class-restricted so a collection-handle can
never walk out of `templates/`. Redirects are operator-config only (no open
redirect). Findings below are one availability issue (unbounded `?page` × the page
cache), plus error-handling and defense-in-depth/product-consistency items. The
page-cache × CSP-nonce trap is real for themes but is the kernel's to fix and is
already filed as **HTTP-1** — cross-referenced here from the theme-contract angle,
not double-counted.

---

### SVM-1 · Unbounded public `?page` fills the page cache (disk-fill DoS) and renders empty deep pages ✅ RESOLVED
- **Resolved:** Slice K. Two halves (platform ❌1 — the second is load-bearing, not belt-and-braces): (1) `renderCollection` returns **404** for a page past the last (`page>1 && page>total_pages`) — page 1 always renders (empty collection is a valid view). A 404 (not a clamp-to-200) is what stops the mint, because `Application::cacheKey` keys on the raw `?page=N` and `respond()` stores only 200s — a clamped 200 would still write one file per N. (2) `cacheKey()` ceiling: `?page > 1000` → `null` (rendered, never cached) — this covers the home and entry routes too, which 200 but *ignore* `?page`, so the index-only 404 would have left them as an unbounded mint. Public 200→404 pagination behavior change noted in COMPATIBILITY; the divergence from the admin *clamp* is principled (public is cached+anonymous → status is the control; admin is uncached+authed → clamp is UX). Tests: `SiteRoutesTest` (page past end → 404, empty page-1 → 200, valid deep page → 200); `CacheRoutesTest` (out-of-range collection page + absurd `?page` on an entry mint **no** `.cache` file).
- **Priority:** P2
- **Type:** security / performance
- **Severity (if security):** Medium (unauthenticated disk exhaustion; precondition: `PAGE_CACHE_TTL > 0`, which is opt-in/off by default — rated one level down for that)
- **Where:** `src/Site/SiteController.php:253` (`max(1, (int) $request->query('page'))` — floor only, no ceiling), `src/Application.php:356-368` (`cacheKey()` appends `?page=N` for any `N > 1`), `src/Support/PageCache.php:66-81` (`put()` writes one `<sha256>.cache` file per key). No rate limit on the public site (`RateLimitMiddleware` is API-only).
- **What:** The public collection page number is floored at 1 but never clamped to `total_pages`; a page beyond the last renders a **200** (empty list). When page caching is on, every distinct `?page=N` is a distinct cache key, so an unauthenticated client can mint unbounded `.cache` files on disk.
- **Evidence:** `GET /posts?page=999999999` → `200` (verified live; empty list). With `PAGE_CACHE_TTL=300`, `cacheKey()` returns `/posts?page=999999999` and `respond()` stores the 200 body (`Application.php:280-282`). Scripted `for n in 1..N: GET /posts?page=$n` writes N cache files with no throttle and no upper bound — the public site has no flood guard. The admin entry list was hardened for exactly this class (ledger 2026-08-22 "Admin listing hardening": page clamped to `[1,total_pages]`), but the **public** path — the one that actually gets cached — was left unclamped.
- **Fix:** Clamp `page` to `[1, total_pages]` in `renderCollection`/`index` (mirror the admin listing hardening): a page past the end renders page 1, redirects to it, or 404s — any of which stops both the empty deep page and the per-N cache entry. (Belt-and-braces: `cacheKey()` could refuse to cache a `?page` beyond a sane ceiling.)
- **Effort:** S

---

### SVM-2 · Null byte (any `realpath` ValueError) in `/theme/assets/{path*}` → uncaught 500 + stack-trace log spam ✅ RESOLVED
- **Resolved:** Slice K. `SiteController::asset()` rejects a NUL byte (`str_contains($path, "\0")`) before `realpath()` → a clean 404, no `ValueError`→500 + logged stack trace. Confirmed complete (both lenses): in PHP 8 `realpath` throws `ValueError` **only** on null bytes — over-long/control-char paths return `false` (already 404'd) — so the null-byte guard is the whole ValueError class. No file was ever disclosed on the throwing path (availability/log-spam, Low). Test: `SiteRoutesTest` — `/theme/assets/app.css\x00.png` → 404; existing `..` traversal test still 404s.
- **Priority:** P3
- **Type:** error-handling
- **Severity (if security):** Low (no file disclosed; unauthenticated, trivially repeatable → noisy 500s + log growth)
- **Where:** `src/Site/SiteController.php:137` (`realpath($base . '/' . $path)`), reached with the raw decoded path from `src/Http/Request.php:36` (`rawurldecode(parse_url(...))`); the resulting `ValueError` is caught only by the generic `catch (\Throwable)` in `src/Application.php:287-295`.
- **What:** `realpath()` throws `ValueError: … must not contain any null bytes` on a `%00` in the asset path, which the asset handler does not guard, so a malformed asset URL returns a generic **500 with a logged stack trace** instead of the intended **404**.
- **Evidence:** `curl '/theme/assets/app.css%00.png'` → `500` ("Something went wrong. Reference: …"); the container log shows the full `ValueError` stack through `SiteController->asset()` → `respond()` → `handle()`. (Confirmed the null byte reaches `realpath` and throws: `php -r 'realpath("…\0…")'` → `ValueError`.) The traversal test at `SiteRoutesTest.php:309` covers `..` but not a null byte / malformed path, so this slips through.
- **Fix:** Reject the bad path before `realpath`: e.g. `if (str_contains($path, "\0")) return $this->assetNotFound();` (or wrap the `realpath` in a guard). Add a regression test asserting `%00` and other malformed asset paths return 404, not 500.
- **Effort:** S

---

### SVM-3 · Uploaded media is served straight from `public/uploads`, so it carries no `X-Content-Type-Options: nosniff` (or CSP) ✅ RESOLVED
- **Resolved:** Slice O (branch `slice-o-security-hardening-p3`) — docs. Confirmed defense-in-depth (the upload allow-list — no HTML/SVG, random name, sniffed MIME — is the primary control). Added a general operator hardening note to COMPATIBILITY ("Serving uploaded media") with nginx/Apache/Caddy snippets setting `X-Content-Type-Options: nosniff` on `/uploads/*`. A PHP media-serving fallback route for no-front-webserver installs is deferred (no evidence yet).
- **Priority:** P3
- **Type:** security
- **Severity (if security):** Low (defense-in-depth; the primary vectors — HTML/SVG upload — are already blocked, and the webserver sets a correct `image/*`/`application/pdf` type)
- **Where:** `src/Support/Config.php:111-120` (`UPLOAD_DIR=public/uploads`, `UPLOAD_URL=/uploads`), `src/Media/MediaUploader.php:82-95` (files land under the docroot), `public/index.php:11-14` (existing files are served directly by the webserver, bypassing the app), `src/Http/SecurityHeaders.php` (applies `nosniff`/CSP only to **PHP** responses, never to statically-served files).
- **What:** Because uploads live under the public docroot and are served by the front webserver (or the built-in server's static path), Nimbus's response headers never touch them: user-uploaded bytes go out **without `X-Content-Type-Options: nosniff`** and without CSP. A content-sniffing client faced with a polyglot file has nothing telling it not to sniff.
- **Evidence:** `public/index.php` returns `false` (serve the file directly) for any existing file under `public/`, so a `GET /uploads/2026/08/<hex>.png` is answered by the webserver, not the kernel — none of `SecurityHeaders::all()` applies. Blast radius is limited by the upload allow-list (no `.html`, no `.svg`, random name, sniffed type) and by the webserver declaring the right `image/*` type, which is why this is Low rather than a stored-XSS finding.
- **Fix:** Ship a documented deployment snippet (nginx/Apache/Caddy) setting `X-Content-Type-Options: nosniff` (and ideally `Content-Disposition: inline` / a restrictive CSP) on `/uploads/*`; note it in the media/deployment docs. Optionally offer serving uploads through PHP with those headers for installs without a front webserver. Keep the allow-list as the primary control.
- **Effort:** S–M

---

### SVM-4 · Reusable `blocks` fragments and single-kind collections are standalone public pages
- **Priority:** P3
- **Type:** product-gap
- **Where:** `src/Site/SiteController.php:118-119` (the `{collection}` / `{collection}/{slug}` catch-alls serve **every** collection), vs `SiteController.php:167-169` (the sitemap deliberately **excludes** `blocks` and single collections).
- **What:** The `blocks` collection (shared content fragments meant to be embedded by slug, e.g. an announcement bar) and `single`-kind collections (a designated Homepage) are individually reachable as their own public pages, even though the sitemap intentionally hides them — so fragments get orphan public URLs and the home entry is duplicated at `/` and `/{home-handle}`.
- **Evidence:** Live: `GET /blocks` → `200` (lists every block), `GET /blocks/announcement` → `200` (renders the announcement fragment as a full page with its own `<canonical>`). A single "Homepage" collection is thus served at both `/` and `/homepage`, splitting canonical/SEO signal. The decision ledger (2026-08-15, "Reusable blocks") already lists "hiding `blocks` from public `/blocks` routes and sitemaps" as **deferred-on-evidence** — this audit is that evidence: the fragments are live and publicly addressable.
- **Fix:** 404 the `blocks` collection's public `{collection}`/`{collection}/{slug}` routes (it is a fragment store, not a browsable section); decide likewise whether a `single` collection should be reachable anywhere but `/`. Smallest correct move: in `index()`/`show()`, treat `handle === 'blocks'` (and, for `index`, `isSingle()`) as not-found. Add tests asserting `/blocks` and `/blocks/{slug}` 404.
- **Effort:** M

---

### SVM-5 · Test gaps: asset-path hardening and the nonce×cache theme contract are unguarded ✅ RESOLVED (cross-reference)
- **Resolved:** already covered — (a) a `%00`/malformed asset path → 404 is guarded by Slice K's `SiteRoutesTest::test_a_null_byte_asset_path_is_404_not_500`; (b) the cache-hit body nonce == `script-src` nonce is guarded by Slice H's `CacheRoutesTest::test_a_cache_hit_reemits_the_stored_nonce`. No new tests needed (Slice P confirmation).
- **Priority:** P3
- **Type:** test-gap
- **Where:** `tests/Http/SiteRoutesTest.php` (asset tests), `tests/Http/CacheRoutesTest.php`.
- **What:** Two properties this domain relies on have no regression test, so a refactor can silently reopen them: (a) a malformed asset path (null byte, control chars) must return **404, not 500** (see SVM-2); (b) the theme contract's promise that a public page's rendered `nonce="…"` matches the response's `script-src` nonce — the property broken by the page-cache×CSP interaction (HTTP-1) — is asserted nowhere for the public site.
- **Evidence:** `SiteRoutesTest.php:309` tests `..` traversal but not `%00`; `CacheRoutesTest.php` asserts hit/flush/exclusion but never the nonce-header/body agreement, so HTTP-1 shipped undetected against the public renderer.
- **Fix:** Add `SiteRoutesTest` cases for `%00` and other malformed asset paths → 404; add a `CacheRoutesTest` case (or fold into HTTP-1's fix) asserting a cache-hit body's inline-script nonce equals the response `script-src` nonce.
- **Effort:** S

---

### Cross-reference (not re-counted here)

- **Page-cache × CSP-nonce (filed as HTTP-1).** A cached public page bakes in the
  nonce from its render, but `SecurityHeaders` emits a freshly-rotated
  `script-src 'nonce-…'` on every request including cache hits, so a theme that
  follows the documented contract (`<script nonce="<?= $e($cspNonce) ?>">`,
  `COMPATIBILITY.md` "theme contract") has its inline scripts **blocked** on
  cache-served pages once `PAGE_CACHE_TTL > 0`. Latent for `starter` (no inline
  script) but a live trap for any nonced theme/plugin head script. `SiteController`
  shares `cspNonce` into the public View (`SiteController.php:103`); the fix belongs
  in the kernel/cache layer — see HTTP-1. Flagging it here because it is the theme
  contract that breaks.

---

## What's solid

- **Escape-by-default holds.** `View::e` is `htmlspecialchars(ENT_QUOTES,'UTF-8')`;
  every dynamic value in `themes/starter` (title, meta description, canonical,
  og:*, menu label/url, entry title/fields, media url/alt, relation titles, block
  text) and in the admin media view goes through it. The one raw sink, `<?= $head ?>`,
  is the documented plugin head-contribution seam (ADR 0004) whose `PageContext`
  docblock explicitly marks its strings UNTRUSTED and requires contributors to
  escape — an accepted, well-signposted contract, not a leak.
- **Upload is hostile-client-safe.** finfo content-sniff against a fixed allow-list
  (JPEG/PNG/GIF/WebP/PDF — **no SVG, no HTML**), `bin2hex(random_bytes(16))` storage
  names with the extension derived from the *validated* mime, is_uploaded_file-guarded
  mover, size/empty checks, and a display-name that is basename-only + control-char
  stripped. A `.php`/`.svg`/`shell.php.png` can neither be written executable nor
  round-tripped as its claimed type. Guarded by `MediaUploaderTest` (SVG rejected,
  type-by-content, random name, cap).
- **Two traversal surfaces closed.** Theme assets: `realpath` + `str_starts_with($full,
  $base.DIRECTORY_SEPARATOR)` containment + extension allow-list. Template names:
  the `^[A-Za-z0-9_-]+(?:/…)*$` character class forbids dots, so a collection-handle
  never escapes `templates/`. (The null-byte hole in SVM-2 is an error-handling miss,
  not a containment bypass — no file is disclosed.)
- **Live-set discipline is consistent.** Home, index, entry, relation expansion,
  sitemap and blocks all filter `status='published' AND published_at <= NOW()`, so a
  draft/scheduled/archived entry is a 404 indistinguishable from absent, and a
  dangling media/relation reference reads as null, never a 500.
- **Media delete is a single guarded choke point** (`MediaService::delete` → usage
  check → `MediaInUse`), reused by admin/MCP; SQL is bound-parameter throughout
  (`MediaRepository`, `MediaUsageRepository`), including the dynamic `IN (…)` which
  builds named placeholders from int-filtered ids.
