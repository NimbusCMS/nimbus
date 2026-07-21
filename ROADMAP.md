# NimbusCMS Roadmap

The single source of truth for what's done, what's deferred, and what's next.
Nothing gets dropped here.

**Guiding principles** (from the architecture stabilization brief): stay
lightweight and explicit. The database is the authority on invariants. Clean
contracts around fields, entry lifecycle, relations, permissions and API
serialization. Plugins are first-class; themes are FE-first. **No** framework,
ORM, DI container, command bus, or needless generic abstraction.

Legend: `[x]` done · `[~]` partial · `[ ]` planned

---

## ✅ Shipped (v0.x foundation)

- [x] Foundation — Docker stack (PHP 8.3 + MySQL 8), migrations + installer CLI, argon2id auth, themed admin shell ("Nimbus" theme)
- [x] Collections engine — user-defined content types, fields, entry CRUD; field-type **registry** (plugin seam)
- [x] Field contract — render / normalize / validate / `toApi` / default / required / help; server-side validation with inline errors + preserved input; richer field config (default, placeholder, help)
- [x] Singletons — single-entry collections (reserved `__singleton` slug, auto title)
- [x] Relations — dedicated `nb_relations` table (referential cascade, reverse lookups)
- [x] Write-path stabilization — `EntryService` + `CollectionService`, `Connection::transaction()`, DB-enforced uniqueness, events after commit, number-normalization fix, `JSON_THROW_ON_ERROR`, error logging with reference id
- [x] Session/logout security — `nimbus_session` HttpOnly/SameSite=Lax/Secure-when-HTTPS/strict; logout POST + CSRF + destroy; login rotates session id
- [x] PHPUnit suite (unit + integration vs MySQL) + GitHub Actions CI

## 🧹 Deferred hardening (from the stabilization "after" list — do opportunistically)

- [ ] Entry-list **pagination**
- [ ] Collection-index **N+1 count** query fix
- [ ] Tiny HTTP **`Response`** object (HTML / redirect / JSON)
- [ ] **PHPStan** (or Psalm) + **PHP-CS-Fixer**/PHPCS in CI
- [ ] HTTP-functional tests: CSRF on write routes, permission enforcement, cross-collection entry-id isolation
- [ ] Migration-upgrade tests · upload-security tests · permission-matrix tests
- [ ] **Structured validation errors** (before freezing the public API error contract)
- [ ] Separate field rendering from field domain behaviour (only when alt themes / non-HTML editors create real pressure)
- [ ] Dependency vulnerability scanning · automated release artifacts · semver + CHANGELOG

---

## 🧭 Pre-plugin stabilization milestone (immediate next — gates plugin work)

Plugins create long-term contracts, so we harden the extension substrate before
freezing any public API. Landing as separate PRs:

1. **HTTP `Response` object** — `html` / `redirect` / `json` / `download`; kernel sends it. No scattered `header()` / `echo` / `exit` in controllers.
2. **Routing improvements** — named routes + URL generation + middleware groups. Explicit, not framework-y.
3. **Security review** — session policy `[x]`, upload validation + MIME verification (lands with Media), CSP headers, auth hardening (throttling, password reset).
4. **Testing** — expand PHPUnit around field contracts, validation, auth, permissions, routing, repositories, transactions, and (eventually) plugin loading.

> Plugins should not be the first consumers of unstable APIs.

## 🎯 Release 0.1 — "usable CMS"

