# Decision ledger

Append-only. Newest at the top. Supersede entries — never delete them. Do not
copy full ADR content here; link to the ADR. Each entry:

- **Date** · **Decision** · **Status** (proposed / accepted / superseded / rejected)
- **Evidence** (commits, PRs, tests, ADRs) · **Product** · **Architecture** ·
  **Engineering consequences** · **Revisit trigger**

Statuses: `proposed` awaits maintainer approval; `accepted` is in force;
`superseded` was replaced (link the successor); `rejected` was considered and
declined (keep it — it stops the idea returning without new evidence).

---

### 2026-08-15 · Boolean fields serialize as JSON booleans
- **Status:** accepted
- **Evidence:** PR (fix/boolean-toapi); `src/Content/FieldTypes/BooleanType.php`;
  `tests/Http/ApiRoutesTest.php`. Found live in Docker (a `featured` toggle
  rendered as "1" on the public site) while validating public rendering.
- **Product:** a toggle field is `true`/`false` for API clients and themes,
  not `1`/`0` — the starter theme's Yes/No branch now works.
- **Architecture:** `BooleanType` overrides `toApi()` (was inheriting the
  pass-through `BaseType::toApi`). Field-type edge, no core change.
- **Engineering:** wire change for boolean fields (int → bool); pre-1.0, noted
  in COMPATIBILITY. Covered by a new API test.
- **Revisit:** audit other field types for wire-shape correctness if a client
  reports a surprising value (none known now).

### 2026-08-15 · SEO split: foundational meta/sitemap/robots in core, rich SEO a future plugin
- **Status:** accepted (slice 1 of 3 — per-page meta — implemented here)
- **Evidence:** PR (feat/seo-meta); `src/Site/SiteController.php` (`meta()`,
  `describe()`); `Config::siteDescription()`; `themes/starter/templates/layout.php`;
  `tests/Http/SiteRoutesTest.php`
- **Product:** every public page gets a title, meta description, canonical URL,
  and Open Graph tags — table-stakes for search and social. Description comes from
  an entry's `excerpt`/`summary`/`description` field, then the collection's
  description, then `config/site.php`'s `description`.
- **Architecture:** the charter lists SEO as an *official plugin*, but (a) the
  plugin system hosts only field types — no route or head hooks — and (b) meta,
  `sitemap.xml`, and `robots.txt` are rendering/crawlability **correctness** every
  site needs, not an optional add-on. So the **foundational** layer is core;
  the **opinionated** layer (JSON-LD, social-card images, RSS/Atom, meta-editing
  UI, per-template OG) is deferred to a future `plugin-seo`, which will concretely
  require — and thus justify — a plugin **routes** + **head-injection** capability
  (ADR 0001 discipline). Request path threaded to the render for the canonical.
- **Engineering:** description is stripped of tags, whitespace-flattened, and
  clipped to ~160 chars; `$meta` guarded in the layout so a template rendered
  without it still works.
- **Revisit:** `og:image` (needs a media-field convention + absolute URLs);
  the `plugin-seo` extension capabilities when that plugin is built.
- **Next slices:** `sitemap.xml`, then `robots.txt` (both core routes).

### 2026-08-15 · Opt-in page caching at the kernel, flushed on content writes
- **Status:** accepted
- **Evidence:** PR (feat/page-cache); `src/Support/PageCache.php`,
  `Config::pageCacheTtl/pageCachePath`; `src/Application.php` (cache read/write +
  event flush); `tests/Unit/PageCacheTest.php`, `tests/Http/CacheRoutesTest.php`
- **Product:** rendered public pages can be cached for speed; off by default
  (`PAGE_CACHE_TTL=0`), opt-in with a positive TTL.
- **Architecture:** cached at the **kernel**, not in SiteController — GET requests
  whose path is not `/admin`, `/api`, or `/theme/assets`, storing only 200 HTML.
  Filesystem store under `storage/`, dependency-free. Invalidation is
  **event-driven** (flush on `entry.saved`/`entry.deleted`) plus the **TTL** as
  the safety net for time-based changes (a scheduled entry going live fires no
  write event) — neither alone suffices. Full-flush on write, not dependency
  tracking (simpler and safe). Injectable into Application for tests.
- **Engineering:** atomic write (temp file + rename); hashed keys can't escape the
  cache dir; only the `page` query varies a key; clock injectable so expiry is
  tested without sleeping. Default-off means existing behaviour is unchanged.
