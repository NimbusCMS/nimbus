# Changelog

All notable changes to NimbusCMS are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org) against the public plugin API — see
[docs/COMPATIBILITY.md](docs/COMPATIBILITY.md).

> **Pre-1.0.** A `0.x` minor release may break the plugin API if a design turns
> out to be wrong. Better to break it at `0.3` than to carry a mistake into
> `1.0` and support it forever.

## [Unreleased]

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
