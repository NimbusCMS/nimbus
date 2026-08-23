# Pre-release full-codebase audit — 2026-08-22

A whole-codebase sweep by parallel review agents, each owning one domain and
running **both** review disciplines against existing code:

- [`nimbus-review-loop`](../../../.claude/skills/nimbus-review-loop/SKILL.md) —
  product / architecture / principal-engineering + Platform Drift Guard.
- [`nimbus-security-review`](../../../.claude/skills/nimbus-security-review/SKILL.md)
  — Attacker / Defender / QA + severity + merge bar.

Goal: find product gaps, error-handling holes, security flaws, correctness bugs,
N+1s, and test gaps **before** release/hardening work begins. Findings are
recorded here (not fixed yet); we work through them before the release milestone.

## Domain files

| File | Domain | Scope |
|---|---|---|
| [auth.md](auth.md) | Auth, sessions & authorization | `src/Auth/**` (incl. OAuth, roles/capabilities/Gate, tokens, throttle) |
| [http.md](http.md) | HTTP kernel & app wiring | `src/Http/**`, `src/Application.php` |
| [admin.md](admin.md) | Admin controllers & views | `src/Admin/**` + admin templates |
| [api-mcp.md](api-mcp.md) | External programmatic surfaces | `src/Api/**`, `src/Mcp/**` |
| [content-db.md](content-db.md) | Content, fields, validation & DB | `src/Content/**`, `src/Database/**` |
| [site-view-media.md](site-view-media.md) | Public site, themes & media | `src/Site/**`, `src/View/**`, `src/Media/**` |
| [plugin.md](plugin.md) | Plugin subsystem & boundary | `src/Plugin/**` + integration points |
| [support-settings-mail.md](support-settings-mail.md) | Config, settings & mail | `src/Support/**`, `src/Settings/**`, `src/Mail/**` |

## Finding format (every domain file uses this)

Each finding is one row you can act on independently:

```
### <DOMAIN>-<n> · <short title>
- **Priority:** P0 (block release) · P1 (fix before release) · P2 (should) · P3 (nice-to-have)
- **Type:** security | correctness | product-gap | error-handling | performance | test-gap | architecture
- **Severity (if security):** Critical | High | Medium | Low
- **Where:** `path/to/file.php:line` (+ callers/callees)
- **What:** one-sentence statement of the defect/gap
- **Evidence:** concrete input → wrong output / exploit path / failing scenario (no theory)
- **Fix:** the smallest correct change, in the right layer
- **Effort:** S | M | L
```

Rules of evidence: a finding is real only with a concrete failing scenario or
exploit path. No CVE cosplay, no generic OWASP padding. Respect decisions already
made — check the [decision ledger](../../../.claude/skills/nimbus-review-loop/references/decision-ledger.md)
and [security ledger](../../../.claude/skills/nimbus-security-review/references/security-ledger.md)
and do not re-litigate accepted risks (verify the fix still holds instead).

## Roll-up

All 8 domain agents reported. **69 findings · 0 P0 · 7 P1 · 28 P2 · 34 P3.** No
critical/unauthenticated-compromise holes; the heavy security machinery (CSRF,
subset-only grants, escape-by-default, token nonce discipline, TLS/CRLF mail
guards, OAuth Phase 1 controls, trusted-proxy deny-by-default) re-verified solid.
The real risks cluster at **seams between subsystems** — the same defect seen from
two domains — which is exactly what a whole-codebase sweep is for.

### Counts by domain

| Domain | P0 | P1 | P2 | P3 | Total |
|---|---|---|---|---|---|
| [auth](auth.md) | 0 | 0 | 3 | 3 | 6 |
| [http](http.md) | 0 | 0 | 2 | 5 | 7 |
| [admin](admin.md) | 0 | 2 | 7 | 5 | 14 |
| [api-mcp](api-mcp.md) | 0 | 3 | 2 | 3 | 8 |
| [content-db](content-db.md) | 0 | 1 | 3 | 2 | 6 |
| [site-view-media](site-view-media.md) | 0 | 0 | 1 | 4 | 5 |
| [plugin](plugin.md) | 0 | 1 | 7 | 5 | 13 |
| [support-settings-mail](support-settings-mail.md) | 0 | 0 | 3 | 7 | 10 |
| **Total** | **0** | **7** | **28** | **34** | **69** |

### Cross-domain themes (fix the theme, not the symptom)

