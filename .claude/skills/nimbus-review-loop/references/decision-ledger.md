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

### 2026-08-15 · Capability D built: plugin admin pages
- **Status:** accepted (analytics milestone, slice D of A–D — capabilities complete)
- **Evidence:** PR (feat/plugin-admin-pages); `src/Admin/AdminPageRegistry.php`,
  `src/Plugin/AdminPageRegistrar.php`, `PluginContext::adminPages()`;
  `src/Admin/PluginPagesController.php`; `Admin\Controller` nav + `shell()`;
  `tests/Unit/AdminPageRegistryTest.php`, `tests/Http/PluginAdminPageTest.php`
- **Product:** a plugin registers a login-gated admin page rendered in the admin
  shell with a sidebar entry — the analytics dashboard's home.
- **Architecture:** registry + provider-scoped registrar + rollback, like the
  others. A slug (validated `[a-z0-9-]`) → `GET /admin/{slug}` under the auth
  group; the handler returns HTML (wrapped in the shell) or a Response
  (passthrough). Registered **last** among admin controllers so a plugin slug
  can't shadow a core route. Nav integration threaded the registry through the
  base `Controller` + the four admin controllers (optional arg — the bundle
  refactor kept this to defaulted params, no test churn beyond the constructors).
- **Engineering:** GET-only for v1 — POST/forms deferred (needs a CSRF-token
  exposure decision); plugin content is trusted HTML (escape-your-values, like
  head contributions); login-gated + shell-render + nav all kernel-tested.
- **Revisit:** admin POST/forms (CSRF token to plugins); public plugin routes
  (a beacon endpoint); per-page permission beyond "logged in".

### 2026-08-15 · Capability C built: scoped plugin storage (ADR 0005)
- **Status:** accepted (analytics milestone, slice C of A–D)
- **Evidence:** PR (feat/plugin-storage); `src/Plugin/PluginStorage.php`,
  `PluginContext::storage()`; `PluginCapabilities` carries the `Connection`;
  `tests/Integration/PluginStorageTest.php`
- **Product:** a plugin reads/writes the tables it created with its migrations —
  the runtime half of ADR 0005, what analytics' charts need.
- **Architecture:** a narrow parameterised interface (`select/selectOne/execute/
  insert/transaction`) — **not** the core `Connection`, not a repository. Built
  lazily from the kernel's connection; requires a DB (throws otherwise). Amended
  `PluginContext`'s "deliberately absent" note: **core** connection/tables/repos
  stay absent; a plugin may own and query its **own** tables.
- **Engineering (honest boundary):** "own tables only" is a **contract, not a
  sandbox** — an in-process PHP plugin has the whole runtime and could open its
  own connection anyway, so there is no enforcement here a determined plugin
  couldn't bypass. `PluginStorage` provides the *intended* path (parameterised,
  no core connection handed over) and the boundary docs/reviews hold plugins to.
- **Revisit:** core-*data* access (Tiers 1–3 in ADR 0005) remains a separate,
  later, operation-level contract — never raw core-table SQL.

### 2026-08-15 · Refactor: plugin capabilities bundled into PluginCapabilities
- **Cross-repo lesson (self-learning):** the bundle refactor revealed
  plugin-markdown's *tests* had been broken against nimbus dev-main since the
  head capability (#47) — a plugin's CI runs only on its own pushes, and the
  boundary test exercises plugin *production* code, not the plugin's suite. Guard
  later (scheduled plugin CI, or run plugin suites in the boundary job). Fixed
  both plugin repos as part of the refactor.
- **Status:** accepted
- **Evidence:** PR (refactor/plugin-capabilities-bundle); `src/Plugin/PluginCapabilities.php`;
  `PluginContext` + `PluginLoader::load` now take one value; `Application` composes it
- **Product:** none (pure refactor); no behaviour change.
- **Architecture:** the four capability registries (field types, head, events,
  migrations) were growing `PluginContext::__construct` and `PluginLoader::load`
  and every test that built them. Bundled into one `PluginCapabilities` value
  object (each registry `new`-defaulted, so a caller names only what it needs).
  Adding capability #5/#6 is now one field, not two signature changes. Done
  **before** C/D deliberately, to stop the churn.
- **Engineering:** plugins' *production* code is untouched (they receive
  `PluginContext`, whose methods are unchanged); only the internal loader
  signature changed, so the two plugin repos' package-integration **tests** need a
  one-line update (coordinated). The cross-repo boundary test exercises plugin
  production code through HTTP, so it stays green.

### 2026-08-15 · Capability B built: plugin-owned migrations
- **Status:** accepted (analytics milestone, slice B of A–D)
- **Evidence:** PR (feat/plugin-migrations); `src/Database/MigrationRegistry.php`,
  `src/Plugin/MigrationRegistrar.php`, `PluginContext::migrations()`;
  `Migrator` runs plugin migrations after core; `bin/nimbus` boots the app to
  collect them; `tests/Unit/MigrationRegistryTest.php`,
  `tests/Integration/PluginMigrationTest.php`
- **Product:** a plugin ships and evolves its own tables — the storage analytics
  (and forms/search/comments) need.
- **Architecture:** mirrors the capability pattern — shared `MigrationRegistry`,
  provider-scoped `MigrationRegistrar`, rollback via `forgetProvider`. Migration
  names are prefixed with the plugin id (globally unique in `nb_migrations`),
  run **after** core's (a plugin's tables may reference core's). The CLI boots the
  app (no DB touched in the constructor) so plugins declare migrations, then hands
  the registry to the Migrator. Per ADR 0005: own tables only, never core `nb_*`.
- **Engineering:** loader threads the registry as an **optional** 4th arg (keeps
  plugin package tests green); idempotent + integration-tested against a real DB.
- **Watch:** `PluginContext`/`load` arg count is climbing (5/4). A capabilities
  **bundle** should precede C/D to stop the churn (and coordinated plugin-test
  updates). Uninstall/table-drop deferred.

### 2026-08-15 · Capability A built: plugin event subscription + request.handled
- **Status:** accepted (analytics milestone, slice A of A–D)
- **Evidence:** PR (feat/plugin-events); `src/Plugin/EventRegistrar.php`,
  `PluginContext::events()`; `EventDispatcher` (provider tag + `forgetProvider`);
  `CoreEvents::REQUEST_HANDLED`; `Application::notifyHandled`;
  `tests/Unit/EventDispatcherTest.php`, `tests/Http/RequestHandledEventTest.php`
- **Product:** a plugin can subscribe to events, and there is a per-request
  `request.handled` event to subscribe to — what analytics needs to count hits.
- **Architecture:** mirrors the field-type/head pattern — a provider-scoped
  `EventRegistrar` (not the dispatcher), rollback via `EventDispatcher::forgetProvider`.
  Loader threads the dispatcher as an **optional** arg (keeps plugin-markdown /
  plugin-seo package tests green). `request.handled` has **distinct** semantics
  from the entry events (documented in CoreEvents): best-effort, post-response,
  **isolated** — a throwing listener is caught, never 500s a served page.
- **Engineering:** guarded by `hasListeners` so a plugin-free install pays
  nothing; fires for every request, listener filters on path.
- **Revisit:** async/buffered delivery if a listener gets heavy; a `request.handled`
  payload value object if the array shape proves awkward.

### 2026-08-15 · Analytics-portal milestone planned; plugins may own their storage (ADR 0005)
- **Status:** accepted (direction); capabilities designed/built in their own slices
- **Evidence:** [ADR 0005](../../../../docs/adr/0005-plugin-owned-storage.md);
  three-hat analysis this date; maintainer approval
- **Product:** a first-party analytics portal (admin dashboard + charts, first-party
  hit collection) plus third-party agent injection (GA/Fathom/Plausible). Broad,
  general-CMS need.
