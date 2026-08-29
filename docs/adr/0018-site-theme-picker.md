# 18. The active public theme is a setting (theme picker)

- **Status:** Accepted; implemented. Revisits a review-loop principle — "the active
  public theme stays in `config/*.php`, set at deploy" — with maintainer approval
  (the picker was requested). Does **not** change the config-vs-DB principle in
  general; it moves one key onto the established file↔DB override pattern.
- **Date:** 2026-08-28
- **Related:** [ADR 0011](0011-roles.md) (`settings:write` gates the change),
  the settings file↔DB pattern (`site.title` defaults from config, the DB
  overrides), and the admin **skin** picker (per-user, `AdminTheme` — a *different*
  surface this does not touch).

## Context

The public theme has always been chosen in `config/theme.php` — a deploy-time file
— because the review principles class the active theme as per-environment config.
That is a fine default, but operators want to switch themes from the admin without
a redeploy, and an official theme picker is a general CMS feature.

The tension: `Support\Config` is deliberately **DB-free** (a hard principle — some
config is needed before the database exists), so it cannot itself read a
DB-stored choice. And the theme name becomes a **filesystem path** (`themes/{name}`),
so a runtime-chosen value is a traversal risk if trusted.

## Decision

Make the active theme a registered setting, `site.theme`, exactly like `site.title`:
the `config/theme.php` value is its **default**, and a DB value overrides it. This
extends the settings layer's existing file↔DB pattern to one more key rather than
inventing anything — a fresh or DB-less install still renders from the file.

- **`Config` stays DB-free.** The DB-stored choice enters in exactly one place —
  `SiteController`, which resolves the theme directory from `Settings::theme()`
  (falling back to `Config::theme()`), never in `Config`.
- **Discovery is a directory scan.** `ThemeCatalog` lists the themes under
  `themes/` (a directory with a `theme.json`), keyed by a validated `[a-z0-9-]`
  slug. Installing a theme is putting it there — the same deliberate act as a
  plugin.
- **Two containment gates, because the name becomes a path.** On **write**, the
  setting's validator allow-lists the value against installed themes (an unknown
  or `../…` name is refused, so it never reaches the store). On **read**,
  `ThemeCatalog::dirFor()` re-checks that the chosen theme is installed *and* that
  its resolved real path sits inside `themes/` — so a theme deleted after it was
  chosen, or any stale value, falls back to the config-file theme rather than
  pointing rendering elsewhere.
- **The picker is a `settings:write` site setting**, rendered as a select of
  installed themes in the site-settings form (distinct from the per-user admin
  skin). MCP parity is automatic: `site.theme` is a registered key, so the settings
  tool reads and writes it under the same validator.

## Consequences

**Enables.** Switching the public theme from the admin (or over MCP) without a
redeploy, and an official picker that lists whatever themes are installed — the
groundwork for shipping themes as their own installable packages.

**Costs / makes harder.** One more setting, and a second place (the read-side
containment) that must stay correct — mitigated by keeping both gates in
`ThemeCatalog` with tests. The `config/theme.php` file remains the default and the
DB-less fallback, so nothing about a file-configured install changes.

**Principle note.** This is a *scoped* revisit: the active theme joins the small set
of values that are file-defaulted and DB-overridable (title, home, description).
Deploy/secret config (DB credentials, `APP_URL`, trusted proxies, enabled plugins)
stays firmly in files — `Config` is not coupled to the DB, and this ADR does not
open that door.

**Debt.** Low. Additive (a setting, a catalog, a resolver), reuses the settings
allow-list boundary, and the traversal risk is closed on both write and read.
Tests cover discovery, the slug allow-list, the write validator, and the read-side
path containment (including nested traversal).