- **Revisit:** ETag/Last-Modified + conditional GET; per-collection or tag-based
  invalidation if full-flush churns under heavy write load; caching for logged-in
  previews (currently only anonymous public pages) — each on evidence.

### 2026-08-15 · Reusable blocks are live entries of a conventional `blocks` collection
- **Status:** accepted
- **Evidence:** PR (feat/blocks); `src/Site/SiteController.php` (`blocks()`,
  `renderPage()`); `themes/starter/templates/layout.php`; `tests/Http/SiteRoutesTest.php`
- **Product:** an editor defines a shared fragment once (an announcement, CTA,
  colophon) as an entry in the `blocks` collection; the theme renders it site-wide
  by slug (`$blocks['announcement']`). The starter shows it as an announcement bar.
- **Architecture:** **no new content concept** — blocks reuse collections/entries;
  the only new code loads the `blocks` collection's live entries into the theme
  view-model. Convention over config (handle `blocks`), consistent with the
  single-kind "Homepage" convention. Loaded **lazily** (a memoized `blocks()`
  threaded through `renderPage()`), so admin/API requests and pages with no
  `blocks` collection pay nothing — SiteController is constructed on every request
  for route registration, so eager loading would have taxed all of them.
- **Engineering:** only the live set is exposed (a draft block never renders);
  capped at MAX_BLOCKS; templates still receive data only (no service fetches a
  block). Labels/values escaped in the theme.
- **Revisit:** in-content block insertion (needs the rich-text/block editor);
  hiding `blocks` from public `/blocks` routes and sitemaps; configurable blocks
  collection handle — each on evidence.

### 2026-08-15 · Navigation menus via config/menus.php, rendered by the theme
- **Status:** accepted
- **Evidence:** PR (feat/menus); `config/menus.php`, `Config::menus()`;
  `SiteController` shared data; `themes/starter/templates/header.php`;
  `tests/Unit/ViewTest.php`, `tests/Http/SiteRoutesTest.php`
- **Product:** a site defines named navigation menus in one place; the starter
  header renders `main`. Every site type wants navigation.
- **Architecture:** config-driven (`config/menus.php`), consistent with
  `plugins`/`theme`/`site`. Menus flow through the theme's shared view-model as
  `$menus`; the theme renders — no menu logic in core beyond parse+validate.
  **Editor-managed menus (admin builder + storage) deferred** until evidence
  editors need it, not built speculatively.
- **Engineering:** `Config::menus()` drops malformed entries, so a config typo
  never reaches a template; labels/urls escaped in the theme.
- **Revisit:** active-item highlighting (needs the current path in the
  view-model); nested/child menus; an editor-facing menu builder — each on
  evidence.

### 2026-08-15 · Theme static assets served at /theme/assets, plus a Router catch-all
- **Status:** accepted
- **Evidence:** PR (feat/theme-assets); `src/Http/Route.php` (`{name*}` wildcard),
  `src/Http/Response.php` (`file()`), `src/Site/SiteController.php` (`asset()`);
  `themes/starter/assets/app.css`; `tests/Unit/RouterTest.php`, `SiteRoutesTest.php`
- **Product:** themes ship real `.css`/`.js`/images/fonts under `assets/`, served
  at `/theme/assets/<path>`, instead of inlining everything. Starter dogfoods it
  (its CSS moved to `assets/app.css`), which also drops the public site's reliance
  on inline `<style>`.
- **Architecture:** needed a route that captures a nested path, so `Route` gained
  a `{name*}` wildcard (`.+`) — a small, general, reusable core addition with a
  concrete consumer, not speculative. `Response::file()` serves a typed body.
  Asset route registered first among the site routes (specific literal prefix).
- **Engineering:** the URL path is resolved with `realpath()` and confirmed to
  sit inside `assets/`, so `..`/absolute paths 404 (tested against the theme's own
  templates one level up). Extension allowlist → a theme's PHP is never served.
  Bodies pass through PHP (fine for modest theme files; a webserver can bypass in
  prod). `Cache-Control: public, max-age=3600`.
- **Revisit:** ETag/Last-Modified + conditional requests; asset fingerprinting;
  reading `theme.json` for real — each on evidence.

### 2026-08-15 · Theme capabilities: partials, per-collection specialization, themed 404
- **Status:** accepted
- **Evidence:** PR (feat/theme-capabilities); `src/View/View.php`
  (`partial` injection, `exists()`, traversal-guarded `file()`);
  `src/Site/SiteController.php` (`specialize()`, themed `notFound()`);
  `themes/starter/*`; `tests/Unit/ViewTest.php`, `tests/Http/SiteRoutesTest.php`
