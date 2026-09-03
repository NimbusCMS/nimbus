# 27. Plugins may contribute view data to themed pages

- **Status:** Accepted
- **Date:** 2026-09-03
- **Supersedes:** —
- **Related:** [ADR 0004](0004-plugin-head-contributions.md) (head contributions —
  the sibling this mirrors), [ADR 0001](0001-plugin-contract.md) (plugin contract),
  [ADR 0003](0003-public-rendering-and-theme-contract.md) (public rendering),
  [ADR 0023](0023-themed-plugin-pages.md) (themed plugin pages),
  [ADR 0025](0025-foodmart-validation-site.md) (validation-site invariant)

## Context

A plugin can contribute to a public page's `<head>` (ADR 0004, `HeadContributor`),
but there is **no equivalent for the page body**. A theme renders a content page
(home / entry / collection) from a fixed view-model: the entry/collection fields
plus core-provided keys. A plugin has no seam to add *live* data to that model.

The concrete need surfaced while validating the platform with Foodmart (ADR 0025):
the storefront home can only show **static, authored** aisle tiles, because the
Storefront plugin cannot feed **live featured products** into the theme-owned home
singleton. Per the ADR-0025 invariant, a validation site must not force a bespoke
core change or couple a theme to a plugin — so this was deferred from the Foodmart
UX work to its own reviewed platform slice.

The gap is general, not e-commerce-shaped. The same missing seam blocks a blog's
"related posts" or "popular tags", a docs site's "see also", any *plugin-owned,
live, page-contextual data* a theme wants to render. It is precisely the body-data
twin of the head seam we already accept as core.

The constraint that shapes the design: content pages are **page-cached by path
(+ bounded `?page`) with no cookie or query vary** (`Application::cacheKey`).
Anything a contributor injects is baked into the shared cached HTML and served to
every visitor. So contributed data must be **visitor-independent**.

## Decision

Add a **view-data-contribution** capability, mirroring head contributions.

A plugin registers a `ViewDataContributor`:

```php
interface ViewDataContributor
{
    /**
     * Extra view data for this page, or [] for none. VISITOR-INDEPENDENT ONLY:
     * the result is baked into the shared page cache, so it must not depend on
     * the cookie, session, or the requesting user. It is DATA the theme escapes
     * on render — never pre-rendered HTML (that is the head seam's job, ADR 0004).
     *
     * @return array<string, mixed>
     */
    public function data(PageContext $page): array;
}
```

It receives the **same `PageContext`** (ADR 0004) the head seam does — a data-only
value object with no `Request`, cookies, or session. That signature is the
load-bearing cache-safety control: an honest contributor has no per-visitor state
to return by construction.

Registration mirrors head/field types exactly:

- `PluginContext::viewData()` returns a provider-scoped `ViewDataRegistrar`.
- Registrations land in a shared `ViewDataContributorRegistry`, stamped with the
  plugin id, rolled back with the plugin on a failed load (`forgetProvider`).
- The registry `collect(PageContext)` runs each contributor **isolated**
  (`try/catch` → `error_log` + skip, never a 500 — the ADR-0004 rule), and merges
  results **namespaced by provider id**:

  ```php
  ['nimbuscms.storefront' => ['featured' => [ /* items */ ]]]
  ```

`SiteController` passes the merged map into the theme under one reserved key,
`contrib`, for the content-page renders (`renderEntry`, `renderCollection`), built
from the `PageContext` already assembled for `head`. A theme reads it defensively:

```php
$featured = $contrib['nimbuscms.storefront']['featured'] ?? [];
```

### Why these choices

- **Namespaced under `contrib[providerId]`** — a contributor can never reach a
  top-level template var (`title`, `entry`, `meta`, `head`, `nav`, `cart_summary`,
  `cspNonce`). Everything it returns is quarantined under its own id, so it cannot
  override core or another plugin. This is the isolation property.
- **Data, not HTML** — unlike `HeadContributor` (which emits trusted raw `<head>`
  HTML), contributed data is escaped on render by the theme (`View::e`, and
  `rawurlencode` for a sku in an href). Safer by default.
- **Reuse `PageContext`** — no new context type, and its lack of per-visitor
  fields is what keeps the cache invariant honest.

### Consumers (separate PRs, not part of core)

- **Storefront** registers a contributor returning `['featured' => …]` via
  `CatalogReadPort` (active-only, an explicit small limit — "a handful, not a
  feed"). Official plugin.
- **Aurora** renders a "Featured this week" row on the home from `contrib`, every
  value escaped, links to `/shop/{sku}`, verified at 375px. Theme.

## Consequences

- New **public API** (frozen like the head contract): `Nimbus\Site\ViewDataContributor`,
  `Nimbus\Site\ViewDataContributorRegistry`, `Nimbus\Plugin\ViewDataRegistrar`,
  reusing `Nimbus\Site\PageContext`. Recorded in `docs/COMPATIBILITY.md`.
- A page with no contributors renders byte-identical to today — the seam is
  optional and additive.
- **Cache-safety is a contract, guarded by a test** pinning the `PageContext`-only
  signature. A contributor that returns visitor-dependent data would leak across
  visitors via the page cache; the interface forbids it and the signature makes it
  unreachable for honest code. A *malicious* plugin reading superglobals is the
  pre-existing ADR-0001 trust model (plugins are trusted code; this is an
  accident-guard, not a sandbox), not a new hole.
- Contributed data is bounded by contract ("a handful"); the Storefront consumer
  passes an explicit limit, so the seam cannot bloat the page cache in practice.

## Alternatives considered

- **Reuse `HeadContributor`** — rejected: it emits HTML into `<head>`, not body
  data a theme can lay out and escape.
- **A bespoke Foodmart/section hack** — rejected: the home is a theme-owned content
  singleton with no plugin seam, and coupling the theme to a plugin (or querying
  Inventory from a theme) violates ADR 0025 and ADR 0003.
- **Pass `Request` to contributors** — rejected: it re-opens the per-visitor cache
  leak; the `PageContext`-only signature is the control.
