# NimbusCMS Roadmap

The single source of truth for what's done, what's deferred, and what's next.
Nothing gets dropped here.

**Governed by [docs/CHARTER.md](docs/CHARTER.md).** Every item here must pass its
gate before it is built:

1. **Classify** — Core / official plugin / theme / application. Core stays small;
   most things are a plugin, a theme, or the consuming app.
2. **Capability test** — a Core capability is added only when *multiple unrelated
   use cases* need it, or it unlocks a category of reusable extensions. Not
   because one validation project (Restaurant, Food Store, Packkit) needs it —
   those are **acceptance tests, not requirements**.
3. **Three hats** — Product Owner, Lead Architect, Principal Engineer must each
   sign off (see the charter).

Current priority is **production readiness**, not feature count: installation,
upgrades, media, editor experience, public rendering, API maturity, docs,
testing, performance, security, release. North star: *opinionated about
architecture, unopinionated about what people build.*

Earlier lightweight/explicit principles still hold: the database is the
authority on invariants; clean contracts around fields, lifecycle, relations,
permissions, API serialization; **no** framework, ORM, DI container, command
bus, or needless abstraction.

## Legend

| Mark  | Meaning |
|-------|---------|
| `[ ]` | planned |
| `[~]` | implemented but **not fully integrated or verified** |
| `[x]` | integrated **and verified by CI** |

A class existing in the repository is not enough for `[x]`. If nothing in CI
would fail when the behaviour breaks, it is `[~]`.

