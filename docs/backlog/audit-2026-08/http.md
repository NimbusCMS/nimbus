# HTTP kernel & app wiring — audit findings (2026-08)

Domain: `src/Http/**` + `src/Application.php`. Reviewed through both disciplines
(platform three-hat + Drift Guard; Attacker/Defender/QA + merge bar), against the
running code, the ADRs, and the two ledgers.

**Bottom line:** the request lifecycle is in good shape. Security headers +
CSP nonce are applied to *every* response (success, redirect, 404/500/503, API,
preflight) with no fail-open path; CSRF is per-session + `hash_equals`; header
injection is blocked at `Response` construction (CRLF/NUL rejected); the theme-
asset traversal surface is closed by a `realpath` containment check; trusted-proxy
handling is deny-by-default and correct. No P0/P1 found. The findings are two
feature-interaction traps (page-cache × CSP-nonce, HEAD/method handling) plus
hygiene/defense-in-depth items. Nothing here re-litigates an accepted risk — the
`style-src 'unsafe-inline'` deferral and the `Csp`/`Url` request-scoped-statics
decision were both verified to still hold.

---

### HTTP-1 · Page cache serves stale CSP nonce → inline scripts blocked on cached public pages ✅ RESOLVED
- **Resolved:** Slice H (branch `slice-h-csp-nonce-cache`). `PageCache` now stores the nonce with the entry (`timestamp\nnonce\nHTML`) and `Application::respond` calls `Csp::adopt($hit['nonce'])` on a hit, so the header re-emits the nonce the cached body was rendered under (both `script-src` and `style-src`). Chose persist-and-re-emit over drop-the-nonce (both reviews agreed) so inline nonce'd scripts/styles keep working on cached pages — the PLUG-5 analytics path. A must-fix caught in review: `get()` validates the stored nonce against the exact emitted shape (`^[A-Za-z0-9+/]{22}==$`) and treats any mismatch — a pre-upgrade `timestamp\nHTML` entry included — as a miss, so a legacy/corrupt entry can never feed arbitrary text into the CSP header. Regression tests: `CacheRoutesTest::test_a_cache_hit_reemits_the_stored_nonce`, `test_a_content_write_rotates_the_cached_nonce` (the flush-rotates-nonce invariant the safety argument rests on), `PageCacheTest` legacy/invalid-entry-is-a-miss, `CspTest` adopt/isValid. Follow-up filed (FU-7: hosted-analytics `connect-src`).
- **Priority:** P2
- **Type:** correctness
- **Severity (if security):** Low (availability of legit scripts, not an XSS bypass)
- **Where:** `src/Application.php:224` (`Csp::rotate()` per request), `src/Application.php:268-273` (cache hit → `Response::html($hit)`), `src/Application.php:280-282` (cache store), `src/Http/SecurityHeaders.php:31` (fresh `Csp::nonce()` into `script-src` every request), `src/Site/SiteController.php:103` (shares `cspNonce` into public templates), `src/Site/HeadContributorRegistry.php:43` (plugin head HTML baked into the cached body)
- **What:** The page cache stores the rendered HTML body (with any `<script nonce="X">` baked in), but `SecurityHeaders::apply` emits a **freshly-rotated** `script-src 'nonce-Y'` on every request including cache hits — so `X ≠ Y` and the browser blocks every inline script on a cache-served public page.
- **Evidence:** Set `PAGE_CACHE_TTL=300`. Give the active public theme (or a plugin head contributor per ADR 0004) an inline `<script nonce="<?= $e($cspNonce) ?>">…</script>`. First GET `/posts` renders and is stored with `nonce="A"`; the header for that response is also `nonce-A`, so it runs. Second GET `/posts` is a cache hit: body still carries `nonce="A"`, but `handle()` rotated a new nonce and the header is now `nonce-B`. The browser refuses the script (`Refused to execute inline script … nonce`). Latent for the shipped `starter` theme only because it emits no inline scripts on public pages; it bites the moment any cacheable public page carries a nonced inline script.
- **Fix:** Public cacheable pages are static output — don't tie them to a per-request nonce. Smallest correct option: when serving/storing a cacheable public page, use a **stable** script-src for that response (drop the per-request nonce on the public/cacheable path, relying on `'self'` + escape-by-default there), so header and body always agree. Alternatively persist the page's nonce with the cache entry and re-emit it on hit. Add a `CacheRoutesTest` asserting a cache-hit body's `nonce="…"` equals the response's `script-src` nonce.
- **Effort:** M

