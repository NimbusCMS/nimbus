# Follow-ups discovered during slice work

Items surfaced by the Fable two-skill bursts while building the audit slices —
tracked here so the burn-down stays complete. Same format as the domain files.

### FU-1 · `roles:seed` re-run widens authority for placeholder users
- **✅ RESOLVED** (Slice V) — `RoleSeeder::seed()` now seeds the legacy-`role`-derived system role ONLY for a user with zero `nb_user_roles` rows (`RoleRepository::hasAnyRole()`); a user who already holds any role is skipped, so a re-run can never widen a placeholder user to `author` caps or re-arm a demoted legacy admin (both closed whenever the demotion left ≥1 role). First boot still seeds everyone (zero assignments then). CLI-only trigger, single-process, no TOCTOU. Rated **Medium** (privilege widening past an admin decision, capped below High only by CLI-only reach). Residual (deliberately zero-role user re-acquires its legacy role): FU-16. Tests: `RoleSeederTest` (reseed-doesn't-widen-narrowed; reseed-doesn't-re-arm-demoted-admin; first-boot-still-seeds).
- **Priority:** P2
- **Type:** correctness (security-adjacent)
- **Discovered:** Slice A review.
- **Where:** `src/Auth/RoleSeeder.php:60-65` (assigns every user the system role matching their legacy `nb_users.role`).
- **What:** MCP/admin-created users carry a least-privilege `nb_users.role='author'` placeholder while their real authority is `nb_user_roles`. The seeder is advertised idempotent/re-runnable, but a re-run assigns the `author` **role** to every placeholder user — silently widening authority (grants `*:read` + media).
- **Fix:** one-line guard — skip users who already hold any `nb_user_roles` row (only assign the legacy-derived role to users with zero assignments).
- **Effort:** S

### FU-2 · Management forms enumerate every collection handle
- **⏸ DEFERRED → admin-experience redesign** (deliberate). Low info-disclosure (a semi-privileged management actor sees collection *names*, never content; subset-only still blocks the grant). UI-shaped (offer only grantable/readable collections in the token/role/settings forms) — the natural home is the gated admin-experience redesign ([[nimbus-admin-experience-redesign]]). Revisit: when that redesign lands.
- **Priority:** P3
- **Type:** security (info-disclosure, Low)
- **Discovered:** Slice B security review (A3).
- **Where:** `src/Admin/TokensController.php` + `tokens/index.php` (scope checkboxes), `src/Admin/RolesController.php` (role form), `src/Admin/SettingsController.php` (site.home dropdown).
- **What:** a narrow `tokens:write` / `roles:write` / `settings:write` holder sees every collection handle in the form, even ones they can't read/act on (subset-only still blocks the actual grant). Display-only leak of names to semi-privileged management actors.
- **Fix:** offer only grantable/readable collections in these forms (align with the "offer only grantable" pattern already used elsewhere).
- **Effort:** M

### FU-3 · Dashboard shows aggregate counts to any signed-in user
- **⏸ DEFERRED → admin-experience redesign** (deliberate). Low aggregate-count disclosure (a number, no names). The fix (scope dashboard stats to readable collections) is a dashboard-widget change that belongs with the admin-experience redesign. Revisit: with that redesign.
- **Priority:** P3
- **Type:** security (info-disclosure, Low — aggregate, nameless)
- **Discovered:** Slice B security review (A4).
- **Where:** `src/Admin/AdminController.php` `dashboardPage()` — raw `COUNT(*)` of `nb_collections`/`nb_entries`.
- **What:** a zero-read user sees "Collections: 7" beside an empty (filtered) collections list, learning hidden collections exist (a count, not names) — a mild dent in the "unreadable == missing" property.
- **Fix:** scope the dashboard stats to readable collections, or accept as a documented residual (numbers only). Natural fit for the admin-experience redesign.
- **Effort:** S

