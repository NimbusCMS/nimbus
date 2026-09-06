# 30. Fine-grained plugin capabilities

- **Status:** Accepted
- **Date:** 2026-09-05
- **Supersedes:** —
- **Related:** [ADR 0015](0015-plugin-capabilities.md) (a plugin declares one
  wildcard-immune management capability), [ADR 0020](0020-plugin-admin-pages.md)
  (capability-gated plugin admin pages), [ADR 0011](0011-authorization.md) (the
  one authorization decision), [ADR 0009](0009-roles.md) (roles, still ahead).

## Context

ADR 0015 lets a plugin declare a wildcard-immune management capability (its own
id), but capped its actions to **`read` / `write`**. That was enough for a plugin
with one operator role. The first real application on the platform — the
Restaurant rebuild — has six staff roles with genuinely different permissions: a
cook must not take payment, a busboy only clears tables, a manager sees reports.
Stock Nimbus could not express that at the gate: one capability per plugin, two
actions. The app logged it as finding **F4** and worked coarsely
(`danmat.restaurant:write` for everyone) meanwhile.

Investigating the fix showed the model was *already* general almost everywhere:

- `Authorizer::can()` grants on an **exact `{resource}:{action}`** match, treats a
  plugin resource as wildcard-immune management, and applies the read/write
  implication to **content only** — so an arbitrary action like `kitchen` already
  authorizes correctly and stays wildcard-immune, with no change.
- `PluginToolset` gates each tool on `principal->can({pluginId}, $tool->action)` —
  it already passes the action through generically.
- `CapabilityRegistry::grantable()` already lists **each action as an independent
  grant**.

Only two places actually capped actions to read/write.

## Decision

Allow a plugin to declare **any action it likes** on its own capability, and to
gate admin pages, actions and MCP tools on any of them:

- `CapabilityRegistry::declare()` accepts an action matching
  `^[a-z][a-z0-9_]*$` (was: a subset of `{read, write}`). The grammar is
  load-bearing — it forbids `:` and `*`, so a declared action can never smuggle a
  second segment or a wildcard into the `{resource}:{action}` grant string it
  becomes. `read`/`write` still validate.
- `AdminPageRegistrar` accepts `{pluginId}:{action}` for any such action on the
  plugin's **own** capability (was: read/write only). A core management resource
  (`schema`, `media`, …) still gates on read/write only, and a plugin still cannot
  gate on another plugin's id or a content handle.
- `CapabilityRegistry::grantable()` labels a finer action as itself
  (`Restaurant: kitchen`), so the roles/token grant checklist stays legible.
- `PluginTool`'s `action` is documented as any declared action (it was already a
  runtime `string`).

Nothing else changed: the Authorizer, the Gate, `PluginToolset`, and the frozen
management set already generalise.

## Consequences

- **Security — no new reach.** A finer action is a management capability like any
  other: satisfied only by an exact grant or `admin`, never by the content
  wildcard, and independent of the plugin's other actions (a `:write` grant does
  not confer `:kitchen`). A plugin declares actions only under its own id;
  `declare()` still refuses a core management id or `admin`. Relaxing the admin
  gate is **fail-safe**: `Gate::holdsPageGate()` honours a plugin capability only
  once it is a frozen management resource, so a mistyped or undeclared action
  opens the page to `admin` alone — it can never fall through to the wildcard. The
  action grammar (no `:`, no `*`, no spaces, no leading digit, lowercase) is what
  keeps a declared action from forging a grant; it is regression-tested.
- **Reuse.** Any plugin with distinct operator roles can now express them —
  restaurant floor/kitchen/manage, a helpdesk agent/admin, an LMS teacher/grader —
  without a core change of its own.
- **Roles (ADR 0009) unchanged and unblocked.** This is the *primitive* a named-
  roles system will bundle; it is deliberately smaller than roles. A role is still
  a set of these grants.

## Follow-ups

- The Restaurant's **Staff & roles** slice is the first consumer; it re-gates its
  terminals on `floor` / `kitchen` / `manage` and closes ledger finding F4.
- Optional later diagnostic: warn at boot if an admin page is gated on an action
  its plugin never declared (today such a page is simply admin-only). Not needed
  for correctness.