---

### HTTP-2 · HEAD requests (and method mismatches) return 404 instead of serving/annotating ✅ RESOLVED
- **Resolved:** Slice J (branch `slice-j-http-cors-head`). `Router::dispatch` matches `HEAD` as `GET` (single pass, first-match-wins preserved) and returns a **405 with an `Allow` header** when a route's *pattern* matches but not its method (else null → 404). `Application::handle` strips the body for a HEAD via new `Response::withoutBody()` — after headers/CORS are set, before `notifyHandled` — so a HEAD carries the GET's status + headers, no body. **Documented consequence** (both reviews): because `SiteController` registers `GET /{collection}` catch-alls, a wrong-method request to a 1–2-segment path (e.g. `POST /anything`) is now **405 (Allow: GET, HEAD)**, not 404 — uniform, no oracle, RFC-tolerable; pinned by a test. 405 returned as a `Response` (not an exception), plain-text (the Cors 204 precedent), `OPTIONS` omitted from `Allow`, no fabricated Content-Length. Tests: `RouterTest` (405+Allow, HEAD→GET), `HttpMethodTest` (HEAD 200 empty, 405 catch-all, 3-seg 404, HEAD still runs admin+API guards), `CacheRoutesTest` (HEAD neither populates nor is served from cache).
- **Priority:** P2
- **Type:** correctness / product-gap
- **Where:** `src/Http/Router.php:70-79` (`dispatch` requires exact `$route->method === $request->method`), `src/Http/Response.php:105-114` (`send()` always echoes the body)
- **What:** A `HEAD` request to any GET resource does not match (only `GET` routes are registered) → the kernel falls through to a 404. A wrong-method request (e.g. `POST` to a GET-only route) also yields 404 with no `Allow` header, rather than 405.
- **Evidence:** `HEAD /` (what uptime monitors, some proxies, and link-checkers send, and what HTTP requires wherever GET is served) returns `404` because `dispatch()` finds no route whose `method` is `HEAD`. A monitor doing HEAD health checks reports the site down; a `HEAD /posts/live-entry` on a real live entry 404s. Separately, `POST /admin/collections` to a GET route returns a generic 404, not `405 Method Not Allowed`.
- **Fix:** In `dispatch()`, treat `HEAD` as `GET` (match GET routes; have `send()` suppress the body when `REQUEST_METHOD` is HEAD). Optionally collect the methods that matched the path but not the verb and return `405` with an `Allow` header instead of 404. Add `tests/Http/` cases for `HEAD /` (200, empty body) and a 405.
- **Effort:** M

---