- **Product:** themes stop being one monolithic file — a shared `header`/`footer`
  compose via `$partial`, a collection (or a home page) can have its own template,
  and a theme can brand its 404. Useful to every theme, tied to no app.
- **Architecture:** one specialization rule — `entry-{handle}` → `entry`,
  `collection-{handle}` → `collection` — subsumes the "home needs its own
  template" need without a special `home.php` concept. Helpers (`$partial`, `$e`)
  are injected into template scope; templates still receive no services. Theme
  path is injectable into `SiteController` for testing.
- **Engineering:** template names are restricted to `[A-Za-z0-9_-]`, so a name
  derived from a collection handle can never traverse out of the theme
  (`exists('../../etc/passwd')` is false — tested). Themed 404 falls back to a
  built-in page when the theme omits `404`.
- **Revisit:** static asset serving (`themes/{active}/assets/*`) is the **next**
  slice; nested template directories; reading `theme.json` for real (still
  decorative) — each on evidence.

### 2026-08-15 · Home page: designated via config/site.php, reusing the single kind
- **Status:** accepted
- **Evidence:** PR (feat/home-page); `config/site.php`, `Config::home()`;
  `SiteController::homePage()`; `tests/Http/SiteRoutesTest.php`
- **Product:** `/` renders a chosen collection — a `single`-kind Homepage shows
  its one live entry, a regular collection shows its index (a blog at the root).
  Every public-site shape (brochure, blog, docs, portfolio) is served.
- **Architecture:** **reused the existing `single` collection kind** (which the
  code already named "Homepage, Settings") instead of adding a `home` flag to
  collection options. A scalar `config/site.php['home']` models "a site has one
  home" correctly, needs no schema change/migration, and mirrors
  `config/theme.php`. Home handle is injected into `SiteController` (testable),
  resolved from `Config::home()` by the kernel. `/` moved from `Application` into
  `SiteController`, consolidating all public rendering.
- **Engineering:** the single entry is fetched with `findLiveBySlug(id,
  __singleton)`, so a draft home never leaks; unknown handle / unset / draft all
  fall through to an un-themed placeholder (never a 500). No new content concept.
- **Revisit:** designating a specific *entry* as home; an optional `home.php`
  theme template; broader `config/site.php` settings (meta, etc.) — each on
  evidence, not speculatively. Supersedes ADR 0003's "home deferred" decision.

### 2026-08-15 · Public rendering, first vertical slice (starter theme + site router)
- **Status:** accepted
- **Evidence:** PR (feat/public-rendering); `src/Site/SiteController.php`,
  `themes/starter/*`, `config/theme.php`, `Config::theme()/themePath()`;
  `tests/Http/SiteRoutesTest.php`
- **Product:** a Nimbus site renders its own live content — a collection's
  entries and a single entry — through a plain-PHP theme, no build step. Basic
  but real; the home page and richer theming are explicitly later.
- **Architecture:** themes are a directory of plain-PHP templates + `theme.json`
  under `themes/{name}/`, rendered by the existing `View`; a template gets the
  EntryView view-model + an escaping helper, never a service or the DB. Theme
  selected by `config/theme.php` (mirrors `config/plugins.php`). `SiteController`
  registered **last** so `{collection}` routes never shadow /admin or /api
  (first-match-wins; verified by test). Combined slices 2–4 because a theme with
  no router and a router with no theme each fail the integrated+verified gate.
- **Engineering:** live predicate reused (drafts/scheduled 404, indistinguishable
  from absent); output escape-by-default in templates (escaping test); 404 is a
  minimal un-themed page so a theme only owes two content templates; themes/ and
  config/ sit outside the phpstan/cs-fixer paths, like the admin theme already does.
- **Revisit:** designated home page (needs a home-collection mechanism); theme
  capabilities (asset pipeline, partial/template overrides, per-collection
  templates) — each added on concrete evidence, not speculatively.

### 2026-08-15 · Relation fields expand at the EntryView edge, live-only (F1 resolved)
- **Status:** accepted
- **Evidence:** PR (feat/relation-expansion); `src/Content/EntryView.php`,
  `RelationRepository::liveTargets()`; `tests/Http/ApiRoutesTest.php`; COMPATIBILITY.md