1. **⚑ Legacy `users.role` vs `nb_user_roles` desync — the #1 issue.**
   `ADMIN-2` (High) / `API-1` / `API-2` / `AUTH-4` are one root cause seen four
   ways: since the roles enforcement flip, authority resolves from `nb_user_roles`
   only, but the MCP user tools + the legacy `users.role` column still read/write
   the dead column. Result: **demoting an admin over MCP silently doesn't revoke**,
   an MCP-created "admin" has zero real power, the last-admin guard protects the
   wrong column, and once the desync is fixed the *missing subset-only guard* on
   those tools becomes account-takeover. Fix all four as one slice.
2. **Cross-collection read-boundary holes.** `ADMIN-1` (admin browsing ignores the
   ADR-0011 `{handle}:read` gate), `API-3` (openapi.json leaks the whole model to a
   scoped token), `DATA-1` (relation target not constrained → foreign live entry
   leaks) — the non-enumeration / per-collection promise has gaps on **read**
   surfaces while writes are gated.
3. **`emitBestEffort` isolates the dispatch, not each listener.** `SUP-3` and
   `PLUG-3` are the same bug: one throwing subscriber starves every later one —
   **including the audit-log records** for access-denied / management-write. Audit
   integrity depends on this.
4. **CSP nonce lifecycle doesn't reach cached HTML or plugins.** `HTTP-1` (stale
   nonce on cache-served pages blocks inline scripts once `PAGE_CACHE_TTL>0`),
   `PLUG-5` (nonce not exposed to plugin head/admin surfaces, so the analytics use
   case the head capability was justified by can't ship), `SVM-5` (untested).
5. **Unvalidated input → 500 instead of 422.** `ADMIN-8` + `DATA-3` (no length
   caps → "Data too long" `PDOException` under strict MySQL), `ADMIN-6` + `DATA-2`
   (malformed `published_at` → `DateMalformedStringException`), `ADMIN-5`
   (colliding field handles), `ADMIN-12` (array-shaped input → `TypeError`). One
   "input-validation hardening" slice covers the lot.
6. **Mail-delivery silent failures on account recovery.** `SUP-1` (transport typo
   → logs but reports success), `SUP-2` (CRLF site title kills all reset/invite
   mail), `ADMIN-7` (a failed/expired invite can't be re-sent — the UI advises the
   impossible).
7. **CORS.** `HTTP-4` (preflight answered before rate-limit/DB gate) + `API-5`
   (preflight advertises only GET/OPTIONS → all cross-origin writes & MCP blocked).
8. **Pre-1.0 contract drift.** `PLUG-6/7/9`, `API-8`, `SUP-4` — the plugin/API
   contract has drifted from COMPATIBILITY; clean up before freezing at 1.0.
9. **Named test gaps** per domain: `AUTH-6`, `API-7`, `DATA-6`, `SVM-5`, `PLUG-8`,
   `SUP-9` — each fix below lands with its regression test.

### Suggested burn-down order (before release work)

**Release blockers — the 7 P1s, as 5 slices:**

- **Slice A · Roles single-source-of-truth** (`ADMIN-2`, `API-1`, `API-2`, +`AUTH-4`)
  — rebuild MCP `create_user`/`set_role` on `nb_user_roles`, apply the subset-only
  guard, base last-admin on assigned counts, retire the legacy `users.role` as an
  authority. **Highest priority — contains the only High.**
- **Slice B · Read-boundary** (`ADMIN-1`, `API-3`) — enforce `{handle}:read` on
  admin browsing; scope-filter the OpenAPI document. (`DATA-1` pairs here.)
- **Slice C · Relation integrity** (`DATA-1`) — constrain relation values to the
  declared target collection; gate expansion on the real collection.
- **Slice D · Plugin migration safety** (`PLUG-1`) — per-statement/isolated apply
  so one plugin's bad migration can't wedge core upgrades.

**Then the P2 slices** (grouped): Slice E input-validation hardening
(`ADMIN-5/6/8/12`, `DATA-2/3`); Slice F event-listener isolation + audit
(`SUP-3`, `PLUG-3`); Slice G CSP-nonce × cache/plugins (`HTTP-1`, `PLUG-5`); Slice
H mail reliability (`SUP-1/2`, `ADMIN-7`); Slice I CORS (`HTTP-4`, `API-5`); Slice
J concurrency CAS (`API-4`); plus the remaining domain P2s (`ADMIN-3/4/9`,
`DATA-4`, `SVM-1`, `PLUG-2/4/6/7/8`).

**P3s** are opportunistic / nice-to-have — fold the contract-drift and test-gap
ones into the release-readiness milestone.

_Findings recorded, not yet fixed. Each domain file has the full evidence + fix +
effort for its items._