### HTTP-3 · A session is started for every request, so API/JSON and CORS-preflight responses set a `nimbus_session` cookie ✅ RESOLVED
- **Resolved:** Slice J. `Application::run()` skips `startSession()` when `Cors::isApiPath($request->path)` — covering `/api/**`, MCP, and the OPTIONS preflight (all bearer-only; verified **zero** `$_SESSION`/`session_*` under `src/Api/`+`src/Mcp/`). Admin + public site + login unchanged. Uses the shared `Cors::isApiPath` predicate so the CORS scope and the session-skip can't drift. Since the cookie is emitted by `session_start()` (not on the `Response`, so unobservable via `handle()`), the seam is tested three ways: `ApiSessionlessTest` unit-tests the predicate AND a static drift guard (no session use under Api/Mcp — a future session-dependent /api addition fails loudly), and `smoke.sh` asserts no `Set-Cookie` on a live `/api/v1` response while `/admin/login` still sets one. **Deferred (recorded):** session-skip for `/theme/assets/` + public GETs (a behavior change — themes could touch session) → its own later slice.
- **Priority:** P3
- **Type:** security (hygiene) / architecture
- **Severity (if security):** Low
- **Where:** `src/Application.php:204-210` (`run()` calls `startSession()` unconditionally before `handle()`), `src/Application.php:331-349`
- **What:** `startSession()` runs on the way into every request — `/api/**`, the OPTIONS preflight, and asset requests included — even though the API authenticates strictly by bearer token and never reads the session. Every API response therefore carries `Set-Cookie: nimbus_session=…`.
- **Evidence:** `curl -i -H 'Authorization: Bearer nimbus_…' https://site/api/v1/collections` returns a `Set-Cookie: nimbus_session=…` header. A bearer-token client (a CI job, an agent over MCP-HTTP) is handed a cookie it never uses and must be told to ignore; a preflight `OPTIONS /api/v1/...` also mints one. It is wasted work and an unnecessary ambient credential on a surface that is deliberately cookie-free (keeps the "API never accepts the session cookie" boundary from the threat catalog clean, but the cookie shouldn't be issued there at all).
- **Fix:** Start the session lazily — skip it for `/api/**` and for the CORS preflight (start only for admin/site paths, or start on first `$_SESSION` access). Keeps the CSRF/session model unchanged for the admin.
- **Effort:** S

---

### HTTP-4 · CORS preflight is answered before rate-limiting and the DB gate ✅ RESOLVED
- **Resolved:** Slice J. The preflight now passes the per-IP flood guard before returning the 204, so it is no longer an uncounted request class. The flood guard is built **once** in `Application` (`RateLimitMiddleware` keyed `ip:{ip}`) and injected into `ApiController` — one config read, one construction site (platform ❌2, no static Config-coupled factory), so preflight + real API requests share the same DB bucket. **Fail-open** (platform ❌1): the guard's DB call runs before `respond()`'s try/catch + the readiness gates and `index.php` has no net, so a DB-down / not-yet-installed site logs a ref and still answers 204 (a real API request 503s pre-limiter anyway). Residual (both lenses, Low/Info): an `OPTIONS` with **no** Origin isn't a preflight → falls to routing → 405, uncounted — but that's baseline-equivalent to any 404/405 (no DB, limiter never fires). Tests: `ApiCorsTest` (repeated preflights → 429; preflight + real request share the `ip:` bucket).
- **Priority:** P3
- **Type:** security
- **Severity (if security):** Low
- **Where:** `src/Application.php:229-231` (`Cors::isApiPreflight` short-circuits before `respond()` and thus before the `/api/v1` middleware group), `src/Http/Cors.php:26-41`, `src/Api/ApiController.php:93,102` (the ip-flood limiter lives inside the group)
- **What:** An OPTIONS preflight is handled in `handle()` ahead of routing, so it never passes through the per-IP flood limiter that guards the rest of `/api`. With `CORS_ALLOWED_ORIGINS=*`, unauthenticated `OPTIONS /api/v1/...` requests are unlimited.
- **Evidence:** With `*` configured, a client can send unbounded `OPTIONS /api/v1/entries` with an `Origin` header; each returns a 204 with CORS headers and is never counted against `api.flood.limit`. The response is cheap (no DB), so the amplification is mild, but it is an uncounted, unauthenticated request class on the API surface.
- **Fix:** Run the ip-flood key/limit check against preflight too (or cap preflight volume) before returning the 204.
- **Effort:** S

---

### HTTP-5 · Compiled route regex is rebuilt on every match; dispatch is a per-request linear rescan
- **Priority:** P3
- **Type:** performance
- **Where:** `src/Http/Route.php:77-83` + `:110-120` (`match()` calls `regex()`, which runs `preg_replace_callback` every time), `src/Http/Router.php:70-80`
- **What:** `Route::regex()` recompiles the pattern → regex string on every `match()` call, and `dispatch()` walks every route calling `match()` until one hits. Per request that is O(routes) `preg_replace_callback` + `preg_match` pairs, redone from scratch.
- **Evidence:** A public request for the last-registered `/{collection}/{slug}` route first mis-matches every earlier admin/api route, recompiling each route's regex on the way. On a plugin-heavy install (many admin pages + API routes), this repeats for every request with no memoization.
- **Fix:** Memoize the compiled regex on the `Route` instance (compute once, reuse). Optional later: bucket routes by method. Small, purely internal.
- **Effort:** S

---

### HTTP-6 · Page-cache key ignores every query param except `page`
- **Priority:** P3
- **Type:** correctness
- **Where:** `src/Application.php:356-368` (`cacheKey` keys only on `path` + `page`)
- **What:** A cacheable public GET is keyed on path and `?page=N` only. Any other query param that a theme or plugin uses to vary output (`?q=`, `?sort=`, `?tag=`) is dropped from the key, so the first response for a path is served for every later query on that path.
- **Evidence:** If a theme renders a filtered list from `?tag=news`, the first request (`/posts?tag=news`) is cached under key `/posts`, and a later `/posts?tag=events` gets the `news` page back. Core ships no such param today, so this is currently latent — but page caching is opt-in operator config and the coupling is invisible to a theme author.
- **Fix:** Either fold the full (sorted) query string into the cache key, or explicitly document that a cacheable public page must not vary on query beyond `page` (and have themes opt sensitive pages out). Add a test that two different query strings on one path don't collide.
- **Effort:** S

---

### HTTP-7 · `Response::redirect` performs no destination check beyond CRLF — latent open-redirect if a target ever becomes user-influenced
- **Priority:** P3
- **Type:** security (defense-in-depth)
- **Severity (if security):** Low (not reachable today — no redirect target is user-controlled in this domain)
- **Where:** `src/Http/Response.php:42-48` (`redirect()` only validates the status + relies on the constructor's CRLF/NUL check), `src/Http/Middleware/AuthMiddleware.php:24` (redirects to a hardcoded `/admin/login`, dropping the originally-requested path)
- **What:** `Response::redirect` accepts any `Location` (protocol-relative `//evil.com`, absolute `https://evil`) as long as it has no CR/LF/NUL. Every current caller passes an internal path (`Url::to`, hardcoded, or the operator-owned `config/redirects.php`), so there is no exploit today — but the moment a `next`/`return_to` param is honoured (a natural follow-up, since login currently always lands on the dashboard and loses the target), it becomes an open redirect.
- **Evidence:** No current path feeds user input into `redirect()`, so this is a hazard note, not a live finding. The concrete trigger would be adding `Response::redirect($request->query('next'))` anywhere.
- **Fix:** When a user-influenced redirect is introduced, force it relative (reject `//` and any scheme/host) or host-allow-list it — ideally add a `Response::redirectSafe()` / a small `Url::safeInternal()` helper now so the safe path exists before the feature does. Consider preserving the requested path through login via such a guarded param.
- **Effort:** S

---

## What's solid (verified, not assumed)

- **Headers on every response, no fail-open.** `handle()` runs `SecurityHeaders::apply` on the result of *both* the preflight branch and `respond()`, and `respond()` funnels 404/500/503 through `notice()` — so error, redirect, API and asset responses all carry CSP + `nosniff` + `X-Frame-Options: DENY` + `Referrer-Policy`. `SecurityHeaders::apply` only *fills* a header the response didn't set, so a page can harden (reset page's `no-referrer`) but never silently weaken (`SecurityHeaders.php:46-57`) — the ledger's control still holds.
- **CSP nonce lifecycle.** `Csp::rotate()` at the top of `handle()` (`Application.php:224`), fresh 128-bit CSPRNG value, `'unsafe-inline'` absent from `script-src`; no code path emits a page without the header. (The only wrinkle is its interaction with the page cache — HTTP-1.)
- **Header injection blocked at the boundary.** `Response`'s constructor and `withHeader` reject invalid header names and any value containing CR/LF/NUL (`Response.php:137-145`), so `Location` and CORS-echoed `Origin` can't carry a smuggled header.
- **Trusted-proxy handling.** Deny-by-default; `X-Forwarded-For` consulted only when the immediate peer is trusted, walking right-to-left to the first untrusted hop; CIDR match is byte+bit correct and rejects IPv4/IPv6 mixing (`TrustedProxies.php`, `Request.php:85-116`). (Operational caveat: if `TRUSTED_PROXIES` is left empty behind a real proxy, `ip()` collapses to the proxy address and login-throttle/rate-limit buckets aggregate — inherent to the model and documented; not a code defect.)
- **CSRF.** Per-session token, 32 CSPRNG bytes, `hash_equals` compare, cleared on logout; the API stays bearer-only and never consults the cookie (`Csrf.php`, `ApiAuthMiddleware.php`).
- **Theme-asset traversal.** `realpath` + `str_starts_with($full, $base . DIRECTORY_SEPARATOR)` containment plus an extension allow-list closes the historically-buggy `/theme/assets/{path*}` surface (`SiteController.php:130-149`); template resolution is character-class-restricted (`View.php:88-97`).
- **Redirect map + readiness ordering.** Config redirects resolve before the DB-ready/installed checks, so an old URL keeps working during an outage (`Application.php:250-262`); the 500 path logs a reference id and never leaks internals with `APP_DEBUG` off.
