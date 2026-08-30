# 20. Gating a plugin admin page on the plugin's own capability

- **Status:** Accepted; implemented. Closes a capability asymmetry surfaced by the
  `nimbus-security-review` of the Inventory/Commerce "Phase 1 Workbench" slice.
- **Date:** 2026-08-30
- **Related:** [ADR 0015](0015-plugin-capabilities.md) (a plugin's grantable,
  wildcard-immune management capability — the one this now lets the admin UI
  enforce), [ADR 0011](0011-authorization.md) (the one authorization function),
  [ADR 0016](0016-plugin-mcp-toolsets.md) (the MCP path that already enforced
  this), H3 plugin admin form actions (PR #194 — the write surface this protects).
- **Reviewed by:** `nimbus-review-loop` (classified Core; Drift Guard passed —
  broadly reusable, no app shape) and `nimbus-security-review` (which raised the
  finding) — before build.

## Context

A plugin declares a grantable, wildcard-immune **management** capability
(ADR 0015) — `nimbuscms.inventory:write`, "moving stock is moving money". Its MCP
tools gate on it, so an agent needs the capability to move stock or advance an
order, and the content `*:write` wildcard can never reach it.

The **admin UI did not.** `AdminPageRegistrar` accepted only `admin` or a *core*
management capability as a page gate; plugin capabilities were "not supported yet"
(deferred over an ordering concern — a page registers *during* plugin load, but
plugin capabilities are frozen into the `Authorizer` only *after* every plugin has
loaded). So both plugins registered their pages with **no capability** — meaning
**any signed-in user**, including a content-only editor, could open the Inventory
and Commerce pages and POST their form actions: receive/adjust stock, place/pay/
fulfil/cancel orders. The same operation the MCP path guards as money-grade was an
ungated backdoor in the UI. The `nimbus-security-review` rated this **High**
(privilege escalation for a low-privilege user; precondition: a multi-user site).
Phase 1 was about to add more money-movement verbs (pay/fulfil/cancel, count/
transfer) to that surface, so the gap is closed first.

## Decision

A plugin admin page (and its H3 actions, which inherit the page's capability) may
gate on **its own** capability — `{pluginId}:{read|write}` — in addition to
`admin` and a core management capability. Two pieces make this safe despite the
load-order trap:

1. **Registration validates structurally, not against the registry.**
   `AdminPageRegistrar` accepts `{pluginId}:{read|write}` where the resource equals
   *this* plugin's own id (which the loader binds — never chosen by the plugin). It
   does **not** consult the `CapabilityRegistry` (which may not yet hold the
   declaration at registration time). A plugin may gate only on its *own*
   capability, never another plugin's.

2. **Enforcement is fail-safe.** A new `Gate::holdsPageGate()` — the single gate
   the nav (visibility) and the plugin route (enforcement) both call, so they
   cannot drift — honours a plugin capability **only once it is a frozen management
   resource** (`Authorizer::isManagement()`, sealed at boot). An unknown or
   undeclared resource is refused to everyone but `admin`; it is *never* allowed to
   fall through to the content `*:action` wildcard. So a page gated on a
   capability the plugin forgot to declare (or mistyped) becomes admin-only —
   restrictive, not exposed.

No change to the `Authorizer` vocabulary or to its single `useManagement()` boot
seal (the `AuthorizerSealTest` invariant holds). Enforcement runs at request time,
after the seal — so the ordering trap that motivated the original deferral never
applies to the check, only to registration-time validation, which #1 sidesteps.

```php
// A plugin, at register():
$ctx->capabilities()->declare('Inventory', ['read', 'write']); // nimbuscms.inventory:{read,write}
$ctx->adminPages()->register('inventory', 'Inventory', '📦', $handler, 'nimbuscms.inventory:write');
// its POST actions inherit that capability automatically.
```

## Consequences

- The Inventory and Commerce admin pages (and every write action on them) now
  require the plugin's `:write` capability — parity with their MCP tools. A
  content-only editor no longer sees them in the nav nor can POST to them; `admin`
  and a granted holder do. The capability is already grantable in the Roles UI
  (ADR 0015), so an operator can hand "manage Inventory" to a staff role.
- A plugin gating a page on a capability it did not declare fails safe (admin-only)
  rather than exposing the page — surprising for that plugin author, but never a
  security regression.
- Cross-plugin gating (page in plugin A gated on plugin B's capability) is
  deliberately **not** supported; no consumer needs it, and it would reintroduce a
  load-order dependency. It can be added later if a real case appears.

## Alternatives considered

- **Validate the declaration at registration** (give the registrar the
  `CapabilityRegistry`): reintroduces the ordering trap (a page may register before
  the plugin declares, or before a depended-on plugin loads). Rejected for the
  structural + fail-safe-enforcement pair, which needs no ordering guarantee.
- **A boot-time assertion that every gated plugin page names a frozen capability:**
  would hard-fail the whole site on one misconfigured third-party plugin. The
  request-time fail-safe (admin-only) is a gentler, equally safe default.
- **Accept the risk with no gate (ADR-accepted login-only):** rejected — the
  operation is money/stock movement, and the platform already treats it as
  wildcard-immune everywhere else.
