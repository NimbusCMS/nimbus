# API & MCP — external programmatic surfaces (audit 2026-08)

Domain: `src/Api/**`, `src/Mcp/**`. Reviewed with both disciplines
(nimbus-review-loop three hats + Platform Drift Guard; nimbus-security-review
Attacker/Defender/QA). Grounded in CHARTER, COMPATIBILITY, ADR 0006/0007/0008/0009/0011
and both ledgers.

**Summary.** The shared-`EntryOperations` design is genuinely strong: content
authz is scope-before-existence, deny-by-default, and identical across HTTP and
MCP; relation expansion is scope-filtered; token secrets are CSPRNG + hash-at-rest;
the content-wildcard-vs-management-capability fix still holds across every toolset
(`Authorizer::can` short-circuits MANAGEMENT before the wildcard, and each toolset
re-gates). The real problems are **not** in the content path — they are on the
*management* edges that grew alongside the roles system: the MCP user tools were
never migrated onto `nb_user_roles`, so they are both functionally broken and a
latent escalation surface; the OpenAPI document ignores scope and re-enumerates the
whole model that the rest of the surface works hard to hide; and the optimistic-
concurrency guarantee is enforced by a check-then-act, not an atomic CAS.

Counts: **P0 0 · P1 3 · P2 2 · P3 3.**

---

### API-1 · MCP user tools are desynced from the roles system (grant no real authority)
- **Priority:** P1
- **Type:** correctness / product-gap (also ADR-0009 "one backend" violation)
- **Where:** `src/Mcp/UsersToolset.php:84-153` (`createUser`, `setRole`, `listUsers`), calling `Nimbus\Auth\UserRepository::create/setRole/countByRole` — contrast `src/Admin/UsersController.php:108-167` (the admin path).
- **What:** MCP `create_user`/`set_role` write only the legacy `nb_users.role` string column and never assign an `nb_user_roles` role, but authorization is resolved from `nb_user_roles` (`RoleRepository::capabilitiesForUser` → `Gate`/`UserPrincipal`). So an agent-created/modified user gets **zero** effective capabilities on any seeded install.
- **Evidence:**
  - `Gate::can()`/`manages()` (src/Auth/Gate.php:42-66) resolve authority from `capabilitiesForUser($userId)`, which joins `nb_user_roles` (RoleRepository.php:120-141). `UserRepository::create`/`setRole` (UserRepository.php:52-67) touch only `nb_users.role`. The only writers of `nb_user_roles` are `RolesController`, `RoleSeeder`, migration 010, and `RoleRepository` — never the MCP path.
  - MCP `create_user role:"editor"` → new user has `nb_users.role='editor'`, no row in `nb_user_roles` → `capabilitiesForUser` returns `[]` → the Gate denies everything. The agent believes it made an editor; it made a user who can log in and do nothing.
  - `create_user`/`set_role` validate `role` against `Permissions::ROLES` = `['admin','editor','author']` (Permissions.php:20) and there is **no MCP roles toolset** — so an agent cannot assign any admin-composed custom role at all. The agent-run-CMS story (ADR 0009: "MCP calls the same services the admin uses") does not hold for user management.
  - `list_users` reports `nb_users.role` (UsersToolset.php:74), which no longer reflects a user's real authority; the last-admin guard uses `countByRole('admin')` on `nb_users.role` (UsersToolset.php:146) while the admin uses `assignedUserCount` on `nb_user_roles` (UsersController.php:159) — two sources that can disagree, so "this is the only admin" can be wrong in both directions.
- **Fix:** route MCP user management through the same roles-aware path the admin uses — assign `nb_user_roles` via `RoleRepository::syncUserRoles`, accept custom-role names/ids (not only the three legacy strings), and read authority back from `capabilitiesForUser`. Fix jointly with API-2 (do not add role assignment without the subset-only guard).
- **Effort:** M

