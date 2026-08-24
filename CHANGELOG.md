# Changelog

All notable changes to NimbusCMS are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org) against the public plugin API — see
[docs/COMPATIBILITY.md](docs/COMPATIBILITY.md).

> **Pre-1.0.** A `0.x` minor release may break the plugin API if a design turns
> out to be wrong. Better to break it at `0.3` than to carry a mistake into
> `1.0` and support it forever.

## [Unreleased]

The **0.1.0 candidate**. Everything below has shipped since `0.1.0-alpha.1`, which
turns the "working core + plugins" foundation into a usable CMS with a public
site, a full read+write API, an agent control surface, roles, and SSO — followed
by a pre-release security & correctness audit (Slices A–P).

### Added

- **Public site** — themeable, server-rendered pages for collections and entries,
  a starter theme, page metadata + Open Graph, canonical URLs, an XML sitemap,
  reusable content *blocks*, and config-driven menus. Themes are plain PHP
  templates handed a data-only view-model.
- **Optional page cache** — a filesystem cache for public GETs (`PAGE_CACHE_TTL`,
  off by default), flushed on every content write and TTL-bounded for scheduled
  changes; CSP-nonce-consistent on a hit.
- **Media library** — uploads with MIME sniffing + an extension allow-list, a
  media field type, and a usage index kept in step with content.
- **Headless HTTP API (`/api/v1`)** — read and write. Bearer tokens with
  per-collection `read`/`write` scopes; paginated list + single-entry reads;
  `POST`/`PATCH`/`DELETE` writes with structured `422` validation; `If-Match`
  ETags with an **atomic compare-and-swap** so concurrent machine clients can't
  silently overwrite each other; a per-token quota and a per-IP flood guard; a
  generated **OpenAPI 3.0** document scoped to the presenting token.
- **MCP control surface** — an agent with a scoped token operates the CMS over the
  Model Context Protocol (content, schema, media, users, tokens, settings) through
  the same services the admin uses, never separate logic.
- **Agent guidance over MCP** — the server now teaches agents how to drive it:
  `initialize` returns an operating brief and `resources/list`/`resources/read`
  serve a full guide (`nimbus://guide/core`). It ships with the CMS and works for
  any MCP client, nothing to install. A plugin can publish its own agent guide via
  the `skills()` capability, served as `nimbus://guide/plugin/{id}` — so enabling a
  plugin teaches agents how to drive it. See [ADR 0013](docs/adr/0013-mcp-agent-guidance.md).
- **Roles & capabilities** — named capability bundles for users and tokens, a
  deny-by-default `Authorizer`/`Gate`, subset-only granting, and a Roles admin UI.
- **Users, invitations & password reset** — admin user management, emailed invites
  and one-time password-reset links (hashed at rest, single-use, purpose-scoped),
  and a pluggable mailer (log / native / API transports) with `nimbus mail:test`.
- **OAuth SSO** — "Sign in with Google / GitHub" for the admin (opt-in, off by
  default): Authorization-Code + PKCE + single-use `state`, identity mapped by
  provider subject, explicit linking from settings. Password login always stays.
- **Admin experience** — a redesigned, phone-native admin with four selectable
  themes (Nimbus / Nocturne / Daybreak / Grimoire).
- **More field types & relations** — relation cardinality + integrity, and a
  universal length/validation backstop on entry writes.
- **CLI** — `migrate` (self-healing on a partial apply), `install`, `create-user`,
  `token:*`, `roles:seed`, `mail:test`, `openapi`, `mcp`, `prune`.

### Changed

- **HTTP semantics** — `HEAD` is served by the `GET` route (empty body); a
  wrong-method request returns `405` + `Allow` (not `404`). The API surface no
  longer mints a session cookie (it is bearer-only), and the CORS preflight
  advertises the write + MCP methods and is counted by the flood limiter.
- **Password floor** raised to 12 characters, enforced consistently everywhere a
  password is set (affects newly-set passwords only).
- Public pagination past the last page is a `404` (not a cacheable empty `200`).
- **Reserved schema handles** — a collection handle can no longer be a
  management-capability name (`schema`/`media`/`users`/`tokens`/`settings`/
  `roles`/`admin`) or a core route prefix (`api`/`uploads`/`theme`), and a field
  handle can no longer be a built-in entry attribute (`title`/`slug`/
  `published_at`). Rejected at create on the admin form and over MCP; existing
  collections/fields with such names are grandfathered.
- The `blocks` fragment store and single-kind collections are no longer served as
  standalone public pages (`/blocks`, `/{single-handle}` → `404`); the sitemap
  already excluded them, and the headless API is unchanged.

### Security

- Pre-release audit hardening (Slices A–P): equal-work login + per-account
  throttle (no user-enumeration timing oracle, no distributed-spray gap);
  role-delete honors the subset guard; input-length backstops on every write path;
  plugin-id and admin-slug validation with full two-phase rollback; a CSP nonce
  that survives the page cache; and defense-in-depth on uploads, the mail log, and
  the plugin/field-type contracts. See `docs/backlog/audit-2026-08/` for the
  finding-by-finding record.

## [0.1.0-alpha.1] — 2026-08-02

The first tagged release. A working, deliberately small CMS core with a proven
plugin system. Not production-ready — no upgrade path between versions, no
password reset, no backup tooling.

### Added

- **Collections & entries** — user-defined content types with fields; entry
  CRUD with server-side validation, inline errors, and preserved input on
  failure. Entry data stored as JSON, so adding a field is not an `ALTER TABLE`.
- **Nine field types** — text, textarea, number, boolean, select, date, email,
  URL, relation — behind a registry that plugins extend.
- **Relations** between collections, with referential cascade; **singletons**
  for single-entry collections.
- **Plugin system** — a one-method `Plugin` contract, a narrow `PluginContext`
  exposing field-type registration, and a Composer-driven loader that discovers
  `nimbuscms-plugin` packages from `installed.json`. First registration wins;
  duplicate ids and duplicate field types are rejected; a failing plugin is
  rolled back and contained, never left partially active. See
  [ADR 0001](docs/adr/0001-plugin-contract.md).
- **Read-only Plugins admin page** (System → Plugins) — installed / enabled /
  disabled / failed state per package, with diagnostics. Diagnostic, not an
  installer.
- **Missing-provider safety** — disabling or removing a plugin never rewrites
  stored data: the field degrades read-only and saves are blocked until the
  provider returns.
- **Auth & hardening** — argon2id hashing, CSRF-guarded writes, session
  rotation on login, progressive login throttling, CSP + security headers on
  every response, and centralized trusted-proxy handling.
- **HTTP core** — an immutable `Response` object, a `Request` threaded through
  the router, named routes with URL generation, and middleware groups.
- **Tooling** — PHPUnit (unit, integration, HTTP-functional), PHPStan level 6,
  PHP-CS-Fixer, an install-and-CRUD smoke test, and a cross-repository Composer
  package-boundary test, all in CI.

### Known limitations

- No public-site rendering, headless API, or media library yet.
- Event names are stable (`CoreEvents`), but payload shapes are not frozen, and
  events are not yet a plugin capability.
- Named routes exist but controllers still build paths as strings.

[Unreleased]: https://github.com/NimbusCMS/nimbus/compare/v0.1.0-alpha.1...HEAD
[0.1.0-alpha.1]: https://github.com/NimbusCMS/nimbus/releases/tag/v0.1.0-alpha.1
