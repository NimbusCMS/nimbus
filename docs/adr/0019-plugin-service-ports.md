# 19. Typed service ports between plugins (Hinge 5)

- **Status:** Accepted; implemented. A fifth plugin-boundary capability, **not** in
  the original Inventory + Commerce four-hinge plan — surfaced when building
  Commerce, which must call Inventory synchronously. Its consumer is Commerce
  (reserve/issue against Inventory), built next.
- **Date:** 2026-08-28
- **Related:** [ADR 0001](0001-plugin-contract.md) (plugins are trusted in-process
  code), [ADR 0005](0005-plugin-owned-storage.md) (own tables only — the boundary
  this works *with*, not around), [ADR 0014](0014-plugin-event-dispatch.md) (events
  — the fire-and-forget seam this complements with request/response).
- **Reviewed by:** `nimbus-review-loop` (the Architect hat scrutinised whether this
  is the forbidden service locator) and `nimbus-security-review` — before build.

## Context

Inventory and Commerce are deliberately separate plugins (Inventory is
load-bearing and useful on its own — a shop that only digitises what it owns). But
a Commerce checkout must ask Inventory, **synchronously and with a result**,
"reserve this — is it available?" Nothing on the boundary could do that:

- **Own-tables-only (ADR 0005):** Commerce's storage is its own tables; it cannot
  write Inventory's reservation table.
- **Events (ADR 0014) are fire-and-forget:** no return value, so no request/response.
- **`PluginContext` has no service locator** — by principle ("a `get('anything')`
  is the absence of an API").

So "Commerce calls Inventory's API", which the initiative always assumed, had no
mechanism. This ADR adds the smallest one.

## Decision

A plugin **provides** a typed implementation of a **contract interface**, and
another **consumes** it by that contract:

```php
// Inventory, at register():
$ctx->services()->provide(ReservationPort::class, new ReservationAdapter($reservations));

// Commerce, at request time:
$port = $ctx->services()->get(ReservationPort::class);   // ?ReservationPort
$port?->reserve($sku, $location, $qty, $orderLineRef);
```

Five properties keep this a **contract-typed port**, not the generic locator the
principles reject — the distinction the Architect review turned on:

1. **Interface-keyed.** A service is registered under an `interface` — a published
   contract, in a package both plugins depend on — validated at registration
   (`interface_exists`). A concrete class or arbitrary string is refused, so this
   can never be used to fetch a core internal.
2. **Typed retrieval.** `get()` is generic on the contract (`class-string<T> → ?T`),
   so a consumer receives a **typed** object, not a bare `object` it must trust and
   downcast.
3. **Plugin services only.** Core internals stay wired in `Application`; nothing
   registers them here. This carries plugin-to-plugin collaboration, nothing else.
4. **One provider per contract.** A second registration of the same contract fails
   the plugin's load, so a plugin can't silently shadow another's port. The
   implementation must actually implement the contract.
5. **Fail-safe.** An unprovided contract returns `null`, so a consumer depends
   *softly* — Commerce refuses checkout with a clear message if no inventory is
   installed, rather than assuming a collaborator is present.

Providers register at plugin load and roll back with a failed plugin; consumers
call `get()` at request time (never inside `register()`, where load order is
undefined).

## Consequences

**Enables.** Commerce ↔ Inventory (and any cooperating plugins) — synchronous,
typed, decoupled calls, without either plugin importing the other's implementation
(they share only the contract). The event bus stays for notifications; this is for
"I need an answer now."

**Costs / makes harder.** It is one step toward plugins depending on each other —
mitigated by the contract being an explicit shared interface (a reviewable
dependency, declared in composer.json) and by fail-safe absence (a soft
dependency). A plugin is trusted in-process code (ADR 0001); a provided
implementation still enforces its own rules (Inventory's reservation port applies
Inventory's guards), so consuming a port grants no privilege the provider wouldn't.

**Deferred / not built:** a shared contract package for Inventory
(`ReservationPort`) and the Commerce plugin that consumes it are the next slices;
this ADR is the core seam only. Versioning of contracts, and multiple named
implementations of one contract, are deferred until a second consumer shows the
shape.

**Debt.** Low. Additive (`services()` alongside the other registrars), reuses the
loader's rollback path, and the locator risk is contained by the interface-only +
one-provider + typed-get guards. Tests cover typed retrieval, fail-safe absence,
the interface-only and matching-implementation guards, single-provider ownership,
and rollback.