1. **Publishing workflow** — draft / published / scheduled / archived; `published_at` + `unpublished_at`; preview unpublished; publish/unpublish actions; autosave / recoverable drafts; unsaved-changes warning; bulk publish/archive/delete; optional approval flow (author → editor → publisher). *Lifecycle fields stay on indexed columns, never JSON.*
2. **Stable URLs & identity** — slugs `[x]`, auto-slug `[x]`, uniqueness `[x]`; **redirect history** on slug change; canonical URLs; **parent/child** pages; configurable route patterns (`/blog/{slug}`); permanent **UUID** separate from DB id.
3. **Field validation** `[x]` — extend with min/max, regex, unique-value constraints.
4. **Entry-list usability** — search `[~]`, filters, sortable columns, configurable visible columns, pagination, bulk actions, status badges `[~]`, author + modified date, saved filters, duplicate entry, quick edit, keyboard nav.
5. **Media library** — see [Media](#media-library-detail) below.
6. **Auth hardening** — login rate limiting, account lockout / progressive delay, password reset flow, email verification for invited users, session revocation ("log out all devices"); **installer must require a password + refuse known defaults outside dev** (stop advertising `admin@nimbus.test` / `password`).
7. **Basic revisions** — immutable snapshots, diff by field, restore, who/when, publish-vs-edit events, retention limits, revision notes, audit export.
8. **Tests + CI** `[x]` — expand coverage.

## 🎯 Release 0.2 — "headless-ready"

1. **Versioned read API** (`/api/v1`) — pagination with hard max limits, filtering + sorting, sparse field selection, relation expansion, consistent errors, ETags + Last-Modified. Entries pass through field **serializers** (`toApi`) — never expose internal JSON storage as the contract.
2. **Scoped API tokens** — per-token scopes, expiry, revocation.
3. **Preview API** — draft-preview tokens.
4. **Webhooks** — after publish / update / delete.
5. **Caching** — ETags, response caching.
6. **OpenAPI** documentation.
7. **CORS** config + **rate limiting**.

## 🎯 Release 0.3 — "public-site-ready"

1. **Public router**.
2. **Themes** — data-only view-models, escape-by-default, `theme.json` manifest, FE-first (React/JS build supported), template inheritance. *No BE logic in templates.*
3. **Menus / navigation**.
4. **Global site settings**, reusable **blocks**.
5. **SEO** — meta title/description, Open Graph, canonical, `sitemap.xml`, robots, RSS/Atom.
6. **Redirect manager**.
7. **Page caching** + invalidation on publish + a **"rendered in X ms · powered by NimbusCMS"** signal (dogfooding perf proof).
8. Custom 404/error templates, preview mode, theme asset versioning.

---

## 🔌 Extensibility — plugin architecture (design; build **after** the milestone above)

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

- [ ] Field types · Routes · Events · Permissions · Admin navigation · Dashboard widgets · API resources · Asset providers · Migrations
- [ ] Plugins get **no** unrestricted access to `Application`, controllers, internal repositories, session internals, or a service locator.
- [ ] `Plugin` interface + loader (register into `PluginContext`); versioned; enable/disable.
- [x] Field-type interface + registry (first capability, already live)
- [~] Event dispatcher — synchronous; documented events (`entry.created/updated/saved/deleted`); add `EntrySaving/Published` as consumers appear
- [ ] Storage adapter interface (local / S3-compatible)
- [ ] Cache adapter interface

**Official plugins** (Media, SEO, Markdown, Search, Revisions, Redirects,
Activity Log) are maintained by Nimbus but optional, and use the **exact same
public APIs** as community plugins — no privileged plugin architecture. If an
official plugin needs an internal API, that API is evaluated for promotion into
the public surface. Later: an official **marketplace** (browse/install/update/
enable/disable) with review-based submission, and an official theme directory.

## 🔐 RBAC / permissions

- [~] Per-collection manage-roles (basic) + admin override + ownership enforcement point (`Permissions`)
- [ ] **Capability** model (`collection.article.publish`, `media.upload`, `users.manage`, `settings.manage`); roles = capability bundles; `update_own` vs `update_any`
- [ ] Custom roles · user invitation flow · disable-user-without-deleting-history · field-level restrictions (only if genuinely needed)

## 🛡️ Security (before calling it production-ready)

Login throttling · account lockout · password reset · email verification ·
session revocation / log-out-all-devices · secure cookie defaults `[x]` ·
session-id rotation on login `[x]` · CSP · escaping-by-default in templates `[x]` ·
HTML sanitization for rich text · upload security (MIME-sniff not extension,
randomized names, SVG script strip) · audit records for security-sensitive
actions · optional 2FA · trusted-proxy config · host-header-poisoning protection ·
production error handling `[x]` · secret/key rotation strategy.
*A small audited library for HTML-sanitize / MIME may beat DIY — "zero deps" is a goal, not an absolute.*

## 🔎 Search / content discovery

- [~] Simple admin search (title LIKE)
- [ ] Collection-specific filterable fields; DB-generated/indexed columns for hot fields; denormalized search-index table; optional **Meilisearch/Typesense** adapter; `reindex` CLI. *No promise of arbitrary efficient JSON filtering without indexes.*

## 🌍 Internationalization (decide early; don't block)

Translated UI · localized entries · per-locale slugs · fallback locale ·
locale-aware dates · RTL admin. *Entry identity + routing must not preclude localization.*

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

- Domain **nimbuscms.dev** (purchased) — the marketing site is **powered by NimbusCMS itself** (not GitHub Pages). Host on the **Oracle free ARM box** (reuse the Foodmart Terraform pattern) or Fly; one deploy can serve marketing site + live demo.
- Sequence: domain `[x]` → live demo + docs at usable-0.1 → landing page → comparison posts / benchmarks at 0.2.
- **Themes/plugins registry** = Composer packages indexed by metadata (don't host code); the registry itself can be a NimbusCMS site. Search: indexed SQL + tags → Meilisearch/Typesense later.
- **USP:** *"A modern PHP CMS for developers who don't want WordPress — lightweight core, first-class plugins, FE-first themes."*

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