- **Architecture:** the portal forces **four** new core capabilities, sequenced,
  each minimal and separately reusable: (A) event subscription + a `request.handled`
  event; (B) plugin-owned migrations; (C) scoped storage/data-access to a plugin's
  **own** tables (ADR 0005 — amends the "no DB for plugins" boundary to "no
  **core** DB"); (D) plugin admin pages (route + nav + authed shell render). Agent
  injection reuses the existing head capability (2nd consumer). Charts are
  server-rendered SVG (no JS/asset capability). Core-*data* access is explicitly a
  **later, tiered, operation-level** contract (read model / services + scopes +
  audit — same substrate as MCP), never raw core-table SQL.
- **Engineering:** per-request hit recording must be cheap and skip admin/api/assets;
  privacy-safe (no PII/cookies first-party); plugin SQL via bound-param helpers;
  admin XSS/CSRF; migration ordering.
- **Sequence:** ADR 0005 (this) → plugin-analytics v0.1 (agents, now) → A → B → C
  → D → plugin-analytics v1.0 (portal).
- **Unlocks:** redirects-admin, search, forms, comments, webhooks, activity log,
  revisions — the "observe → store → show an admin view" class of plugins.
- **Revisit:** each capability's concrete contract in its slice; the Tier 1–3
  core-data-access model when a concrete plugin needs it.

### 2026-08-15 · Plugin capability #2: head contributions (ADR 0004)
- **Status:** accepted
- **Evidence:** [ADR 0004](../../../../docs/adr/0004-plugin-head-contributions.md);
  `src/Site/{HeadContributor,HeadContributorRegistry,PageContext}.php`,
  `src/Plugin/HeadRegistrar.php`, `PluginContext::head()`; `PluginLoader` wiring;
  `SiteController` integration; `tests/Unit/HeadContributorRegistryTest.php`,
  `tests/Http/HeadContributionTest.php`
- **Product:** plugins can add markup to a public page's `<head>` (structured
  data, extra meta) — the capability `plugin-seo` needs. First `PluginContext`
  capability beyond field types, added with a concrete consumer (ADR 0001 rule).
- **Architecture:** mirrors the field-type pattern exactly — shared
  `HeadContributorRegistry`, provider-scoped `HeadRegistrar`, rollback via
  `forgetProvider`. Contributor receives a **data-only** `PageContext` (the page's
  view-model), so the contract keeps refusing repositories/DB. Chose head
  contribution over a routes capability as the first extension: it needs no data
  access, where a feed would need routes **and** content-query at once.
- **Engineering:** render-time contributions are **isolated** — a throwing
  contributor is logged and skipped, never 500s a public page (a deliberate
  divergence from the loud, propagating event contract, justified by where it
  runs). `PageContext` is public API now (small, data-only, additive).
- **Revisit:** a **routes** capability (for RSS/Atom, OG-image endpoints) with its
  own consumer; folding SiteController's site-scoped deps into a value object if a
  6th constructor param appears.

