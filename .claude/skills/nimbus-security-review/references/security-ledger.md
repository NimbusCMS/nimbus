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

### 2026-08-18 · Media delete guard — reference integrity (Low)
- **Status:** verified (no defect); one accepted Low edge
- **Surface:** `src/Media/MediaService.php`, `src/Content/EntryService.php` (content write hot path), `src/Media/MediaUsageRepository.php`
- **Scenario:** Slice 5a adds a reverse index (`nb_media_usage`) synced by
  EntryService on save and a shared `MediaService::delete` guard that refuses to
  delete media still referenced by content (block + pinpoint). Reviewed as it
  touches the entry write path.
- **Controls / findings:**
  - Hot-path sync is integer-filtered + bound-parameter, inside the existing save
    transaction — no injection, no crash on a huge/dangling id.
  - `media_id` is intentionally **not** an FK: an entry may legitimately hold a
    dangling media id (file deleted out-of-band / imported), and indexing it must
    never fail a save. Confirmed by `ApiRoutesTest::test_a_deleted_media_reference_reads_as_null_not_an_error`.
  - **Single delete choke point:** `MediaRepository::delete` is now called only by
    `MediaService::delete`; the admin routes through it, and MCP (5b) will too — no
    path bypasses the guard.
  - **Accepted Low:** the guard's check→delete is not lock-transactional against a
    concurrent entry save, so a race could orphan a reference. Consequence is a
    dangling reference, which already reads as null (graceful). Revisit with row
    locking only if it proves real.
- **Evidence:** MCP Slice 5a PR · `tests/Integration/MediaUsageTest.php` (sync on
  save/update, block+pinpoint on delete, entry-delete cascade frees media, reindex backfill).

### 2026-08-18 · MCP media upload — content-sniffed, capped, copy-mover — Low (documented relaxation)
- **Status:** verified (no defect); one documented relaxation
- **Surface:** `src/Mcp/MediaToolset.php` (file upload over JSON-RPC)
- **Scenario:** MCP has no multipart, so `upload_media` takes base64. Classic
  upload risks apply — type spoofing, traversal, oversize, upload-to-exec.
- **Controls (all reused from the admin's MediaUploader, unchanged):**
  - **Type sniffed from the real bytes (finfo)** against a fixed allow-list
    (JPEG/PNG/GIF/WebP/PDF — **no SVG**); the claimed filename/mime never decides.
    Proven: a text payload named `evil.png` is rejected.
  - **Random stored filename** (bin2hex) — the agent's filename is display-only,
    so no path traversal; the extension is derived from the sniffed type.
  - **Size cap**: base64 length checked *before* decode (~4/3 rule) + the
    uploader re-checks the decoded size against `UPLOAD_MAX_BYTES` (10 MB).
  - Temp file staged via `tempnam`, unlinked in `finally`.
- **Documented relaxation (Low):** the MCP uploader uses a **copy mover** instead
  of `is_uploaded_file`/`move_uploaded_file`, because the bytes are a legitimate
  token upload, not an HTTP file upload. Safe — content sniffing + allow-listing
  are unchanged; only the "arrived via multipart" check is dropped, which is
  meaningless here. The admin still uses the strict mover.
- **Gating/audit:** `media:read`/`media:write` (non-enumerating unknown-tool +
  audited denial); upload/delete emit `api.management_written`. Delete routes
  through the shared guard (refused + pinpointed when in use).
- **Evidence:** MCP Slice 5b PR · `tests/Http/McpMediaToolsTest.php` + a live
  upload smoke (PHP-decoded response: mime=image/png).

### 2026-08-18 · Token-mint privilege escalation guard — High-risk surface, verified
- **Status:** verified (no defect) — the key control of Slice 6
- **Surface:** `src/Mcp/TokensToolset.php::mintToken()` / `holds()` (catalog #2 — privilege escalation)
- **Scenario:** minting is the classic escalation path — a `tokens:write` token
  could otherwise forge itself an `admin` token and own the install.
- **Control:** you can only mint scopes you already hold. Every requested scope is
  checked against the minter's own `can()` (`admin` only by an admin; `*:action`
  only if held; `resource:action` via `can()`). Proven live: a
  `tokens:write,posts:write` token is refused `admin` and `users:write`, allowed
  `posts:write`; an `admin` token may grant `admin`. This is the subset-only rule
  the roles system needs, and it validates the Slice-1 management-cap model
  (management caps are unreachable via `*:write`, so they can't be minted either).
- **Secret discipline:** the minted plaintext and any generated user password
  appear only in the tool result — never persisted (hash only), never in the
  `api.management_written` audit (records name+scopes/role), never logged (stdio
  errors pinned to stderr). `list_tokens` exposes no secret.
- **Other rails:** roles validated against `Permissions::ROLES`; `set_role`
  refuses demoting the last admin (no self-lockout); weak passwords rejected via
  the shared `Password::isWeak`.
- **Evidence:** MCP Slice 6 PR · `tests/Http/McpAdminToolsTest.php`
  (`test_mint_cannot_grant_scopes_the_minter_does_not_hold`,
  `test_set_role_but_never_the_last_admin`, show-once + revoke tests).

### 2026-08-18 · MCP milestone — final composition review — Low
- **Status:** security-green for the milestone; one Low documented
- **Surface:** the composed `McpServer` (5 toolsets) — the cross-slice checks a
  per-slice review can't do.
- **Checked:** toolset ordering is management-first [Schema, Media, Users, Tokens,
  Content], so every fixed management name is claimed before a content verb could
  parse it; each tool still enforces its own capability + the underlying service
  re-checks; a multi-capability (or admin) token composes without escalation;
  every content write (`api.entry_written`) and management action
  (`api.management_written`) is now recorded by api-advanced; the mint guard
  (subset-only) and media delete guard (block-in-use) hold across the surface.
- **Low (documented, not an escalation):** a content collection whose *handle*
  equals a management/media tool name (`media`, `users`, `tokens`, `collections`,
  `field(s)`, `collection`) has those content tools shadowed by the management
  tool. The token is *denied* (unknown tool), never granted extra access — a
  functional quirk. Documented as reserved handles in docs/MCP.md.
- **Evidence:** the full MCP test suite (content/schema/media/users/tokens over
  HTTP + stdio) + the plugin audit tests.
