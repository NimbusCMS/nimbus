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

Implemented methods: `initialize`, `tools/list`, `tools/call`, `ping`,
`resources/list`, `resources/read`.

**Agent guidance (ADR 0013).** `initialize` returns an `instructions` brief, and
`resources/list` / `resources/read` serve the operating guide as markdown
documents — `nimbus://guide/core`, plus `nimbus://guide/plugin/{id}` for each
enabled plugin that ships one. This means any MCP client gets Nimbus's operating
knowledge for free, with nothing to install. Resource URIs are registry keys
(never file paths); an unknown URI is a resource-not-found. MCP prompts and
sampling are **not** implemented (and not advertised).

**One request per call.** JSON-RPC batches (a top-level array of messages) are
**not supported** — a batch is answered with a single `Invalid Request`
(`-32600`) error, never silently dropped. MCP protocol `2025-06-18` removed
batching, so a compliant client never sends one; the rejection also keeps the
per-request rate limit meaningful (one HTTP request cannot fan out into many
tool calls).

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

A write that fails validation returns a tool error (`isError: true`) whose
`error` carries a machine `code` and a **`fields`** map of `{ code, message }`
per input — so an agent can branch on the code and self-correct (`required` →
supply the field; `invalid` → fix the value) rather than parsing prose. A
`missing_provider` error is top-level with no `fields`. The vocabulary is the
same additive-only set as the HTTP API (see
[COMPATIBILITY.md](COMPATIBILITY.md)); treat an unknown code as `invalid`.

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

- `list_users` (id, email, name, assigned **roles**), `list_roles`, `create_user`, `set_role`
- `list_tokens`, `mint_token`, `revoke_token`, `pause_token`, `resume_token`

Users are authorized by the **roles** system: `create_user` and `set_role` take a
**role name** (see `list_roles` for the assignable names + their capabilities) and
assign it in `nb_user_roles` — the legacy `nb_users.role` string is not the
authority. `create_user` defaults to the `editor` role; `set_role` replaces a
user's role assignments. Both tools require the roles system to be seeded
(`roles:seed`).

Like `mint_token`, they are **subset-only** — you can only assign a role whose
every capability your token already holds, so a `users:write` token cannot mint or
promote an admin. `set_role` checks this in **both directions**: it also refuses to
change a role the target already holds that you could not grant, so a lesser
manager cannot strip a superior. Generated passwords are returned **once** and
never persisted, logged or audited. `set_role` will not demote the last holder of
the `admin` role (counted by role assignment).

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
