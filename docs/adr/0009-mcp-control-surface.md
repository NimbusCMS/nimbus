# 9. MCP as the CMS control surface

- **Status:** Implemented (Slices 1–7). MCP is live over HTTP + stdio for
  content, schema, media, users and tokens, with management actions audited.
  `settings:write` is reserved but **deferred** — there is no runtime settings
  store yet (site config lives in PHP files); it lands with a future DB-backed
  settings store.
- **Date:** 2026-08-17 (implemented 2026-08-18)
- **Related:** [ADR 0006](0006-non-human-authentication.md) (tokens/scopes),
  [ADR 0007](0007-write-api.md) (write path + concurrency),
  [ADR 0008](0008-openapi.md) (the contract MCP tools mirror), the admin services
  (`CollectionService`, `MediaUploader`, `Auth`, `ApiTokenRepository`).
- **Drives:** letting an agent run NimbusCMS through the Model Context Protocol —
  the reason the hardened API, scopes, concurrency and OpenAPI were built.
- **Reviewed by:** `nimbus-review-loop` (Core; the culmination of the API arc) and
  `nimbus-security-review` on **every** slice — management tools are the
  highest-privilege surface in the product.

## Context

The API is now a hardened, scoped, concurrent, self-describing read+write surface.
The goal it was all for: **an agent, holding a scoped token, can operate the CMS
through MCP — define content types, write content, manage media, users (incl.
role assignment via `set_role`), tokens and settings — so the admin UI is optional,
not required.** One surface is deliberately deferred: composing/editing a *role*
(a capability bundle) stays admin-UI-only for now (ADMIN-9; see ROADMAP for the
revisit trigger) — role *assignment* is already covered.

The risk is obvious: "configure everything from an agent" could become a second,
divergent CMS with its own weaker rules. The decision below prevents that.

## Decision

### One backend — MCP is a transport + a tool surface, never new logic

Every tool calls the **same services** the admin uses (`EntryService`,
`CollectionService`, `MediaUploader`, `Auth`, `ApiTokenRepository`, settings) —
same validation, transactions, events, and audit. MCP adds no business rules. The
scope-checked *content* path is extracted into a shared **`EntryOperations`** so
the HTTP API and MCP call one implementation, not two.

### Transports — both

- **HTTP:** `POST /api/v1/mcp`, JSON-RPC 2.0, inside the existing API group, so it
  inherits **bearer auth + rate limiting**. The remote, deployed-CMS story: point
  an agent at the URL with a scoped token.
- **stdio:** `nimbus mcp` speaks JSON-RPC over stdin/stdout for local desktop
  clients. It **still takes a scoped token** (from env) and enforces its
  capabilities — even locally, MCP is capability-scoped, never raw DB access.

Tools only for v1 (`initialize`, `tools/list`, `tools/call`); MCP resources /
prompts / sampling are out of scope until a concrete need appears.
*(Amended: that need has arrived for **resources** + the `initialize`
`instructions` field — agent guidance — added in [ADR 0013](0013-mcp-agent-guidance.md).
Prompts / sampling remain deferred.)*

### Capabilities — granular, with an `admin` grant, as the substrate for roles

Content scopes (`{handle}:read` / `{handle}:write`) exist. Management adds
**granular capabilities**, so a token is least-privileged:

`schema:write` · `media:read` / `media:write` · `users:write` · `tokens:write` ·
`settings:write` — with **`admin`** as the one cross-cutting super-grant.

Precisely (a Slice-1 security finding pinned this): `admin` grants everything;
an exact `{resource}:{action}` always suffices; and the content wildcard
`*:{action}` grants that action on every *collection* but **never** on a
management capability. So `*:write` ("write all my content") cannot silently mint
tokens, create users, or change settings — those escalate privilege and must be
granted explicitly. Management capabilities live in the same `resource:action`
namespace, so keeping the wildcard content-only is what stops that confusion.

These capabilities are deliberately the **atoms of a future roles system**: a role
will be a named bundle of capabilities, and a lower-privilege role grants exactly
the subset it permits. That RBAC layer is later; the capability model is designed
now so it slots in without rework. `TokenPrincipal::can(resource, action)` already
generalises to these — `can('schema', 'write')`, etc.

### Tools

- **Content** — per collection, **typed** (input schema from each field's
  `jsonSchema()`, ADR 0008) and **scope-filtered**: `list_{h}`, `get_{h}`,
  `create_{h}`, `update_{h}`, `delete_{h}`. Update/delete **require the entry's
  `version`** (read-before-write), the same If-Match contract as the API — MCP and
  HTTP never diverge on concurrency.
- **Management** — fixed, each **capability-gated**: collections & fields
  (`create_collection`, `add_field`, …), media (`upload_media`, …), users
  (`create_user`, `set_role`), tokens (`mint_token`, `revoke_token`), settings.

`tools/list` returns only the tools the presented token's capabilities allow — so
tool discovery itself is least-privilege.

### Every management action is audited

Content writes already emit `api.entry_written` (ADR 0007). Management writes emit
an analogous best-effort event that `nimbuscms/api-advanced` records, so an agent
reshaping the CMS leaves a full who-did-what trail.

## Consequences

**Enables**
- Agent-run CMS: the whole control surface reachable through one scoped, audited,
  rate-limited protocol.
- The capability model becomes the RBAC substrate — roles later bundle these atoms.
- MCP is a *proof* of the architecture: it adds a transport and a tool list, and
  everything else it needs already exists.

**Costs / makes harder**
- The largest privilege surface in the product. Mitigated by granular capabilities
  (deny-by-default), auditing management writes, and a security review per slice.
- Binary media over JSON-RPC needs base64 (with size caps) or a signed URL —
  decided in the media slice.
- Two transports to build and test.

**Out of scope** (later, on evidence): MCP resources/prompts/sampling; OAuth (we
use bearer/env tokens); the roles UI (capabilities land first).

## Slices (one milestone, built and reviewed in increments)

1. **Capabilities + shared `EntryOperations`** — the management capabilities in the
   scope model; extract the scope-checked content operations so HTTP + MCP share
   one path.
2. **MCP server core + HTTP transport + content tools** — JSON-RPC
   (`initialize`/`tools/list`/`tools/call`), `POST /api/v1/mcp`, the per-collection
   content tools + introspection (`list_collections`, `describe_collection`).
   Verified against a real MCP client.
3. **stdio transport** — `nimbus mcp`.
4. **Schema tools** — `schema:write` + collections/fields via `CollectionService`.
5. **Media tools** — `media:*` (base64 upload with caps).
6. **Users / tokens / settings tools**.
7. **Audit of management actions + docs + final review.**

Each slice lands CI-green with a `nimbus-security-review` pass and no open
Critical/High lacking a risk-acceptance ADR.
