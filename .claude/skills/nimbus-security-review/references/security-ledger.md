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

### 2026-08-23 · style-src nonce-only — CSP deferral finished, security-green
- **Status:** shipped. **Supersedes the accepted residual in the 2026-08-22
  "Nonce-based CSP (script-src)" entry** ("`style-src 'unsafe-inline'` kept").
  Reviewed design-first via a **Fable two-skill burst** (green-to-build). No
  Critical/High — this closes a defense-in-depth CSS-injection gap (exfil via
  attribute-selectors / UI redressing, only reachable after a prior escape
  failure), it does not close an open hole.
- **Surface:** `src/Http/SecurityHeaders.php` (catalog: CSP nonce hygiene — the
  standing check from the script-src entry now applied to style-src).
- **Controls (all fail-closed — a missed nonce = visibly broken CSS, never an
  injection hole; regression-tested in `tests/Http/CspNonceTest.php`):**
  1. `style-src 'self' 'nonce-…'`, `'unsafe-inline'` **removed** — test asserts a
     `nonce-…` present and no `'unsafe-inline'` on style-src (can't silently rot).
  2. Same per-request `Csp::nonce()` as script-src (128-bit CSPRNG, rotated at
     `Application::handle()`); header nonce == rendered admin `<style nonce>`
     (test).
  3. All 5 admin/auth `<style>` blocks nonce'd; the **only** 2 inline `style=`
     attributes refactored to classes (swatch gradients moved into `theme.css`,
     the `gradient` key dropped from `AdminTheme::THEMES` — single source; static
     plugins-intro margin → class). Test asserts **no `style="` remains** in
     `src/View/themes/nimbus/templates/`.
- **What it leaves open (documented, not blocking):** the inherent
  same-response nonce-scrape limitation (escape-by-default stays the primary XSS
  control); content-borne inline `style=` (Markdown/raw-HTML entry bodies) goes
  inert on public pages (deliberate — also makes content CSS-injection inert);
  the PageCache×inline-nonce caveat for third-party public themes (cached pages
  must use external CSS) — both recorded in COMPATIBILITY.

### 2026-08-22 · OAuth SSO Phase 1 — design review, security-green to build
- **Status:** built with all mandated controls; no open Critical/High. Reviewed
  design-first (highest-stakes auth surface yet: account takeover / full
  compromise). ADR [0012](../../../../docs/adr/0012-oauth-sso.md).
- **Surface:** `src/Auth/OAuth/*`, `src/Admin/OAuthController.php`, `Auth::login()`
  (catalog: auth/session, CSRF, open-redirect, secret handling).
- **Controls shipped, each with a regression test in `tests/Http/OAuthFlowTest.php`
  driving the real kernel with `FakeOAuthProvider`:**
  1. **state** — CSPRNG (`random_bytes(32)`, base64url), stored in `$_SESSION`,
     compared with `hash_equals`, and **consumed before use** (single-use):
     `test_state_mismatch_is_rejected`, `test_state_is_single_use`.
  2. **PKCE S256** — verifier `random_bytes(64)` in session; challenge =
     base64url(SHA-256(verifier)) sent on start; verifier sent at exchange:
     `test_pkce_and_state_are_sent_on_start`.
  3. **Token provenance** — tokens come only from the provider token endpoint via
     `OAuthHttp` curl with `SSL_VERIFYPEER=true`/`VERIFYHOST=2` (never disabled,
     https-only), never from request input; no id_token JWT is trusted.
  4. **Explicit-link binding** — link intent + target uid live in the **session**
     (not the URL); the callback rejects unless the recorded uid equals the
     current session user: `test_link_is_bound_to_the_initiating_user`. `UNIQUE`
     conflict is graceful, never a steal: `test_identity_already_linked_elsewhere_is_not_stolen`.
  5. **Unknown identity rejected** — no auto-provision, no email fallback:
     `test_unknown_identity_is_rejected`.
  6. **Open-redirect guard** on `next` (internal single-`/` path only):
     `test_open_redirect_next_is_blocked` / `test_internal_next_is_honoured`.
  7. **Session fixation** — `Auth::login()` does `session_regenerate_id(true)`:
     asserted in `test_linked_identity_signs_in`.
  8. **Secret handling** — `client_secret` env-only, never front-channel/logged;
     authorize URL carries only `client_id`: `test_login_button_appears_only_when_configured`
     asserts the secret never reaches the page.
  9. **Throttle** — start + callback IP-throttled via the shared `LoginThrottle`.
  10. **Immutable-subject key** — `nb_oauth_identities` keyed on `(provider, sub)`,
      never email (Google `sub` / GitHub numeric `id`).
  11. **Provider binding** — the callback provider must equal the start provider:
      `test_provider_confusion_is_rejected`.
  12. **Email display-only** in Phase 1 — never a matching key.
  13. Provider errors handled gracefully: `test_provider_exchange_failure_is_handled`;
      disconnect is CSRF-guarded: `test_disconnect_requires_csrf`.
- **Deferred (each needs its own review before build):** Phase 2 invite-accept via
  provider; Phase 3 opt-in verified-email auto-link (the classic takeover class —
  stays off until then); Phase 4 opt-in allow-list signup.
- **Recurrence:** 1st sighting of the OAuth-callback-hygiene class — watch for a 2nd
  (state/PKCE/provider-binding/open-redirect together) to promote into the catalog.

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

### 2026-08-18 · Authorization unified into one decision function (Roles Slice 1) — Low
- **Status:** verified (no defect); one intended widening
- **Surface:** `src/Auth/Authorizer.php`, `src/Api/TokenPrincipal.php` (now delegates), `src/Auth/{Role,RoleRepository,RoleSeeder,UserPrincipal}.php`
- **Scenario:** roles extracts `TokenPrincipal::can()` into a shared `Authorizer`
  used by both tokens and a new `UserPrincipal`, so people and agents are judged
  by one deny-by-default function — the security-critical core of the product.
- **Controls confirmed:** admin super-grant, exact grants, **management-exact-only**
  (schema/media/users/tokens/settings/**roles** — the content wildcard still can't
  reach them), content wildcard. The `Authorizer` is small, pure, unit-tested.
- **Intended widening (Low):** content `{handle}:write` now **implies**
  `{handle}:read` (ADR 0011 — can't edit what you can't read). Management read/write
  stay independent (media:write ≠ media:read). No existing test asserted a
  write-only-content token was denied read, so nothing regressed.
- **Enforcement-inert this slice:** the admin gates (`Permissions`/`requireAdmin`/
  `canManage`) are UNCHANGED — roles are populated but not yet the enforcement
  source (Slice 3). So no admin authz behavior changed, and no lockout is possible
  from an un-seeded state. Slice 3 will require the seed before flipping.
- **Evidence:** Roles Slice 1 PR · `tests/Unit/AuthorizerTest.php`,
  `tests/Integration/RolesTest.php`, and the unchanged `TokenPrincipalTest`.

### 2026-08-19 · Roles + users admin pages (Roles Slice 2) — Low
- **Status:** verified (no defect)
- **Surface:** `src/Admin/RolesController.php`, `src/Admin/UsersController.php`
- **Scenario:** two new admin write surfaces — composing capability bundles and
  creating/assigning users.
- **Controls confirmed:** every action `requireAdmin()` + CSRF; the role form
  **validates capabilities** against the known set (management + `admin` +
  wildcards + per-collection), dropping anything else (no arbitrary-capability
  injection — proven); passwords argon2id-hashed + weak-checked; output escaped
  via `View::e` (role/user names, emails, capabilities); the **last admin cannot
  be stripped of the admin role** and built-in roles can't be deleted / the admin
  role can't be edited (no lockout).
- **No escalation:** the actor is always an admin here (subset-only trivially
  holds); delegated `roles:write` + its subset-only check arrive with the
  enforcement flip (Slice 3).
- **Transitional note (safe direction):** enforcement is still the legacy
  `Permissions` path this slice, so assigned roles are not yet the enforcement
  source — a user's real access still follows `users.role` until Slice 3. This
  *under*-grants (a role assigned in the UI isn't yet effective), never over-grants.
- **Evidence:** `tests/Http/RolesAdminTest.php`, `tests/Http/UsersAdminTest.php`
  (9 tests) + visual verification of both pages.

### 2026-08-19 · Enforcement flip to capabilities (Roles Slice 3) — reviewed pre-build, controls landed
- **Status:** fixed/verified — the authorization core; two High escalation paths closed by design + tests
- **Surface:** `src/Auth/Gate.php`, admin controllers (requireAdmin→requireCan), `src/Admin/{Roles,Users}Controller.php` (subset-only) — catalog #1 (IDOR/authz) + #2 (privilege escalation)
- **Findings the pre-build review surfaced, and their controls:**
  - **A2 (High) — escalation by role *assignment*:** a `users:write` non-admin
    posting `roles[]=<admin role>` to `/admin/users/{self}` would grant itself
    admin. My design had only *mused* about this. **Control:** `UsersController`
    now rejects assigning (or editing a user who already holds) any role whose
    capabilities exceed the actor's — `firstUngrantableRole` via `Gate::holds`
    (admin holds all). Test: `RolesEnforcementTest::test_a_user_manager_cannot_assign_a_role_beyond_itself`.
  - **A1 (High) — escalation by role *capabilities*:** a `roles:write` non-admin
    minting a role with `admin`/`schema:write`. **Control:** `RolesController`
    rejects granting (create) or editing a role holding any capability the actor
    lacks. Test: `test_a_role_manager_cannot_grant_a_capability_it_lacks`.
  - **A4 (High if missed) — a missed gate on a flipped endpoint.** **Control:** an
    authorization-matrix test asserts deny-without / allow-with for schema/tokens/
    users/roles + per-collection entry management.
  - **A5 (Medium) — media stays auth-only** (any signed-in user uploads/deletes),
    which under-serves a future read-only role. **Accepted-with-tracking:** the
    admin media page is not capability-gated this slice (gating it would tighten
    editor/author and needs a seed refresh). Fast-follow tracked in ROADMAP.
  - **A6 — force the legacy fallback by emptying `nb_roles`:** not reachable —
    system roles are undeletable and roles have no MCP surface; the fallback only
    fires pre-seed. The system-role-undeletable test is now load-bearing for authz.
- **Correctness:** the Gate resolves the user *lazily* via `Auth` (matches the old
  live-read; a construction-time capture broke `SharedRegistryTest`). Un-seeded →
  legacy `Permissions` verbatim (tested: identical authorization; nothing seeds
  behind our back).
- **Evidence:** `tests/Http/RolesEnforcementTest.php` (matrix + A1 + A2 + fallback);
  full suite (538) green — behavior preserved.

### 2026-08-20 · Roles for tokens (Roles Slice 4) — reviewed pre-build, controls landed
- **Status:** fixed/verified — a token minted bound to a role draws its capabilities **live**; the change touches the API auth path, so it was reviewed adversarially before build.
- **Surface:** `src/Api/ApiTokenRepository::principalFor` (the one resolution point), `src/Api/TokenPrincipal::fromToken`, `src/Mcp/TokensToolset::mintToken`, `bin/nimbus token:create --role`, migration `011_token_role.php` (`role_id` FK ON DELETE SET NULL) — catalog #2 (privilege escalation) + token handling.
- **Findings the pre-build review surfaced, and their controls:**
  - **Escalation by binding a powerful role (High):** a `tokens:write` non-admin
    minting a token bound to an `admin`/`users:write` role would launder authority
    it lacks. **Control:** `mintToken` runs subset-only over **every** capability
    the role grants (`holds($principal, $cap)` for each) — binding is no weaker a
    gate than granting explicit scopes. Tests:
    `McpAdminToolsTest::test_mint_cannot_bind_a_role_beyond_the_minter`,
    `::test_binding_an_admin_role_requires_holding_admin`.
  - **Deleted/tightened role must fail *safe* (High if wrong):** the legacy
    "empty abilities → `['*:read']`" compat grant (ADR 0006) is **removed** — with
    it, deleting a role-bound token's role (`role_id`→NULL, no explicit abilities)
    would have *granted* read-all. Now empty → deny-by-default. Tests:
    `ApiTokenRepositoryTest::test_a_role_bound_token_denies_once_its_role_is_deleted`,
    `::test_a_dangling_role_id_never_resolves_to_extra_authority`,
    `TokenPrincipalTest::test_from_token_denies_when_abilities_are_empty`,
    `ApiRoutesTest::test_scope_enforcement_matrix` (`[]`→403).
  - **Live link is intentional, not a TOCTOU hole:** tightening a role reaches its
    tokens at the next request (central partial revocation) — the security-positive
    direction. Test: `ApiTokenRepositoryTest::test_tightening_a_role_immediately_tightens_its_tokens`.
  - **Union correctness:** effective caps = explicit abilities ∪ live role caps, no
    more. Test: `::test_principal_for_unions_explicit_abilities_with_live_role_caps`.
- **Secret handling unchanged:** mint still returns the plaintext once; role caps
  are never logged. `role_id` is a bound int param (no SQLi/IDOR surface).
- **Blast radius (behavior change, documented):** null-ability tokens that relied
  on the old read-all grant now deny — noted in `docs/COMPATIBILITY.md`; the four
  pre-scope tests that leaned on it were given explicit `*:read`.
- **Evidence:** full suite (548) green; PHPStan level 6 clean.

### 2026-08-20 · Admin token form had no subset-only (Slice 4b-security) — High, fixed
- **Status:** fixed/verified (commit 698df9e, PR #98). Found by the pre-build 4b review, which the *design* had framed away ("read-only by construction").
- **Surface:** `src/Admin/TokensController::store()` — the `/admin/tokens` web mint. Catalog #2 (privilege escalation) + management-surface mint.
- **Finding (A2, High):** the form applied NO subset-only check. Slice 3 made
  `tokens:write` grantable to non-admins via custom roles; such an actor reaches
  the form (`requireCan('tokens','write')`) and could POST `scope_all=1` (or a
  collection) to mint a `*:read`/`{handle}:read` token it does **not** itself
  hold — a read-all escalation. The read-only construction (`scopesFrom()` only
  emits `:read`) was a *limitation*, never an authz control; the CLI/MCP mint
  paths already enforced subset-only, the web form did not.
- **Control:** `TokensController::firstUngrantable()` (mirrors
  `RolesController::firstUnheld`; `Gate::holds` = `admin` super-grant + split
  `resource:action`→`can()`), rejecting any ungrantable scope **before** the
  nonce is consumed (preserves "invalid retry keeps its nonce; mint renders, no
  PRG"). When Slice 4b-UI adds the role dropdown, role caps join the same checked
  set — binding a role is no bypass.
- **Regression tests** (`tests/Http/TokenAdminTest.php`, through the kernel, red
  on the pre-fix form): `test_a_token_manager_cannot_grant_read_all_it_does_not_hold`,
  `test_a_token_manager_cannot_grant_a_collection_it_cannot_read`,
  `test_a_token_manager_may_grant_reads_it_holds` (positive).
- **Note:** the *role-binding* half of A1 lands with Slice 4b-UI (no role field on
  the form yet); the shared `firstUngrantable` set already covers it by design.

### 2026-08-20 · Gate admin media on media:* (Roles Slice 3b) — reviewed pre-build, no High
- **Status:** shipped (PR #100, commit 4bd2eeb). Closes the last auth-only admin surface; brings it to parity with the MCP media tools. Was A5 (tracked) from the Slice 3 review.
- **Surface:** `src/Admin/MediaController.php` (index/store/destroy), `Controller::nav()`, the dashboard media card, `RoleSeeder`, migration `012_role_media_caps.php`. Catalog #2 (privilege escalation) + first data migration.
- **Design had no Critical/High.** The only High was a *build* risk — gating the read but not each write. Controls landed:
  - **Per-action gating:** index→`requireCan('media','read')`; store+destroy→`requireCan('media','write')` **independently**. Management caps carry no read↔write implication (Authorizer: `media` is management, so `media:write` does not imply `media:read`, and `*:read` never reaches it) — so each write is gated on its own and both media caps must be seeded.
  - **Behavior-preserving seed:** `RoleSeeder::CONTENT_MEDIA_CAPS` (editor/author get media:read+write, fresh installs) + **migration 012** backfills already-seeded installs.
- **Findings (both accepted Low, documented):**
  - **F2 (Low):** a system role an admin *renamed* away from editor/author is skipped by the backfill (`name IN('editor','author')`) → those users lose media. **Fails closed** (visible, admin re-grants) — never an over-grant. Kept the name filter deliberately (safer than matching all `is_system`).
  - **F3 (Low, accept):** media has **no per-object ACL** — `media:*` gates the surface, not individual files. The entry-form picker still shows media names to a collection-writer without `media:read` (needed to pick media; consistent with pre-3b). A per-object media ACL is a separate, larger design; revisit only if wanted.
- **First data migration — verified safe:** static SQL (no injection); `capabilities` is `JSON NOT NULL` array (well-typed `JSON_ARRAY_APPEND`); idempotent via `JSON_CONTAINS`; scoped to `is_system=1 AND name IN('editor','author')`; additive-only; runs **once** (tracked in `nb_migrations`) so it can never re-add a cap an admin later strips.
- **Regression tests:** `tests/Http/MediaRoutesTest.php` (a media:read-only role lists but is denied BOTH writes independently; media-less denied all three; editor/author retain; media caps leak into no other management gate; nav hides without media:read), `tests/Integration/RoleSeederTest.php` (seed grants media; backfill additive/idempotent/parity; custom+admin untouched; empty-install no-op). 562 green.

### 2026-08-20 · Roles milestone — final holistic sweep (Slice 5 closer) — security-green
- **Status:** milestone closed (PR #102, commit 9b291e1). A cross-slice composition sweep over 1–4b + 3b; **no open Critical/High**.
- **Verified invariants (traced in code, not memory):**
  - **Management boundary intact:** `Authorizer::can` short-circuits every MANAGEMENT resource before the content wildcard, so no `*:read`/`*:write` (via role union or token binding) ever yields schema/media/users/tokens/settings/**roles** or admin. Test gap closed — `TokenPrincipalTest::test_the_content_wildcard_never_reaches_a_management_capability` now asserts `roles` too.
  - **No confused deputy:** every core admin handler gates independently of the nav (collections→schema:write ×4, media→media:read/write per-action, users→users:write ×3, roles→roles:write ×4, tokens→tokens:write ×3, plugins→requireAdmin, entry writes→`requireManage`/`manages`). Nav/dashboard gating is cosmetic.
  - **Subset-only uniform:** `Gate::holds` == `firstUnheld` (Roles) == `firstUngrantableRole` (Users) == `firstUngrantable` (admin Tokens) == `TokensToolset::holds` (MCP, over `principalFor`'s union) — same predicate, each over the FULL granted set. CLI exempt (trusted operator, documented).
  - **Live-binding not an escalation:** a token bound to a role can only be raised by `RolesController` edit, which is subset-only on BOTH the existing and the new caps — so it never exceeds an authorized grantor.
  - **Migrations safe:** 011 (role_id SET NULL → deleted role denies, not read-all) and 012 (additive, idempotent, is_system+name scoped, runs once) — neither rideable by an attacker (UI forces is_system=0; names unique; system roles undeletable).
- **Findings:** none blocking. **C6 (Low, out of scope):** plugin admin pages are `authMw`-only — plugin self-gating at the plugin boundary; pre-existing, not a Roles regression. Idea (not required): a `PluginContext` capability declaration for admin pages.
- **Forward requirement:** when the settings-store slice adds a settings write, gate it on `settings:write` (settings is a read-only stub today — no gap).
- **Doc integrity:** `docs/ROLES.md`'s matrix points at RolesEnforcementTest/MediaRoutesTest/TokenAdminTest/McpAdminToolsTest/TokenPrincipalTest as authoritative — no second source of truth to drift.

### 2026-08-20 · Per-user theme write path (theme system, Increment 3) — security-green
- **Status:** shipped (PR #116, commit 8cf6626). The initiative's first admin write path — reviewed adversarially before build; no Critical/High/Medium.
- **Surface:** `POST /admin/settings/theme` → `nb_users.theme` → `<html data-theme>` + a `[data-theme]` CSS selector. `SettingsController`, `AdminTheme::sanitize`, `UserRepository::setTheme`.
- **The pattern (worth reusing):** a value that flows user-input → DB → **an HTML attribute AND a CSS selector** is defended by **allow-list at BOTH write and render** (`AdminTheme::sanitize` — the fixed set of theme slugs), plus `View::e()` (`ENT_QUOTES`) escaping. Neither alone is the whole control: render-side allow-list handles an out-of-band DB value (direct edit, a slug removed in a later release); escape backs it up. *Allow-list a value that selects a code path; never escape-only.*
- **Controls verified:** CSRF (`requireCsrf` on the POST; no state-changing GET); **self-only** write (`$this->auth->user()->id` from the session — a crafted `id`/`user_id` is inert, no IDOR); fixed-column bound UPDATE (no mass-assignment to `role`/`password`); hardcoded redirect + fixed-key mapped flash (no open-redirect/flash-injection). **No capability gate is correct** — a per-user theme is a personal *presentation preference*, not a management capability (so MCP-check N/A too).
- **Regression tests** (`tests/Http/SettingsThemeTest.php`, 8): allow-list rejects a `nimbus"><script>` payload at write; a **tampered stored value** still renders `nimbus` (render-side allow-list); persist+render; CSRF-required; **IDOR** (a crafted id can't set another user's theme); revert; read-only GET.
- **Distinction recorded:** the per-user theme (un-gated, self-only) is NOT the future **site-settings** store (the deferred `settings:write` capability), which IS a management capability needing the Gate + an MCP tool. Keep them separate.

### 2026-08-20 · Token-form role dropdown (Slice 4b-UI) — security-green
- **Status:** shipped (PR #120, commit e0918e4). The third and final surface of roles-for-tokens; reviewed against the escalation-at-mint standing check. No Critical/High/Medium.
- **Surface:** `src/Admin/TokensController::store()` role branch + the `/admin/tokens` form.
- **Controls (all verified + tested):** server-side subset-only over the **full** role capability set (`firstUngrantable($role->capabilities)`, incl. the `admin` super-grant) — a crafted admin/`users:write` role id is rejected regardless of the filtered dropdown (the filter is convenience, not the gate); unknown/`0`/`abc`/deleted id → rejected; the **union is guarded on both paths** — the existing scopes `firstUngrantable` was KEPT when relaxing to "scopes or role", so a held role can't excuse an unheld scope; role-only mint still hits CSRF + the single-use nonce (validate → subset-only(scopes) → subset-only(role) → consume nonce → render); plaintext still renders once (no leak).
- **Regression tests** (`tests/Http/TokenAdminTest.php`, 7 new): bind-a-held-role (positive, role-only), bind-beyond-actor rejected (admin + users:write roles), admin-can-bind-admin, crafted/unknown id rejected, held-role-doesn't-excuse-unheld-scope, role-only-needs-CSRF, dropdown-offers-only-grantable-roles.
- **Escalation-at-mint standing check — now three-for-three:** Slice 4 (MCP `mint_token`), 4b-security (web scopes), 4b-UI (web role). One `firstUngrantable`/`holds` predicate over the entire granted set guards every mint/grant/assign surface (admin UI, CLI-trusted-operator-exempt, MCP). The check has caught or confirmed each; it stands.

### 2026-08-22 · Plugin admin-page capability gating (C6) — security-green
- **Status:** shipped (PR #123, commit f1352f1). Closes the Slice-5 sweep's C6 (Low): a plugin admin page was login-only, reachable by any signed-in user.
- **Surface:** `AdminPageRegistrar::register(..., ?string $capability = null)` (public plugin API), `AdminPageRegistry`, `PluginPagesController` (route), `Controller::nav()`.
- **The control:** a page may require a capability; it is **validated at registration** to be `admin` or a core management cap (`{schema|media|users|tokens|settings|roles}:{read|write}`) — a content-shaped cap (`analytics:read`, `posts:read`) is **rejected**, because `Gate::holds` would route it through the content path where the `*:read` wildcard satisfies it. So only wildcard-immune caps reach enforcement. The route aborts to `/admin` for a non-holder (not just nav-hidden); default `null` = login-only (BC).
- **Regression tests** (`PluginAdminPageTest`, `AdminPageRegistryTest`): a `*:read` holder cannot reach a `media:read`-gated page (wildcard-immunity, end-to-end); route-level deny for a non-holder; holder/admin allowed; uncapped stays login-only (BC, no accidental deny-all); registration throws on content/unknown/malformed caps (incl. case/whitespace, via a data provider).
- **Pattern reused:** validate-a-selector-value-at-the-boundary (as the theme allow-list). **Recorded future (Option-B):** plugin-*defined* capabilities would need a wildcard-immune `holdsExactly` + Roles-UI grantability — not built; plugins gate on admin/management or stay login-only.

### 2026-08-22 · Settings store (site.home, site.description) — security-green
- **Status:** shipped (`feat/settings-store`). Activates `nb_settings` + the reserved `settings:write` capability as an admin- and MCP-editable site-config store. Both skills passed the DESIGN before any code; no Critical/High/Medium.
- **Surfaces:** `src/Settings/*` (typed `SettingsRegistry` allow-list, `SettingsRepository`, `Settings` service); `POST /admin/settings/site` (`SettingsController::saveSite`); MCP `SettingsToolset` (`get_settings`/`set_settings`); reads on the public site via `SiteController` (home + meta description).
- **The five build invariants (each with a test):**
  - **A1 mass-assignment** — the admin write loop is **registry-driven** (iterate the known settings, pull each from the request), and the MCP setter **registry-looks-up every submitted key** (unknown → rejected). An arbitrary key (`settings[role]=admin`, `evil.key`) never reaches `SettingsRepository::set`. *The typed registry is the allow-list boundary — the same pattern as the theme allow-list and plugin-page cap.*
  - **A2 stored XSS** via `site.description` → escaped at every sink: the public `<meta name=description>`/`og:description` and the admin form echo all go through `View::e()` (`ENT_QUOTES`). (A plugin emitting the description into a JSON/JSON-LD context must JSON-encode — documented; out of core scope.)
  - **A3 `site.home`** — a collection **handle** (a DB lookup, not a path/URL: no traversal/SSRF; renders only the already-public live index). Validated-at-write (must name a real collection) + **null-safe at read** (a since-deleted home → placeholder, never 500).
  - **A4 authz** — writes gate on `settings:write` (MANAGEMENT → **wildcard-immune**: a content `*:write` never reaches it) on BOTH the admin form (`requireCan`) and MCP `set_settings`; reads are public (the `Settings` service is uncapped — home/description render to anonymous visitors); MCP `get_settings` needs `settings:read`/`settings:write`.
  - **A7 length cap** — `site.description` bounded at 500 chars (against meta/storage abuse). Keys are registry-fixed.
- **Also:** CSRF on the admin POST (`requireCsrf`); every MCP write audited via `API_MANAGEMENT_WRITTEN`; all SQL bound-param, keys sourced from the registry (no string-built SQL); upsert uses the MySQL8 row-alias form (no reused placeholder).
- **Regression tests:** `tests/Http/SettingsSiteTest.php` (admin: authz incl. `*:write` denied, CSRF, A1 unregistered-key-not-written, bogus-home rejected, over-long rejected, escaped echo, happy path), `tests/Http/SiteSettingsTest.php` (public: DB-home renders, dangling→placeholder not 500, description escaped in meta, BC no-row fallback), `tests/Http/McpSettingsToolsTest.php` (gating read/write, round-trip, unknown-key rejected, validation, `*:write` cannot set, write audited).
- **Boundary recorded (principles.md):** deploy/env config stays in files (`Config` stays DB-free); admin-editable site content goes in `nb_settings` with the `config/*.php` value as the default the DB overrides (no seed migration). Do not move env/deploy config into the store.
- **Follow-up:** the site title (`APP_NAME`, ~8 consumers) is a deferred fast-follow with the same controls.

### 2026-08-22 · Site title (site.title) — security-green
- **Status:** shipped (`feat/settings-site-title`). Makes the site title (was `.env` APP_NAME) an admin/MCP-editable setting in the EXISTING settings store — ONE new registry key (`site.title`), NO new route/capability/MCP tool. Both skills before code.
- **Reused controls (unchanged from the store, PR #126):** registry-driven admin write + registry-lookup MCP setter (over-posting closed), `requireCsrf` + `requireCan('settings','write')`, settings:write wildcard-immune on MCP, bound-param SQL, audited. So the only new surface is the render SINKS.
- **A1 stored XSS at the sinks — closed, test-locked:** `View::e()` (`htmlspecialchars ENT_QUOTES UTF-8`) at EVERY core+starter sink — admin `<title>`/brand/dashboard, login `<title>`/brand, public header brand + `<title>`/`$pageTitle` + og:title + og:site_name. Verified in all three contexts: text node, attribute (both quote styles), and RCDATA (`<title>` — `</title>` injection neutralized because `<`→`&lt;`). Regression tests assert a stored `"><script>alert(1)</script>` renders escaped (not raw) in the admin shell AND the public site.
- **OpenAPI `info.title`:** `json_encode` escapes the `"` and keeps the value a quoted string; served `application/json; charset=UTF-8` (not HTML-executable). Not exploitable. Unit test asserts a passed title reaches `info.title`.
- **Validation:** NON-EMPTY (unlike home) + ≤80 chars; default `Config::appName()` never empty → `title()` always non-empty & bounded. Tests: blank rejected, >80 rejected (admin + MCP).
- **Non-display contexts:** grep-confirmed the title reaches NO session/cookie/CSP/header/filename/cache-key — display-only, exactly as the env var was. No new sink.
- **A3 (Low, the one genuinely new observation) — latent trust flip at the plugin head-contribution boundary:** `SiteController` passes the title into `PageContext.appName` → head contributors emit RAW into `<head>`. Core + starter escape their own uses; a *plugin* head-contributor that embedded `appName` assuming it was trusted `.env` config could now be a stored-XSS vector. **Closed for core/starter**; addressed by documenting in `PageContext`'s docblock that its string values are UNTRUSTED and contributors MUST escape (`View::e()`/`json_encode`). **Follow-up (out-of-repo, tracked):** verify `plugin-seo` escapes `og:site_name`/title.
- **Refinement shipped:** the admin `saveSite` now **skips keys the request omits** (only validates/writes submitted keys) instead of treating a missing key as `''` — matches the MCP setter's partial-update behavior and avoids a required-title forcing every partial POST to carry it. Still registry-driven (only registry keys are ever read from the request → A1 holds); no control weakened (omitting a key leaves it unchanged, never deletes).
- **Regression tests:** `SettingsSiteTest` (+title field shown, save→shell reflects it, blank rejected, >80 rejected, hostile title escaped in shell, unset→.env default), `SiteSettingsTest` (+title renders public, hostile escaped in meta, unset→default), `McpSettingsToolsTest` (+set/get title, blank/over-long rejected), `OpenApiGeneratorTest` (+info.title reflects the resolved title).
- **Threat-catalog WATCH (2nd cousin of the settings-description XSS):** *making a previously-trusted config value user-editable requires auditing every downstream consumer — including plugins/head-contributors — for escape-by-omission.* Promote to a standing check if it bites a third time.

### 2026-08-22 · Admin listing hardening (entry pagination + collections N+1) — security-green
- **Status:** shipped (`feat/admin-listing-hardening`). Admin-only; no new route/capability. Both skills before code.
- **Untrusted inputs:** `page` and `q` (query params) on the admin entry list.
- **A1 SQLi via LIMIT/OFFSET — closed:** `$limit`/`$offset` are interpolated (can't be bound) but ALWAYS derived from `(int)`-cast `page`/a const `PER_PAGE`, and the repo params are typed `int` under repo-wide `strict_types=1` — a non-int throws a `TypeError`, never reaches SQL. Matches the shipped `liveForCollection` precedent. `(int)"1;DROP"` → `1`.
- **A2 SQLi via `q` — closed:** bound `:s` LIKE in BOTH `forCollection` and `countForCollection` (no concat); LIKE wildcards in input are fuzzy-match, not injection.
- **A3 Reflected XSS via `q` (the one real finding, Medium) — closed by construction:** the pager links reflect `q`; built with `rawurlencode($q)` inside the href (encodes `"`/`<`/`>`) then `View::e()` on the whole href (escapes `&`). Regression test: `q='"><script>alert(1)</script>'` → href contains `q=%22%3E%3Cscript%3E`, body has no raw `<script>alert(1)`. (Note: the entries template had **no** `q` echo before — the pager is the first reflection point, escaped from the start. A future visible search box's `value="<?= $e($q) ?>"` is covered by the same rule.)
- **A4 DoS via deep `?page` — closed/improved:** page clamped to `[1,total_pages]`; pagination is strictly better than the prior unbounded "load every row." No `LIMIT 0/-1` path (positive const limit, offset ≥ 0).
- **A5/A6 authz/IDOR — no change:** collections list same `authMw` visibility (grouped counts, same numbers, same set); entry query resolves the collection from the route as before with `collection_id = :c`; `page`/`q` never select a collection.
- **Regression tests:** `tests/Http/AdminListingTest.php` (pagination window, no-pager-single-page, out-of-range clamp incl. `0`/`-5`/`abc`/`999999`, search-aware count, **A3 encoded-`q`**, zero-entry collections list) + `tests/Integration/ListingRepositoryTest.php` (windowing, search-aware count, BC no-limit=all, grouped counts zero-safe).
- **Ledger note:** reflected `q` in admin links — encode at the reflection point; watch for a second reflected-search surface (promote to threat-catalog if it recurs).

### 2026-08-22 · Structured validation errors ({code,message}) — security-green
- **Status:** shipped (`feat/structured-validation-errors`). An error-*representation* change to the API + MCP; both skills before code. No Critical/High/Medium.
- **A1 info disclosure — Low, unchanged/reduced:** messages are static prose or schema-derived (`"{label} is required."`), exposed only to a token that can already read the schema to write. No SQL/exception/path/stack text reaches a message (the one slug `PDOException` is caught and retried, never surfaced). `missing_provider` names an unavailable provider — Low, writer-only, and it *already* leaked today; the slice actually **removes** the `__title`/`__types` leak.
- **A2 code-from-input — closed by construction:** the `code` is ALWAYS core-assigned (`required`/`invalid`/`missing_provider`); a plugin field type's `validate()` string lands ONLY in `message`, never in `code` (the Validator wraps it). Test: a field-type failure yields `code:invalid` with the message separate.
- **A3 XSS across sinks — closed:** API/MCP are `json_encode` (application/json — the hostile quote is escaped `\"`, `<`/`>` are inert data, round-trips intact); the admin renders the message via `View::e()` (a `<script>` label → `&lt;script&gt;`). Tests cover a hostile label in both the JSON sink and the admin HTML sink.
- **A4 enumeration — none:** per-field codes reveal nothing beyond the schema an authorized writer can already read; errors are scoped to the in-scope collection.
- **A5 representation-only — confirmed:** validation logic, the mass-assignment guard, and scope/If-Match checks are untouched; the existing suite guards that nothing was dropped.
- **Correction applied:** dropped `duplicate` from the vocabulary (no producer — slugs auto-uniquify).
- **Regression tests:** `tests/Http/ValidationErrorsTest.php` (API required/invalid/missing_provider/hostile-label-JSON; MCP structured; admin prose + top-level alert + hostile-label HTML escaping), `tests/Integration/EntryServiceTest.php` (title→`title`/`required`; missing-provider top-level), `tests/Unit/NumberTypeTest.php` (code vs message).

### 2026-08-22 · Password reset (emailed one-time token) — security-green
- **Status:** shipped (`feat/password-reset`). Highest-stakes flow to date (account takeover); reviewed hard before code. No Critical/High.
- **Controls built (each tested):**
  - **Reset-link poisoning — closed by design:** the link uses `Config::appUrl()` (`APP_URL` env), never the request `Host` (verified no Host-derived URLs exist).
  - **Token:** 32-byte `random_bytes` (256-bit), **SHA-256 hash-at-rest**, lookup **by hash** (no raw-token timing), plaintext only in the email. 1h expiry.
  - **Single-use, atomic:** `UPDATE … SET used_at=NOW() WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW()` → apply only if affected-rows=1 (single-winner lock; no double-spend race). Second use / expired / unknown all rejected.
  - **No enumeration:** `/admin/forgot` always renders the identical "if that account exists…" and mints a token regardless (comparable work); throttled by IP **and** target email (reuse `LoginThrottle`); a delivery failure is swallowed (still generic) and never logs the token/key.
  - **Strength gate before consume:** a weak password is rejected with the token left intact (retry); strong → consume + set argon2id + invalidate the user's other tokens.
  - **CSRF** on both POSTs; **Referrer-Policy: no-referrer** on the reset page (header + `<meta>`), so `?token=` can't leak via Referer; short expiry + single-use bound the URL-in-logs residue.
  - **Mailer header injection:** `NativeMailer` rejects CR/LF in recipient/subject and `filter_var`s the recipient; `ApiMailer` requires **https** + verifies TLS (`VERIFYPEER`/`VERIFYHOST`), key from env only (never logged/surfaced), recipient validated.
  - Post-reset redirect is hardcoded (`/admin/login?reset=1`) — no open redirect.
- **Accepted residuals (documented, Low):** other active sessions aren't force-invalidated on reset (PHP file sessions; the resetter re-logs in) — a later `password_changed_at` check could add it; and a perfect timing-oracle-free forgot isn't attempted (identical response + throttle is the control).
- **Related control change:** `SecurityHeaders::apply` now **only sets a header the response didn't already set**, so a page can *harden* a default (the reset page's `no-referrer`) — it can never silently *weaken* one (nothing sets weaker values; the defaults still fill every gap).
- **Regression tests:** `tests/Http/PasswordResetTest.php` (13 — real/unknown parity, hashed storage, invalidate-prior, valid reset changes the password, single-use, expired, weak-retry, Referrer-Policy, forgot/reset CSRF, throttle, delivery-failure parity) + `tests/Unit/MailerTest.php` (6 — CRLF/invalid-recipient rejection, https-only, key-required, log capture).
- **Threat-catalog watch:** reset-link poisoning via Host header — closed here (config-derived URL); keep checking any future absolute-URL builder never uses the request Host.

### 2026-08-22 · User invitation (purpose-scoped token) — security-green
- **Status:** shipped (`feat/user-invitation`). Account-provisioning surface; reuses PR #130's hardened token routine. No Critical/High.
- **Controls (each tested):**
  - **Purpose isolation:** every token query filters `purpose` (`reset`|`invite`); an invite token is rejected at `/admin/reset` and vice versa, and issuing a reset does NOT invalidate a pending invite (purpose-scoped `invalidateForUser`). This is the load-bearing correctness property of sharing one table.
  - **Pending-user auth bypass — closed:** an invited user is created with `Password::hash(random_bytes(32))` (unusable — `verify` fails for `''`/any guess), so it grants zero access until accepted; no session ⇒ not an API/MCP principal.
  - **Priv-esc at invite — closed:** the invite path reuses `UsersController::firstUngrantableRole` (subset-only) — a `users:write` holder can't invite above their own grant; on refusal no user is created and no invite is sent. (Escalation-at-grant standing check now covers a 4th surface: admin-tokens, roles, users, invites.)
  - **Shared hardened routine:** `AccountTokenService::setPassword` (strength-before-consume, atomic single-use, invalidate-siblings, audit) is the one place both reset and accept set a password — no copy-paste drift.
  - CSRF on invite (via `store`) + accept POST; `Referrer-Policy: no-referrer` + `<meta>` on `/admin/accept`; 72h invite TTL (bounded, single-use, re-invite supersedes prior); accept-link built from `APP_URL` (no Host poisoning); invited email `filter_var`-validated + Mailer CRLF guard; rendered `View::e()`-escaped.
  - **Delivery failure surfaced to the admin** (`msg=invited-nomail`) — safe because invitation is admin-initiated (no enumeration concern, unlike the public reset flow which stays silent).
- **Regression tests:** `tests/Http/UserInvitationTest.php` (11 — invite issues a usable token + creates a passwordless user; direct-create fallback; no pre-accept login; accept activates; **invite-token-rejected-at-reset**; **reset-doesn't-kill-invite**; single-use; **subset-only role guard**; accept CSRF; delivery-failure reported; no-referrer). Reset suite unchanged and green after the `AccountTokenService` refactor.
- **Threat-catalog (new standing check):** purpose-scoped shared tokens — one table serving multiple credential flows must filter by purpose on *every* read/consume/invalidate, or the classes become interchangeable.

### 2026-08-22 · Nonce-based CSP (script-src) — security-green
- **Status:** shipped (`feat/csp-nonce`). Hardening the CSP itself; the risk is getting it WRONG (false protection). No Critical/High.
- **Controls (each tested/verified):**
  - **Nonce quality:** `base64(random_bytes(16))` = 128-bit CSPRNG; `Http\Csp::rotate()` at `Application::handle()` top → fresh per request (incl. error/404, since `SecurityHeaders::apply` wraps every response); test asserts two requests differ.
  - **`'unsafe-inline'` removed from `script-src`** (the crux) — a regression test asserts `script-src` has a `nonce-…` and NO `'unsafe-inline'`, so it can't silently rot; a browser ignoring a stray `'unsafe-inline'` can no longer mask a missed block.
  - **Header/render match:** a test asserts the CSP-header nonce equals the `nonce=""` on a rendered admin `<script>`; live smoke confirmed a nonced inline script executed and produced zero console CSP violations.
  - **Nonce only in safe sinks:** CSP header + `nonce=""` on server-rendered `<script>` (`$e()`-escaped); never logged, in URLs, or user-controlled attributes. The same-response HTML-injection-scrapes-the-nonce case is the inherent CSP-nonce limitation — Nimbus's escape-by-default remains the primary XSS control; CSP is defense-in-depth.
  - **No control dropped:** the 5 inline `onsubmit` confirms were UX only; delete/revoke routes remain POST + `requireCsrf` + `requireCan`.
- **Accepted residual (Low, documented):** `style-src 'unsafe-inline'` kept — CSS injection (exfil/defacement, no code exec) and needs an escape failure first; additive to harden later.
- **Regression tests:** `tests/Http/CspNonceTest.php` (nonce-only-no-unsafe-inline; fresh-per-request; header-matches-rendered-script; destructive forms use `data-confirm` not inline `onsubmit`).
- **Threat-catalog (new standing check):** CSP nonce hygiene — CSPRNG + per-request + `'unsafe-inline'` removed — for any future CSP change.
