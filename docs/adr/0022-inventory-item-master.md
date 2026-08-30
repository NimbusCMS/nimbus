# 22. Inventory item master — retail goods as items, not content

- **Status:** Accepted; design-first, not yet implemented. Its consumer is the
  Foodmart grocery (the Food Store validation app rebuilt on Nimbus), which sells
  its inventory as-is and needs each SKU to carry sellable attributes. Slice 1 of
  the Foodmart build.
- **Date:** 2026-08-30
- **Related:** [ADR 0005](0005-plugin-owned-storage.md) (plugins own their tables —
  the boundary this works *with*), [ADR 0019](0019-plugin-service-ports.md) (typed
  ports — how Commerce will later read the item price), [ADR 0020](0020-plugin-admin-page-capability-gate.md)
  (the `inventory:write` management cap gating item writes), [ADR 0016](0016-plugin-mcp-toolsets.md)
  (the MCP toolset the item tools extend), [ADR 0009](0009-mcp-control-surface.md) (the
  agent is a first-class operator). **Evolves** the "catalog lives in content"
  note in `nimbus-plugin-inventory` (`Schema.php` decision 5, `InventoryPlugin`
  docblock) — this ADR supersedes those, which are updated to point here.
- **Reviewed by:** `nimbus-review-loop` (Architect hat: is this drift into a
  PIM/storefront, or a bounded retail capability?) and `nimbus-security-review`
  (the stored fields become public in Slice 2 — the escape-on-render contract is
  set here) — both before build.

## Context

Inventory shipped as a deliberately **pure ledger**: movements, on-hand,
reservations, keyed by an opaque `sku_code` with no foreign key into core. The
recorded decision was that **the catalog — what a SKU *is* (its name, price,
description) — lives in *content*** (ordinary collections), so it is editable,
themeable and API-native, while the plugin owns only the numbers. That fit the
café: a menu is editorial content you author, and stock (if tracked at all) is a
separate ledger.

Rebuilding a **grocery** (Foodmart) breaks that fit. A grocery **sells its
inventory as-is** — the sellable catalog *is* the stock list. Modelling it the
café way means every SKU is entered **twice**: once as a content entry (name,
price, image) and once as a ledger SKU (on-hand), joined by a hand-copied code,
with two places to edit and drift between them. For retail, "catalog in content"
is duplication, not separation.

The need is not Foodmart-specific: **any site that stocks and sells physical
goods** — grocery, hardware, bookshop, pharmacy — has the same shape. A *stocked
good that has a name and a price* is a coherent, broadly-reusable unit. So the
question is not "does Foodmart need this" (it does) but "is this a general retail
capability that happens to be evidenced by Foodmart" — and it is, provided it
stays generic.

## Decision

Give the **Inventory plugin** an optional **item master**: a SKU may carry
sellable attributes alongside its ledger stock, in the plugin's own tables. Two
patterns for "products" now **coexist**, chosen by one question:

> **Is this editorial content you author, or operational stock you manage?**
> A curated menu, a showcase, a portfolio → a **content collection**.
> Goods you stock and sell at scale → the **Inventory item master**.

Neither is the default; a fresh install has neither until a plugin/collection is
added. This ADR does not remove content-catalog; it adds its retail counterpart.

**Shape (plugin-owned tables, ADR 0005; no FK into core):**

- `inventory_item` — `sku_code` PK (the same opaque key the ledger uses),
  `name`, `price` DECIMAL(18,2), `unit`, `description` TEXT, `image_media_id`
  (nullable soft ref), `category_id` (nullable), `active` + `featured` flags,
  timestamps. **Optional and additive:** a SKU can have stock with no item, and
  an item with no stock — a warehouse/internal user's pure ledger is untouched.
- `inventory_category` — `id`, `name`, `slug`, `parent_id` (nullable). **Two
  levels only:** a category whose own `parent_id` is set cannot itself be a
  parent (this enforces the depth *and* makes cycles structurally impossible).

