# 26. Commerce public checkout — cart, checkout, order

- **Status:** Accepted; design-first, not yet implemented. Requested for Foodmart
  Slice 4 (so the grocery *sells*, not just browses). The largest public,
  unauthenticated, state-changing surface in the platform — built as sequenced PRs.
- **Date:** 2026-08-30
- **Related:** [ADR 0017](0017-plugin-public-routes.md) (the `/ext` action routes
  the POSTs use), [ADR 0019](0019-plugin-service-ports.md) (the `CartPort` +
  existing `ReservationPort`), [ADR 0023](0023-themed-plugin-pages.md) (the themed
  sections the cart pages use — extended here with a `private` flag),
  [ADR 0022](0022-inventory-item-master.md) (the item price resolved server-side).
- **Reviewed by:** `nimbus-review-loop` and `nimbus-security-review` — the biggest
  public-write surface yet; both before build.

## Context

The storefront (ADR 0023) is browse-only. To sell, a visitor needs a cart, a
checkout, and an order confirmation — all public and unauthenticated. Commerce
already places orders and reserves stock (`OrderBook.place` → `ReservationPort`,
atomically), but has no cart and no public face. This must compose from the
existing hinges without turning Nimbus core into an e-commerce framework — a cart
is an **optional plugin** concern; a blog install never sees one.

## Decision

Mirror the Slice-2 split: **the owning plugin holds the domain; Storefront renders
the public face.**

**Commerce — the cart domain.** `commerce_cart` (opaque-random `cart_token` PK, a
per-cart random `csrf` secret, timestamps) + `commerce_cart_line` (`cart_token`,
`sku_code`, `qty`) storing **only `{sku, qty}` — never a price**. A published
**`CartPort`** (ADR 0019): `getOrCreate`, `add`, `setQty`/`remove`, `contents`,
and `checkout(token, customer)` which resolves prices **server-side from the
Inventory item**, calls `OrderBook.place` (reserving stock atomically), clears the
cart, and returns the order ref.

**Storefront — the public UI.** Themed **GET page-sections** `/cart`, `/checkout`,
`/order/{ref}` (all **private**, see below), plus **`POST /ext/commerce/{add,
update,checkout}`** action routes (ADR 0017 — unauthenticated; the plugin owns its
protection). POST-redirect-GET; redirect targets are **server-fixed** (never a
user `next` param).

**The load-bearing security controls (the review's gate):**

1. **Server-resolved price.** The cart never stores a price; totals are computed
   from the item at render and checkout. A price/total in a POST is ignored. This
   is the one control that makes it a shop rather than a liability.
2. **CSRF without a session.** Core `Csrf` is `$_SESSION`-based (admin only) and
   there is no `APP_KEY`, so Commerce uses a **per-cart random `csrf` secret**
   (server-stored, rendered into every form, `hash_equals`-verified) plus a
   **`SameSite=Lax` + `HttpOnly` + `Secure`** cart cookie (Lax already blocks
   cross-site POST). A synchronizer token, no core dependency.
3. **Cart authorization = the cookie.** `cart_token` is `random_bytes`, opaque,
   read **only from the cookie** (never a query/body param → no IDOR to another
   cart). `/order/{ref}` is gated to the **placing cart** and/or an unguessable
   order token — never a guessable sequential ref.
4. **Input bounds.** `qty` a positive bounded int; `add` accepts only an **active**
   item (via `CatalogReadPort`); per-line and per-cart caps.
5. **Escape-on-render.** Customer name/email + item fields escaped (`View::e`) in
   every cart/checkout/order template; email `filter_var`-validated.
6. **Reserve at checkout only** (browsing holds no stock); the reserve is atomic
   (`OrderBook.place`), so a last-unit race fails one order cleanly.

**The one core change: a `private` page-section.** `/cart`·`/checkout`·`/order`
are per-user and must **never** be cached by the page-cache or a shared CDN (a
leaked cart is Critical). `PageView` gains a **`private` flag** → `SectionController`
sets `Cache-Control: no-store, private` + `noindex`. This is **general** (any
per-user page — an account page, a dashboard), so it is a small, justified
extension of ADR 0023, not e-commerce in core.

**Payment is a stub.** The order is placed `pending` with a confirmation; a real
hosted-payment gateway (webhook via `/ext`, ADR 0017) is a future slice.

**Cart lifecycle.** Abandoned carts are GC'd by a maintenance task (TTL); a basic
per-IP checkout flood-limit ships in-slice (finer rate-limits deferred).

## Build order (sequenced PRs — not one blob)

1. **Core** — `PageView.private` → `SectionController` no-store/noindex (+ test).
2. **Commerce** — cart tables + `CartPort` + cart CSRF secret + GC task (+ tests).
3. **Storefront** — `/cart` section + `add`/`update` `/ext` actions + add-to-cart
   forms on the product templates (+ tests).
4. **Storefront** — `/checkout` + `/order/{ref}` + `checkout()` placement + the
   payment stub + the checkout flood-limit (+ tests).
5. **Aurora + storefront-default templates** — cart / checkout / order.
6. **Foodmart** — reseed a *shoppable* grocery + deploy (folds into ADR 0025).

## Consequences

**Enables.** A real shop on Nimbus — for any site selling goods, through the
optional Commerce+Storefront plugins. Foodmart becomes shoppable. The `private`
section unlocks any per-user themed page.

**Costs / makes harder.** A large public-write surface to keep hardened; carts to
GC; a stub where real payment will later go. Mitigated by composing from reviewed
hinges and the pinned controls above.

**Considered and rejected.** *PHP `$_SESSION` cart* — anonymous visitors have no
session and it's harder to test; a DB cart keyed by a cookie is server-side and
price-safe. *Reserving at add-to-cart* — lets a visitor hold all stock; reserve at
checkout. *Client-sent prices* — the classic flaw; never.

**Deferred / not built (tracked):** real payment gateway + webhooks; order-status
emails (header-injection review then); promo/tax; finer rate-limiting.

**Debt.** Moderate but contained. One small core add (`private` section); the rest
is optional-plugin code behind the boundary. Every High from the security review
is closed by a structural control with a regression test.