### FU-4 · Collection handle can collide with a management capability name
- **✅ RESOLVED** (Slice W) — a collection handle in `CollectionService::RESERVED_COLLECTION_HANDLES` (= `Authorizer::MANAGEMENT ∪ {admin}` + route prefixes `api`/`uploads`/`theme`, kept a superset by a drift test) is rejected at schema-create on **both** surfaces (admin `store` catches `ReservedHandle` → field error; MCP `create_collection` → `invalid`, error naming the set). Enforced in the shared `CollectionService::create` on the **normalized** handle (`Str::handle`), so `"Media"`/`" media "` can't bypass it, and a 3rd caller (seeder) inherits the guard. **Create-time only** — a grandfathered pre-existing `media` collection still edits (the handle is immutable anyway). Security review: **Medium** (one-way management-cap→content widening, incl. drafts; the sharp end is an admin *accidentally* naming a section "Media"), CLI-reach caps it below High; fixed in-slice, no ADR. Residual (grandfathered existing collision) = FU-17. Tests: `ReservedHandleTest` (drift), `CollectionRoutesTest` + `McpSchemaToolsTest` (reject each name incl. case-variant; grandfathering).
- **Priority:** P3
- **Type:** correctness / security (Low, pre-existing)
- **Discovered:** Slice B reviews (A9).
- **Where:** `src/Admin/CollectionsController.php` `validateDraft` + `src/Mcp/SchemaToolset` `createCollection` (no reserved-handle check); interacts with `Authorizer::MANAGEMENT`.
- **What:** a collection named `media`/`users`/`tokens`/`settings`/`roles`/`schema` (or `admin`/`*`) is treated by `Authorizer` as a management resource — no content wildcard, no write⇒read — so `reads()`/`manages()`/the API judge it by management rules (a `*:read` role is denied it; a `media:read` holder is granted content-read of a collection named `media`). Pre-existing via `manages()`; Slice B extends it to reads.
- **Fix:** reject `Authorizer::MANAGEMENT ∪ {admin, *}` as collection handles at creation (admin form + `SchemaToolset`), one validation line each — closes the class for reads/manages/API at once.
- **Effort:** S

