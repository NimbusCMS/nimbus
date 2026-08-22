# MCP — the agent control surface

NimbusCMS speaks the [Model Context Protocol](https://modelcontextprotocol.io).
An agent holding a scoped token can operate the CMS — read and write content,
define content types, manage media, users and tokens — through the **same
services the admin uses**, with the same authorization, validation, concurrency
and audit trail. MCP adds a transport and a tool list; it adds no business rules.

This is by design, not a bolt-on: the scoped tokens, deny-by-default scopes,
optimistic concurrency and generated OpenAPI were all built so that **an agent is
a first-class operator**. See [ADR 0009](adr/0009-mcp-control-surface.md).

## Transports

Both speak JSON-RPC 2.0 and share one server implementation, so they never
diverge:

- **HTTP** — `POST /api/v1/mcp`, inside the API group, so it inherits bearer auth,
  the per-IP flood guard and the per-token rate limit. Point a remote client at
  the URL with a scoped token. Notifications return `202` with an empty body.
- **stdio** — `nimbus mcp` speaks JSON-RPC over stdin/stdout for a local desktop
  client. It resolves a scoped token from `NIMBUS_MCP_TOKEN` (exactly as the HTTP
  bearer path does), so even locally the session is capability-scoped — never raw
  database access.

Implemented methods: `initialize`, `tools/list`, `tools/call`, `ping`.

## Authorization — scoped tokens

Every session is a **standalone token principal** (ADR 0006): its authority is
its granted scopes, never a user's. Mint one with the CLI:

```
nimbus token:create --name="My agent" --scopes=posts:read,posts:write
nimbus token:create --name="Site admin" --scopes=admin
```

A scope is `resource:action`:

- **Content** — `{handle}:read`, `{handle}:write` (a collection handle).
- **Management** — `schema:write`, `media:read`, `media:write`, `users:write`,
  `tokens:write`, `settings:read`, `settings:write`.
- **`admin`** — the one cross-cutting super-grant (every action, every resource).

The content wildcard `*:{action}` grants that action on every *collection* but
**never** a management capability — so `*:write` ("write all my content") cannot
mint tokens, create users or change schema. Those must be granted explicitly (or
via `admin`).

`tools/list` returns **only** the tools a token's scopes allow, and calling
anything else is reported as an *unknown tool* — so a token cannot enumerate what
lies outside its scope. Every scope denial and every write is audited.

## Tools

### Content (per collection, typed)

Generated from each collection's fields, so tool inputs match the model:

- `list_{handle}`, `get_{handle}`, `create_{handle}`, `update_{handle}`,
  `delete_{handle}`
- Introspection: `list_collections`, `describe_collection`

`update_*` and `delete_*` **require the entry's `version`** (read-before-write):
`get_*` returns the current `version`; a write that presents a stale one is
refused, so two clients cannot silently clobber each other.

> **Reserved handles.** Management tool names win a name clash, so a collection
> whose handle is `collections`, `media`, `users`, `tokens`, `collection` or
> `field(s)` has some of its content tools shadowed by the management/media tools.
> This never grants extra access (the management tool enforces its own
> capability) — it just makes those content tools unreachable by that name.
> Avoid those handles for content collections.

### Schema (`schema:write`)

- `create_collection`, `update_collection`, `add_field`, `remove_field`,
  `set_fields`, `delete_collection`

The handle is immutable (renaming would orphan scopes + API paths). Field types
are validated. `delete_collection` is irreversible and requires `confirm: true`,
reporting the entry count it would destroy.

### Media (`media:read` / `media:write`)

- `list_media`, `get_media`, `media_usage`, `upload_media`, `delete_media`

`upload_media` takes base64 bytes; the type is verified from the bytes (not the
filename), the stored name is random, and the size is capped. `delete_media` is
**refused when content still references the file**, pinpointing where — call
`media_usage` first and detach those references.

### Users (`users:write`) and tokens (`tokens:write`)

- `list_users`, `create_user`, `set_role`
- `list_tokens`, `mint_token`, `revoke_token`, `pause_token`, `resume_token`

`mint_token` can only grant **scopes the minter already holds** — no privilege
escalation. Minted token secrets and generated passwords are returned **once** in
the result and never persisted, logged or audited. `set_role` will not demote the
last admin.

### Settings (`settings:read` / `settings:write`)

- `get_settings`, `set_settings`

The site settings store (site title, home page, default meta description).
`get_settings` needs `settings:read` (or `settings:write`); `set_settings` needs `settings:write`
— a management capability, so a content `*:write` scope cannot reach it. Only
**known** keys are accepted (`set_settings` with an unregistered key is rejected —
no arbitrary rows), each value is validated (a home must name a real collection; a
description is length-bounded), and every write is audited. Deploy/environment
configuration is **not** here — it stays in `.env` + `config/*.php`.

## Connecting a client

**stdio** (e.g. a desktop MCP client):

```json
{
  "command": "php",
  "args": ["bin/nimbus", "mcp"],
  "env": { "NIMBUS_MCP_TOKEN": "nbt_…" }
}
```

**HTTP**: point the client at `https://<host>/api/v1/mcp` with an
`Authorization: Bearer nbt_…` header.

## Audit

Content writes emit `api.entry_written` and management actions emit
`api.management_written`. The `nimbuscms/api-advanced` plugin records both (plus
rejected tokens and scope denials) into an audit log with an admin page — a full
who-did-what trail of everything an agent does.
