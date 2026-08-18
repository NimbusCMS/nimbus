# Security findings ledger

Append-only record of confirmed security findings and accepted risks from the
`nimbus-security-review` loop. Supersede entries, never delete them. Every
confirmed finding links the commit that fixed it **and** the regression test that
guards it; every accepted risk links its ADR and revisit date.

When a finding **class** appears here twice, promote it into
[`threat-catalog.md`](threat-catalog.md) as a standing check and note that here.

## How to read a row

- **Severity** — Critical / High / Medium / Low (see SKILL.md).
- **Status** — `fixed` (+ commit + guarding test) · `accepted-risk` (+ ADR +
  revisit date) · `superseded`.
- **Surface** — the file(s)/route the finding touched (catalog class in parens).

---

## Findings

### 2026-08-17 · Content wildcard reaches management capabilities — Medium (latent High)
- **Status:** fixed
- **Surface:** `src/Api/TokenPrincipal.php::can()` (catalog #2 — scope confusion)
- **Scenario:** MCP Slice 1 introduces management capabilities (`schema:write`,
  `users:write`, `tokens:write`, `settings:write`) in the same `resource:action`
  namespace as collection scopes. A token minted `*:write` ("write all my
  content") would then satisfy `can('users','write')`, `can('tokens','write')`,
  etc. once Slices 4–6 add tools that consume them — escalating a content-write
  token into user creation / token minting / settings changes (site takeover).
  Rated Medium not High only because no tool consumes those caps *yet*; it
  becomes High the moment Slice 4 lands, which is why it was fixed in the
  foundation slice rather than deferred.
- **Control added:** the content wildcard `*:{action}` is now scoped to
  collections only — `can()` denies it for the fixed management set and requires
  an exact grant or `admin` for those. `admin` remains the sole cross-cutting
  super-grant. ADR 0009's capability section updated to state this precisely.
- **Evidence:** MCP Slice 1 PR · guarding test
  `tests/Unit/TokenPrincipalTest.php::test_the_content_wildcard_never_reaches_a_management_capability`
- **Recurrence:** 1st sighting of scope confusion (catalog #2) — watch for a 2nd.

<!--
Template for a confirmed finding:

### YYYY-MM-DD · <short title> — <Severity>
- **Status:** fixed
- **Surface:** `src/...` (catalog #N — <class>)
- **Scenario:** <the input and the bad outcome an attacker would achieve>
- **Control added:** <the smallest control that closed it>
- **Evidence:** <commit/PR link> · guarding test `tests/...::<name>`
- **Recurrence:** 1st sighting | 2nd → promoted to threat-catalog #N

Template for an accepted risk:

### YYYY-MM-DD · <short title> — <Severity> — ACCEPTED RISK
- **Status:** accepted-risk
- **Surface:** `src/...` (catalog #N)
- **Why accepted:** <rationale — why not fixed now>
- **Owner:** <who accepted> · **Revisit by:** YYYY-MM-DD
- **ADR:** docs/adr/NNNN-...md · **Reproduction:** <link/steps>
-->

### 2026-08-17 · MCP tool denial left no audit trail — Low
- **Status:** fixed
- **Surface:** `src/Mcp/ContentToolset.php::callContent()` (observability; adjacent to catalog #1/#2)
- **Scenario:** the MCP content-tool dispatcher pre-checked `can()` and returned
  "unknown tool" *before* reaching `EntryOperations`, so an out-of-scope tool
  call (a scope-probe by a valid token) produced no `API_ACCESS_DENIED` event —
  unlike the equivalent HTTP API request, which audits the denial. A blind spot
  for the api-advanced audit log on the highest-privilege surface.
- **Control added:** authorization is now decided by the shared `EntryOperations`
  (which audits the denial) and its `Forbidden` outcome is mapped to "unknown
  tool" at the MCP boundary — non-enumeration preserved AND the probe audited.
  Rate limiting (per-token quota) already bounds probe volume, so auditing every
  denial adds no new DoS vector.
- **Evidence:** MCP Slice 2 PR · non-enumeration guarded by
  `tests/Http/McpTest.php::test_calling_a_tool_outside_scope_is_an_unknown_tool`
- **Recurrence:** 1st sighting (audit-parity between transports) — watch as stdio
  (Slice 3) and management tools (4–6) add more surfaces.

### 2026-08-18 · stdio MCP could leak PHP errors into the JSON-RPC stream — Low
- **Status:** fixed
- **Surface:** `bin/nimbus` (`mcp` command) / `src/Mcp/StdioTransport.php` (protocol hygiene)
- **Scenario:** the stdio transport frames JSON-RPC on stdout; a stray PHP
  warning/notice printed to stdout (display_errors) would corrupt a client's
  message framing (protocol break, not data exposure). Not remotely reachable —
  stdio is a local, token-scoped channel — hence Low.
- **Control added:** the `mcp` command pins `display_errors` to `stderr`, so
  stdout carries only the JSON-RPC stream. Auth is unchanged: the stdio session
  resolves NIMBUS_MCP_TOKEN through the same `findByPlaintext` path as HTTP
  (rejects revoked/expired/paused) → a scoped `TokenPrincipal`, never raw DB; all
  scope/concurrency/audit come from the shared `McpServer`/`EntryOperations`.
- **Evidence:** MCP Slice 3 PR · framing guarded by
  `tests/Integration/StdioTransportTest.php` (one reply/line, silent
  notifications, parse/invalid-request handling).
- **Recurrence:** 1st sighting (transport-hygiene) — noted for future transports.

### 2026-08-18 · Schema tools realize the first management capability — Low (verification)
- **Status:** verified (no defect)
- **Surface:** `src/Mcp/SchemaToolset.php` (catalog #2 — scope confusion; #1 — authz)
- **Scenario:** Slice 4 makes `schema:write` the **first consumed** management
  capability, which is exactly when the latent scope-confusion finding (2026-08-17)
  would have become High. Verified the fix holds: a `*:write` (content-write-all)
  token can neither see nor call the schema tools — `SchemaToolset::definitions`
  returns `[]` and `call()` reports an unknown tool + audits the denial.
- **Controls confirmed:** `schema:write`/`admin` gate (deny-by-default, non-
  enumerating); handle is immutable (no rename → no scope/path hijack); every
  write audited via `api.management_written`; destructive `delete_collection`
  requires `confirm:true` and surfaces the entry count. schema:write is a *global*
  structural privilege (can delete any collection) — CLI-mint only, roles later.
- **Evidence:** MCP Slice 4 PR · `tests/Http/McpSchemaToolsTest.php`
  (`test_schema_tools_require_the_schema_write_capability`,
  `test_delete_collection_requires_confirmation_and_reports_the_blast_radius`)
  + `tests/Unit/TokenPrincipalTest.php::test_the_content_wildcard_never_reaches_a_management_capability`.