### API-2 · MCP create_user / set_role has no subset-only escalation guard (latent High)
- **Priority:** P1
- **Type:** security
- **Severity (security):** Medium now, **latent High** (becomes High the moment API-1 is fixed)
- **Where:** `src/Mcp/UsersToolset.php:84-153`
- **What:** neither `create_user` nor `set_role` checks that the caller *holds* the authority it is granting. A non-admin `users:write` token can request `role: "admin"` (and, on create, set that user's password) — the classic escalation-at-mint the standing check exists to stop, applied on every other grant surface but missing here.
- **Evidence:**
  - The escalation-at-mint standing check is "three/four-for-four": `TokensToolset::holds` (MCP mint), `TokensController::firstUngrantable` (admin tokens), `RolesController::firstUnheld`, `UsersController::firstUngrantableRole`, invites. `UsersToolset::createUser`/`setRole` apply **none** of them — the only gate is `in_array($role, Permissions::ROLES)`.
  - A "User Manager" custom role (holds `users:write`, not `admin`) — grantable to a non-admin under Roles Slice 3 — presents its token to MCP and calls `create_user {email, role:"admin", password:"Known-Pass-1"}`. Subset-only would reject `admin` for a non-admin; nothing does.
  - Rated Medium (not High) **only** because API-1's desync currently makes the granted role inert on a seeded install — `nb_users.role='admin'` confers no capabilities. On an **un-seeded** install the `Gate` legacy fallback reads `Permissions::isAdmin` (i.e. `nb_users.role`), so it would bite there, but a non-admin cannot hold `users:write` pre-seed, so reachability is low. Once API-1 assigns real roles, this is unauthenticated-of-a-scoped-token → admin account with a known password → full compromise: **High**.
- **Fix:** in the same change that fixes API-1, reuse the shared subset-only predicate (`Gate::holds` / `firstUngrantableRole` semantics, evaluated over the calling `TokenPrincipal`) for both create and set-role, over the entire granted capability set of the target role; reject before any write. Add the MCP rows to the authorization matrix (see API-7).
- **Effort:** S (once API-1's role plumbing exists)

### API-3 · OpenAPI document leaks the full content model to any scoped token
- **Priority:** P1
- **Type:** security / product-gap (contract consistency)
- **Severity (security):** Medium
- **Where:** `src/Api/ApiController.php:118-121` → `src/Api/OpenApiGenerator.php:38-51` (`generate()` iterates `collections->all()` with no principal).
- **What:** `GET /api/v1/openapi.json` is behind bearer auth but **not scope-filtered**: it emits paths + schemas for *every* collection, so a token scoped to one collection learns the handle, every field name/type/JSON-schema, and existence of every other collection in the install — defeating the non-enumeration guarantee the rest of the surface enforces.
- **Evidence:**
  - ADR 0006 and COMPATIBILITY promise an out-of-scope collection "cannot be told apart from one that does not exist"; `EntryOperations::allowed` (scope-before-existence), MCP `tools/list`/`describe_collection` (scope-filtered, unknown-tool on deny) all honor it. `openapi()` passes no `TokenPrincipal` into `OpenApiGenerator`, and `collectionPaths()` is built for all handles.
  - A `posts:read` token: `GET /api/v1/openapi.json` → response `paths` contain `/collections/internal_hr/entries`, `/collections/customer_pii/entries`, etc., with full field schemas — collections the token gets 403==404 on everywhere else. The code comment already concedes "a scope-filtered per-token spec is a later refinement."
  - The API contract is versioned; an unfiltered spec is an accidental inconsistency worth nailing before 1.0.
- **Fix:** thread the request principal into `OpenApiGenerator` and emit only collections the token can `read`/`write`, and only the verbs its scope allows (drop the write paths without `{handle}:write`). Smallest correct change; no new dependency.
- **Effort:** M

### API-4 · Optimistic concurrency is check-then-act, not an atomic CAS (lost update)
- **Priority:** P2
- **Type:** correctness (concurrency) / security-adjacent (broken data-integrity guarantee)
- **Severity (security):** Medium
- **Where:** `src/Api/EntryOperations.php:161-166, 191-196` (read row → `checkPrecondition` → `entryService->save`) → `src/Content/EntryRepository.php:135-147` (the UPDATE).
- **What:** the If-Match/`version` precondition is validated against a *previously read* row, then the UPDATE runs `version = version + 1 WHERE collection_id AND id` — with **no** `AND version = :expected` and no row lock/transaction spanning the check. Two concurrent writers that both read version N both pass the precondition and both write, so the second silently overwrites the first — exactly the lost update the ETag/If-Match contract exists to prevent.
- **Evidence:**
  - COMPATIBILITY: "`PATCH`/`DELETE` needs `If-Match` … so machine clients cannot silently overwrite each other." Two agents (or CI jobs) `get_posts`/`GET` → both hold version 3 → both `update_posts version:3` / `PATCH If-Match:"id-3"`. `Precondition::evaluate` compares 3==3 for both (nothing has mutated the row yet); both call `save`; the UPDATE (EntryRepository.php:142) has no version guard, so both apply and the later write wins. First writer's changes are lost with a 200, not a 412.
  - Window is the read→write gap — narrow, but this is precisely the concurrent-agent scenario the write API was built for.
- **Fix:** make it a compare-and-swap: add `AND version = :expected` to the UPDATE and treat affected-rows = 0 as `PreconditionOutcome::Failed` (412); or wrap read+check+save in a transaction with `SELECT … FOR UPDATE`. The contract lives in this domain even though the query is in `Content` — coordinate with the content-db agent on the repository change.
- **Effort:** M

### API-5 · CORS preflight advertises only GET/OPTIONS — cross-origin writes & MCP blocked
- **Priority:** P2
- **Type:** product-gap / correctness (contract consistency)
- **Where:** `src/Http/Cors.php:33-38` (`preflight()`), reachable for the whole `/api/` surface.
- **What:** the preflight answers `Access-Control-Allow-Methods: GET, OPTIONS` and `Access-Control-Allow-Headers: Authorization, Content-Type`, but the API also serves `POST`/`PATCH`/`DELETE` writes (ADR 0007) and `POST /api/v1/mcp`. A browser app on an allow-listed origin cannot perform any write or MCP call cross-origin — the preflight tells it the method is not allowed.
- **Evidence:** allow-listed origin runs `fetch('/api/v1/collections/posts/entries/x', {method:'PATCH', headers:{Authorization, If-Match, 'Content-Type':'application/json'}})`. Browser sends `OPTIONS`; `Cors::preflight` returns methods `GET, OPTIONS` → the browser blocks the PATCH before it is sent. Same for `POST /mcp`. COMPATIBILITY says preflights "are answered without a token," implying cross-origin use works; for writes it does not.
- **Fix:** advertise the methods the API actually serves — `GET, POST, PATCH, DELETE, OPTIONS` — in `Access-Control-Allow-Methods` (and keep `If-Match` acceptable via the existing `Content-Type, Authorization` allow-list; `If-Match` is a non-safelisted request header, so add it too). Not a security change (bearer auth, no cookies, no `Allow-Credentials`), purely enabling the documented cross-origin story.
- **Effort:** S

### API-6 · MCP HTTP transport silently drops JSON-RPC batch requests
- **Priority:** P3
- **Type:** correctness / interop
- **Where:** `src/Api/ApiController.php:129-140` (`mcp`) → `src/Mcp/McpServer.php:53-84` (`handle` takes one decoded message).
- **What:** JSON-RPC 2.0 permits a batch (a top-level array of messages). `handle()` treats the whole decoded body as a single object: a batch array has no top-level `id`, so `$isNotification` is true and it returns `null` → the controller answers `202` with an empty body. A compliant client that batches calls gets silence, not results or an error.
- **Evidence:** `POST /api/v1/mcp` body `[{"jsonrpc":"2.0","id":1,"method":"tools/list"}]` → `array_key_exists('id',$message)` is false (numeric keys) → returns null → 202, empty body. No tool runs, no error is reported.
- **Fix:** either detect a list-shaped body and reject it with an explicit JSON-RPC error (batches unsupported), or implement batch handling. At minimum stop returning a misleading 202. (Note: silently ignoring batches is incidentally rate-limit-safe — one HTTP hit can't fan out — so document non-support rather than adding a bypass.)
- **Effort:** S

### API-7 · Test gap: no authorization matrix for the MCP management edges
- **Priority:** P3
- **Type:** test-gap
- **Where:** `tests/Http/McpAdminToolsTest.php` (tokens covered; users under-covered), no matrix covering user-tool escalation or the concurrency race.
- **What:** the token-mint escalation is well guarded by tests, but there is **no** regression test asserting (a) a non-admin `users:write` token is refused `create_user`/`set_role` to `admin` (API-2), (b) an MCP-created user actually receives effective capabilities (API-1), or (c) two concurrent version-N writes cannot both succeed (API-4).
- **Evidence:** the ledger's escalation-at-mint entries cite token tests only; `McpAdminToolsTest` asserts `set_role` last-admin and mint subset-only, not user-role subset-only or capability-effectiveness. The ADR-0006 authorization-matrix artifact does not include the MCP user tools as rows.
- **Fix:** extend the authorization matrix (actor × scope × object × action) with MCP `create_user`/`set_role` rows (deny for non-admin granting admin; created user's resolved capabilities match the assigned role); add a concurrency test that two same-version updates yield one 200 and one 412 after API-4 lands.
- **Effort:** S

### API-8 · `ApiResponse` docblock lists a stale subset of error codes
- **Priority:** P3
- **Type:** product-gap (doc drift)
- **Where:** `src/Api/ApiResponse.php:19-22`
- **What:** the class docblock enumerates only `unauthorized/forbidden/not_found/rate_limited`, but the API also emits `invalid` (422), `missing_provider` (422), `precondition_required` (428), `precondition_failed` (412). COMPATIBILITY.md is correct; the in-code contract comment is not — a maintenance hazard for the one place error shapes are defined.
- **Evidence:** `mapFailure` (ApiController.php:239-246) and `ApiResponse::invalid`/`error` produce all the above codes; the docblock omits four.
- **Fix:** list the full stable code set in the docblock (or point it at COMPATIBILITY.md as the single source).
- **Effort:** S

---

## What's solid

- **Shared content path holds the line.** `EntryOperations` enforces scope
  before existence, deny-by-default, on every list/get/create/update/delete; HTTP
  and MCP both go through it, so they cannot diverge on authz, mass-assignment
  binding (declared-fields allow-list, unknown keys dropped), or auditing.
- **The scope-confusion fix still holds everywhere.** `Authorizer::can`
  short-circuits the six MANAGEMENT resources before the content wildcard, and
  every management toolset (schema/media/users/tokens/settings) re-gates on its
  exact capability with non-enumerating unknown-tool + audited denial. A `*:write`
  token reaches no management tool. Verified across all toolsets.
- **Relation expansion is scope-filtered** (`EntryView` `canRead` predicate) and
  live-only, so a relation never leaks an unpublished or out-of-scope target.
- **Token mint is genuinely subset-only** (`TokensToolset::holds` over the full
  scope set *and* every capability of a bound role) — the escalation guard that
  API-2 is missing on the *user* tools is correct here.
- **Secret hygiene:** tokens are CSPRNG (`random_bytes(20)`) + SHA-256 at rest,
  looked up by hash, shown once, never in audit events or logs; lifecycle
  (revoke/pause/expire) gates at one chokepoint and never leaks state.
- **Media upload over MCP** reuses the admin uploader unchanged (finfo sniff,
  no SVG, random stored name, size cap before + after decode); delete routes the
  shared in-use guard.