### FU-5 · No app-level request-body-size bound
- **⏸ DEFERRED — documented, no code** (deliberate, proportionality). The deployment ceiling is documented in COMPATIBILITY (PHP `post_max_size`, MySQL `max_allowed_packet`); an app-level body-size middleware is not built without evidence of a parse-time DoS (bounded meanwhile by `MAX_PER_PAGE` on the read path + the validation caps on writes). Revisit: evidence of a parse-time memory/CPU DoS.
- **Priority:** P3
- **Type:** security (DoS, defense-in-depth)
- **Discovered:** Slice F security review.
- **Where:** `src/Http/Request.php` `json()` (`file_get_contents('php://input')`, no cap).
- **What:** the entire request body is read + JSON-decoded before app validation runs. Slice F bounds what reaches the DB (per-field length, relation cardinality, column widths), but parse-time memory/CPU is guarded only by PHP `post_max_size` / MySQL `max_allowed_packet` — deployment config, not app.
- **Fix:** document the deployment ceiling (done in COMPATIBILITY), and optionally a small app-level body-size guard in the kernel if evidence warrants (proportionality: don't build a body-size middleware speculatively).
- **Effort:** S

### FU-6 · A field handle can collide with a reserved error-map key
- **✅ RESOLVED** (Slice W) — a field handle in `RESERVED_FIELD_HANDLES` (`title`/`slug`/`published_at`) is rejected as a **new** field at schema-create on both surfaces. Correctness-only (security review confirmed the native title/slug validations run AFTER the field validator and win the key, so they can't be masked — the bug was a silently-dropped *custom-field* error). **New-only** on update so a grandfathered `title` field is never renamed out from under its stored values (syncFields matches by handle → a rename is a data-lossy DELETE+INSERT). Kept a distinct set from FU-4 (a field named `media` is harmless; reserving it would ban a natural field name). Tests: `CollectionRoutesTest` + `McpSchemaToolsTest`.
- **Priority:** P3
- **Type:** correctness
- **Discovered:** Slice F platform review (relates to FU-4 / ADMIN-14 reserve-names family).
- **Where:** the entry error map keys `title`/`slug`/`published_at` alongside field handles; nothing reserves those names at schema-create (`CollectionsController::validateDraft`, `SchemaToolset`).
- **What:** a user-defined field with handle `published_at`/`title`/`slug` collides in the flat `{code,message}` error map, so its error could be shadowed by (or shadow) the entry-level one.
- **Fix:** reserve `title`/`slug`/`published_at` (with the `Authorizer::MANAGEMENT` names from FU-4) as disallowed field/collection handles at schema-create — one allow-list check, closes both families.
- **Effort:** S

### FU-7 · Hosted-analytics beacons need a CSP `connect-src` (or the proxy pattern documented)
- **⏸ DEFERRED — reverse-proxy pattern documented; config-CSP deferred** (deliberate). The supported path (self-hosted or reverse-proxied analytics served from `'self'`) is documented in COMPATIBILITY (§ Page caching / CSP nonce, "External analytics beacons"). A config-driven `connect-src`/`script-src` source extension (an env allow-list, opt-in, off by default) is **not** built — it needs its own security review (widening the CSP is a real surface). Revisit: a concrete hosted-analytics operator need + that security review.
- **Priority:** P3
- **Type:** product-gap / security (scope decision)
- **Discovered:** Slice H reviews (HTTP-1 / PLUG-5).
- **Where:** `src/Http/SecurityHeaders.php` CSP (`default-src 'self'`, no `connect-src`); the analytics head-contribution use case (PLUG-5).
- **What:** Slice H exposed the nonce so a plugin can emit a nonce'd analytics `<script>` — and under CSP L2+ a nonce'd external `<script src>` *loads* regardless of origin. But the site CSP has no `connect-src`, so the loaded script's `fetch`/`sendBeacon` to a third-party endpoint (Plausible/Fathom/GA event APIs) is blocked: the script runs, the event never sends. Self-hosted or reverse-proxied analytics (served from `'self'`) works fully today; GA additionally needs `'strict-dynamic'` for its chained injects.
- **Fix (decide, own review):** either an operator-config CSP source extension (an env allow-list feeding `connect-src`/`script-src`, opt-in, off by default — needs a security review of its own), or document the reverse-proxy pattern (Plausible's official proxy) as the supported path and leave the CSP tight. Do NOT widen the CSP without that review.
- **Effort:** S (docs) / M (config-driven CSP)

### FU-8 · No per-route throttle on resend-invite
- **⏸ DEFERRED — no code** (deliberate, proportionality). The **pending-gate** (only a genuinely-pending user is resendable) is the primary bound, on top of auth + `users:write` + CSRF + the subset guard; a bespoke per-route throttle is not built speculatively. Revisit: evidence of resend abuse (mail-bombing a pending inbox / `nb_password_resets` growth).
- **Priority:** P3
- **Type:** security (DoS, defense-in-depth)
- **Discovered:** Slice I reviews (ADMIN-7).
- **Where:** `src/Admin/UsersController.php` `resendInvite` (`POST /admin/users/{id}/invite`) — issues an invite token + sends mail, no throttle.
- **What:** the route is behind auth + `users:write` + CSRF + the subset guard + **pending-gate** (only genuinely-pending users are resendable), so abuse is already bounded to a privileged insider hitting the finite set of pending users. But unlike the public `/admin/forgot` (IP+email throttled), a resend has no per-actor/per-target rate limit — an authenticated `users:write` insider could still repeatedly resend to spam a pending user's inbox / grow `nb_password_resets`.
- **Fix:** reuse `LoginThrottle` keyed by actor and/or target email on the resend route (mirrors the reset flow), if evidence warrants. Proportionality: the pending-gate is the primary bound; don't build a bespoke throttle speculatively.
- **Effort:** S

### FU-9 · Password-reset request has the same timing oracle AUTH-1 closed for login
- **✅ CLOSED — accepted risk** (Slice V, no code) — rated **Low** with the compensating control, and NOT code-fixed. Unlike AUTH-1 (both branches CPU-bound hashing → complete equal-work), the reset path's dominant cost is the I/O-bound **mail send**, which can't be faked for an unknown email without sending decoy mail; a partial mint-and-discard would be security theater (closes only the DB-write slice, leaves the ~100ms mail-send signal). The dual digest-keyed reset throttle (`pwreset-ip:` + `pwreset-em:`, recorded BEFORE the service call) defeats repeat-sampling of a target; the residual is a bounded, loud (every hit mails the victim), low-value ('account exists' on a small-admin-set CMS) one-shot list-enumeration oracle. The `PasswordResetService` docblock already documents it honestly. Recorded in the security ledger. **Revisit trigger:** async/queued mail dispatch landing (fixes it for free), or a deployment mode that raises the value of 'account exists'. No ADR (Low, not High).
- **Priority:** P3
- **Type:** security (Low, enumeration)
- **Discovered:** Slice N reviews (both lenses).
- **Where:** `src/Auth/PasswordResetService::request()` — early-returns on an unknown email; a known email mints a token + sends mail synchronously (100ms+).
- **What:** the login path was equalized (AUTH-1: a dummy-hash verify on the unknown branch), but the reset-request path still does far more work for a known email than an unknown one → a single-sample timing oracle for "is this email registered?", blunted only by the dual-key rate limit. The `PasswordResetService` docblock previously overclaimed timing-safety (corrected in Slice N).
- **Fix:** equalize the work on the unknown branch (e.g. always do comparable token/mail work, or move mail send off the request path / to a queue so both branches return in constant time), mirroring AUTH-1's approach. Async mail also fixes the latency.
- **Effort:** M

### FU-10 · Login/reset throttle rows accumulate (no pruning of stale keys)
- **✅ RESOLVED** (Slice V) — `LoginThrottle::prune(int $olderThanSeconds): int` removes decayed `nb_login_throttle` rows, wired into `nimbus prune` beside the `ApiRateLimiter` prune (24h). **Lockout-safe predicate** (the load-bearing part): `WHERE last_attempt < :cutoff AND (locked_until IS NULL OR locked_until < :now)` — copying `ApiRateLimiter::prune`'s `updated_at`-only predicate would delete an actively-locking row and reset the lockout (an AUTH-2 bypass). Cutoff (24h) ≥ MAX_LOCK. Tests: `LoginThrottleTest` (prune-removes-stale; **prune-preserves-an-active-lockout** — the merge gate; noop-on-empty).
- **Priority:** P3
- **Type:** performance / housekeeping
- **Discovered:** Slice N reviews.
- **Where:** `nb_login_throttle` — `LoginThrottle::clear` only runs on a *successful* login/reset; failed keys (`login-ip:`/`login-em:`/`pwreset-*`/`oauth-ip:`) for random sprayed IPs/emails are never removed.
- **What:** a spray against random emails/IPs mints one row per key that lingers past its decay window forever, growing the table unboundedly.
- **Fix:** prune rows older than the decay window in `nimbus prune` (a `MaintenanceRegistry`-style task or a direct DELETE `WHERE updated_at < NOW() - INTERVAL <decay>`). Low-risk, opportunistic.
- **Effort:** S

### FU-11 · Optional `nb_`-reference lint on plugin migrations (accident guard)
- **✅ RESOLVED** (Slice Y) — `MigrationRegistrar::register` now rejects (→ `REGISTER_FAILED`, plugin skipped) any migration statement that mutates a core `nb_*` table: CREATE/ALTER/DROP/TRUNCATE TABLE, RENAME (either operand), CREATE/DROP INDEX … ON, and **target-keyed DML** (INSERT/REPLACE/UPDATE/DELETE — a direct `nb_user_roles` INSERT would dodge the Slice-A subset-only chokepoint; a read via `…SELECT FROM nb_*` is a source, not matched). **Verb-anchored on the target** (so a legit `REFERENCES nb_users(id)` FK is allowed) over a comment/literal-stripped statement (versioned-comment bodies kept), with a `(?<![\w.])` guard so a plugin table named `analytics_nb_x` isn't false-flagged, and **fail-closed** on a PCRE error. The evasion corpus (case/backtick/`IF EXISTS`/comment-split/`/*!*/`/comma-list/RENAME-target/no-`TABLE` TRUNCATE) IS the test spec; a drift test flags every core migration statement. **Framing:** an accident guard, not a sandbox (ADR 0001 — a plugin bypasses it with dynamic SQL / raw PDO); the diagnostic teaches (names the table + cites ADR 0005). Completes PLUG-9 (docs half, Slice P). Residuals → FU-18/19. Tests: `MigrationLintTest`.
- **Priority:** P3
- **Type:** architecture (defense-in-depth, accident-only)
- **Discovered:** Slice P (PLUG-9 — the docs half shipped; the lint deferred).
- **Where:** `src/Plugin/MigrationRegistrar::register()`.
- **What:** PLUG-9's convention (prefix your tables; don't touch `nb_*`) is now documented, but nothing *flags* a plugin migration that references a core `nb_`-prefixed table. A cheap regex lint would catch the honest accident (a plugin CREATE/ALTER/DROP against `nb_*`) at registration → REGISTER_FAILED. Explicitly NOT a sandbox (a determined plugin using dynamic SQL bypasses it).
- **Fix:** add the lint in `register()`; ensure no false positives (a plugin never legitimately DDLs `nb_*`; its own tables are prefixed). Deferred from Slice P because a false-positive-prone regex deserves the full two-skill burst, which was unavailable during the 2026-08-24 model incident.
- **Effort:** S

### FU-12 · No front-end performance baseline (Lighthouse / Core Web Vitals) ⏳ BASELINE + CI GATE DONE
- **Update (2026-08-24, #159):** the **CI perf gate now ships** — `.github/workflows/performance.yml` runs on every published release (+ `workflow_dispatch`), seeds a fixed 3-page-type preset (`tests/perf/seed.php`), and runs Lighthouse (`.lighthouserc.json`, 3 URLs × 3 runs) with **hard composition budgets** (script bytes ≈ 0, transfer ceilings, zero third-party) + warn-tracked score/CWV. Verified end-to-end via a dispatched run. **Still open (only):** a representative *content* theme with images/fonts (the starter is a skeleton) — the remaining M-effort half, folded into the website work under release readiness.
- **Update (2026-08-24):** the **baseline is measured + documented** (`docs/PERFORMANCE.md`): a 15-entry blog index on the starter theme scores **100/100 Lighthouse (mobile)** with perfect Core Web Vitals (FCP/LCP 0.8s, TBT 0ms, CLS 0) at ~5.6 KB / 2 requests / 0 JS, page cache off. README leads with it.
- **Priority:** P3 (release-adjacent)
- **Type:** performance / product
- **Discovered:** 2026-08-24 (release-readiness discussion).
- **What:** the public site is fast *by construction* — server-rendered HTML + one ~1 KB external stylesheet (`themes/starter/assets/app.css`), zero mandatory JS, no web fonts, an optional filesystem page cache, and `Cache-Control: public, max-age=3600` on assets. But nothing is *measured*: no Lighthouse / Core Web Vitals run, no perf budget on the public theme, no CI perf gate, and no critical-CSS/preload/font guidance for a real content theme (the starter is a skeleton). Compression (gzip/brotli) is webserver-level (operator's job, like the `/uploads/*` nosniff header). Contrast: WordPress/Drupal/Craft treat CWV as first-class (SEO ranking) — Nimbus's lean-by-default posture is a genuine advantage but is currently a claim, not a proof.
- **Fix:** establish a baseline — run Lighthouse / a CWV check against a representative page on a real content theme (not the starter skeleton); document the numbers; optionally add a lightweight perf assertion or a documented budget. Consider a `Content-Encoding` note in the deployment docs. Relates to DATA-5 (API list N+1) and HTTP-5 (route-regex) on the server side.
- **Effort:** S (baseline + docs) / M (CI perf gate + a real theme)

### FU-13 · Admin `saveSite` writes are unaudited (asymmetry vs MCP `set_settings`)
- **✅ CLOSED — documented decision (Slice X, no code).** Both review hats: the `api.*` audit is deliberately a **token trail** (ADR 0006 — it exists because tokens are non-human principals; its payload is `token_id`/`token_name`). Interactive admin writes have **never** been actor-audited anywhere (schema deletes, user edits, admin token mints are all equally silent) — the asymmetry is the existing design, not a Slice-Q accident. Emitting a settings-only admin event would (a) freeze a NEW pre-1.0 public event family with **zero consumers** (Drift-Guard #3 fail — no requesting operator, three cosmetic keys at stake), and (b) create a misleading **partial** audit surface (worse than documented absence). Rated a **Low** forensic gap with no exploit path (the settings surface holds no secrets — OAuth creds are env-only, never Settings). The coherent capability is an **admin activity log** (the dormant `nb_activity` table, `user_id`/`action`/`subject`, is its natural home) — deferred until evidence. **Revisit triggers:** (1) settings gain a security-relevant key (SMTP/mail, OAuth, URLs) — the security loop co-owns this; (2) a real operator/compliance request for admin attribution; (3) the `nb_activity` use-or-drop decision.
- **Priority:** P3
- **Type:** observability / parity
- **Discovered:** 2026-08-24 (Slice Q security review).
- **What:** the MCP `set_settings` path emits an `API_MANAGEMENT_WRITTEN` audit event per key, but the admin `SettingsController::saveSite()` path writes the same settings with no audit trail — so a settings change made through the admin UI leaves no record, while the same change via a token does. Pre-existing (not introduced by Slice Q); surfaced while moving the MCP emits post-commit.
- **Fix:** emit a settings-write audit event from `saveSite()` after the atomic `setMany` commits (mirror the MCP `target`/`action` shape, actor = the session user), or record a deliberate decision that admin-UI settings writes are out of the audit scope. Small.
- **Effort:** S

### FU-14 · Deleting a collection silently breaks relation fields that target it
- **✅ RESOLVED** (Slice X) — `CollectionService::delete` now **refuses** (transaction-wrapped) when a relation field in ANOTHER collection targets this one (`CollectionRepository::relationFieldsTargeting`, PHP-side JSON decode, **excluded by collection id** so a self-targeting field never blocks its own deletion), throwing `CollectionInUse` (mirrors `MediaInUse`) — the reverse of ADMIN-14a's write-time validation. Refuse (not null/re-point) is the reversible, no-surprise choice — re-pointing would silently mutate a sibling collection's schema + bump its version. Admin `destroy` catches → **server-renders the escaped detail** (never round-tripped through `?err=` — ADMIN-10); MCP `delete_collection` → `in_use` ToolResult carrying the usage; `confirm` flow untouched. Grandfathered dangling targets stay fail-closed by the DATA-1 read guards (no migration). Tests: `CollectionRoutesTest` (targeted→refused naming the field; self-target→deletes; untargeted→deletes) + `McpSchemaToolsTest` (in_use + usage payload parity).
- **Priority:** P3
- **Type:** correctness (data integrity)
- **Discovered:** 2026-08-24 (Slice R platform review).
- **What:** ADMIN-14a validates a relation field's `target` at *write* time (the target must be an existing collection). But nothing guards the *reverse*: deleting collection X while another collection's relation field still targets X leaves that field pointing at a now-missing collection — the same dead-relation/empty-picker state 14a prevents on write, reached from the other direction. Fail-closed today (reads resolve to `[]`, no 500), so it's a silent product papercut, not a security bug.
- **Fix:** at collection delete, either warn/refuse when a relation field elsewhere targets it (mirroring the media in-use guard), or null/re-point those fields deliberately. Decide the semantics; small once decided.
- **Effort:** S

### FU-15 · A regular-collection home is duplicated at `/` and `/{handle}`
- **⏸ DEFERRED (P4)** (deliberate). Unlike the single-kind case (SVM-4, fixed), `/{handle}` for a *browsable* collection is a legitimate advertised URL, so the remedy is a **canonical-tag policy**, not a 404. Revisit: SEO evidence that the duplicate signal matters.
- **Priority:** P4
- **Type:** SEO / canonical
- **Discovered:** 2026-08-24 (Slice U platform review).
- **What:** SVM-4 (Slice U) 404s a *single-kind* home's `/{handle}`, but a **browsable** collection set as the home (`settings.home = posts`) still serves identical content at both `/` and `/posts`, and the sitemap lists both — split canonical signal. Unlike the single case this is NOT a 404 fix: `/posts` is a legitimate advertised URL for a browsable collection.
- **Fix:** a canonical-tag policy (emit `<link rel="canonical" href="/">` on the collection index when it is the designated home, or vice-versa), not a route change. Decide the canonical direction.
- **Effort:** S

### FU-16 · A deliberately zero-role legacy admin re-acquires `admin` on a `roles:seed` re-run
- **⏸ DEFERRED — accepted** (deliberate). The Slice V security review rated the `RoleSeeder` zero-assignment guard **sufficient**; this narrow residual needs (legacy admin, `nb_users.role='admin'`) + (deliberately stripped to *exactly zero* roles) + (a CLI `roles:seed` re-run). The companion fix (normalize `nb_users.role` on role edits) is **riskier than it looks** — `Auth` hydrates `User->role` from that column, so normalizing it would need auditing every `User->role` read for a legacy authz check. Not built. Revisit: `User->role` becoming provably non-authoritative, or evidence of the sequence occurring.
- **Priority:** P3
- **Type:** security (privilege widening, Low residual of FU-1)
- **Discovered:** 2026-08-24 (Slice V reviews).
- **What:** FU-1's guard skips any user with ≥1 `nb_user_roles` row, treating **zero** roles as "never seeded". So a legacy admin (`nb_users.role='admin'`) whom an operator deliberately stripped to **zero** roles (a non-last admin can be) re-acquires the `admin` system role on the next `roles:seed`. Narrow (legacy-admin + demoted-to-exactly-zero + a reseed), CLI-only, but re-grants admin specifically.
- **Fix (the platform review's companion, deferred here to keep Slice V tight):** when the admin UI (`UsersController::update`) or MCP `set_role` edits a user's roles on a seeded install, normalize `nb_users.role` to the `'author'` placeholder (the legacy column is non-authoritative once `nb_user_roles` drives authority), so a later reseed of a zero-role user can only grant the least-privilege role. Small; touches the two role-edit paths.
- **Effort:** S

### FU-17 · A grandfathered collection whose handle collides with a management name stays management-judged
- **⏸ DEFERRED — documented residual** (deliberate). Reject-at-create (FU-4) can't reach a pre-existing collision; a silent rename would orphan its scopes/API paths (handle immutable). A `nimbus doctor`-style warning is the fix **if a real upgrade hits it**. Revisit: an upgraded install carrying a colliding `nb_collections.handle`.
- **Priority:** P3
- **Type:** security (Low residual of FU-4)
- **Discovered:** 2026-08-24 (Slice W security review).
- **What:** FU-4's guard is create-time only. A collection named `media`/`users`/etc. that **already exists** on an install upgraded to Slice W is still judged by `Authorizer` under management rules (a `media:read` holder reads its content, a `*:read` role is denied it). Reject-at-create can't reach it, and renaming it at migration would orphan its scopes/API paths/entries (the handle is deliberately immutable).
- **Fix:** a `nimbus`-doctor / startup diagnostic that flags any existing `nb_collections.handle IN (RESERVED_COLLECTION_HANDLES)` and tells the operator to rename it (an operator decision, not a silent migration). Docs note now; build the check only if a real upgrade hits it.
- **Effort:** S

### FU-18 · Reserve `nb`-prefixed plugin ids (namespace symmetry)
- **✅ RESOLVED** (Slice Z) — the loader id-gate now rejects an id matching `^nb([._-]|$)` (`nb`, `nb_stats`, `nb.x`, `nb-y`) with `INVALID_MANIFEST`, so a plugin can't claim an id in core's `nb_*` table namespace (symmetric with the FU-11 migration lint). Test: `PluginLoaderTest::test_a_plugin_id_in_the_reserved_nb_namespace_is_rejected`.
- **Priority:** P4
- **Type:** hygiene
- **Discovered:** 2026-08-24 (Slice Y platform review).
- **What:** the loader id gate accepts `[a-z0-9][a-z0-9._-]*`, so a plugin id like `nb_stats` is valid — and would name tables `nb_stats_hits`, correctly tripping the FU-11 lint (a policy true positive, but confusing). Reserving `^nb[._-]` ids in the loader would make the `nb_*` namespace reservation symmetric (a plugin can't even claim an id in core's namespace).
- **Fix:** one regex tweak in `PluginLoader`'s id validation + a REJECT test. Severable from FU-11.
- **Effort:** S

### FU-19 · FU-11 lint's uncovered surface (recorded residual, not a gap to fix)
- **Priority:** P4 (documentation of scope)
- **Type:** security (accepted, framing)
- **Discovered:** 2026-08-24 (Slice Y security review).
- **What:** the FU-11 lint is an **honest-accident guard, not a security control** (ADR 0001: plugins are trusted in-process code). It deliberately does NOT catch: `nb_*` DDL/DML from plugin **runtime** code (event listeners, admin handlers, a raw `new PDO`), **dynamic/concatenated** SQL (`CONCAT('nb_','users')`, `PREPARE`/`EXECUTE`), stored routines/triggers/views bodies (beyond a literal match), `SET FOREIGN_KEY_CHECKS=0`, and **reads** that copy core data (`CREATE TABLE x AS SELECT … FROM nb_*`). These are the hostile-plugin surface, unchanged by the lint — catalog #12's "hostile in-process plugin can DDL `nb_*`" Low stays open and is NOT mitigated by this lint.
- **Fix:** none — recorded so no future review counts the lint toward the plugin threat model. Revisit only if evidence of a specific accident class arrives.
- **Effort:** —