- **Product:** a headless client (or a theme) gets `{id,slug,title}` per linked
  entry in one request instead of bare ids — reusable across any frontend.
- **Architecture:** a second reference-expanding edge case alongside media, not a
  resolver abstraction — still two, so the "third → extract a capability" trigger
  is not yet met. Relation expansion bypasses `RelationType::toApi()` at the edge,
  exactly as media does. One live predicate reused (published, publish time due).
- **Engineering:** the JOIN filters to the live set, so a link to a draft /
  scheduled / archived entry leaks nothing — not even its existence. One query per
  relation field per entry (same N+1 shape as media; acceptable, revisit below).
- **Revisit:** a **third** reference-resolving field type, or list-view N+1 showing
  up under load → extract a batched reference-expansion capability then, not before.

### 2026-08-14 · Public rendering + theme contract accepted (ADR 0003)
- **Status:** accepted
- **Evidence:** [ADR 0003](../../../../docs/adr/0003-public-rendering-and-theme-contract.md), PR #31
- **Product:** a Nimbus site can render its own live content server-side with a
  plain-PHP theme and no build step — the last unbuilt production-readiness pillar.
- **Architecture:** theme = directory of plain-PHP templates + `theme.json`,
  rendered by the existing `View`; templates receive a data-only view-model +
  escaping helper (no services/DB/logic). One content shape for API and themes:
  `Nimbus\Api\EntrySerializer` → `Nimbus\Content\EntryView` (internal refactor,
  wire contract unchanged), folding in F1 relation expansion. Public router
  registered after and never shadowing `/admin` or `/api`. Theme chosen via
  `config/theme.php`, matching `config/plugins.php`. Home page (`/`) deferred
  until a collection can be designated home — collection/entry routes ship first.
- **Engineering:** only the live set renders; escape-by-default in templates;
  each slice (EntryView extract, `themes/starter/`, router, tests) its own PR.
- **Revisit:** a designated-home mechanism; theme capabilities beyond templates
  (assets pipeline, partial overrides) — each on concrete evidence.

### 2026-08-03 · MCP as an official companion, gated behind a scoped write API
- **Status:** proposed (three-hat review done; milestone awaits maintainer approval; nothing implemented)
- **Evidence:** review this date; grounding — authz lives in controllers not
  services (`EntryService`/`CollectionService` enforce none); API is read-only;
  `nb_api_tokens.abilities` exists but is **never enforced**
  (`ApiAuthMiddleware`).
- **Why general-purpose, not a pivot:** MCP is one client of a **scoped,
  authenticated write API**. That API benefits REST consumers, CLI, and automation
  equally — agents are not privileged. Nimbus must run identically with **zero**
  MCP installed. Rejected any framing where Nimbus depends on agents.
- **Core capability gaps MCP reveals** (all broadly useful, none MCP-specific):
  (1) **enforced token scopes** — activate the dead `abilities` column;
  (2) a **scoped write API** (`POST/PATCH/DELETE /api/v1/...`) calling the
  existing services, enforcing scope ∩ collection-permission;
  (3) **token→principal binding** so `Permissions` applies to token callers;
  (4) an **authenticated read** that can see drafts the principal may access
  (distinct from the public live-only read);
  (5) an **audit log** for authenticated writes (`nb_activity` is unused).
- **Ownership:** **separate official companion `NimbusCMS/mcp`**, talking to
  Nimbus over **HTTP** only. Not core (optional integration). Not an in-process
  plugin (MCP is a separate process; importing services would bypass the authz
  that lives at the HTTP boundary). "First-class" = maintained, CI'd,
  compatibility-tested — never mandatory.
- **Tools rejected:** any generic `execute` / `query` / `run` / `call`; arbitrary
  SQL / PHP / filesystem; session-cookie auth; MCP-specific fields in content
  models; schema-mutation (collection create/edit), `users:write`, and media
  upload in v1 (defer for stronger auth + audit).
- **Contracts that would become public:** the scoped write API and token-scope
  vocabulary; the MCP tool schemas (versioned with the package).
- **Assumptions to revisit after the first real agent integration:** create
  idempotency (slug auto-resolve duplicates on retry); update concurrency (no
  optimistic lock → lost updates); API rate limiting; whether scopes should be
  coarse (admin-only) or fine from day one.
- **Revisit trigger:** maintainer approval of the enabling write-API milestone.
  Capability evidence is **not** updated until an end-to-end test proves an agent
  operates Nimbus through public contracts only.