*Last audited against `main` after the headless + media slices (PRs #4–#27):
295 core tests / 982 assertions plus 29 plugin tests, PHPStan level 6 in both
repositories, install+CRUD and package-boundary tests — all green.*

---

## ✅ Shipped (v0.x foundation)

- [x] Foundation — Docker stack (PHP 8.3 + MySQL 8), migrations + installer CLI,
  argon2id auth, themed admin shell ("Nimbus" theme) · *installer proven by
  `tests/smoke.sh` in CI*
- [x] Collections engine — user-defined content types, fields, entry CRUD ·
  *`CollectionRoutesTest`, `EntryRoutesTest`*
- [x] Field contract — render / normalize / validate / `toApi` / default / required /
  help; inline errors with preserved input · *`FieldTypeTest`, `ValidatorTest`,
  `NumberTypeTest`*
- [x] Field-type **registry** with strict lookup — unknown types raise
  `UnknownFieldType` instead of silently becoming text; missing providers degrade
  through `MissingType` without data loss · *`FieldTypeRegistryTest`,
  `EntryServiceTest`*
- [x] Singletons — single-entry collections (reserved `__singleton` slug, auto title) ·
  *`EntryServiceTest`, `EntryRoutesTest`*
- [x] Relations — dedicated `nb_relations` table (referential cascade, reverse lookups)
  · *`EntryRoutesTest` covers write + replace-on-update*
- [x] Write-path stabilization — `EntryService` + `CollectionService` own every write;
  `Connection::transaction()`; DB-enforced uniqueness; **events after commit**; number
  normalization; `JSON_THROW_ON_ERROR`; error logging with reference id
- [x] Duplicate collection handles — `DuplicateHandle` from the unique index
  (race-safe), re-rendered as a field error with the submission intact
- [x] Session/logout security — `nimbus_session`
  HttpOnly/SameSite=Lax/Secure-when-HTTPS/strict; logout POST + CSRF + destroy; login
  rotates session id · *`AuthRoutesTest`*
- [x] PHPUnit suite — unit + integration + HTTP-functional vs real MySQL, on GitHub
  Actions

## 🧹 Deferred hardening (from the stabilization "after" list — do opportunistically)

- [x] Tiny HTTP **`Response`** object (HTML / redirect / JSON / download) — *shipped #1,
  hardened #5*
- [x] **PHPStan** level 6 in CI — *#7*
- [x] HTTP-functional tests: CSRF on write routes, permission enforcement,
  cross-collection entry-id isolation — *#8*
- [ ] **PHP-CS-Fixer**/PHPCS in CI — PHPStan landed, formatting did not. Wanted
      **before outside contributions arrive**: with several repositories and
      contributors, formatting noise multiplies and every review starts
      arguing about whitespace. PSR-12-oriented, small config.
- [ ] Raise PHPStan above level 6
- [ ] Entry-list **pagination**
- [ ] Collection-index **N+1 count** query fix
- [ ] Migration-upgrade tests · upload-security tests · permission-matrix tests
- [ ] **Structured validation errors** (before freezing the public API error contract)
- [ ] Consume named routes in controllers — URL generation exists and is tested, but
  every controller still builds paths as strings, so the names are not yet load-bearing
- [ ] Nonce-based CSP (drop `'unsafe-inline'` once inline theme/field-builder JS moves
  out)
- [ ] Password reset flow (needs an email-transport decision)
- [ ] Trusted-proxy config for URL generation (already used by sessions + throttling)
- [ ] Separate field rendering from field domain behaviour (only when alt themes /
  non-HTML editors create real pressure)
- [ ] Dependency vulnerability scanning · automated release artifacts · semver +
  CHANGELOG

---

## ✅ Pre-plugin stabilization milestone — COMPLETE (PRs #1–#3)

1. [x] **HTTP `Response` object** — `html` / `redirect` / `json` / `download`; kernel
   sends it. No `header()` / `echo` / `exit` in controllers.
2. [~] **Routing improvements** — middleware groups `[x]` (gate every admin route,
   proven by `AuthRoutesTest`); named routes + URL generation `[~]` — implemented and
   unit-tested, but no controller consumes `Router::url()` yet, so the names are not
   load-bearing.
3. [x] **Security review** — CSP + security headers on *every* response including
   errors; progressive login throttling; installer refuses weak/default credentials
   outside dev. Upload validation lands with Media; password reset is deferred.
4. [x] **Testing** — field contracts, validation, auth, permissions, routing,
   repositories, transactions.

## ✅ Core convergence milestone — COMPLETE (PRs #4–#9)

Making every new abstraction actually load-bearing, then proving it.

1. [x] **Request through the router** — handlers are `fn(Request, array $params):
   Response`; `Request::fromGlobals()` is called exactly once, at the boundary. Thirteen
   controller actions previously re-read superglobals mid-request.
2. [x] **Response hardening** — header injection rejected at construction;
   case-insensitive replacement; redirect-status validation; UTF-8 JSON; RFC 5987
   download filenames.
3. [x] **Centralized proxy trust** — `TRUSTED_PROXIES`; `X-Forwarded-*` ignored unless
   `REMOTE_ADDR` matches. One decision shared by session cookies and throttling.
4. [x] **Strict field-type lookup** — see Shipped.
5. [x] **PHPStan level 6** in CI, ahead of the tests.
6. [x] **HTTP-functional tests** — real requests through the real kernel; 65 tests over
   auth, collections, entries and the response contract.
7. [x] **Route-contract architecture test** — every registered route provably declares
   `Response` and takes `Request` first. `Application::routes()` builds the table once,
   so tested routes are served routes.
8. [x] **Install + CRUD smoke test** — `tests/smoke.sh`, in CI.

> Plugins should not be the first consumers of unstable APIs.

## ✅ Plugin-readiness milestone — COMPLETE (PRs #10–#12)

The last core-boundary cleanup before the plugin loader.

1. [x] **Truthful lifecycle events** — `EntryService::delete()` returns `bool`
   and dispatches `entry.deleted` only when a row was really removed. Listeners
   never hear about a deletion that did not happen.
2. [x] **`CoreEvents` constants** — event names are a contract the moment a
   plugin subscribes; a mistyped string literal fails silently today. Semantics
   documented: post-commit notification, synchronous, exceptions propagate.
3. [x] **Controller boundary** — `EntriesController` split from
   `CollectionsController`: schema administration and content editing answer to
   different people under different rules. No base CRUD controller;
   `ControllerBoundaryTest` enforces it.
4. [x] **Plugin contract on paper** — [ADR 0001](docs/adr/0001-plugin-contract.md).
   Design only, nothing implemented.

## ✅ Plugin foundation milestone — COMPLETE

Built together with the reference plugin, so the API was derived from a real
consumer rather than designed in isolation.

1. [x] **Application-owned registries** — one `FieldTypeRegistry` and one
   `EventDispatcher`, composed in `Application` and passed to controllers and
   services. Three registries existed before; a plugin registering into one
   would have been invisible to the others.
2. [x] **Instance event dispatcher** — static `Events` removed. Static
   listeners leak between tests, between application instances, and duplicate
   on a double bootstrap.
3. [x] **`Plugin` contract** — one method. No lifecycle methods until a
   concrete requirement exists.
4. [x] **`PluginContext`** — exposes `fieldTypes()` and nothing else. No
   `Application`, controllers, connection, repositories, session or `get()`.
5. [x] **Duplicate registration fails** — first registration wins; the registry
   records provenance so the error names both providers.
6. [x] **`PluginLoader`** — Composer `installed.json` discovery, manifest and
   class validation, duplicate-id rejection, enable/disable config, structured
   diagnostics, and containment of a plugin that throws.
7. [x] **Reference plugin** —
   [nimbuscms/markdown](https://github.com/NimbusCMS/plugin-markdown), its own
   repository and CI, installed through Composer with no core changes.
8. [x] **Degradation proven** — disabling the plugin leaves stored data
   byte-identical, shows it read-only, blocks saves, and re-enabling restores
   editing.

## 🧩 Plugin lifecycle hardening

- [x] **Registration rollback** — a plugin that registers two types and throws
      on the second has the first undone, so "failed" in the diagnostics and
      "inactive" in the application can never disagree
- [x] **Ids claimed on installation, not on success** — a disabled or broken
      plugin keeps its id, so disabling the official plugin cannot hand its
      identity to another installed package
- [x] **Provider ids bound by the loader** — a plugin cannot register under
      another provider's name (and so cannot get their types rolled back)
- [x] **Compatibility policy** — [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md)
- [ ] **Read-only admin plugin screen** — installed / enabled / disabled /
      failed, with the diagnostic. Read-only on purpose: no install, no remote
      update, no upload.
- [x] **Cross-repository integration test** — `tests/Integration/package-boundary.sh`
      in CI installs the Markdown package through real Composer resolution and
      drives its whole lifecycle across the package boundary
- [ ] **Plugin admin *forms* — CSRF-token exposure decision** *(backlog)* — the
      admin-pages capability shipped GET-only (proven by `nimbuscms/analytics`).
      Before a plugin page can POST, decide how a plugin obtains and embeds the
      admin CSRF token without widening the trusted surface (a scoped helper on
      the page context vs. a rendered hidden field the plugin can't read). No
      concrete consumer needs a form yet — build it when one does, not before.
      See the plugin-analytics ledger entry.

## 📦 Release & packaging (blocks a stable plugin ecosystem)

Neither `nimbuscms/nimbus` nor `nimbuscms/markdown` is on Packagist, so the
plugin currently requires core through a VCS repository at `dev-main`:

```json
"require-dev": { "nimbuscms/nimbus": "dev-main" },
"repositories": [
    { "type": "vcs", "url": "https://github.com/NimbusCMS/nimbus" }
]
```

That works, and it is the right call while the API is still moving — but it
means every plugin build tracks core's `main`, so a breaking change to core
breaks every plugin's CI the moment it merges, with no way to pin. And nobody
outside the project can `composer require nimbuscms/markdown` at all.

Ordered, because each step depends on the one before:

- [ ] Decide the **public API surface** — which namespaces a plugin may rely on
      (`Nimbus\Plugin\*` and the `FieldType` contract today) and which are
      internal and free to change without notice
- [ ] Adopt **semantic versioning** + a `CHANGELOG.md`, and document which core
      versions each plugin supports
- [ ] **Tag `0.1.0`** on core — the first point at which a plugin can pin `^0.1`
- [ ] **Publish to Packagist**, so `composer require` works with no
      `repositories` block
- [ ] Switch `plugin-markdown` from `dev-main` to `^0.1` and test against the
      **lowest and current** supported core versions in its matrix
- [ ] Decide whether reusable contracts eventually split into a separate
      `nimbuscms/core` package — **only** when installing Nimbus as a
      dependency becomes a real requirement. Splitting now buys release and
      synchronisation overhead before the plugin API has been proven.

> Renaming packages or moving repositories after third parties depend on them
> is the expensive version of this work. Doing it while nothing is published
> costs nothing — which is exactly why core moved to `NimbusCMS/nimbus` before
> the first plugin shipped.

## 🧭 Next: production readiness, kept honest by acceptance tests

The plugin **loader / registry / lifecycle mechanics** are **frozen** (see the
charter) — done, not to be polished further. That freeze is on the *machinery*,
not the capability set: individual `PluginContext` capabilities are still added
one at a time, each alongside an official plugin that needs it (ADR-0001) — see
the Extensibility section and `capability-evidence.md`. The focus otherwise is
production readiness (the Release themes below).

**Validation projects are acceptance tests, not the roadmap.** Restaurant
Management (rebuild on branch `nimbus-rebuild`), Food Store, and Packkit exist to
prove Nimbus is flexible. When one hits a wall, the gap is recorded, then built
**only if broadly reusable** — never to satisfy one app. App-specific logic
(kitchen queues, cart rules, reservations) stays in the app.

Findings so far, from the Restaurant **Menu** vertical (which needed **zero core
changes** — collections + relation + number, served over the API):

- **F1 — API returns relations as bare ids** (`"category": [15]`). Reference
  *expansion* in the read API, like media already has. **Classify: Core
  capability** (API maturity) — many headless frontends need it, unrelated to
  Restaurant. Fits Release 0.2's "relation expansion". *Candidate next.*
- **F2 — no supported way to consume Nimbus from a separate app repo** (root-only,
  not on Packagist, no library mode/image). **Classify: Core / release process** —
  foundational to anyone deploying. Belongs with installation/upgrades below.
- **F3 — number decimals** (`8.00` → `8`). **Classify: application concern**, not
  Core — a frontend formats money. Only a shared "money" field type if several
  apps want it.

## 🔑 Milestone: Programmatic Access Hardening (complete)

Make Nimbus safe for **non-human clients** before a write API or MCP is built on
it — the surface where PHP CMSes classically bleed. Design decided in
[ADR 0006](docs/adr/0006-non-human-authentication.md): **standalone-principal**
API tokens with **`resource:action` per-collection scopes**, enforced at the
query/service layer, deny-by-default. Baseline `nimbus-security-review` of `main`
found **no Critical/High in shipped code** — these slices install the controls
that would otherwise become High the moment tokens reach private data or writes.

Small, CI-green slices; each ends with a security-review pass (no open High
without a risk-acceptance ADR):

- [x] **Slice 0 — ADR 0006** (auth/authz model)
- [x] **Slice 1 — principal plumbing + token lifecycle**: principal carried to
      the controller; `expires_at` / `revoked_at` / `paused_at` + bounded usage
      record; reject expired/revoked/paused (401); `token:list` / `revoke` /
      `pause` / `resume` CLI.
- [x] **Slice 2 — token admin UI**: mint (plaintext shown once, idempotent via a
      single-use form nonce) / list / revoke / pause / resume, CSRF-guarded.
- [x] **Slice 3 — scope enforcement + authorization matrix**: `abilities`
      enforced as per-collection `resource:action` at the query layer,
      deny-by-default; scope checked before existence (no enumeration); relation
      expansion respects scope; legacy null-ability tokens compat-granted `*:read`
      during the read-only era (removed when the write API lands); scopes settable
      via CLI `--scopes` and the admin picker. Authorization matrix shipped as a
      test.
- [x] **Slice 4 — structured API error contract**: one JSON envelope with a
      stable machine-readable `code` (`unauthorized`/`forbidden`/`not_found`/…);
      401 (unauthenticated) vs 403 (out-of-scope) vs 404 (absent), no existence
      leak. Documented in COMPATIBILITY.
- [x] **Slice 5 — API rate limiting + CORS**: two fixed-window limiters — a
      per-IP flood guard before auth and a per-token quota after — `429`
      `rate_limited` + `Retry-After`; DB-backed (swap for a cache adapter at
      scale). Minimal CORS (origin allow-list + preflight), off by default.

### The `nimbuscms/api-advanced` plugin track (offloads pro features from core)

Consumes core events into its own append-only tables; the second unrelated
consumer of the events + storage capabilities. Planned:

- **Per-token activity history / audit** (lifecycle events + a bounded last-used
  rollup; write attribution once the write API lands).
- **Security failure events** — core emits `api.token_rejected` /
  `api.access_denied` at the error choke point, **isolated + `hasListeners`-guarded**
  (free until subscribed), payloads scrubbed of secrets, names pre-1.0. The plugin
  records/alerts. *Caveat: a failure event per request is a DoS-amplifier under a
  flood — the consumer must aggregate/sample, and it pairs with Slice 5 rate
  limiting.* Emitted **with** this plugin (its consumer), not before (ADR-0001).
- Webhooks · per-token analytics · per-token quotas.

## ✍️ Milestone: Write API (complete)

On the hardened base, the same operations the admin performs, over the API.
Design in [ADR 0007](docs/adr/0007-write-api.md): a new transport in front of
proven core — the JSON body maps to `EntryInput` and goes through `EntryService`
(validation, slugs, transactions, events, and the allow-list field binding that
guards mass-assignment). A single `{handle}:write` scope; optimistic concurrency
(ETag / If-Match); writes are audited.

- [x] **Slice 0 — ADR 0007**
- [x] **Slice 1 — concurrency foundation**: entry `version` column + bump in
      `EntryService`; `GET` returns `ETag`; an `If-Match` helper.
- [x] **Slice 2 — write endpoints**: `POST`/`PATCH`/`DELETE` via `EntryService`;
      `{handle}:write` enforced deny-by-default (scope before existence); `422`
      validation errors; `If-Match` required on update/delete (`412`/`428`); the
      authorization matrix gains write rows.
- [x] **Slice 3 — write auditing**: core `api.entry_written` (best-effort, carries
      the acting token) + `nimbuscms/api-advanced` records it (who-changed-what).
- [x] **Slice 4 — docs + review**: COMPATIBILITY, final security-review pass.

## 📖 Milestone: OpenAPI (complete)

A machine-readable contract for the `/api/v1` read+write surface, **generated**
from the live content model (the model is user-defined, so a hand-written spec
can't work). Design in [ADR 0008](docs/adr/0008-openapi.md).

- [x] **Slice 0 — ADR 0008**
- [x] **Slice 1 — `FieldType::jsonSchema()`**: a JSON-Schema fragment per field
      type (sibling of `toApi()`), defaulted in `BaseType` so nothing breaks;
      number/boolean/date/email/url/select/relation/media override.
- [x] **Slice 2 — `OpenApiGenerator`**: build the OpenAPI 3.0 document from
      collections + fields + the fixed routes/components (bearer security, error
      envelope + codes, pagination, ETag/If-Match). Structure test.
- [x] **Slice 3 — serve it**: `GET /api/v1/openapi.json` (auth-gated, full spec)
      + `nimbus openapi` CLI dump; COMPATIBILITY + security-review pass.

## 🤖 Milestone: MCP — the CMS control surface (active)

The payoff the whole API arc was for: an agent with a scoped token runs the
**entire** CMS through the Model Context Protocol — content, schema, media,
users, tokens, settings — so the admin UI is optional, not required. MCP is a
transport + a generated tool surface over the same services the admin uses, never
new logic. Design in [ADR 0009](docs/adr/0009-mcp-control-surface.md).

Capabilities go granular (`schema:write`, `media:*`, `users:write`,
`tokens:write`, `settings:write`, + `admin`), designed as the atoms of a future
**roles** system. Both transports (HTTP `POST /api/v1/mcp` bearer-auth; `nimbus
mcp` stdio). Content tools are per-collection + typed (from field `jsonSchema()`),
version-required on write; management tools are capability-gated; `tools/list` is
scope-filtered. Every management action is audited.

- [x] **Slice 0 — ADR 0009**
- [x] **Slice 1** — management capabilities (`admin` + granular) + shared `EntryOperations`
- [x] **Slice 2** — MCP server core + HTTP transport (`POST /api/v1/mcp`) + typed content tools + introspection
- [x] **Slice 3** — stdio transport (`nimbus mcp`) — env-token scoped, reuses the shared server
- [x] **Slice 4** — schema tools (`schema:write`) — create/update/add_field/remove_field/set_fields/delete_collection via a Toolset seam
- [x] **Slice 5a** — core media usage tracking + shared delete guard (block + pinpoint)
- [x] **Slice 5b** — media MCP tools (`media:*`; base64 upload, list/get/usage, guarded delete)
- [ ] **Slice 6** — users / tokens / settings tools
- [ ] **Slice 7** — management-action audit + docs + final review

Each slice: CI-green + a `nimbus-security-review` pass (this is the highest-
privilege surface in the product).

## 🎯 Release 0.1 — "usable CMS"

1. **Publishing workflow** — `[x]` draft / published / scheduled / archived,
   `published_at`, publish/unpublish actions, cron-free scheduling
   ([ADR 0002](docs/adr/0002-publication-lifecycle.md)); still open: `unpublished_at`,
   autosave / recoverable drafts, unsaved-changes warning, bulk actions, approval
   flow. *Lifecycle fields stay on indexed columns, never JSON.*
2. **Stable URLs & identity** — slugs `[x]`, auto-slug `[x]`, uniqueness `[x]`;
   **redirect history** on slug change; canonical URLs; **parent/child** pages;
   configurable route patterns (`/blog/{slug}`); permanent **UUID** separate from DB id.
3. **Field validation** `[x]` — extend with min/max, regex, unique-value constraints.
4. **Entry-list usability** — search `[~]`, filters, sortable columns, configurable
   visible columns, pagination, bulk actions, status badges `[~]`, author + modified
   date, saved filters, duplicate entry, quick edit, keyboard nav.
5. **Media library** — `[x]` upload (finfo-validated, allowlisted, safe names),
   library admin, and a `media` field expanded by the API; still open: image
   resizing/thumbnails, multiple-media fields, remote/S3 storage.
6. **Auth hardening** — login rate limiting `[x]`, account lockout / progressive delay
   `[x]`, installer refuses weak/default credentials outside dev `[x]`; still open:
   password reset flow, email verification for invited users, session revocation ("log
   out all devices").
7. **Basic revisions** — immutable snapshots, diff by field, restore, who/when,
   publish-vs-edit events, retention limits, revision notes, audit export.
8. **Tests + CI** `[x]` — unit, integration, HTTP-functional, static analysis and a
   smoke test all run on every PR.

## 🎯 Release 0.2 — "headless-ready"

1. **Versioned read API** (`/api/v1`) — `[x]` live-only entries, pagination with a
   hard cap, consistent JSON errors, field `toApi` serialization, bearer-token auth;
   still open: **relation expansion (F1)**, filtering + sorting, sparse fields,
   ETags + Last-Modified.
2. **API tokens** — `[~]` bearer tokens with SHA-256 hashing and last-used
   tracking; still open: per-token **scopes** (abilities column reserved), expiry,
   revocation UI.
3. **Preview API** — draft-preview tokens.
4. **Webhooks** — after publish / update / delete.
5. **Caching** — ETags, response caching.
6. **OpenAPI** documentation.
7. **CORS** config + **rate limiting**.

## 🎯 Release 0.3 — "public-site-ready"

1. **Public router**.
2. **Themes** — data-only view-models, escape-by-default, `theme.json` manifest,
   FE-first (React/JS build supported), template inheritance. *No BE logic in
   templates.*
3. **Menus / navigation**.
4. **Global site settings**, reusable **blocks**.
5. **SEO** — meta title/description, Open Graph, canonical, `sitemap.xml`, robots,
   RSS/Atom.
6. **Redirect manager**.
7. **Page caching** + invalidation on publish + a **"rendered in X ms · powered by
   NimbusCMS"** signal (dogfooding perf proof).
8. Custom 404/error templates, preview mode, theme asset versioning.

---

## 🔌 Extensibility — plugin architecture (loader frozen; capabilities added one at a time per ADR-0001)

**Vocabulary.** *Core* = the small kernel (routing, HTTP, auth, DB, migrations,
collections, entries, validation, plugin/theme loading, event dispatcher, stable
extension registries); defines invariants. *Plugin* = the only installable
extension unit (independently versioned/enabled/disabled; Composer now,
marketplace later); provides *features*. *Theme* = presentation package; never
owns business logic; may depend on plugins. *Feature* = user-facing capability
(product language) — may live in Core or a plugin. *Capability* = a stable Core
extension point (architecture language, **not** a second installable concept).

**Plugins consume capabilities via an explicit `PluginContext`** — the small,
deliberate public surface:

- [ ] Field types · Routes · Events · Permissions · Admin navigation · Dashboard widgets
  · API resources · Asset providers · Migrations
- [ ] Plugins get **no** unrestricted access to `Application`, controllers, internal
  repositories, session internals, or a service locator.
- [ ] `Plugin` interface + loader (register into `PluginContext`); versioned;
  enable/disable.
- [x] Field-type interface + registry (first capability — consumer: Markdown)
- [x] Document-head contributions (ADR 0004 — consumer: SEO)
- [x] Event listeners via `PluginContext::events()` — synchronous, post-commit;
  documented events (`entry.created/updated/saved/deleted`) plus `request.handled`
  (best-effort/isolated); consumer: Analytics. `entry.saving/published` deferred
  until a consumer appears
- [x] Plugin-owned migrations + storage — own tables only ([ADR 0005](docs/adr/0005-plugin-owned-storage.md));
  consumer: Analytics. Core connection/tables/repos stay off-limits
- [x] Admin pages + nav via `PluginContext::adminPages()` — GET-only for now
  (forms pending the CSRF-token decision above); consumer: Analytics
- [ ] Storage adapter interface (local / S3-compatible) — *media/asset backends,
  distinct from plugin-owned storage above*
- [ ] Cache adapter interface

**Official plugins** (Media, SEO, Markdown, Search, Revisions, Redirects,
Activity Log) are maintained by Nimbus but optional, and use the **exact same
public APIs** as community plugins — no privileged plugin architecture. If an
official plugin needs an internal API, that API is evaluated for promotion into
the public surface. Later: an official **marketplace** (browse/install/update/
enable/disable) with review-based submission, and an official theme directory.

## 🔐 RBAC / permissions

- [~] Per-collection manage-roles (basic) + admin override + ownership enforcement point
  (`Permissions`)
- [ ] **Capability** model (`collection.article.publish`, `media.upload`,
  `users.manage`, `settings.manage`); roles = capability bundles; `update_own` vs
  `update_any`
- [ ] Custom roles · user invitation flow · disable-user-without-deleting-history ·
  field-level restrictions (only if genuinely needed)

## 🛡️ Security (before calling it production-ready)

Login throttling · account lockout · password reset · email verification ·
session revocation / log-out-all-devices · secure cookie defaults `[x]` ·
session-id rotation on login `[x]` · CSP · escaping-by-default in templates `[x]` ·
HTML sanitization for rich text · upload security (MIME-sniff not extension,
randomized names, SVG script strip) · audit records for security-sensitive
actions · optional 2FA · trusted-proxy config · host-header-poisoning protection ·
production error handling `[x]` · secret/key rotation strategy.
*A small audited library for HTML-sanitize / MIME may beat DIY — "zero deps" is a goal,
not an absolute.*

## 🔎 Search / content discovery

- [~] Simple admin search (title LIKE)
- [ ] Collection-specific filterable fields; DB-generated/indexed columns for hot
  fields; denormalized search-index table; optional **Meilisearch/Typesense** adapter;
  `reindex` CLI. *No promise of arbitrary efficient JSON filtering without indexes.*

## 🌍 Internationalization (decide early; don't block)

Translated UI · localized entries · per-locale slugs · fallback locale ·
locale-aware dates · RTL admin. *Entry identity + routing must not preclude
localization.*

## ⚙️ Operations / DevX

Unit/integration/HTTP/migration-upgrade/permission-matrix/upload-security tests ·
GitHub Actions CI `[x]` · PHPStan/Psalm · PHP-CS-Fixer/PHPCS · dependency vuln
scanning · automated release artifacts · semver + CHANGELOG · upgrade docs ·
backup/restore commands · health-check endpoint · structured logs · production
Docker example · read-only maintenance mode · transaction boundaries `[x]`.

---

<a name="media-library-detail"></a>
## 🖼️ Media library (0.1 detail)

Uploads · image thumbnails · **MIME inspection (not extension)** · file-size +
dimension limits · **randomized storage names** · original filename metadata ·
alt / title / caption / credit · dimensions + focal point · multiple generated
sizes · WebP/AVIF where supported · duplicate detection · **usage references
("where is this used?")** · **SVG script-injection protection** · private vs
public media · **pluggable local/S3 storage** · orphaned-file cleanup ·
replace-file-without-breaking-URLs · a `media` **field type** + reusable picker.

---

## 🚀 Product & website (dogfooding)

- Domain **nimbuscms.dev** (purchased) — the marketing site is **powered by NimbusCMS
  itself** (not GitHub Pages). Host on the **Oracle free ARM box** (reuse the Foodmart
  Terraform pattern) or Fly; one deploy can serve marketing site + live demo.
- Sequence: domain `[x]` → live demo + docs at usable-0.1 → landing page → comparison
  posts / benchmarks at 0.2.
- **Themes/plugins registry** = Composer packages indexed by metadata (don't host code);
  the registry itself can be a NimbusCMS site. Search: indexed SQL + tags →
  Meilisearch/Typesense later.
- **USP:** *"A modern PHP CMS for developers who don't want WordPress — lightweight
  core, first-class plugins, FE-first themes."*

## 📚 Docs & community

- [~] README
- [ ] `docs/` — install · first content · themes · plugins · API · config · deployment
- [ ] `AGENTS.md`
- [ ] CONTRIBUTING · SECURITY · CODE_OF_CONDUCT · issue/PR templates · CHANGELOG
- [x] LICENSE (MIT)

---

## Workflow

`main` is protected. Each feature lands via a branch → PR → squash-merge once CI
is green. See open PRs for work in flight.
