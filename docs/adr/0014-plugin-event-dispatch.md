# 14. Plugin event dispatch (namespaced emit)

- **Status:** Accepted; implemented. First of the four plugin-boundary
  capabilities the Inventory + Commerce initiative needs (see Context); the others
  (H2 grantable capabilities + plugin MCP tools, H3 plugin admin forms, H4 plugin
  public routes) get their own ADRs as they are built.
- **Date:** 2026-08-27
- **Related:** [ADR 0001](0001-plugin-contract.md) (plugins are trusted
  in-process code — the trust boundary this does **not** change),
  [ADR 0005](0005-plugin-owned-storage.md) (a plugin owns its own tables — the
  same "your namespace, not core's" principle, applied to events).
- **Drives:** an Inventory plugin emitting `…​.low` / `…​.movement.recorded` for
  notification and domain plugins to consume — inter-plugin choreography without
  either side depending on the other's code.
- **Reviewed by:** `nimbus-review-loop` and `nimbus-security-review` — both ran on
  the *design* before this build (they converged: build H1 now, in its smallest
  hardened form). A final security pass ran on the built code.

## Context

Nimbus is growing an **Inventory** capability (stock as an append-only movement
ledger) and, after it, **Commerce**. The design review established that four
small, general extensions to the plugin boundary are needed to build them as
plugins rather than forks — named H1–H4. This ADR is **H1**, and it is the one
with no dependencies and the smallest surface, so it is built first.

The event bus (`EventDispatcher`, `CoreEvents`) has always been **listen-only for
plugins**: `EventRegistrar` exposed `listen()` and nothing else, and the class
comment said plainly "a plugin cannot dispatch events." Core dispatches; plugins
observe. That was right while the only events were core's own lifecycle points.

It stops being enough the moment one plugin needs to tell *another* something
happened. Inventory recording a movement that drops stock below a threshold has
no way to notify a hypothetical notifications plugin, and a domain plugin
(pharmacy, café) has no way to react to a receipt — except by the two plugins
depending on each other's classes, which the boundary rightly forbids. An event
is exactly the decoupled seam for this, and every plugin ecosystem eventually
needs inter-plugin events. This is a general CMS capability, not an Inventory one:
it would be recommended even if Inventory did not exist.

The risk is equally general. Giving a plugin the ability to *dispatch* — not just
subscribe — opens three specific ways to turn the bus against the site, and the
review required all three closed **in this first cut**, not later:

1. **Namespace collision.** Two plugins both emitting `low` would cross wires; a
   subscriber could not tell whose stock was low.
2. **Core-event forgery.** A plugin emitting `entry.saved` would fire core's
   revision and audit listeners with attacker-shaped payloads.
3. **A dispatching plugin taking down the caller.** A throwing subscriber
   surfacing as a `500` on the stock write that emitted, or a listener loop
   (`a` → `b` → `a`) recursing until the stack overflows or the request hangs.

## Decision

Add **one** method to the plugin-facing `EventRegistrar`:

```php
$ctx->events()->emit(string $name, mixed $payload = null): void;
```

with three properties that each close one of the risks above.

### 1. The name is namespaced under the plugin id, verbatim

`emit('low')` from the plugin loaded as `nimbuscms.inventory` dispatches
`nimbuscms.inventory.low` — the registrar prepends `{pluginId}.`, the plugin never
supplies the prefix. The id is bound by the loader per `PluginContext`, so it
cannot be spoofed, and using it **verbatim** (not a stripped last segment like
`inventory`) is deliberate: `acme.inventory` and `nimbuscms.inventory` must not
collide. `$name` is validated as one or more lowercase dot-separated segments;
a malformed name throws `InvalidArgumentException` — that is the *emitting*
plugin's own programming error, surfaced loudly, and is distinct from a
subscriber failure (below), which is swallowed.

### 2. A plugin id rooted in a core event namespace is refused at load

Because `emit` always produces `{pluginId}.{name}`, the only way to forge
`entry.saved` is to be loaded under an id rooted in `entry`. So the loader now
rejects any plugin id that **is, or starts with**, a namespace core dispatches in
— `entry`, `api`, `auth`, `request` — exactly as it already reserves `core` and
`nb`. The reserved roots are **derived from `CoreEvents::all()`**
(`CoreEvents::reservedRoots()`), so adding a core event automatically extends the
reservation and the list can never drift. Closing this at the *identity* level
makes forgery structurally impossible and means `emit` itself needs no per-call
reserved-name check — the prefix is already proven safe.

### 3. Delivery is best-effort and depth-bounded

`emit` routes through `EventDispatcher::emitBestEffort()`, so a throwing
subscriber is caught, logged with its plugin id, and the *emitting* operation is
never failed — an `inventory.low` notifier must not `500` the stock write that
triggered it. This is the same isolation core already uses for its `request.*` /
`api.*` events, and the deliberate asymmetry with `dispatch()` (whose post-commit
`entry.*` listeners are allowed to matter and propagate) is preserved.

The dispatcher additionally gains a **re-entrancy cap** (`MAX_DEPTH = 8`) applied
across *every* delivery path. A listener may legitimately emit again (fan-out),
but a runaway loop is dropped at the ceiling — logged, not recursed — so a plugin
cannot hang a request or overflow the stack, through either `emit` or a listener
on a core event that emits. The depth counter unwinds in a `finally`, so a
throwing post-commit listener cannot leave it raised and silently starve every
later event in the same request.

### What this does **not** add

No new payload contract, no async/queued delivery, no cross-plugin veto, no way
to read another plugin's listeners, no filtering of who may subscribe. Emitting is
fire-and-forget and post-hoc, matching core's existing event contract. A plugin's
*own* emitted names and payloads are that plugin's contract to keep; core freezes
neither (see `docs/COMPATIBILITY.md`).

## Consequences

**Enables.** Inventory (and any plugin) can publish domain events for others to
consume with zero coupling — the choreography the initiative depends on. The
capability is general: notifications, webhooks (later, via H4), and audit plugins
are all natural consumers.

**Costs / makes harder.** The plugin-id rules are marginally stricter — four more
reserved roots — but they were never sensible plugin ids. A plugin author now has
two ways to misuse the bus (a bad name, an over-eager loop); both fail safely and
observably rather than silently. The `MAX_DEPTH` ceiling is a fixed constant; if a
real, legitimate fan-out ever needs more than eight levels, that is a signal to
reconsider the design, not to raise the number blindly.

**Debt.** Acceptable and low. The change is additive (`emit` alongside `listen`),
reuses the existing `emitBestEffort` path, and the reserved-root derivation keeps
the forgery guard self-maintaining. Regression tests cover verbatim namespacing,
best-effort isolation, malformed-name rejection, the depth cap on both delivery
paths, and depth unwinding after a throw; the loader test rejects reserved-root
ids.
