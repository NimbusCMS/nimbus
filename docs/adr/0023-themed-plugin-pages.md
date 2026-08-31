# 23. Themed plugin pages (page sections) — SEO pages from plugin data

- **Status:** Accepted; design-first, not yet implemented. The 6th plugin-boundary
  capability, completing the set (events · capabilities · MCP tools · public
  actions/`/ext` · service ports · **pages**). Its consumer is the Foodmart
  Storefront (Slice 2), which surfaces the Inventory item master (ADR 0022) as a
  public, shoppable listing.
- **Date:** 2026-08-30
- **Related:** [ADR 0017](0017-plugin-public-routes.md) (plugin `/ext` routes —
  this fills the gap that ADR named: *"SEO-facing pages stay content"*, leaving a
  plugin with public data nowhere themed to render), [ADR 0019](0019-plugin-service-ports.md)
  (the typed port the Storefront reads Inventory through), [ADR 0022](0022-inventory-item-master.md)
  (the item master whose escape-on-render contract this slice finally executes),
  [ADR 0018](0018-site-theme-picker.md) (the active theme these pages render in).
- **Reviewed by:** `nimbus-review-loop` (is a new public-render seam core, or
  drift toward an app framework?) and `nimbus-security-review` (a new public
  render + routing seam + public SQL + public rendering of author input — the
  highest-exposure slice since ADR 0017) — both before build.

## Context

