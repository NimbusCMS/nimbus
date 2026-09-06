# ADR 0031 — Per-site embedding allowlist (framing)

**Status:** accepted · **Date:** 2026-09-06

## Context

Nimbus ships a deliberately strict, nonce-only CSP and locks framing down hard:
`frame-ancestors 'none'` plus `X-Frame-Options: DENY`, and `frame-src` falls back to
`default-src 'self'`. Two consequences block legitimate embedding:

1. **Outbound** — a site cannot embed any external origin in an `<iframe>` (e.g.
   danmat.dev embedding its own live app at `numistoria.danmat.dev` inside a project
   card).
2. **Inbound** — a Nimbus site can never be framed by anyone, even an origin the
   owner fully trusts.

Both are the right *defaults*. What was missing is a way for the **site owner** to
open specific, per-origin holes — deliberately.

First consumer: danmat.dev (embedding its own apps in "rack" cards). But framing
control is wanted by many unrelated sites (storefront previews, docs widgets,
dashboards), so this is a reusable **core** capability — the CSP/security-header
builder is core and a plugin must never be able to weaken framing.

## Decision

Two per-site allowlists, **both defaulting to empty** (= today's behaviour, byte for
byte):

- **`frame_src`** — origins this site may embed. When non-empty, emit
  `frame-src 'self' <origin> …`; when empty, omit the directive (falls back to
  `default-src 'self'`, as today).
- **`frame_ancestors`** — origins allowed to embed this site. When non-empty, emit
  `frame-ancestors 'self' <origin> …` **and drop `X-Frame-Options` entirely**; when
  empty, keep `frame-ancestors 'none'` **and** `X-Frame-Options: DENY`, exactly as
  today. (`X-Frame-Options` can only express `DENY`/`SAMEORIGIN`/one `ALLOW-FROM`, so
  it can't represent a multi-origin allowlist — and a lingering `DENY` would override
  `frame-ancestors` in browsers that honour it.)

Every other directive (the nonce, `default-src 'self'`, `object-src 'none'`,
`base-uri 'self'`, `form-action 'self'`, `img-src`) is unchanged.

### It is an operator setting, not content

Framing rules weaken security headers, so they are a deliberate config act by
someone with shell access — the same posture as plugin enablement. They live in
**`config/security.php`** (`return ['embedding' => ['frame_src' => [...],
'frame_ancestors' => [...]]]`), read by `Config::embedding()`. They are **never**
editable over MCP or the admin, and no content author can reach them.

### Fail-closed validation

`Config::normalizeEmbedding()` (a pure, tested normalizer) accepts only a **bare
origin** — `scheme://host[:port]`, `http`/`https` only, no path/query/fragment,
no trailing slash, no wildcard host, no userinfo. Anything else (`*`,
`'unsafe-inline'`, `data:`, a schemeless host, `https://*.example`) is **dropped and
logged**, never emitted. An invalid entry can never silently widen the policy; the
strict default is kept for that slot.

## Consequences

- The empty/default case is byte-identical to the prior baseline — no site changes
  behaviour until its owner opts in (guarded by a regression test).
- The header builder (`SecurityHeaders::all()`) gains an optional `$embedding`
  parameter (defaulting to `Config::embedding()`) purely so the four cases are
  unit-testable without touching disk.
- The site owner opting into `frame_ancestors` accepts the clickjacking trade-off for
  the listed origins; default-deny is preserved for everyone else. The theme still
  owns the actual `<iframe>` `sandbox`/`allow`/`referrerpolicy` attributes.
- Wildcards, per-page rules, and a UI are out of scope (v1).

Same "an application drives a small, reusable core capability" pattern as ADR 0029
(plugin content-read) and ADR 0030 (fine-grained plugin capabilities).
