# 8. A generated OpenAPI description of the API

- **Status:** Accepted (direction approved; the document's concrete shape is
  built in the implementation slices)
- **Date:** 2026-08-17
- **Related:** [ADR 0006](0006-non-human-authentication.md) (auth/scopes/errors),
  [ADR 0007](0007-write-api.md) (the write surface this describes), the field
  contract (`Content\FieldType`).
- **Drives:** a machine-readable contract for the `/api/v1` read+write surface —
  Swagger UI, typed client SDKs (codegen), and the MCP layer that comes next,
  which introspects it.
- **Reviewed by:** `nimbus-review-loop` (Core; a headless CMS with a public API
  wants a spec) and `nimbus-security-review` on the serving slice.

## Context

The API surface is stable and hardened, but a consumer has to read prose to use
it. An OpenAPI document turns it into a contract tools understand. The wrinkle:
the content model is **user-defined at runtime** — collections and fields live in
the database — so the spec cannot be hand-written. It must be **generated** from
the live model.

## Decision

### Generated from the live model

An `OpenApiGenerator` builds an OpenAPI **3.0** document from three inputs:

- the **fixed shape** — `info`, `servers`, the bearer **security scheme**, and
  reusable components: the error envelope (with the stable `code`s), the
  pagination `meta`, and the `ETag`/`If-Match` parameters;
- the **collections** (from `CollectionRepository`) — each becomes the read/write
  paths (`GET` list + item, `POST`, `PATCH`, `DELETE`) with its scope requirement;
- each collection's **fields** — assembled into a request/response schema.

### Field types describe their own schema

`FieldType` gains **`jsonSchema(Field): array`** — a JSON-Schema fragment for the
field's wire value, the schema-language sibling of the existing `toApi()`.
`BaseType` supplies a `{ "type": "string" }` default, so every field type — the
built-ins *and* plugin types like Markdown, which all extend `BaseType` — keeps
working with no change; a type overrides when it can say more (number → number,
boolean → boolean, date → string/date, email/url → string+format, select → enum,
relation → array of `{id,slug,title}`, media → nullable object). This keeps the
API's shape defined by the field types, exactly as `toApi()` already does — not
by a mapping table that drifts from them.

### Served two ways

- **`GET /api/v1/openapi.json`** — the live spec, always current, **behind the
  same bearer auth** as the rest of the API (it is a contract for authenticated
  clients; the schema is metadata, not content). It sits inside the rate-limited
  API group.
  - **Amended (Slice B, 2026-08-23):** the HTTP spec is now **scoped to the
    presenting token** — it lists only the collections the token can `read`, with
    write operations only where it can `write`. The original "full model is shown;
    a scope-filtered per-token spec is a later refinement" decision is
    **superseded**: an unfiltered spec let a single-collection token enumerate the
    whole content model, defeating the non-enumeration guarantee (`403==404`) the
    rest of the surface enforces. The spec now varies per caller (so it is
    per-token, not cache-shared — use `Vary: Authorization` if fronted by a cache);
    the full document remains available via the CLI below.
- **`nimbus openapi`** — a CLI that prints the **full** document (`generateFull()`),
  for build pipelines that commit a snapshot or run SDK codegen offline. The CLI is
  a trusted local operator (it already holds the database), so it is not
  scope-limited — matching the CLI token-mint exemption in ROLES.md.

## Consequences

**Enables**
- Swagger UI / Redoc, typed SDKs via codegen, contract tests — and the **MCP**
  server, which can derive its tool list from this document.
- The spec can never lie about the field shapes: it is generated from the same
  types that serialize them.

**Costs / makes harder**
- A small addition to the `FieldType` contract (`jsonSchema()`), defaulted in
  `BaseType` so nothing breaks.
- The generator must track the routes/components by hand (there is no framework
  reflection) — acceptable for a handful of endpoints; a route-contract test
  keeps it honest.

**Out of scope here** (later, on evidence): a bundled Swagger UI page (clients
point their own at the JSON); scope-filtered per-token specs; OpenAPI 3.1.

## Slices

0. **This ADR.**
1. **`FieldType::jsonSchema()`** + `BaseType` default + per-type overrides.
2. **`OpenApiGenerator`** — build the document from collections + fields + the
   fixed routes/components; a test asserts its structure.
3. **Serve it** — `GET /api/v1/openapi.json` (auth-gated) + `nimbus openapi` CLI +
   COMPATIBILITY, with a security-review pass.

Then, on this contract: **MCP**.