A plugin can serve **actions and webhooks** under `/ext/{ns}/…` (ADR 0017), but
that ADR deliberately drew the line there: *"SEO-facing pages stay content
(collections + theme)."* That holds while a plugin's public face is machine
endpoints. It breaks the moment a plugin owns **data that should be a public,
themed, crawlable page** — a storefront listing over the Inventory item master, an
events calendar, a directory, a job board. None of that is *content* (it isn't
authored as entries; it's a query over plugin tables), yet all of it wants exactly
what content pages get: the active theme's layout, the site menus, SEO meta, a
pretty URL.

`SiteController.renderPage()` already assembles all of that — theme layout, DB
menus, shared `blocks`, head contributors, canonical/OG meta, the demo banner,
page-cache — but only for content entries and indexes. There is no seam for a
plugin to render *its* data through that same machinery. The choice was to fill
the gap ADR 0017 named, or to abuse "content" for non-content.

## Decision

Add a **page-sections** registrar: a plugin registers a public **section** at a
pretty top-level handle, supplying a resolver; core renders the result through the
**active theme**.

```php
$ctx->pages()->register('shop', $resolve);   // GET /shop and /shop/{path*}
// $resolve(Request): ?PageView   →  { template, data, meta, status }  (null → 404)
```

A `SectionController` is mounted in the kernel **after** the core controllers, the
API, and the plugin `/ext` routes, and **before** `SiteController`'s
`{collection}` catch-alls — the same first-match/mount-order discipline as
ADR 0017. It calls the resolver and renders the returned view-model through the
existing `renderPage` path (layout, menus, `blocks`, head contributors, meta,
`DemoBanner`), so a section page is indistinguishable from a content page to the
theme and to a crawler.

`View` gains an optional **fallback template directory**: a section template is
looked up in the active theme first (`shop-index`, `shop-product`) and, if absent,
in the **plugin's** bundled templates — so a plugin ships working defaults and a
theme (Aurora, Slice 3) overrides them, while the **layout is always the theme's**.
Both directories resolve names through the same `[A-Za-z0-9_-]` slash-segment rule
(no dots, no `..`, no absolute) — the fallback never weakens traversal safety.

**Core stays domain-agnostic.** The seam is `handle → resolver → view-model`;
core knows nothing of "product", "cart", or "catalog". Any vocabulary beyond
"a plugin renders a themed page" would be app-framework drift, and is refused.

### Containment (the review's gate)

1. **Reserved-handle refusal at registration.** A section handle in
   `RESERVED_COLLECTION_HANDLES` or a core literal (`admin`, `api`, `ext`,
   `theme`, `uploads`, `sitemap.xml`, `robots.txt`, `llms.txt`), or one with bad
   characters, is **refused at plugin-load** — the plugin fails and rolls back
   (ADR 0017 parity). A plugin can never claim `/admin` or `/api`.
2. **A section handle becomes a `ReservedHandle`.** Collection creation refuses a
   handle a section claims, and section registration refuses a handle an existing
   collection uses — the two namespaces cannot collide.
3. **Deterministic mount order.** Section before the `{collection}` catch-all,
   after core + `/ext`, so resolution never depends on load timing.
4. **No ambient authority.** Sections are **GET-only** and sit outside the admin
   middleware — an admin cookie grants nothing there (ADR 0017 parity). No state
   change happens on a section route; a mutation is still an `/ext` action.
5. **Uncacheable while a query is present.** `Application::cacheKey` keys on path +
   bounded `?page` and *ignores unknown params* — correct for content (which
   ignores them) but a **poisoning + fill** vector for a section that varies by
   `?q`/`?category`/`?sort` (the first query's HTML would be stored under the bare
   path and served to all). So **section pages bail the page-cache whenever a
   query string is present** (mirroring the `preview` bail); the bare canonical
   page may still cache. A query-aware section cache is deferred.
6. **CSP-clean defaults.** The public CSP is nonce-only for `style-src` and
   `script-src` (as in admin). The section view-model therefore carries the
   **CSP nonce**; default templates use no inline `style=`/`<script>` — filters
   are no-JS GET forms, styling is a nonce'd `<style>` block or a theme asset.

## The Slice-2 consumers (built with the hinge — no speculative API)

- **Inventory `CatalogReadPort`** (ADR 0019 services()): `list({category, search,
  sort, page}) → {items, total}`, `get(sku) → item|null`, `categories() → tree`.
  Returns **public-safe DTOs** — `{sku, name, price, unit, description, image,
  category, featured, availability}` where `availability` is **coarse**
  (in-stock/low/out from summed on_hand−reserved) — **never** exact counts,
  reserved, location, or cost, and **never `active=0` items** (invisible in `list`
  and a 404 from `get`). SQL is bound; `sort` is an **allow-list**; `search` is a
  bound, `%`/`_`-escaped LIKE; `page` is bounded, past-the-end → 404.
- **Storefront plugin**: consumes the port, registers the `shop` section, ships
  default `shop-index` (grid + category filter + sort + search + pagination) and
  `shop-product` templates, escaping every author value (`View::e`; description
  plain-text). It touches no Inventory table (ADR 0005 holds) and depends on the
  port **softly** (absent Inventory → an empty "catalog unavailable" page, not a
  500). An `inventory_items` list/search MCP read tool gives an agent parity with
  the public query.

## Consequences

**Enables.** Themed, SEO-friendly, crawlable public pages from any plugin data
source — a storefront now, and any directory/calendar/listing later — with pretty
URLs and full theme integration, at the cost of one small, contained seam.

**Costs / makes harder.** A plugin can now claim a top-level path — a bigger
surface than `/ext`'s fixed prefix — which is why the containment above is
structural (registration-time refusal + `ReservedHandle` + mount order), not
advisory. A plugin serving a themed page is trusted in-process code (ADR 0001);
the seam widens what that trust reaches, so the render path stays escape-by-default
and the DTO is public-safe by construction.

**Considered and rejected: storefront-as-content.** Model `/shop` as a content
page whose template queries Inventory. Rejected: it still needs a new seam
(template → plugin data), it abuses "content" for a query over plugin tables, and
it cannot express a clean `/shop/{sku}` product route (content is
`/{collection}/{slug}` where the slug is an *entry*, not a plugin SKU). The
section hinge is cleaner and no larger.

**Deferred / not built (tracked):** query-aware caching of section pages; an
exact-count availability opt-in; rate-limiting `/shop` (the `RateLimitMiddleware`
primitive is available if a scraper appears); a product-detail page may ride the
Storefront PR or a fast-follow.

**Debt.** Moderate but contained. A new controller + registrar + one `View`
parameter, all reusing the existing render path and the ADR-0017 discipline. The
exposure is real (public SQL + public render of author input) but every High from
the security review is closed by a structural control with a regression test:
escape-on-render, sort allow-list, cache-bail-on-query, registration-time handle
refusal, fallback-template no-traversal, active-only coarse DTO.
