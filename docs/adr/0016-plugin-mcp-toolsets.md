# 16. Plugin MCP toolsets (H2b)

- **Status:** Accepted; implemented. The second half of the H2 keystone — the
  third of the four plugin-boundary capabilities for the Inventory + Commerce
  initiative. Completes what [ADR 0015](0015-plugin-capabilities.md) (H2a) began:
  a plugin declares a capability *and* the agent tools that gate on it.
- **Date:** 2026-08-28
- **Related:** [ADR 0009](0009-mcp-control-surface.md) (the MCP control surface
  this extends), [ADR 0015](0015-plugin-capabilities.md) (the capability a plugin
  tool gates on), [ADR 0013](0013-mcp-agent-guidance.md) (plugin-authored text is
  a definition, never the instruction brief), [ADR 0001](0001-plugin-contract.md)
  (plugins are trusted in-process code).
- **Drives:** an Inventory plugin exposing `receive` / `adjust` / `count` as agent
  tools — ledger appends that cannot be expressed as content writes — so the
  agent-driven onboarding the initiative is built around works over MCP from day
  one.
- **Reviewed by:** `nimbus-review-loop` and `nimbus-security-review`, on the H2b
  design before this build. They required two controls be **structural** (below).

## Context

An agent runs Nimbus over MCP (ADR 0009): `McpServerFactory` composes a fixed list
of core toolsets — schema, media, users, tokens, settings, content — and the
server dispatches `tools/call` to the first toolset that owns the name. Plugins
had no seam here: `PluginContext` could not add a toolset.

Inventory needs one. Its catalog is content (collections + entries, rides the
existing content tools for free), but its *operations* — receiving stock,
adjusting, counting — are appends to an append-only movement ledger in the
plugin's own tables. Those are not content writes; they need bespoke tools. And
they are privileged: moving stock is moving money, so each tool must gate on the
plugin's `inventory:write` capability (ADR 0015), unreachable by a content token.

The risk is that this is the agent-effect surface. The review required two
controls not be left to the plugin author's diligence:

1. a plugin must not be able to ship an **ungated** tool;
2. a plugin must not **collide** with a core tool name, nor with a peer plugin's.

## Decision

A plugin registers one toolset:

```php
$ctx->mcp()->register(new InventoryToolset($ctx->storage(), $ctx->events()));
```

by extending a base — `PluginToolset` — that owns both controls, so a subclass
declares only *what its tools are*, never the gate or the name-spacing:

```php
final class InventoryToolset extends PluginToolset {
    public function namespace(): string { return 'inventory'; }
    protected function tools(): array {
        return [
            new PluginTool('receive', 'write', 'Receive stock into a location', $schema, $this->receive(...)),
            new PluginTool('stock',   'read',  'Current stock for a SKU',        $schema, $this->stock(...)),
        ];
    }
}
```

**1. Every tool gates — structurally.** Both `definitions()` (what a token may see)
and `call()` (what it may run) check `principal->can({pluginId}, tool.action)`
against the plugin's own management capability (ADR 0015). A `write` tool needs
`{pluginId}:write`, so the content `*:write` wildcard can't reach it; a token
without the capability cannot even *enumerate* the tool — a denied name reports as
`Unknown tool`, matching core's non-enumeration. The `pluginId` is **bound by the
registrar** from the loader-verified id, never passed by the plugin, so a toolset
can't gate on (or masquerade as) another plugin's capability. The author writes no
`->can()` call; it lives in the sealed base and cannot be forgotten.

**2. Every name is namespaced — structurally.** A tool declared `receive` is
advertised and dispatched as `{namespace}_receive`. The namespace
(`[a-z][a-z0-9]*`) is validated and checked **unique across plugins** at
registration — a collision fails the plugin's load, like a duplicate admin-page
slug. Core toolsets are composed **before** plugin ones, so a fixed core name is
always claimed by core regardless.

Composition threads one new registry (`McpToolsetRegistry`) from `Application`
through the single `McpServerFactory::build()` seam both transports share, and
spreads the plugin toolsets **after** the core ones. A failed plugin's toolset is
rolled back with its other registrations.

### Trust (ADR 0013)

Plugin tool names and descriptions enter the agent's tool list as *definitions*,
never the always-in-context instruction brief; combined with the mandatory gate, a
mis-described tool still cannot act beyond its capability. No manifest or
name-allow-list is added — the two structural controls are the smallest that close
the holes.

### Scope — what this is not

`PluginTool.action` is `read`/`write` only, tied to the H2a capability actions. No
per-tool custom capabilities, no plugin-defined MCP *resources* or *prompts* (ADR
0009 keeps those core), no batching (the server rejects batches, unchanged).

## Consequences

**Enables.** Inventory's agent-driven operations, and the same seam for any future
privileged plugin's tools (Forms export, Webhooks management, CRM). With H1
(events) and H2a (capabilities), a plugin can now declare a capability, expose
agent tools that gate on it, and emit events — the full triad Inventory composes.

**Costs / makes harder.** Tool names carry a namespace prefix (`inventory_receive`)
— longer, but unambiguous and collision-free. A plugin toolset must extend the
base rather than implement the raw `Toolset` interface; that is the point (the base
is where the un-forgettable gate lives).

**Deferred, recorded as follow-ups:**
- **No audit event on a plugin-tool denial.** Core management tools emit
  `api.access_denied` on a scope refusal; a plugin cannot emit a core event (H1
  reserves the `api` root), and threading a core recorder into the base was judged
  disproportionate for v1. The non-enumeration property holds; the audit of
  plugin-tool denials waits for a real consumer (an activity-log plugin).
- **Core-vs-plugin tool-name collision** is prevented by composition order (core
  wins `call()`), but a plugin *advertising* a name equal to a core tool would show
  a duplicate in `tools/list`. A hard reject needs the factory to thread the core
  name set; filed, low risk (plugins use a distinctive product namespace).

**Debt.** Acceptable and low. Additive (`mcp()` alongside the other registrars),
reuses the existing `Toolset` interface and the single-factory seam, and the two
controls are structural (in the base + the registrar), not lint. Tests cover the
gate on both `definitions()` and `call()`, the non-enumeration denial, the
namespacing, deferral of unowned names, namespace uniqueness + shape, and the
load-rollback tripwire.
