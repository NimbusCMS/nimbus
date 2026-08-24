# 13. MCP agent guidance (instructions + resources), and a plugin skill capability

- **Status:** Accepted; implementing (Slice 1 = core guidance; Slice 2 = the
  plugin skill capability). Amends [ADR 0009](0009-mcp-control-surface.md), which
  recorded MCP resources/prompts as "out of scope until a concrete need appears" —
  that need has now arrived (see Context).
- **Date:** 2026-08-24
- **Related:** [ADR 0009](0009-mcp-control-surface.md) (the MCP control surface),
  [ADR 0001](0001-plugin-contract.md) (plugins are trusted in-process
  code — the trust boundary this ADR does **not** change),
  [ADR 0006](0006-non-human-authentication.md) (token principals/scopes),
  [ADR 0008](0008-openapi.md) (the surface describing itself — the precedent).
- **Drives:** making any MCP-speaking agent a *pro user* of Nimbus out of the box,
  and letting an installed plugin teach agents its own surface automatically.
- **Reviewed by:** `nimbus-review-loop` (Core; classified the MCP-delivery
  mechanism and the plugin-capability sequencing) and `nimbus-security-review`
  (the plugin-text-to-agent injection/reach surface) — both **before** build.

## Context

An agent connecting over MCP today gets tool names and JSON schemas but none of
the *operating knowledge* that makes it competent: read-before-write via
`If-Match`/`version` (a stale write is a `412`), subset-only granting, the
no-batch rule, reserved handles, the `{code, message}` per-field validation
shape, singletons, the media in-use guard, the management-vs-content scope
boundary. Every one of those is a documented sharp edge an agent currently
discovers by failing. Nimbus already stakes its positioning on the agent being a
first-class operator (ADR 0009); an agent that must trial-and-error the surface
undercuts exactly that claim.

Two requirements shape the solution:

1. **It must ship *with* the CMS and be agent-agnostic** — free on every install,
   working identically for Claude Code, Cursor, or any MCP client, with nothing
   for the operator to install into their agent.
2. **A plugin must be able to ship its own guidance**, so enabling a plugin that
   adds (say) a Markdown field type also teaches agents how to drive it — without
   the plugin gaining any new privilege.

A generated per-agent skill file (`.claude/skills/*.md`, Cursor rules, …) fails
requirement 1: it lives in the *agent's* tree, must be installed per agent and
per project, and goes stale against the running install (version, enabled
plugins). The one interface every agent already uses to drive Nimbus is **MCP**.
So guidance is delivered *through the MCP server itself*.

## Decision

**1. Deliver agent guidance over MCP, through two standard channels.**

- **`initialize` result `instructions`** (the MCP-spec optional field): a tight,
  **core-authored**, always-in-context operating guide — how the tools compose and
  the cross-cutting rules (scopes, concurrency/`412`, validation shape, no-batch,
  rate limits, audit trail). Bounded in size; a static core payload.
- **MCP Resources** (`resources/list` + `resources/read`; advertise
  `capabilities.resources = {subscribe: false, listChanged: false}`): the full
  guide as read-on-demand documents, so depth does not inflate every connect.
  URIs are **keys into an in-memory registry**, never filesystem paths derived
  from the URI: `nimbus://guide/core`, plus (Slice 2) `nimbus://guide/plugin/{id}`
  per enabled plugin that registered a fragment. An unknown/malformed/`../`/
  `file://`/not-enabled URI returns the MCP resource-not-found error (`-32002`),
  uniform with the toolsets' non-enumerating "unknown tool".

Both channels are assembled in **one place** — a single `McpServerFactory` shared
by the HTTP transport (`POST /api/v1/mcp`) and the stdio transport
(`nimbus mcp`) — so the two transports serve identical guidance and cannot drift.
`serverInfo.version` is sourced from the real CMS version, and the guide is
versioned with it.

**MCP Prompts remain deferred** (Phase 2): no concrete client need is
demonstrated, and a prompt template re-opens the same injection questions.

**2. Plugins contribute guidance through a new, data-only capability.**

A seventh plugin capability (a skill registrar on `PluginContext`) lets a plugin
register a **static markdown fragment** (`{ id, title, body }`) — never executed
code, no interface a plugin implements, just data. It joins the existing six
registrars and rolls back through the same `forgetProvider($id)` path (a failed
plugin leaves no fragment). An over-long fragment is **rejected at registration**
(→ the loader's existing `REGISTER_FAILED` containment), mirroring the id/name
gates. Per the platform loop's "a capability published without a consumer is a
guess that becomes a commitment," the registrar ships **with its first real
consumer** — the official Markdown plugin's fragment — not empty.

**3. Plugin-authored text reaches an agent as on-demand *resources only* — never
`initialize.instructions`.** This is the load-bearing control (see
Consequences). The core `instructions` stay core-authored and teach the
*discovery affordance* ("enabled plugins may publish guides; `resources/list`
shows them"). A plugin guide enters an agent's context only when the agent
chooses to read it, and `resources/read` wraps it in an envelope that names the
owning plugin and frames it as **reference documentation, not instructions —
trusted exactly as much as that plugin's code**.

**4. The guide is static, platform-neutral content.** It documents *capabilities*
(never a site shape), interpolates **no** live install values (collection
handles, field labels, or `settings` values — those are a second injection sink
and are what `list_collections`/`describe_collection` are for), and contains no
literal privileged example invocations (e.g. a ready-to-run "grant admin" call)
that an injected context could weaponize as "the docs said to."

## Consequences

**The trust boundary is unchanged.** ADR 0001 already makes an installed plugin
trusted in-process code that can write `nb_*` directly; surfacing its *text* to an
agent grants it **no new authorization**. Every privileged tool re-checks `can()`
server-side and applies subset-only on `mint_token`/`set_role`, so an injected
instruction can only drive actions the **token the operator handed the agent**
already authorizes — it cannot escalate past that token. What is genuinely new is
*reach*: the plugin's text can influence the operator's agent in sessions the
plugin's own code never runs in. Decision 3 (resources-only + attribution) is the
mitigation; the irreducible residual — a naive client acting on delimited plugin
text — is bounded by the operator scoping the token they give the agent, which the
core guide reinforces.

**Enables later:** MCP Prompts and generated per-agent artifacts from one source;
the versioned website docs; every future toolset or plugin arriving
pre-documented to agents.

**Costs:** the `nimbus://guide/*` URI scheme and the skill-registrar shape become
compatibility surface (pre-1.0 evolving, noted in COMPATIBILITY); `initialize`
stops being a static payload (the factory absorbs the plugin/version state); every
behavior change now has a prose shadow to keep true — bounded by a guide-coverage
drift test (every management tool name and error code must appear in the guide)
and by versioning the guide with the CMS.