**Fields stay generic — the binding constraint.** `name, price, unit,
description, image, category, active, featured` describe *any* retail good.
There are **no grocery-specific columns** (no dietary/aisle/brand/organic).
Domain-specific attributes are the app's own content/config, or a future
extensible-attributes mechanism with its own evidence — never a baked column
here. This is what keeps the capability a platform feature rather than a
Foodmart-shaped one.

**Management (person + agent, both first-class):**

- **Admin:** `InventoryAdmin` gains item + category management (create/edit),
  gated on `nimbuscms.inventory:write` (a management, wildcard-immune cap —
  ADR 0020) with CSRF; designed and verified at ~375px.
- **MCP:** `inventory_item_set`/`inventory_item_get` (+ category tools) on the
  plugin toolset (ADR 0016), `set` gated on write, `get` on read.

**Security contract set now (data lands here, is rendered in Slice 2):**

1. **Store raw, escape on render.** The stored `name`/`description`/`unit` are
   author input kept **byte-exact** (no lossy pre-escape). *Every* render path
   escapes by default — `View::e()` in the Aurora templates, and the JSON API
   returns data (`application/json` + `nosniff`, inert until rendered). This is a
   **standing contract for Slice 2**, recorded here so the render slice cannot
   forget it. `description` is **plain text v1 — no HTML** (no sanitizer
   dependency; render escaped).
2. **Price is validated** at the write boundary (non-negative decimal in range,
   like the ledger validates qty) → 422/dropped, never a raw string into DECIMAL,
   never negative.
3. **`image_media_id` resolves defensively** through `MediaRepository::find()`
   (`?MediaItem`) — missing → no image, never a 500 or a path leak; it is an int
   into the public media library, so no traversal, no arbitrary-file IDOR.
4. **Writes build from a field allow-list** (no mass-assign of `sku`/timestamps/
   flags from arbitrary keys); slug is allow-listed (`[a-z0-9-]`), all values
   bound; `parent_id` is an int that must reference an existing, top-level
   category in this install.

## Consequences

**Enables.** A grocery (and any stock-selling site) models each SKU once, in
Inventory, as the source of truth for both **price and stock** — feeding the
public Storefront (Slice 2) and, later, letting Commerce read the authoritative
price via the ReservationPort's sibling port instead of trusting a caller-
supplied `unit_price`. Categories give the storefront its taxonomy for
filter/sort/browse.

**Costs / makes harder.** The lean ledger gains a second concern. Mitigated by
the item master being a **separate, optional table** (the pure-ledger use case is
unchanged) and by keeping fields generic. Two "product" patterns could confuse;
mitigated by the one-sentence rule above, documented in the plugin README and the
agent guide.

**Considered and rejected: a separate `Catalog` plugin.** Maximally pure (the
ledger stays single-purpose), but it adds a third plugin and a cross-plugin join
(Catalog items ↔ Inventory stock ↔ Storefront) plus another service port, for no
concrete benefit at this evidence level. "Inventory owns the goods (items +
stock)" is the simpler cohesion, and the additive-table design preserves the
pure-ledger path. Revisit only if a real consumer needs a catalog with no stock
concept at all.

**Deferred / not built (tracked):** Commerce reading the item price via a port
(a small follow-on, not this slice); the public Storefront listing/filter/sort/
search + the Slice-2 render-escape regression test; VAT/tax and stock-warning
thresholds (old-app settings, app config not core columns); richer per-item
attributes; category reparent-on-delete (Slice 1 blocks delete when referenced).

**Debt.** Low. Additive plugin tables, reuses the ADR-0020 cap gate and the
ADR-0016 toolset, no core change. Tests cover: raw-storage of markup, price
validation, media-id fail-safe, slug allow-listing, the `content:*`-cannot-write
authz row, unknown-key rejection, and category parent/depth/delete integrity.
