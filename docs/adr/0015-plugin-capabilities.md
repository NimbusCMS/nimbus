# 15. Grantable plugin capabilities (H2a)

- **Status:** Accepted; implemented. The keystone's first half — the second of the
  four plugin-boundary capabilities for the Inventory + Commerce initiative
  ([ADR 0014](0014-plugin-event-dispatch.md) was H1). The plugin **MCP toolset**
  half (H2b) is deferred to build with Inventory, its first consumer.
- **Date:** 2026-08-28
- **Related:** [ADR 0011](0011-roles.md) (the `Authorizer` decision this extends),
  [ADR 0001](0001-plugin-contract.md) (plugins are trusted in-process code),
  [ADR 0014](0014-plugin-event-dispatch.md) (the same "namespace verbatim" move,
  applied to events).
- **Drives:** an Inventory plugin declaring `inventory:write` — a capability an
  admin can grant and a token can carry, that the content `*:write` wildcard can
  never reach, so a ledger of money-grade stock is not movable by "write all my
  content."
- **Reviewed by:** `nimbus-review-loop` and `nimbus-security-review` — both on the
  H2 design before this build. They converged: build the grant half (H2a) now with
  three controls in-scope, defer the tool half (H2b), and they caught that the
  first design **silently re-opened FU-4** (see below).

## Context

The [`Authorizer`](../../src/Auth/Authorizer.php) is the one deny-by-default
decision every principal runs through (ADR 0011). Its load-bearing rule is
**management-immunity**: a *management* resource — `schema`/`media`/`users`/
`tokens`/`settings`/`roles`, a hard-coded const — needs an exact grant or `admin`;
the content wildcard `*:write` never reaches it, so "write all my content" cannot
manage the site.

Inventory needs a capability with exactly that property: stock is money, so
`inventory:write` must be un-reachable by a broad content token. But `inventory`
is a plugin-invented string, not in the const — so today
`can(['*:write'], 'inventory', 'write')` returns **true**, and any `editor` role or
"all collections" token could move stock. The security review rated this **A1,
Critical**. It is structural: no careful tool code fixes an authorizer that treats
`inventory` as content.

The fix is to let a plugin add its own resource to the management vocabulary. The
danger is that this is the highest-privilege plugin surface yet, so the dual-review
required the collision surface be closed *in this slice*. It also caught that the
naive design re-opened a **fixed** finding: `CollectionService::RESERVED_COLLECTION_HANDLES`
reserves `Authorizer::MANAGEMENT ∪ {admin}` so a collection can't be named after a
management resource (FU-4); a plugin declaring `inventory` as management would make
any collection named `inventory` be judged under management rules — silently
flipping an existing collection's authorization on plugin install.

## Decision

A plugin declares one grantable management capability through a new registrar:

```php
$ctx->capabilities()->declare('Inventory', ['read', 'write']);
```

The **resource is the plugin's id, verbatim** (bound by the loader, never passed),
and the id **must be namespaced** — contain a dot, like `nimbuscms.inventory`.
That single rule closes every collision structurally, the same way H1's verbatim
event prefix did:

- **Two plugins can't collide** — ids are unique (`PluginLoader`).
- **A plugin can't shadow a core management name** — `schema`/`media`/… are flat,
  so a dotted id can never equal one.
- **A plugin resource can't collide with a collection handle (the FU-4 re-open).**
  `Str::handle()` folds any dot to `_`, so a dotted resource is *not a possible
  handle*: no collection can ever be named `nimbuscms.inventory`, in either
  direction. `CollectionService` needs **no change** — the collision cannot arise.

The declared set flows to three places:

1. **[`Authorizer::can()`](../../src/Auth/Authorizer.php)** consults core `MANAGEMENT`
   ∪ the plugin set (`isManagement()`), so a plugin capability is wildcard-immune,
   and `holds()` gives subset-only granting for free (you can't grant
   `inventory:write` unless you hold it or `admin`).
2. **The roles grant UI** ([`RolesController`](../../src/Admin/RolesController.php)) —
   the checklist and its server-side allow-list include the plugin capabilities, so
   an admin can grant them and a posted one isn't dropped.
3. Nothing else needs wiring: the token grant surface reaches management only via a
   bound role (so #2 covers it), and `AdminPageRegistrar` stays core-only (see
   Consequences).

### The seal

`Authorizer` holds the plugin set in a process-static installed **once**, at the
end of plugin load, from the frozen `CapabilityRegistry`
(`Application::loadPlugins`). `can()` is a pure decision on hot paths, so the
vocabulary is sealed at boot and never mutated at request time. A drift test
(`AuthorizerSealTest`) holds that `useManagement()` has exactly one caller. This is
the boot-frozen-static the review approved over threading a service through every
`can()` caller (huge blast radius, no security gain); a test-only `reset()` keeps
the static from leaking between unit tests.

### Scope — what this is not

Deferred to **H2b** (build with Inventory): plugin-registered **MCP toolsets** —
the `mcp()->register()` seam, its `McpServerFactory` composition, per-tool gating,
and tool-name namespacing. That API is frozen only once its one consumer has
exercised it. Also deferred: plugin content-style (wildcardable) scopes, multiple
capabilities per plugin, and hierarchies — none needed for Inventory.

## Consequences

**Enables.** A trustworthy money-grade Inventory, and the same seam for every
future privileged plugin (Forms, Webhooks, the named-next CRM) — a general CMS
capability, recommended even absent the validation projects.

**Costs / makes harder.** Scope strings are fully-qualified (`nimbuscms.inventory:write`)
— longer, but the UI shows a friendly label, and the dot is what makes them safe.
A capability-declaring plugin must use a namespaced id; every official/conventional
id already does, and a flat id fails the declaration with a clear message.

**Deferred, recorded as follow-ups:**
- A plugin cannot yet gate an **admin page** on its own capability: a page is
  registered *during* `register()`, but the plugin set isn't sealed into the
  Authorizer until every plugin has loaded, so `AdminPageRegistrar::isGateableCapability`
  stays core-only to avoid an ordering trap. Admin pages are GET-only today (forms
  are H3) with no consumer — deferred, not broken.
- The RolesController **HTTP grant round-trip** for a plugin capability is covered
  indirectly (the enforcement is unit-tested in `AuthorizerTest`; `grantable()`
  output in `CapabilityRegistryTest`; the controller change is a data merge). A
  full round-trip needs a capability-declaring fixture plugin in the HTTP kernel —
  filed for when Inventory provides a real one.

**Debt.** Acceptable and low. Additive (`capabilities()` alongside the other
registrars), reuses the loader's rollback path, and the dotted-resource invariant
keeps the collision guarantees structural rather than checked. Regression tests
cover the A1 wildcard-immunity, subset-only granting of plugin caps, the
declaration validation, the structural handle guarantee, the boot-seal invariant,
and the load-rollback completeness tripwire.