### 2026-08-03 · Charter governs; validation projects are acceptance tests
- **Status:** accepted
- **Evidence:** [`docs/CHARTER.md`](../../../../docs/CHARTER.md), PR #28
- **Product:** Nimbus stays a general CMS; Restaurant/Food Store/Packkit prove
  flexibility but do not own the roadmap.
- **Architecture:** classify every change (core/plugin/theme/app); capability
  added only on broad reuse; three-hat gate.
- **Engineering:** roadmap items gated; production readiness is the priority.
- **Revisit:** only via a charter change (maintainer-approved).

### 2026-08-03 · Media field expansion resolved at the serializer edge, like relations
- **Status:** accepted
- **Evidence:** PR #27; `src/Api/EntrySerializer.php`; `tests/Http/ApiRoutesTest.php`
- **Product:** clients get a media object (url/alt/dims) in one request.
- **Architecture:** field types stay pure; two edge special-cases (relation,
  media) rather than a resolver abstraction — no second consumer yet justifies one.
- **Engineering:** a dangling media id serializes to `null`, never a 500.
- **Revisit:** a **third** reference-resolving field type → extract a reference-
  expansion capability instead of a third special-case. (Ties to finding F1.)

### 2026-08-02 · Publication: cron-free scheduling; "scheduled" is derived, not stored
- **Status:** accepted
- **Evidence:** [ADR 0002](../../../../docs/adr/0002-publication-lifecycle.md); PRs #23, #24
- **Product:** draft / published / scheduled / archived without a scheduler to run.
- **Architecture:** one live predicate (`published AND published_at <= now`) used
  by admin badges and the API; stored status is three values, "scheduled" derived.
- **Engineering:** indexable, no background job, no state that lies about liveness.
- **Revisit:** if per-entry timezones or publish-time side effects (webhooks) are
  needed — those need a different, event-driven trigger.

### 2026-08-02 · Read API is read-only, serves only the live set, token-authed
- **Status:** accepted
- **Evidence:** PR #25; `src/Api/*`; `tests/Http/ApiRoutesTest.php`
- **Product:** any frontend can consume published content over HTTP+JSON.
- **Architecture:** the API is an HTTP contract (not a PHP public class surface);
  every value serialized via field `toApi()`; no writes over the API in this slice.
- **Engineering:** tokens stored as SHA-256; drafts/scheduled indistinguishable
  from absent (no leak); pagination hard-capped.
- **Revisit:** write endpoints, per-token scopes, CORS, ETags — each its own decision.

### 2026-07 · Plugin contract minimal; capabilities added one proven consumer at a time
- **Status:** accepted
- **Evidence:** [ADR 0001](../../../../docs/adr/0001-plugin-contract.md); PRs #14, #18, #19
- **Product:** third parties extend Nimbus via Composer packages.
- **Architecture:** `Plugin::register(PluginContext)`; `PluginContext` exposes only
  `fieldTypes()` today; loader is two-phase (validate/claim ids, then register with
  rollback); first-registration-wins; provider id bound by the loader.
- **Engineering:** a failing plugin is contained and rolled back, never partial;
  ids claimed on install so a disabled plugin can't have its id stolen.
- **Revisit:** add a `PluginContext` capability (routes/events/permissions/nav)
  **only** when an official plugin concretely needs it — see capability-evidence.md.

### 2026-07 · Plugin infrastructure is frozen
- **Status:** accepted
- **Evidence:** charter; three-hat review consensus
- **Product/Architecture/Engineering:** the loader/registry/lifecycle are done;
  further polishing is diminishing returns.
- **Revisit:** a concrete official plugin blocked by a missing, broadly-reusable
  extension point.

---

## Open findings (proposed — awaiting classification into work)

From the Restaurant **Menu** acceptance test (Menu itself needed zero core changes):

- **F1 — API returns relations/references as bare ids.** **Resolved** —
  relations now expand at the EntryView edge to `{id,slug,title}`, live-only.
  See the 2026-08-15 accepted entry above. Evidence: Restaurant
  `docs/PLATFORM-VALIDATION.md` F1.
- **F2 — no supported way to consume Nimbus from a separate app repo** (root-only,
  not on Packagist, no library mode/image). Classify **Core / release process**.
- **F3 — number decimals (`8.00`→`8`).** Classify **application concern** —
  rejected for core unless several apps want a shared money field type.
