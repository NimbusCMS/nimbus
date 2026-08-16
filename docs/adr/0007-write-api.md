# 7. A write API over the hardened programmatic surface

- **Status:** Accepted (direction approved; each slice's concrete API is designed
  in its implementation PR, as with ADR 0005/0006)
- **Date:** 2026-08-16
- **Related:** [ADR 0006](0006-non-human-authentication.md) (tokens, scopes, rate
  limiting, error contract — the base this sits on), [ADR 0002](0002-publication-lifecycle.md)
  (lifecycle), the read API (`Api\ApiController`, `Content\EntryService`).
- **Drives:** programmatic content management — the same operations the admin
  performs, over the API, for integrations, CI, and (later) MCP.
- **Reviewed by:** `nimbus-review-loop` (Core; passes the Platform Drift Guard —
  every headless CMS needs writes) and `nimbus-security-review` on every slice
  (writes are the highest-risk surface in the whole API).

## Context

The Programmatic Access Hardening milestone (ADR 0006) made the *read* API safe:
scoped, expirable, revocable tokens; enforcement deny-by-default at the query
layer; a structured error contract; rate limiting. The `write` half of every
scope has been dormant since it was defined. This ADR turns it on.

The guiding principle: **the API is a new transport in front of proven core, not
a second write path.** The admin already writes entries through `EntryService` /
`EntryInput`, which own validation, slugs, the transaction, post-commit events,
and — critically — **allow-list field binding** (only a collection's declared
fields are bound, which is the mass-assignment / over-posting guard). The write
API maps a JSON body to the same `EntryInput` and calls the same service. It must
not reimplement any of that.

## Decision

### Endpoints (under the existing group)

- `POST   /api/v1/collections/{handle}/entries`        → create  → `201 Created`
- `PATCH  /api/v1/collections/{handle}/entries/{slug}` → update  → `200 OK`
- `DELETE /api/v1/collections/{handle}/entries/{slug}` → delete  → `204 No Content`

A create returns the new entry (and a `Location`); an update returns the updated
entry. The body is `{ "title", "slug"?, "status"?, "fields": { … } }`, mapped to
`EntryInput`. Unknown field keys are dropped by the same allow-list the admin
uses — never merged into storage.

### A single `write` scope per collection

`{handle}:write` grants **create, update, delete, and status changes (including
publish)** for that collection — mirroring the admin's single per-collection
*manage* grant. Finer scopes (`publish`, `delete`) are split out later only if a
consumer needs the distinction (ADR-0001). Enforced deny-by-default at the
service layer, and — like reads — the scope is checked **before** the entry is
looked up, so a token without `handle:write` cannot tell a missing entry from one
it may not touch.

### Optimistic concurrency (ETag / If-Match)

To prevent lost updates between machine clients:

- Every entry carries a **monotonic `version`** (a new column, bumped by
  `EntryService` on each save). A read (`GET` one) returns it as a strong
  **`ETag`**.
- `PATCH` and `DELETE` **require `If-Match`**: absent → `428 Precondition
  Required`; present but stale → `412 Precondition Failed`; matching → proceed.
  So a client must have read the current version before it may overwrite it.

### Writes are audited

A new best-effort, isolated core event **`api.entry_written`** carries the acting
token and what changed (`token_id`, `token_name`, `collection`, `entry_id`,
`slug`, `action` ∈ create/update/delete). The `nimbuscms/api-advanced` plugin
records it, so its audit log becomes a full **who-changed-what** trail — the
write-attribution its rejection/denial log was missing. Emitted with its consumer
(ADR-0001), guarded so it never affects the response.

### Responses and errors (on the Slice-4 envelope)

- `201` / `200` return the entry; `204` for delete.
- Validation failure → **`422` `invalid`** with a `fields` map of messages.
- `403 forbidden` (out of `write` scope), `404 not_found`, `409 conflict` (a
  duplicate slug, from the DB uniqueness the admin already surfaces), `412` / `428`
  (concurrency). `401` unauthenticated as before.

### What stays the same

- **Auth / rate limiting**: writes go through the same bearer auth and count
  toward the per-token quota. No CSRF — bearer, not cookies.
- **Legacy compat**: a null-ability token stays `*:read` (read-only). It has no
  `write`, so it is already safe — **no forced re-mint**, contrary to the
  cautious note in ADR 0006, which is hereby relaxed: the compat is read-only, so
  the write API does not require removing it.

## Consequences

**Enables**
- Programmatic content management for integrations, CI, and MCP — all as thin
  consumers of one authorization + validation model, not a second backend.
- A complete audit trail (failures *and* writes) in `api-advanced`.

**Costs / makes harder**
- An entries migration (`version`) and an `EntryService` change (bump on save) —
  the one core change beyond the API layer; everything else reuses existing
  services.
- Every write endpoint must handle concurrency preconditions and map validation
  errors — deliberate friction on the riskiest surface.
- The authorization matrix grows a `write` dimension (a required test artifact).

**Out of scope here** (later, on evidence): bulk/batch writes; media upload over
the API; finer `publish`/`delete` scopes; idempotency keys for create; per-write
rate limits distinct from reads.

## Slices

0. **This ADR.**
1. **Concurrency foundation** — entry `version` column + `EntryService` bump;
   `GET` returns `ETag`; an `If-Match` helper. No writes yet.
2. **Write endpoints** — `POST`/`PATCH`/`DELETE` via `EntryService`, `write`-scope
   enforcement, `422` validation errors, `If-Match` on update/delete. The
   authorization matrix gains its write rows.
3. **Write auditing** — core `api.entry_written` + `api-advanced` records it.
4. **Docs + review** — COMPATIBILITY, a final `nimbus-security-review` pass.

Each slice lands CI-green (`composer check`) with a security-review pass and no
open Critical/High lacking a risk-acceptance ADR.
