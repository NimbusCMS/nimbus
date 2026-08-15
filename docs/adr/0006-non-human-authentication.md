# 6. Non-human authentication and authorization (API tokens)

- **Status:** Accepted (direction approved; each capability's concrete API is
  designed in its implementation PR — as with ADR 0005)
- **Date:** 2026-08-15
- **Related:** the headless read API (`Api\ApiController`,
  `Http\Middleware\ApiAuthMiddleware`), [ADR 0001](0001-plugin-contract.md)
  (contracts added one proven consumer at a time),
  [`docs/CHARTER.md`](../CHARTER.md).
- **Drives:** the **Programmatic Access Hardening** milestone — scoped, expirable,
  revocable API tokens with a stable authorization model — and everything that
  later sits on it (a write API, integrations, CLIs, CI, and MCP).
- **Reviewed by:** `nimbus-review-loop` (Core capability; passes the Platform
  Drift Guard — every headless CMS needs this, independent of any one app) and
  `nimbus-security-review` (baseline audit found no Critical/High in shipped
  code; this ADR installs the controls **before** a scoped/write API makes their
  absence High).

## Context

The read API authenticates a request with a bearer token
(`ApiAuthMiddleware` → `ApiTokenRepository::findByPlaintext`). Tokens are stored
as a SHA-256 hash, shown once at creation, and matched by hash — a good
foundation. But the model stops at *authentication*, and a baseline security
audit of current `main` found the programmatic-access surface missing every
control a real API needs:

1. **No authorization identity reaches the controller.** The middleware validates
   the token, stamps `last_used_at`, and returns `null`; the token — and its
   dormant `abilities` — is discarded. `Http\Request` is an immutable `readonly`
   value with no attribute bag, so a controller cannot learn *who* is calling.
2. **Scopes are inert.** The `abilities` JSON column is stored but never enforced;
   every valid token can read the entire live set.
3. **Tokens are immortal.** No `expires_at`, no `revoked_at`; the CLI mints
   (`token:create`) but cannot revoke or list. A leaked token is valid forever
   with no kill switch short of editing the database.
4. **No programmatic rate limiting.** `LoginThrottle` guards login only.

Today these are Low/Medium — the API is read-only over content that is already
public. Each becomes **High** the instant a token can reach private data or
perform writes. The discipline (ADR 0001, and the charter) is to build the
control when the concrete need is in view, not before and not after: the write
API and MCP are that need, and they are next. This ADR fixes the **model** so the
implementation slices don't each re-invent authorization.

## Decision

### A principal is the unit of authorization

Introduce a small **principal** abstraction: the authenticated actor behind a
request, able to answer *"may I perform this **action** on this **resource**?"*.
There are two implementers, kept distinct rather than merged:

- the **admin session** — a `User`, authorized by role via `Content\Permissions`
  (unchanged);
- an **API token** — a **standalone principal** carrying its own scopes, **not
  bound to any user**.

A machine client is not a person. A token's authority is its **own** granted
scopes; revoking a token touches no user account, and a token is never silently
widened or narrowed by changes to a human's role. (Should a future need arise to
tie a token to a user, the effective permission becomes *token scopes ∩ user
authorization* — the intersection, never the union. That is a later ADR; v1 is
standalone.)

### Scopes are `resource:action`, per collection

A scope is a string `"<resource>:<action>"`:

- **resource** — a **collection handle** (`posts`, `pages`), or a reserved
  non-collection resource name (`media`); `*` means all resources.
- **action** — `read` or `write` for v1. `write` is the coarse mutate grant;
  finer actions (`publish`, `delete`) may be split out later **when a consumer
  needs the distinction**, without breaking existing `read`/`write` scopes.

Examples: `posts:read`, `posts:write`, `media:read`, `*:read`. This mirrors the
per-collection `managerRoles` model already in core, so the two authorization
systems reason the same way.

### Enforcement is at the query/service layer, deny-by-default

- The middleware **authenticates** and **attaches the resolved principal** to the
  request context; it does **not** make the authorization decision.
- The read/write path checks the principal's scopes against the **concrete
  resource and action** it is about to perform — not merely that a token was
  present at the door. A route-only guard is precisely the IDOR/scope-confusion
  gap this ADR exists to prevent.
- **Fail closed.** An unknown, unparseable, or absent scope denies. New API
  resources must declare the scope they require; a resource with no declared
  scope is unreachable, not open.
- **401 vs 403 are distinct** — unauthenticated (no/invalid/expired/revoked
  token) is 401; authenticated-but-out-of-scope is 403 — without leaking the
  existence of resources the caller may not see (uniform not-found stands).

### Principal plumbing without global mutable state

`Request` stays immutable. The resolved principal is **request-scoped and
explicit** — passed alongside the request into handlers (the concrete mechanism —
an attribute-carrying request context vs. a principal parameter threaded by the
router — is chosen in the Slice 1 PR). No global "current user/token" singleton,
no service locator: a plugin or controller receives the principal it is given,
never an ambient one.

### Lifecycle: expiry and revocation are first-class

- Add `expires_at` (nullable — a non-expiring token is allowed but discouraged and
  flagged in the UI) and `revoked_at` to `nb_api_tokens`.
- `findByPlaintext` returns a token only if it is neither expired nor revoked;
  the middleware answers 401 otherwise.
- `token:list` and `token:revoke` join `token:create` on the CLI, and a
  CSRF-guarded admin page mints (plaintext shown **once**), lists, and revokes.

### Backward compatibility for existing tokens

A token minted before this change has `abilities = NULL`. During the read-only
era it is treated as `*:read` so nothing breaks. **This compatibility grant is
removed when the write API lands** — at that point a null-ability token is
`read`-only at most and operators are prompted to re-mint with explicit scopes.
Recorded as a dated compatibility decision so the revisit is not forgotten.

## Consequences

**Enables**
- Least-privilege machine access: a CI token scoped to `posts:read` cannot touch
  `media` or write anything.
- A safe path to the **write API** and **MCP** — both become thin consumers of
  one authorization model, not a second backend that reinvents authz.
- A revocation and expiry story an operator can actually run.

**Costs / makes harder**
- Every new API resource must **declare its required scope** — deliberate
  friction that keeps the surface honest.
- A schema migration and a compatibility window for existing tokens (handled
  above).
- An **authorization matrix** (actor × scope × resource × action) becomes a
  required test artifact for the scope-enforcement slice — every new scope or
  resource must fill in its allow/deny answers before merge.

**Explicitly out of scope here** (later ADRs / slices, each on evidence)
- Tokens bound to users; per-object ACLs; OAuth/refresh flows; write API
  semantics; CORS policy; OpenAPI; MCP. This ADR is the authorization *model*
  only.

## Verification

Each implementing slice lands CI-green (`composer check` — PHPStan level 6 +
tests — plus the HTTP-functional suite) and passes a `nimbus-security-review`
pass with **no open Critical/High lacking a risk-acceptance ADR**. The
scope-enforcement slice ships the authorization matrix as a test, asserting the
**deny** paths, not only the happy path.