### 2026-08-15 · SEO split: foundational meta/sitemap/robots in core, rich SEO a future plugin
- **Status:** accepted (all 3 core slices done: per-page meta PR #44, sitemap.xml PR #45, robots.txt)
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
- **`sitemap.xml`** lists home + browsable collection indexes + live entries
  (excludes `blocks`, single collections, drafts); **`robots.txt`** welcomes
  crawlers, disallows `/admin` + `/api`, and advertises the sitemap. Both
  registered before the `{collection}` catch-all.

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

### 2026-08-15 · Analytics portal ships; four new plugin capabilities proven by one plugin
- **Status:** accepted (milestone complete)
- **Evidence:** [ADR 0005](../../../../docs/adr/0005-plugin-owned-storage.md);
  `nimbuscms/analytics` ([repo](https://github.com/NimbusCMS/plugin-analytics),
  CI green: 21 tests / 51 assertions on PHP 8.2 + 8.3); live Docker verification
  (migration created `analytics_hits`; page views recorded with external referrer
  captured and admin/bot/internal navigation filtered; `/admin/analytics`
  auth-gated); capability-evidence.md rows for events / admin pages / migrations /
  storage / admin navigation.
- **Product:** first-party, privacy-first analytics (path + referrer host +
  timestamp; no cookies/PII) with an admin dashboard, **plus** optional injection
  of a third-party agent (Plausible / Fathom / GA) via env — one plugin, two
  independent uses, both on the public contract.
- **Architecture:** to build it the plugin contract grew four capabilities, each
  added only because this concrete plugin needed it (ADR-0001 discipline) —
  **event subscription** (`request.handled`, best-effort/isolated),
  **plugin-owned migrations + storage** (ADR-0005: own tables only, a contract not
  a sandbox — in-process PHP can't be sandboxed), and **admin pages + nav**
  (GET-only for v1). Reused **head contributions** for the agent snippet.
  `PluginCapabilities` value object bundles the registries so `PluginContext`
  hands out capabilities, never the objects that implement them. Server-rendered
  SVG charts avoided introducing a JS/asset capability.
- **Engineering:** recording runs *after* the response (`request.handled` listener
  is isolated — a throwing listener is logged and skipped, never a 500); storage
  resolved lazily so `register()` runs no query and loads without a DB; dashboard
  rendering is pure and unit-tested without a database; all untrusted values
  escaped in both the dashboard and the agent snippet.
- **Lessons (self-learning):** plugin CI only runs on plugin pushes and the
  boundary test exercises only a plugin's *production* code through HTTP — a core
  change that breaks a plugin's *own tests* stays green until that plugin is
  touched (found via plugin-markdown's second test file). Each of the four new
  capabilities now has exactly **one** consumer: a strong first signal, **not**
  broad proof — do not widen or freeze any of them until a second, unrelated
  consumer appears (see capability-evidence.md "Next evidence required").
- **Revisit:** an admin **form** capability (needs a CSRF-token exposure decision);
  a second storage-owning plugin; the tiered core-data-access contract (Tier 1
  read via read-model, Tier 2 write via services + scopes + audit) when a plugin
  first needs core data — never raw core-table SQL.

### 2026-08-16 · Programmatic Access Hardening milestone complete (API tokens)
- **Status:** accepted (milestone complete)
- **Evidence:** [ADR 0006](../../../../docs/adr/0006-non-human-authentication.md);
  PRs #60 (ADR), #61 (lifecycle/principal), #62 (admin UI), #63 (scopes+matrix),
  #64 (error contract), #65 (rate limiting + CORS). 435 core tests green.
  Governed by the new [`nimbus-security-review`](../../nimbus-security-review/SKILL.md)
  companion skill, which ran on every slice.
- **Product:** the read API is safe for non-human clients — scoped, expirable,
  pausable, revocable tokens; a clean error contract; rate limiting + CORS. This
  is what a static frontend (e.g. on Cloudflare Pages) needs to consume Nimbus
  cross-origin. **Classify: Core** (API maturity) — passed the Platform Drift
  Guard: every headless CMS needs it, independent of any validation app.
- **Architecture:** API tokens are **standalone principals** with their own
  **per-collection `resource:action` scopes**, enforced deny-by-default at the
  query layer; a request-scoped `ApiAuthContext` carries the principal (Request
  stays immutable, no global singleton). Relation expansion respects scope
  (`EntryView` gained an optional `canRead` predicate; out-of-scope targets leak
  nothing, reusing the non-live-target semantics). Two fixed-window rate limiters
  (per-IP flood before auth, per-token quota after), DB-backed. Minimal CORS via
  an `Application::handle` decoration seam (the pipeline only pre-processes).
- **Engineering / security lessons (evidence-linked):**
  - *Scope before existence* (#63): checking scope before collection existence
    stops a narrow token enumerating collections by 403-vs-404. A design decision
    the security review surfaced, not a bug.
  - *Reload-resubmit* (#62): the show-once mint renders (can't redirect a secret),
    so a reload re-POSTed and minted a duplicate — found in **browser** verification,
    not the passing unit tests. Fixed with a single-use `FormNonce` (secret never
    touches session/URL). Lesson: adversarial "what does a reload do?" catches what
    happy-path tests miss.
  - *Ambiguous column* (#65): the `INSERT … AS new … ON DUPLICATE KEY UPDATE`
    row-alias makes a bare column reference ambiguous — qualify the existing row
    (`table.col`). Caught by a direct probe before CI.
  - *Local-env drift:* a stale `vendor/nimbuscms/analytics` (not in the lock) made
    a local-only test failure; `composer install` re-synced. Local should match a
    clean CI install.
- **Revisit / follow-ups:**
  - `nb_api_rate` rows don't self-expire — prune periodically (or a cache adapter).
  - Legacy null-ability tokens are compat-granted `*:read`; **remove that grant
    when the write API lands**.
  - **Failure events** (`api.token_rejected` / `api.access_denied`) were
    deliberately deferred to the `nimbuscms/api-advanced` plugin (their consumer,
    ADR-0001) — isolated + `hasListeners`-guarded, consumer must aggregate (a
    per-request failure event is a DoS amplifier). Building this next gives the
    events + storage capabilities their **second unrelated consumer** (broad proof).

### 2026-08-16 · api-advanced ships → four plugin capabilities broadly proven
- **Status:** accepted
- **Evidence:** core PR #67 (`api.token_rejected` / `api.access_denied` +
  `EventDispatcher::emitBestEffort`); [`nimbuscms/api-advanced`](https://github.com/NimbusCMS/plugin-api-advanced)
  (CI green on PHP 8.2 + 8.3; its `PackageIntegrationTest` loads the package
  through a real Composer install and registers its migration, **both** failure
  listeners, and its admin page).
- **Product:** an official **Advanced API** plugin — a home for programmatic
  "pro" features. First feature: a **security audit log** of API access failures
  (rejected tokens, scope denials), never storing a presented token. A CF-Pages
  frontend + this = a headless deployment an operator can actually monitor.
- **Architecture / the loop closes:** api-advanced is the **second unrelated
  consumer** of the plugin **events**, **storage**, **migrations**, and **admin
  pages** capabilities (after Analytics). All four move from "one consumer — a
  first signal" to **broadly proven** in capability-evidence.md. The failure
  events were emitted **with** their consumer (ADR-0001), not before — the same
  discipline as `request.handled` → Analytics.
- **Engineering:** events are best-effort + isolated (a throwing listener never
  500s) via the shared `emitBestEffort`; `api.token_rejected` fires only after the
  per-IP flood guard, so the rate limiter bounds the audit's write volume —
  the recorder need not aggregate for v1. Payloads carry no secret.
- **Revisit:** a retention/prune helper for plugin-owned tables (`api_audit_log`,
  `analytics_hits`, and core's `nb_api_rate` all accumulate); the other
  api-advanced features on the roadmap (webhooks, per-token analytics/quotas).

### 2026-08-16 · Maintenance capability + `nimbus prune` (table retention)
- **Status:** accepted
- **Evidence:** core PR #69; retention tasks in `nimbuscms/analytics` (PR #1) and
  `nimbuscms/api-advanced` (PR #1), both CI-green.
- **Product:** three tables accumulated with nothing pruning them (core
  `nb_api_rate`, plugin `analytics_hits` / `api_audit_log`). `nimbus prune` (cron)
  now cleans core's own rate rows and runs every plugin retention task.
- **Architecture:** a **seventh** `PluginContext` capability, `maintenance()` —
  and the first capability **born broadly proven**, shipped with two consumers at
  once. Same registry/registrar/bundle/rollback shape as the others; tasks are
  `callable():int` run only by the CLI (no scheduler in core yet).
- **Engineering:** while completing the rollback for the new capability, found the
  loader was **not** rolling back `adminPages` either — a plugin that registered an
  admin page then threw would leave it behind. Rollback is now complete (head,
  events, migrations, adminPages, maintenance, fieldTypes).
- **Revisit:** a scheduler (so `prune` and future tasks run without operator cron)
  is the natural next step, when a task needs it.

### 2026-08-17 · Write API milestone complete
- **Status:** accepted (milestone complete)
- **Evidence:** [ADR 0007](../../../../docs/adr/0007-write-api.md); PRs #72 (version
  + ETag), #73 (endpoints), #74 (`api.entry_written`), plus `api-advanced` PR #2
  (write audit). 457 core tests; a live curl run exercised the real `php://input`
  path. Each slice closed with a `nimbus-security-review` pass — no Critical/High.
- **Product:** the API can now create / update / delete content, not just read —
  a real headless CMS for integrations, CI, and (next) MCP.
- **Architecture — the guiding call that paid off:** the write API is a **new
  transport in front of `EntryService`, not a second write path.** The JSON body
  maps to `EntryInput` and goes through the same service the admin uses, so
  validation, slugs, the transaction, events, and the **allow-list field binding**
  (the mass-assignment guard) are reused, never reimplemented. A single
  `{handle}:write` scope, enforced deny-by-default, scope-before-existence.
  Optimistic concurrency via a monotonic entry `version` → `ETag`/`If-Match`
  (428/412). `api.entry_written` (best-effort) gives `api-advanced` a
  who-changed-what trail.
- **Security (the highest-risk surface, reviewed hardest):** mass-assignment is
  neutralised by the `EntryService` reuse (undeclared fields + top-level
  privileged keys ignored); no enumeration (403 before existence); lost updates
  blocked by mandatory `If-Match`. Accepted low notes: no `415` on non-JSON bodies
  (they read empty → `422`); API-created entries have a null author (a token is
  not a user — the token-level trail is the audit event).
- **Lessons:** the tests inject `rawBody` directly, so they never exercised
  `php://input` — a **live curl** did, and confirmed the real body path. Worth a
  live pass on any transport-layer change. The `api.entry_written` payload uses
  `collection`/`slug` while failure events use `resource`; the audit recorder maps
  both — a reminder to keep event payload keys consistent (a future cleanup).
- **Revisit:** finer `publish`/`delete` scopes; bulk writes; media upload over the
  API; idempotency keys — each on evidence. Next milestones: **OpenAPI**, then **MCP**.

### 2026-08-17 · OpenAPI milestone complete
- **Status:** accepted (milestone complete)
- **Evidence:** [ADR 0008](../../../../docs/adr/0008-openapi.md); PRs #77
  (`jsonSchema()`), #78 (`OpenApiGenerator`), + serving. 466 tests; live curl:
  `GET /api/v1/openapi.json` is 401 without a token, 200 with.
- **Product:** the API now has a machine-readable contract — Swagger UI, typed
  SDKs, and (next) MCP can consume it.
- **Architecture:** the spec is **generated from the live model**, not
  hand-written, so it can never lie about the shapes. Field types **describe
  their own JSON Schema** via a new `FieldType::jsonSchema()` (defaulted in
  `BaseType`, so no field-type or plugin broke — the Markdown plugin inherited the
  default). Served two ways: `GET /api/v1/openapi.json` behind the group's bearer
  auth (inside the rate-limited group), and a `nimbus openapi` CLI dump.
- **Security:** the endpoint is auth-gated and rate-limited. Accepted low: it
  serves the **full** model regardless of the token's scopes (a scope-filtered
  per-token spec is a documented later refinement — it would vary per caller and
  break caching).
- **Revisit:** a bundled Swagger UI page; a scope-filtered spec; OpenAPI 3.1.
  **Next:** MCP, deriving its tool list from this contract.

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

### 2026-08-17 · MCP Slice 1 — capability model + shared EntryOperations (Core)
- **Merged:** PR #81 (code), #82 (roadmap). CI-green, 469 tests, PHPStan L6.
- **Decision:** the scope-checked content path is one service (`EntryOperations`)
  that both HTTP and MCP call; `ApiController` is now a thin HTTP adapter. The
  extraction was behavior-preserving — the entire existing API suite passed
  unchanged, which is the evidence the shared path did not weaken authz,
  concurrency, mass-assignment binding, or auditing.
- **Capability model:** `admin` super-grant + granular management capabilities
  (schema/media/users/tokens/settings) as the atoms of a future roles system.
  They are inert until Slices 4–6 consume them.
- **Assumption corrected in review (not after):** management capabilities sharing
  the `resource:action` namespace let the content wildcard `*:write` transitively
  grant `users:write` etc. Fixed so `*:{action}` is collection-only; `admin` is
  the sole cross-cutting grant. Lesson: when a new privilege class joins an
  existing namespace, re-examine every wildcard/`*` rule that spans it. Recorded
  in the security ledger (scope confusion, catalog #2, 1st sighting).
- **Design note (validated later):** the shared-service extraction as the *first*
  MCP slice is paying off — Slice 2 adds only a JSON-RPC transport over an
  already-authz/concurrency/audit-complete service, not a second content path.

### 2026-08-18 · MCP Slice 4 — schema tools + the Toolset seam (Core)
- **Decision:** management tools plug in via a `Toolset` interface the `McpServer`
  aggregates (management-first ordering, so a fixed name like `create_collection`
  is claimed before a content verb could parse it). `ContentToolset` now
  implements it; `SchemaToolset` is the first management group. This is the seam
  Slices 5–6 extend without touching existing toolsets.
- **Decision:** schema tools reuse the admin's `CollectionService` (one write
  path); field-level tools read-current→mutate→re-sync (safe), with `set_fields`
  the deliberate full-replace power tool. Destructive `delete_collection` gated by
  `confirm:true` + surfaced entry count (Dan's call: a real need exists).
- **Decision:** added a `version` column to `nb_collections` now (bumped on every
  shape change), so a read-before-write guard on schema can land later without a
  migration then. Guard deferred; column tracks + is surfaced in `describe_collection`.
- **Lesson (self-learning):** the HTTP suite passes against a **migrated** DB and
  masked an ordering gap — a live smoke against the un-migrated dev DB 500'd on
  `add_field` because `repo->update` writes the new `version` column. `create`
  masked it (INSERT omits the column → default). Takeaway: any slice adding a
  column + code that writes it must be smoked against a freshly-migrated env, and
  the deploy release gate (ADR 0010) must run `migrate` before serving. No code
  defect; a process signal.

### 2026-08-18 · Slice 5a — media usage tracking as a CORE capability (Core)
- **Decision (user-driven):** "don't let users delete media that's in use." Built
  as a **core content-integrity capability**, NOT MCP logic — a reverse index
  (`nb_media_usage`, migration 009) synced by EntryService on save (mirroring
  relations), a `MediaUsage` query service, and a shared `MediaService::delete`
  guard that **blocks + pinpoints** (returns the referencing entries/fields). The
  admin, API and MCP all inherit it via the one delete path. Rationale: keeping the
  guard in a shared service (not the MCP tool) means the admin is protected too and
  honors "MCP adds no business rules".
- **Scope boundary:** "used" = referenced by a media **field** (structured,
  indexable). A raw media URL pasted in freetext is deliberately out of scope
  (unreliable to detect). Stated in the migration + guard.
- **Delete semantics (user's call):** block when in use, never silently orphan;
  the caller clears the reference first (via normal edit) then deletes. Structured
  usage is returned so a future explicit "detach optional fields + delete"
  convenience can consume it (a required media field can't be nulled — must be
  reassigned; noted for later).
- **Design note:** `media_id` is not an FK (dangling refs are legitimate and must
  not fail a save); entry/field FKs give the cascades that free media automatically.
- **Backfill:** `nimbus media:reindex` rebuilds the index for pre-existing content.
- **Ops lesson:** correcting an already-applied local migration means resetting it
  by hand — drop the table + `DELETE FROM nb_migrations WHERE migration='NNN.php'`
  (column is `migration`, not `name`); the test DB (`nimbus_test`) needs the
  root creds from tests/bootstrap.php, not the app user.

### 2026-08-18 · MCP Slice 5b — media tools (Core/MCP)
- **Decision:** `MediaToolset` on the shared seam (ordered Schema→Media→Content so
  `list_media`/`delete_media` are claimed before a content verb could parse
  them). Tools: `upload_media` (base64), `list_media`, `get_media`, `delete_media`
  (via the Slice-5a guard — block + pinpoint), and `media_usage` (read, so an
  agent can check before deleting). Gated `media:read`/`media:write`.
- **Upload:** base64 → temp file → the admin's MediaUploader with a **copy mover**
  (not move_uploaded_file). All validation reused (finfo sniff + allow-list + random
  name + size cap). get_media returns metadata + URL, not bytes (byte read-back
  deferred; the public URL already serves the file).
- **ToolResult gained an `extra` param** so a structured error (the in-use usage
  list) rides on the error object — used by delete_media's `in_use`.
- **Milestone note:** Slices 1–5 done — MCP is now a working control surface for
  content, schema and media over both transports. Remaining: users/tokens/settings
  (S6), management-audit recording + docs + final review (S7).

### 2026-08-18 · MCP Slice 6 — users + tokens; settings DEFERRED (Core/MCP)
- **Discovery:** `nb_settings` (key/value) exists but is **unused** — site config
  lives in `config/*.php` files. So there is no settings store to expose, and
  writing PHP config from an agent is the wrong approach. **`settings:write`
  deferred** to a future slice that first builds a real DB-backed settings store
  (activate `nb_settings`, migrate a few values out of `site.php`). The scope
  stays reserved. This is the review-loop working: don't build a tool for a store
  nothing reads.
- **Delivered:** `UsersToolset` (`users:write`: list/create/set_role) + a small new
  `UserRepository`; `TokensToolset` (`tokens:write`: list/mint/revoke/pause/resume).
  On the seam as [Schema, Media, Users, Tokens, Content].
- **Key control:** mint = subset-only (can't grant scopes you don't hold) — the
  RBAC substrate. Secrets/passwords are show-once (never persisted/audited/logged).
  `create_user` password optional → strong generated one returned once; roles
  validated; last-admin demotion refused. `delete_user` deferred (sharp; rarely
  agent-driven).
- **Reuse:** `Password::isWeak` extracted from the installer's rule (shared floor).
- **Milestone:** Slices 1–6 done — MCP reaches content, schema, media, users and
  tokens. Remaining: S7 (api-advanced records `api.management_written` + docs +
  final review) and the deferred settings-store slice.

### 2026-08-18 · MCP milestone CLOSE — final three-hat review (Core)
- **Product:** MCP is a general agent-control surface for *any* Nimbus site
  (content/schema/media/users/tokens), not shaped to Restaurant/Foodmart — the
  "MCP-native" differentiator. Passes the Platform Drift Guard.
- **Architecture:** Core. The `Toolset` seam + shared services (`EntryOperations`,
  `CollectionService`, `MediaService`/`MediaUploader`, `UserRepository`,
  `ApiTokenRepository`) mean MCP adds a transport + generated tool list, never
  business logic — one backend, two transports (HTTP + stdio). Capabilities are
  the RBAC substrate. No app-shape assumptions.
- **Engineering:** 511 core tests + the plugin's audit tests, PHPStan L6, a
  security-review per slice + this composition pass. Writes transactional; no N+1
  introduced.
- **Slices 1–7 done.** `settings:write` deferred to a future DB-backed settings
  store (nb_settings is unused; config is file-based). ADR 0009 → Implemented.
- **Enables:** agent-run CMS; the capability model bundles into named roles later.
- **Makes harder:** the largest privilege surface in the product — mitigated by
  deny-by-default caps, mint-subset-only, full audit, and per-slice review.

### 2026-08-18 · Roles Slice 1 — store + shared Authorizer + seed (Core)
- **Delivered:** `nb_roles` + `nb_user_roles` (migration 010); `Role`/`RoleRepository`;
  a shared `Authorizer` (extracted from `TokenPrincipal::can()`, now delegated) used
  by a new `UserPrincipal` whose capabilities are the **union** of its roles;
  `RoleSeeder` (system roles admin/editor/author, folding collection manage-lists
  into caps, assigning users) run by `install` + `nimbus roles:seed`.
- **Compat bridge:** enforcement is UNCHANGED (Slice 3 flips it). The seed is
  behavior-preserving — editor/author get `*:read` + their granted `{handle}:write`,
  each user their current role → union == today's access. Verified on dev.
- **Decision realized:** `{handle}:write` implies `{handle}:read` for content (in
  the Authorizer); `roles` added to the management set (subset-only later).
- **Tooling:** composer `process-timeout` bumped to 900 (suite > 5 min; CI runs
  phpunit directly so was unaffected).
- **Next:** Slice 2 (roles admin UI + user assignment + users page).

### 2026-08-19 · Roles Slice 2 — roles + users admin UI (Core)
- **Delivered:** `RolesController` (`/admin/roles`, admin-only nav) — CRUD roles as
  a grouped capability checklist (Full admin / Content per-collection read+manage /
  Administration), built-in roles protected; `UsersController` (`/admin/users`,
  fills the pre-existing dead nav slot — removed the AdminController stub) — create
  users (email/name/password + roles) and assign roles, last-admin guard. Reuses
  `RoleRepository`/`UserRepository`/`Password::isWeak`.
- **Scope call:** the collection-creation "grant manage to: [roles]" shortcut was
  MOVED to Slice 3 (it's coupled to the enforcement flip + `managerRoles` retirement;
  building it now would mean dual-writing during the transition).
- **Still enforcement-inert:** both pages gate on `requireAdmin` (legacy); assigned
  roles/edited caps are not yet the enforcement source (Slice 3 flips it). Safe:
  under-grants until then.
- **Verified:** 531 tests (9 new) + live browser check of both pages.
- **Next:** Slice 3 (the risky heart) — migrate `Permissions`/`requireAdmin`/
  `canManage` to `can()` over the user's roles; retire `managerRoles`; add the
  collection "grant to roles" shortcut; require the seed has run.

### 2026-08-19 · Roles Slice 3 — the enforcement flip (Core) + both review loops
- **Delivered:** admin authorization moved to capabilities via a per-request
  `Gate` (lazy user resolution via `Auth`, memoized). `requireAdmin→requireCan`
  (schema/tokens/users/roles:write; plugins→admin); `canManage→Gate::manages`;
  nav gated per-cap; `Permissions::canView` deleted; collection form's dead
  managerRoles picker replaced by a hint to the Roles page (`managerRoles` dormant).
- **Both review skills materially improved the design pre-build:**
  - review-loop caught that `canView` is dead code → do NOT newly read-gate the
    collections list (would silently tighten); and replaced my leaky per-user
    fallback with an **all-or-nothing legacy fallback** (un-seeded → legacy
    Permissions verbatim), which also lets the existing suite pass in legacy mode.
  - security-review escalated **A2 (assignment subset-only)** from a design
    question to a **required High control**, and required the authorization-matrix
    test. Media-gating deferred as a tracked Medium.
- **Behavior-preserving proof:** full suite green (538). Test helpers updated
  (actingAs assigns the system role; makeCollection grants role caps) so seeded-mode
  tests reflect the real model; a dedicated un-seeded-fallback test covers legacy.
- **Lesson:** an enforcement *model flip* is behavior-preserving at the *production*
  layer (via the seed + fallback), but the test suite's authz *setup* must migrate
  — the fallback made that far cheaper than feared.
- **Next:** Slice 4 (roles for tokens) + the media-gating fast-follow.

### 2026-08-20 · Roles Slice 4 — roles for tokens (LIVE reference) [Core]
- **Classification:** Core (the payoff of the ADR 0009 capability model: one
  authority vocabulary now spans users *and* tokens). Reviewed via both skills
  pre-build; both endorsed **LIVE** over SNAPSHOT.
- **Delivered:** a token can be minted **bound to a role**; its authority is the
  role's *current* capabilities, unioned with any explicit abilities, resolved in
  **one place** — `ApiTokenRepository::principalFor()` — called by both the HTTP
  middleware and the `nimbus mcp` stdio path (DRY across transports). Migration
  011 adds `role_id` (FK ON DELETE SET NULL). Surfaces this slice: CLI
  `token:create --role`, MCP `mint_token` role param, role shown in listings;
  admin token-form dropdown deferred (Slice 4b).
- **LIVE vs SNAPSHOT (the load-bearing call):** LIVE chosen — tightening/deleting a
  role reaches its tokens immediately (central partial revocation), consistent with
  how roles already work for users. Its extra cost (a schema column + a resolution
  point + the deleted-role edge) is paid down by keeping *stored* abilities the
  explicit set and computing *effective* caps only at the one boundary. SNAPSHOT
  would have frozen caps at mint and forced per-token revocation on every role edit.
- **Security-required simplification:** removing the legacy empty→`['*:read']`
  grant is what makes deleted-role fail *safe* (deny, not read-all) — the review
  turned a compat cleanup into a correctness requirement. See security-ledger
  2026-08-20.
- **Coupling check:** `ApiTokenRepository`→`RoleRepository` is acceptable — token
  resolution *is* an authorization concern; the alternative (a snapshot) only moves
  the coupling to mint time and loses the live property.
- **Evidence:** full suite (548) green; PHPStan clean; `docs/COMPATIBILITY.md`
  documents the null-ability behavior change.
- **Next:** Slice 4b (admin token-form role dropdown) + Slice 3b (media gating) +
  Slice 5 (docs/ROLES.md + final security review).

### 2026-08-20 · Roles Slice 3b — gate admin media + first data migration [Core]
- **Classification:** Core (authorization enforcement on a core admin surface + a
  system-role seed). Behavior-preserving. Reviewed via both skills pre-build.
- **Delivered:** `/admin/media` moved from auth-only to `media:read`/`media:write`
  (per-action), matching the MCP media tools; nav + dashboard media card gated;
  editor/author keep media via `RoleSeeder::CONTENT_MEDIA_CAPS` (fresh) + migration
  012 (existing installs). PR #100 (4bd2eeb), 562 tests green.
- **New pattern — the first DATA migration.** Precedent set and bounded: additive
  JSON union on **system** roles only, `JSON_CONTAINS`-guarded (idempotent),
  `is_system=1 AND name IN(...)`, never removes, runs once. The "runs once
  (tracked in nb_migrations)" property is what makes an additive backfill safe
  against a later admin edit — a reusable rule for future behavior-preserving
  backfills.
- **Lesson reinforced (2nd time, after Slice 3):** a behavior-preserving
  enforcement change must migrate the **test helpers** in lockstep — `actingAs`
  AND `makeCollection` base maps both had to gain the media caps, and their
  ordering (which creates the role first) mattered. Test-setup drift is the
  recurring cost of these flips; a shared source (`CONTENT_MEDIA_CAPS`) + a parity
  test is the mitigation.
- **Key correctness fact captured:** management caps have **no** read↔write
  implication (only content does), so a media surface needs BOTH `media:read` and
  `media:write` seeded — seeding only write would silently break listing.
- **Deferred (unchanged):** Slice 4b-UI (token-form role dropdown, rides the admin
  redesign), Slice 5 (authz-matrix docs + `docs/ROLES.md` — the milestone closer).
  Noted a pre-existing minor issue out of scope: the dashboard **Users** card is a
  dead link for a non-`users:write` user (same class as the media card, not fixed
  here).

### 2026-08-20 · Roles milestone CLOSED (Slice 5) [Docs + closer]
- **Classification:** Tooling/Docs (secondary: a trivial Core dashboard fix). PR
  #102 (9b291e1). Milestone marked done in ROADMAP.
- **Delivered:** `docs/ROLES.md` (guide + authorization matrix pointing at the
  tests as source of truth), README + COMPATIBILITY roles notes, ADR 0011 →
  Implemented, a final holistic security sweep (no open High), the
  management-boundary test extended to `roles`, and the dashboard Users-card
  dead-link fix. 563 tests green.
- **Stability call (Q1):** promise the **model and guarantees** (deny-by-default,
  the management boundary, subset-only, undeletable system roles) but mark the
  capability **names + role schema evolving until 1.0** — same framing as the MCP
  tool set. A closer documents; it does not freeze.
- **Matrix as docs (Q3):** the tests ARE the authorization matrix; the doc is a
  readable VIEW that links them. No generated-doc mechanism, no second asserter —
  seeded-cap claims are already guarded by RoleSeederTest, enforcement by
  RolesEnforcementTest. Reusable rule: a doc that restates a security guarantee
  must cite the test that enforces it.
- **Milestone retrospective (evidence-linked):**
  - The **one shared `Authorizer`** (Slice 1) paid off across every later slice —
    users, tokens, admin, MCP, CLI all judge by it; the management short-circuit
    is the single invariant that keeps content-only actors out of management.
  - **Subset-only** generalized cleanly from one mint control (ADR 0009) to five
    surfaces via one predicate shape (`firstUnheld`/`holds`) — the escalation-at-
    mint threat-catalog entry (promoted after Slice 4/4b) is now standing.
  - Recurring cost, seen 3× (Slices 3, 3b, and the 4b security fix): a
    behavior-preserving enforcement flip must migrate the **test helpers** in
    lockstep. Mitigation adopted: shared constants (`CONTENT_MEDIA_CAPS`) + parity
    tests + the un-seeded legacy fallback that let the suite pass mid-migration.
  - The **first data migration** (012) established a safe pattern: additive,
    idempotent, scoped, once-only.
- **Deferred/carried:** Slice 4b-UI → Admin Experience initiative; future
  `settings:write` gate; plugin admin-page self-gating (Low, plugin boundary).

### 2026-08-20 · Admin Experience — drift-guard + Increment 1 [Tooling / Theme]
- **Initiative:** turn Fable's `docs/design/admin-experience.md` into a uniquely-Nimbus, themeable admin. Drift-guard passed: a themeable admin + per-user theme preference is a general-CMS good (every install benefits), opt-out-able via the token layer, admin-only, no framework/asset-pipeline. Classification per part: token refactor = **Tooling**; signatures + the four themes = **Theme**; the theme-system mechanism + picker = small **Core** (+ a security surface at the picker's write).
- **Increment 1 delivered** (PR #104, commit ad09d14): `theme.css` refactored onto the `--nb-*` token set (each default = the current literal → no *unintended* visual change), the substrate the theme system needs. Four confirmed defects fixed (undefined `var(--nb-border)` with no fallback; duplicate conflicting `.nb-check`; used-but-undefined `.nb-link`; phantom Inter/Sora font vars) + a11y (`:focus-visible`, `prefers-reduced-motion`, `aria-current`). Zero security surface. 563 tests green; verified live across login/dashboard/tokens/collections.
- **Review calls that held:**
  - "Zero visual change" was corrected to "no *unintended* change + N named, verified deltas" — the honest framing that makes a screenshot-diff a real gate, not a rubber stamp. Standing lesson for re-skin work.
  - Sequencing: pure-CSS increments 1–2 carry no security surface; the picker's `nb_users.theme` write (Increment 3) is gated behind `nimbus-security-review`. Don't security-review a CSS refactor; do review the write path.
  - Budget honesty: Fable's "weight-neutral ≤18 KB base" estimate was optimistic — the expanded token set lands the base at ~19 KB. Kept the substrate tokens and corrected the budget comment; total-with-themes still ≤24 KB (the binding ceiling). Lesson: verify a design's stated weight against the actual bytes, don't inherit the estimate.
- **Live-DB gotcha recurred:** the dev DB on :8080 was two migrations behind (011/012 unrun) → a `role_id` PDOException on the tokens page that looked like a regression but wasn't. `nimbus migrate` on the live dev DB before smoke-testing, every time a slice adds a column.
- **Next:** Increment 2 (signatures, pure CSS), then Increment 3 (theme system + picker, security-gated) which also lands Slice 4b-UI.

### 2026-08-20 · Admin Experience — Increment 2 (signatures) [Theme]
- **Delivered** (PR #106, commit 55ead28): the five signature elements (Sky glow + twinkle, Charm Line, constellation empty states, the token Reveal, the "summoned in N ms" Whisper). Pure CSS + three template tweaks (`NB_START` in `public/index.php`, the Whisper in `layout.php`, the Reveal copy in `tokens/index.php`). No security surface — verified inline (no auth/write/SQL/user-input). 563 tests green.
- **Review posture:** the initiative-level drift-guard already blessed Increment 2 specifically (pure-CSS Theme, security deferred to Increment 3), so this was execution, not a new design decision — built directly rather than re-running the skill (signal-not-ceremony). Security-review correctly N/A here.
- **Verified LIVE** (browser): Charm Line under every h1, horizon glow + Whisper on the dashboard, the constellation on the empty media panel, and a real token mint showing the gold-washed Reveal. Screenshots are the gate for presentation work.
- **Guard that held:** both new animations are compositor-only and dead under `prefers-reduced-motion` via Increment 1's global guard — no per-animation reduced-motion code needed.
- **Budget watch:** base CSS now 21.4 KB (< 24 KB ceiling). The three additional theme blocks (Nocturne/Daybreak/Grimoire; Nimbus is the base :root, not a block) must stay tight (~1.1 KB each) to hold 24 KB — will trim base comments in Increment 3 if the real total needs it.
- **Next:** Increment 3 (theme system + Nocturne + the Settings picker) — the first security-gated increment (the `nb_users.theme` write), and it carries Slice 4b-UI.

### 2026-08-20 · Admin Experience — mobile-first revision + M1 [Theme]
- **Trigger:** a mid-build mobile audit (Dan asked before starting the theme system) found the admin was desktop-first with one breakpoint; three list tables (roles/users/tokens) rendered bare → page-level horizontal scroll on phones. Dan's call: **mobile is a first-class user** — now a codified standing check (PR #108).
- **Design:** Fable revised `docs/design/admin-experience.md` in place (new §1.6 Responsive: strategy, the nav-drawer centerpiece, the two-tier table fix, forms/touch, signatures on mobile, a byte-budget ledger, staged M1–M4). Drift-reviewed and blessed with corrections.
- **Review calls that held (evidence for future mobile work):**
  - **Desktop-default CSS + two `max-width` blocks**, not a min-width rewrite of 350 live lines — the honest architecture; mobile-first is the *design doctrine*, not a mechanical mandate.
  - **CSS-only checkbox-hack drawer** (the sidebar slid off-canvas, Sky intact) is the right default over a JS `button+aria-expanded`; the "Menu, checkbox" SR announcement + no-focus-trap + no-scroll-lock are acceptable lightweight trades, with the JS fallback kept documented as a one-step escalation — don't gold-plate a hypothetical.
  - **Byte ceiling is a guardrail, not a religion:** do zero-loss cuts first (comment diet, drop back-compat aliases), and **raise the 24 KB ceiling a hair (still ~5 KB gzipped) before cutting M4** (a real UX win). Reordered Fable's ledger accordingly.
  - **Tier-1 (wrap all) is the mandatory floor; Tier-2 stacked cards (M4) is cuttable** — ship the floor first, don't block on the upgrade.
  - **MCP standing check = N/A** for the M-track (pure admin-chrome presentation, no new back-end capability); the **mobile** check is the one in force and this design satisfies it. Security-review N/A for M1–M4 (no auth/write/SQL/untrusted input; the drawer JS takes no input; `data-label` is `$e()`-escaped) — but Increment 3's theme picker (`nb_users.theme` write) still needs it.
- **M1 delivered** (PR #109, commit bcaaa7a): wrapped roles/users/tokens tables. Templates only, byte-identical CSS, no security surface. **Verified live at 375px AND 320px** — `overflowByPx = 0`, table scrolls in-panel. Shipped ahead of the theme system (highest value-to-risk in the initiative).
- **New standing rule (from §1.6.4):** a bare `.nb-table` in a template is a bug.
- **Next:** M2 (forms/touch/spacing CSS), M3 (drawer), M4 (stacked cards) — interleave with theme Increments 3–4.

### 2026-08-20 · Admin Experience — mobile M2 + M3 [Theme]
- **M2** (PR #111, pure CSS): grid collapse, 16px inputs (iOS focus-zoom guard), ≥44px touch targets, stepped spacing, page-head wrap. `theme.css` 21,442 → 22,909 B. Verified live 375/320px.
- **M3 — the nav drawer** (PR #112, commit 1c79f90): the phone-native win. Replaced the clipping horizontal rail with a **CSS-only off-canvas drawer** — the sidebar slides in via a checkbox-hack (hidden `<input>` + `<label>` hamburger + `<label>` scrim), the whole Sky + Whisper riding along; a 6-line Escape/focus-return script; legacy rail deleted. Verified live: open → transform none + scrim + Whisper; closed → off-canvas + `visibility:hidden` (no ghost tab stops); overflowByPx 0. No security surface (no user data in the markup; the script takes no input). 563 tests green.
- **Budget lesson (evidence for the theme increments):** the drawer hit the 24 KB ceiling (24,543 B), so the review's recovery order fired **now, not later**: cheap zero-loss cuts first — **dropped the two back-compat aliases** (`--nb-night`/`--nb-shadow`, unused since Increment 1 repointed usages) + a **comment diet** — landing 23,347 B with real headroom. The pattern: when a CSS increment bumps the ceiling, run the zero-loss levers immediately; state `wc -c` on every CSS PR. The theme blocks (Increment 3–4) still need tightening or a small ceiling raise — comment-diet + aliases alone won't hold 3 full theme blocks; prefer a hair-higher ceiling (imperceptible gzipped) over cutting M4.
- **Live-verification technique:** the browser JS console (`getComputedStyle` on `.nb-side` transform/visibility, `matchMedia`, `overflowByPx`) is the reliable gate for a CSS-only interaction like the drawer — but read AFTER the transition settles (a mid-transition read shows the interpolated value and misled once).
- **Next:** M4 (stacked cards, cuttable) + the theme system (Increment 3, security-gated for the picker write).

### 2026-08-20 · Admin Experience — mobile M4 + M-track COMPLETE [Theme]
- **M4** (PR #114, commit bdfa984): stacked-card reflow for `entries` + `tokens` (`.nb-stack` + `td[data-label]::before`), scoped to the two real phone jobs; the other four tables keep the M1 scroll-wrap. `data-label`s are `$e()`-escaped → no security surface. `display:block` strips table semantics at mobile only (the correct linear label→value SR read). `theme.css` 23,347 → 24,100 B. Verified live 375px. 563 green.
- **The mobile-hardening M-track is COMPLETE** (M1 wrap tables → M2 forms/touch/spacing → M3 the drawer → M4 stacked cards). The admin is phone-native: no page-level horizontal scroll at 320px anywhere, a real off-canvas nav drawer that keeps the Sky identity, collapsing forms, ≥44px targets, and card-reflowed tables for the two phone-critical lists.
- **Milestone retrospective (evidence for future mobile work):**
  - The audit-driven pivot worked: the maintainer's instinct ("check mobile, especially tables") found real breakage a desktop-first design had shipped; codifying the **mobile standing check** (PR #108) makes it structural, not luck.
  - **Fable revising the existing spec in place** (vs a new doc) kept one source of truth and let the existing `theme.css §2` references stay valid — good pattern for design updates.
  - The **CSS-only checkbox drawer** delivered a first-class mobile nav with 6 lines of JS and no framework — evidence that "lightweight" and "good mobile UX" aren't in tension when the design reuses existing structure (the drawer IS the sidebar).
  - **Byte budget:** the whole mobile track (M1–M4) net **+658 B** on `theme.css` (21,442 → 24,100) after the M3 alias-drop + comment-diet recovery — the two ambient signatures and the drawer fit inside the envelope. The theme increments are where the ceiling is genuinely decided.
- **Next:** the theme system (Increment 3 — Nocturne + the Settings picker, security-gated for the `nb_users.theme` write; carries Slice 4b-UI) then Increment 4 (Daybreak + Grimoire). The stacked-card CSS is the designated cut if the theme blocks force the 24 KB ceiling.

### 2026-08-20 · Admin Experience — Increment 3 (theme system + Nocturne) [Core + Theme]
- **Delivered** (PR #116, commit 8cf6626): the token-only theme system (`AdminTheme` allow-list, `data-theme` render wiring), Nocturne (dark mode), and the Settings swatch picker with a security-reviewed self-only `nb_users.theme` write. Removed the dead settings stub + `stub.php`. 571 tests green. Verified live incl. Nocturne on the mobile drawer.
- **Classification:** Core (small — a persisted per-user preference + a new controller/write path) with a Theme component (Nocturne palette). Dedicated `SettingsController` (not folded into AdminController) — matches the one-controller-per-section pattern; Settings will grow.
- **Byte-ceiling decision (maintainer-relevant):** raised the documented ceiling **24 → 28 KB**. The original 24 KB was an *estimate* from before the ~60-token layer; four full themes legitimately need the bytes; inlined CSS gzips to ~5.5 KB with zero extra requests, so the charter's "fast/lightweight" (about architecture: no framework/build/webfonts/requests) is untouched. A design-doc figure, not a principle — surfaced in the review + PR, not changed silently. Lesson: a stated byte *budget* is a guardrail to force economy; when an estimate is proven obsolete by real, justified content, correct the figure openly rather than cutting UX to defend it.
- **The MCP standing check's first real test:** correctly classified a **per-user theme preference as N/A** (presentation preference, not a management capability) — validating the codified exemption. The distinct future *site-settings* store WILL be MCP-relevant.
- **Token-only theming proved out end-to-end:** Nocturne is a pure `[data-theme]` token override; the component CSS, the mobile drawer, the stacked cards, and the signatures all inherited dark mode with zero per-theme selectors — the discipline (§2.2) held. This is the evidence that the four-theme plan is cheap.
- **Next:** Increment 4 (Daybreak + Grimoire + the 8-line preview JS) — no new mechanism, just two more token blocks + swatch chips. Slice 4b-UI separately (its own security surface).

### 2026-08-20 · Admin Experience — Increment 4 (Daybreak + Grimoire); theme track COMPLETE [Theme]
- **Delivered** (PR #118, commit a3ffda2): the last two themes (Daybreak — dawn blue/sun-gold, light; Grimoire — bottle-green/parchment/brass, warm), the swatch chips, and the 8-line instant-preview JS. Pure token blocks + a progressive enhancement; no new mechanism, no new write path/security surface. `SettingsThemeTest` now selects+renders all four. 572 tests green. All four verified live on the dashboard.
- **Theme track COMPLETE:** Inc 1 (token layer) → Inc 2 (signatures) → Inc 3 (theme system + Nocturne + picker) → Inc 4 (Daybreak + Grimoire). The admin ships four selectable, per-user themes on one component layer.
- **Byte budget — final resolution:** the ceiling moved 24 → 28 → 30 KB across the theme increments as the true cost of the ~60-token layer + four full themes became known; now **settled at 30 KB** for the complete set (a fifth theme must prove its bytes). `theme.css` 29,293 B (~6 KB gzipped, zero requests). **Lesson:** a byte *estimate* made before the token layer existed was wrong twice; the honest move each time was cheap-cuts-first (two comment diets, dropped aliases) then correct the figure openly — never cut a shipped feature or a theme to defend a guessed line. The charter's "lightweight" is architecture (no framework/build/webfonts/requests), which held throughout.
- **Token-only theming — proven at full scale:** four maximally-different palettes (two light, one dark, one warm) all ride the *same* component CSS, drawer, stacked cards, and signatures with **zero per-theme selectors** (the one bounded exception: picker swatch chips). §2.2's discipline delivered exactly what it promised — this is the capability-evidence that the theme mechanism is cheap and extensible.
- **Next:** Slice 4b-UI (token-form role dropdown) is the last open initiative item — separate, small, its own security surface, now buildable in the finished design language.

### 2026-08-20 · Roles Slice 4b-UI + Admin-Experience initiative COMPLETE [Core + initiative wrap]
- **Slice 4b-UI** (PR #120, commit e0918e4): the admin token-form role dropdown — mint a role-bound token from the web form, grantable-roles filtered, server-side subset-only over the full role cap set (union guarded on both scope + role paths), the bound role shown in the list. Security-green (7 tests). 579 tests green. Verified live in Nocturne. **Completes ADR 0011 roles-for-tokens across admin + CLI + MCP.**
- **Deferred-then-built cleanly:** 4b was split at design time into 4b-security (the load-bearing control, shipped immediately) and 4b-UI (the visuals, deferred to the finished design language). Building the UI last — after the token layer, signatures, mobile, and themes existed — meant it landed once, in the real design system, with the security control already in place and tested. Evidence that "split the security fix from the UI, ship the fix first" is a good pattern.
- **ADMIN-EXPERIENCE INITIATIVE COMPLETE.** Theme track (Inc 1 tokens → Inc 2 signatures → Inc 3 theme system + Nocturne → Inc 4 Daybreak + Grimoire) + Mobile track (M1 tables → M2 forms → M3 drawer → M4 stacked cards) + 4b-UI. The admin went from generic chrome to a uniquely-Nimbus, four-theme, phone-native experience — every slice through both skills, verified live, lightweight (≤30 KB inlined CSS, no framework/build/webfonts).
- **What the initiative proved (capability evidence):** token-only theming scales to four maximally-different palettes with zero per-theme selectors; a CSS-only drawer gives first-class mobile nav with ~6 lines of JS; "mobile is a first-class user" and "MCP-friendly" are now codified standing checks that will guard every future slice.
- **Remaining Nimbus threads (unrelated to this initiative):** the settings-store slice (site `settings:write` + MCP tool), plugin admin-page self-gating (Low), and the deferred "Auto"/"Owl" themes — all noted, none blocking.

### 2026-08-22 · Post-initiative small follow-ups: plugin gating + Auto/Owl themes + docs [Core + Theme + Docs]
- **Docs (PR #122):** README announces the **alpha (0.x)** stage + current feature set (roles, MCP, six-theme mobile-native admin), honest caveats (no tagged release / upgrade path / password reset).
- **Plugin admin-page capability gating (PR #123, Core-small):** optional required capability on plugin page registration, validated to admin/management at the boundary (the footgun-closing control), enforced at the route + nav; BC-preserving. Both skills; security-green. Reusable pattern: validate a code-path-selecting value at the boundary. Option-B (plugin-defined caps) recorded, not built.
- **Auto + Owl themes (PR #124, Theme):** completes a **six-theme** set. Auto = the one media-driven theme (inherits Nimbus in light, Nocturne under `prefers-color-scheme: dark`); Owl = high-contrast (handled the gold-on-black coupling: bright-amber `--nb-gold`, stars off, heavier focus). Token-only; verified live in both color-schemes.
- **Byte ceiling 30→32 KB (third adjustment), now with a stated exit:** each theme's real cost only shows when it lands; the file header now says a **7th** theme must override *only* differing tokens (a lean block) or move themes to a separate concern — not another blanket raise. Cheap cuts first each time (three comment diets, dropped aliases + section headers). ~6.5 KB gzipped, zero requests — the charter's lightweight (architecture) holds. **Lesson: when content legitimately grows a guardrail, correct the figure openly AND record the condition that stops the next raise.**
- **Flake noted (not caused here):** one full-suite run showed a single failure that vanished on re-run — a pre-existing timing/rate-limit flake, unrelated to CSS/allow-list changes. Candidate to stabilise later.

### 2026-08-22 · Settings store (site.home, site.description) [Core]
- **Classification: Core** — a general-CMS capability (every site has editable site-level config); passes the Platform Drift Guard *without* the validation apps (a CMS needs a home page + default meta regardless of Restaurant/Food Store). Ships the smallest slice that proves the store: two settings, one small table, per-request-memoized reads.
- **Design-first, both skills before code** (Dan's standing rule). Review-loop verdicts adopted verbatim: (Q1) **typed registry**, not free key/value — the allow-list is the safety property *and* it drives the admin form + MCP schema from one source, and it's not over-engineering at two keys; (Q2/boundary) **deploy/env config stays in files, admin-editable site content goes in the DB** — codified in `principles.md` (Config stays DB-free); (Q3) **file-as-default + DB-override, no seed migration** (BC: fresh install works from the file, a set value wins) over a seed-from-file migration; (Q4) **ship home + description, defer the site title** (`APP_NAME`, ~8 consumers — a broad change, fast-follow); (Q5) a **separate `Settings` service + repository**, never fold into the static `Config`; (Q6) stays lightweight, no app-shape drift.
- **The registry-driven write is the load-bearing detail:** iterate the *registry* and pull each key from the request (admin), and registry-look-up *every* submitted key (MCP) — never iterate request keys and write. This is what makes over-posting structurally impossible; it's the same "allow-list a value at the boundary" pattern as the theme slug and the plugin-page capability (now three consumers of that pattern).
- **MCP standing check honored:** the new management capability is MCP-reachable — `get_settings`/`set_settings`, gated by the same cap, non-enumerating, audited (`API_MANAGEMENT_WRITTEN`). Mobile check: the admin form is the existing mobile-native Settings page (stacked form fields), no new responsive surface.
- **Definition of done — met:** implemented (`src/Settings/*`, `SettingsToolset`, admin form) + integrated (`Application`/`ApiController`/`bin/nimbus` wiring; `SiteController` reads via the service) + verified (3 new HTTP test files; PHPStan L6 clean; `composer format` clean; full suite) + documented (README, ROADMAP, `docs/MCP.md`, `docs/COMPATIBILITY.md`, `principles.md` boundary, both ledgers). No migration (nb_settings ships in 001).
- **Next thread:** site title (`APP_NAME`) as a fast-follow behind the same registry + controls.

### 2026-08-22 · Site title (site.title) — the settings-store fast-follow [Core]
- **Classification: Core** (extends the already-core settings store). Passes the Drift Guard on all four: naming your site without a redeploy is baseline CMS, universal across unrelated site types, backed by 8 real existing consumers (not speculation), recommended even absent Restaurant/Food Store/Packkit.
- **Design-first, both skills before code.** Verdicts: (Q1 boundary) the site title is admin-editable **brand content**, not deploy/env config — belongs in the store with `.env` APP_NAME as the DEFAULT the DB overrides. The staging-indicator use survives (a fresh staging install shows its `.env` default; only a copied prod DB masks it, same as all content). (Q2) the **lazy render-time** threading is the smallest correct approach — resolving at controller construction would add a query to every request incl. /api/redirects (routes() builds all controllers eagerly); memoized `siteTitle()` injected via `page()/shell()/bare()` + `SiteController` render paths keeps it to one query on rendered requests only. (Q3) a new `'text'` (single-line) registry type is justified over jamming a title into a textarea — bounded vocabulary (text/textarea/collection), not a field framework. (Q4) editable OpenAPI title leaks nothing (already public, behind auth). (Q5) title-only; APP_URL stays env, logo/tagline are YAGNI.
- **Implementation:** `site.title` registry key (default `Config::appName()`, validate non-empty + ≤80) → auto-appears in the admin form + MCP (registry-driven, no new write surface); `Settings::title()`; base admin `Controller` lazy memoized `siteTitle()` injected at render; `SiteController` render paths + PageContext + placeholder; `OpenApiGenerator` optional title ctor arg resolved by ApiController (live endpoint) + the CLI dump. `Config::appName()` kept as the DB-free default.
- **Refinement:** admin `saveSite` now skips request-omitted keys (partial-update, matches MCP), still registry-driven → A1 intact.
- **Default-source note codified in principles.md:** a setting's default comes from "whatever the file layer says for that key" and may differ per setting (site.title from `.env`, home/description from `config/site.php`) — expected, not a bug.
- **Security:** green (sibling loop). Only-new item was A3 (latent trust flip: a formerly-`.env`-trusted value now user-editable could bite a plugin head-contributor that skipped escaping) → documented `PageContext` values as untrusted; `plugin-seo` escaping flagged as an out-of-repo follow-up.
- **DoD met:** implemented + integrated (all 8 consumers read the resolved title) + verified (tests across admin/public/MCP/OpenAPI; PHPStan L6; full suite) + documented (README, COMPATIBILITY, MCP.md, principles.md, both ledgers).
- **Settings store now has 3 keys + 2 scalar types + the lazy render-time resolution pattern — the template for logo/tagline later.**

### 2026-08-22 · Admin listing hardening (entry pagination + collections N+1) [Core]
- **Classification: Core** (admin scalability/hardening). Two coupled items from the production-readiness backlog, one slice.
- **Grounding caught a stale ROADMAP note:** the chosen "next" item F1 (relation expansion) was ALREADY shipped (PR #34 — `EntryView` expands relations to `{id,slug,title}`, live-only + scope-filtered, with tests in `ApiRoutesTest`). Fixed the stale "candidate next" note in the same PR. **Lesson (reinforced): never review from memory — verify the backlog against the code; a doc checkbox is not ground truth.** Also found the "PHP-CS-Fixer in CI" backlog line stale (ci.yml already runs `php-cs-fixer --dry-run`).
- **Design (both skills before code):** (A) `EntryRepository::forCollection($id,$q,?$limit,$offset)` (BC: `$limit` null → full set, so the existing `forCollection($id)` caller keeps working) + `countForCollection($id,$q)`; admin `PER_PAGE=25` (a touch larger than the site's reader-facing 20); count → total_pages → **clamp page to `[1,max(1,total_pages)]`** so a too-high `?page` can't produce a huge OFFSET or a dead Next; mobile pager preserving `q`. (B) `CollectionRepository::fieldCounts()`/`entryCounts()` — grouped `GROUP BY collection_id`, **map-with-default** for zero-count collections (no LEFT JOIN) → 2N+1 becomes 3 queries.
- **Standing checks:** mobile — pager verified at 375px; MCP — N/A (admin-list presentation/efficiency, no new management capability; the read API already paginates). Both confirmed by the review.
- **DoD met:** implemented + integrated (`EntriesController`/`CollectionsController` use the new methods) + verified (`AdminListingTest` HTTP + `ListingRepositoryTest` integration; PHPStan L6; full suite; live) + documented (ROADMAP items `[x]`, F1 note fixed, easy-install research added under release-readiness, both ledgers).
- **Net debt reduction:** removed an unbounded admin query and an N+1. Reusable admin-pagination shape now exists if media/users/tokens lists ever need it.

### 2026-08-22 · Structured (AI-friendly) validation errors [Core]
- **Classification: Core** (public API + MCP error contract). Done pre-`0.1.0` because the ROADMAP gates it "before freezing the error contract" — after a tag it would be breaking; now it is free. Passes the Drift Guard (every headless/agent consumer benefits; two real consumers API+MCP; on-brand for "operated by agents").
- **Design (both skills before code) — Option A:** per-field errors became `{code,message}`; `code` is **core-assigned** (`required` for required-empty; `invalid` wrapping a field type's `?string` failure) — `FieldType::validate(): ?string` (the plugin contract) is UNCHANGED. This delivers the distinction agents act on (omitted vs malformed vs server-misconfigured) without freezing a per-type code vocabulary we haven't designed. **Forward-compatible with Option B**: the shape already accommodates a *more specific* code later (`invalid` → `invalid_email`) with no shape change, so B is a purely additive refinement when a consumer needs it.
- **One source, three surfaces:** `Validator` → `array<handle,FieldError>` → `EntryService`(+`SaveEntryResult`) reused by `EntryOperations`(+`EntryOpResult`). API + MCP emit `{code,message}`; the **admin keeps prose** via a `messages()` projection (template essentially unchanged) — "AI-friendly *as well*," not instead.
- **Cleaned a real wart:** `__title`/`__types` were LEAKING into the public `fields` map (EntryOperations reuses EntryService). Normalized: `__title` → `title`/`required`; `__types` → **top-level** `missing_provider` (a config fault, not a per-field user error).
- **Vocabulary correction from the security pass:** dropped the speculative `duplicate` code — entry slugs auto-uniquify, so nothing produces it (a documented-but-never-emitted code is a confusing contract). Frozen set: `required`, `invalid`, `missing_provider`; **additive-only; unknown ⇒ treat as `invalid`** (documented in COMPATIBILITY + OpenAPI + MCP.md).
- **DoD met:** `FieldError` VO; Validator/EntryService/EntryOpResult/SaveEntryResult reshaped; API/MCP/admin surfaces + OpenAPI Error schema updated; docs; tests (new `ValidationErrorsTest` across API+MCP+admin, updated `EntryServiceTest`/`NumberTypeTest`); PHPStan L6; full suite. **Lesson: enrich the single validation source, freeze the *shape* + a small additive vocabulary, keep the plugin contract untouched — the reshape is representation-only (no authz/validation-logic change), guarded by the existing mass-assignment/scope suite.**
