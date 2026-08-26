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

### 2026-08-24 · Slice Y — Plugin `nb_`-reference migration lint (FU-11)
Reviewed via the Fable security burst before building. **No security finding (None/hygiene)** — the lint neither strengthens nor weakens the actual plugin boundary.

- **This is an ACCIDENT GUARD, not a security control.** It must **not** be counted as a mitigation for a malicious plugin. Per ADR 0001 an installed plugin is trusted in-process code; it bypasses the lint trivially via runtime SQL (listeners/handlers/raw PDO), dynamic/concatenated SQL (`CONCAT('nb_','users')`, `PREPARE`/`EXECUTE`), stored routines, `SET FOREIGN_KEY_CHECKS=0`, or reads that copy core data (`CREATE TABLE x AS SELECT … FROM nb_*`). **Catalog #12's "hostile in-process plugin can DDL `nb_*`" Low (Slices D/M) stays OPEN and is not narrowed by this slice.** Full uncovered-surface list recorded as FU-19.
- **Security value = availability/integrity hygiene only:** it catches a *typo'd or copy-pasted* `DROP/ALTER TABLE nb_*` (or a `nb_user_roles` INSERT that would dodge the Slice-A subset-only chokepoint) **before** MySQL's irreversible auto-commit DDL runs it — genuine value docs alone don't give (the accident is unrecoverable without backups).
- **False-negative discipline (the failure mode that matters for a guard):** the regex normalizes away comments + string literals (keeping versioned-comment bodies MySQL executes), is verb-anchored, checks comma lists + both RENAME operands + `INDEX … ON`, and **fails closed** on a PCRE error. The evasion corpus (`MigrationLintTest`) — case/backtick/`IF EXISTS`/`/**/`-split/`--`-split/`/*!*/`/comma-list/no-`TABLE`-TRUNCATE — is the regression spec so a future "simplify the regex" can't silently open an evasion.

Verdict: **security-green.** Framing recorded in code + docs (accident guard, not sandbox; ADR 0001 trust unchanged); catalog #12 untouched.

### 2026-08-24 · Slice X — Collection-delete integrity + settings-audit decision (FU-14 built / FU-13 accepted)
Reviewed via the Fable security burst before building. Security-green, **no ADR** (both Low/correctness).

- **FU-13 — Low (forensic/observability gap), ACCEPTED as documented scope.** A hijacked `settings:write` admin session can change `site.home`/`site.title`/`site.description` with no actor-attributed trail. Bounded: the settings surface holds **no secrets** (OAuth creds are env-only, never `Settings`), no script sink, no authz input — so this is anti-forensics only, no capability gained, and the same absence exists on every admin write (schema/users/tokens). The `api.*` audit is a token trail by design (ADR 0006). Recorded as an accepted Low with revisit triggers; **the security loop co-owns revisit trigger #1** (a future SMTP/mail/OAuth/URL settings key would silently inherit the unaudited surface — re-rate then). No code (emitting would freeze a zero-consumer event family + create a misleading partial audit).
- **FU-14 — no security finding (correctness).** Confirmed the current dangling-target state is already fail-closed (the DATA-1 read guards: target-handle JOIN + `canRead` gate + FK cascade → `[]`, no leak/500/oracle). The refuse-to-delete fix adds no attack surface: the in-use detail (collection names + field labels) reaches only `schema:write` holders (the highest structural privilege — they read all schema anyway; no non-enumeration regression), the admin render **escapes** the operator-authored labels server-side (ADMIN-10-safe, not URL-reflected), the guard is a *refusal* not a new grant and runs post-authz at the shared chokepoint (no bypass), the confirm flow is untouched, and cross-privilege pinning is impossible (creating the targeting field needs the same `schema:write`). TOCTOU (concurrent field-create vs delete) composes into today's fail-closed dangling state, not a hole — accepted (media parity), mitigated by the transaction wrap.

Verdict: **security-green.** Hardens: completes the schema-integrity triangle (collection delete gains the in-use discipline media already has); records the settings-attribution gap as a deliberate, revisit-triggered scope decision rather than silent debt. Leaves open (tracked): grandfathered dangling targets (fail-closed by DATA-1); the platform-wide "admin writes carry no actor" posture (ADR 0006 family, revisit on a compliance trigger).

### 2026-08-24 · Slice W — Reserve names at schema-create (FU-4/FU-6)
Reviewed via the Fable security burst before building. Security-green, **no ADR** (FU-4 is a Medium fixed in-slice).

- **FU-4 — Medium (one-way management-cap → content widening), FIXED.** A collection whose handle IS a management name (`media`, …) is judged by `Authorizer` under management rules: a `media:read` holder gains content-read (**incl. drafts**) of it, `media:write` gains content-write; the reverse (content wildcard → management) is impossible (management-deny runs before the wildcard). Two abuse paths: self-escalation (a `schema:write`+`media:write` actor crafts a `media` collection — bounded, `schema:write` is already top privilege, the collection is freshly-empty) and the realistic **accidental-admin-grant** (an admin names a section "Media" → standing `media:*` principals silently gain draft reach, no attacker needed). CLI/`schema:write`-gated creation caps it below High. Fix = reject `MANAGEMENT ∪ {admin}` (+ route prefixes) at schema-create on both surfaces, **on the normalized handle** (the one bypass — checking raw input — is guarded by a normalization test). Guarding tests: `CollectionRoutesTest` + `McpSchemaToolsTest` (each name incl. `Media`); `ReservedHandleTest` drift guard. **Standing check:** the reserved set must stay ⊇ `Authorizer::MANAGEMENT ∪ {admin}` (drift test) — a new management capability that isn't reserved re-opens this.
- **A3 residual — Low.** A *grandfathered* colliding collection (created before the guard, on an upgraded install) stays management-judged; reject-at-create can't reach it and a silent rename would orphan its scopes/paths (handle immutable). Documented; detect-and-warn tracked as **FU-17**.
- **FU-6 — no security angle (correctness).** A field named `title`/`slug`/`published_at` cannot mask the security-relevant native title/slug/published_at validations (those run *after* the field validator and win the flat-map key); the bug is a silently-dropped *custom-field* error. Reserved as new-field handles anyway (data-integrity), grandfathered on update.

Verdict: **security-green.** Hardens: closes the standing catalog-#2 handle/management-namespace confusion for all new collections across admin + MCP + API via one shared allow-list at the create chokepoint. Leaves open: the grandfathered-collision residual (FU-17, Low, operator-owned).

### 2026-08-24 · Slice V — Auth/account hardening (FU-1/FU-9/FU-10)
Reviewed via the Fable security burst before building. Security-green, **no ADR** (highest severity is Medium, fixed in-slice).

- **FU-1 — Medium (privilege widening past an admin decision), FIXED.** `RoleSeeder::seed()` unconditionally re-assigned each user its legacy-`role` system role on every run; `roles:seed` is the *documented upgrade step*, so the widening was a scheduled footgun, not hypothetical. Confirmed blast radius: a placeholder user (`nb_users.role='author'`) re-gains `*:read` (every collection, incl. admin-withheld ones) + media caps; a **demoted legacy admin** re-gains `admin` (full compromise of the demotion); and the Slice-A subset-only invariant is violated transitively (a narrow `users:write` creator's user gains caps the creator never held). **CLI-only** (RoleSeeder has exactly two callers, both `bin/nimbus` — no admin route/MCP/plugin reaches it), which caps it below High. Fix = seed only users with zero `nb_user_roles` (`RoleRepository::hasAnyRole`); traced to not under-seed first boot; single-process CLI → no TOCTOU. Guarding tests: `RoleSeederTest::test_a_reseed_does_not_widen_a_user_whose_roles_were_narrowed` (+ demoted-admin, + first-boot). **Standing check:** if any non-CLI surface ever calls `RoleSeeder::seed()` (an admin "reseed" button, an MCP maintenance tool), re-rate to High immediately. Residual (deliberately zero-role legacy admin) tracked as FU-16.
- **FU-9 — Low, accepted with compensating control (no code).** Reset-request timing oracle (unknown ~1ms vs known ~100ms+ mail send; body identical). Complete equalization is infeasible (dominant cost is I/O mail send, not the CPU hash AUTH-1 equalized); partial mint-and-discard is theater. Compensating control = the dual digest-keyed `pwreset-ip:`/`pwreset-em:` throttle recorded *before* `request()` (defeats repeat-sampling of a target); residual is a bounded, victim-loud, low-value one-shot list-enumeration oracle. **Low → recorded here, no ADR.** Revisit: async/queued mail (fixes free) or a deployment mode raising the value of "account exists". Guarding tests: existing `PasswordResetTest` body-identical + throttle-covers-path.
- **FU-10 — Low (growth), but the fix carried a High trap.** Unbounded `nb_login_throttle` growth is a slow disk-drip (bounded by distinct attacker IPs/emails). The prune's danger is the *predicate*: deleting an actively-locking row resets the counter → an **AUTH-2 lockout bypass** (would be Medium/High). Shipped the lockout-safe predicate (`last_attempt < :cutoff AND (locked_until IS NULL OR locked_until < :now)`, cutoff ≥ MAX_LOCK); did NOT clone `ApiRateLimiter::prune`'s `updated_at`-only predicate. **Merge gate:** `LoginThrottleTest::test_prune_preserves_a_row_under_an_active_lockout` (red on a naive predicate).

Verdict: **security-green.** Hardens: closes the audit's last authority-widening footgun so a routine upgrade `roles:seed` can never re-grant authority past an admin's decision; adds throttle-table hygiene without opening a lockout bypass; formally records the reset-enumeration residual as a bounded, compensated Low.

### 2026-08-24 · Slice U — Public fragment/single-kind routing (SVM-4)
Reviewed via the Fable security burst before building. **No findings (None/hygiene only)** — a public, anonymous, read-only surface *reduction*: `/blocks`, `/blocks/{slug}`, `/{single-handle}`(+entry) now 404, all already-public content. Confirmed: (a) the 404 reuses `notFound()` verbatim, so "exists-but-hidden" is byte-identical to "absent" (no enumeration oracle); (b) 404s are never cached (`respond()` stores 200s only), so the fix *shrinks* the cacheable surface (SVM-1-adjacent win) — the only transient is a TTL-bounded stale-200 from a pre-deploy cache entry (flush-on-write/TTL bound it; noted "flush on deploy" in ops); (c) chose plain 404 over the 301→`/` option, avoiding the only way to introduce a defect (a request-derived redirect target). Media/API/MCP paths untouched. Guard tests in `SiteRoutesTest` (absent-parity) + `CacheRoutesTest` (no cache file). Security-green.

### 2026-08-24 · Slice T — API read-path: batch interop & the N+1 (API-6/DATA-5)
Reviewed via the Fable security burst before building. Security-green — both changes are net hardening; the security-relevant decisions were the **fix choices**, and DATA-5 is a refactor of the authz-critical DATA-1 read path.

- **API-6 — reject-not-implement is the security-correct call (no finding; would be High if batching shipped naively).** The rate limiter counts HTTP hits only (one `ipFlood` + one `tokenQuota` per request), so batch fan-out = a per-token **quota bypass + write/audit amplifier** (one metered request executing N `tools/call`s, N bounded only by body size — Slice F's un-bounded-body note). Rejecting preserves the load-bearing invariant "one metered tool execution per HTTP request". Detection is by **body shape** (`array_is_list`), not `Request::json()` emptiness (which collapses `[]`/invalid/scalar together); the reject is a static string (no input echo) consuming one rate hit. Guard: `McpTest::test_a_json_rpc_batch_is_rejected_not_silently_dropped`.
- **DATA-5 — DATA-1 re-open avoided by construction (the gating surface).** The batched relation query preserves BOTH Slice-C guards: the declared-target-collection JOIN + the `status='published' AND published_at<=NOW()` live predicate stay **inside** the grouped query (per-field `:target`, so different fields' targets can't cross-contaminate), and the per-field **scope gate** stays in `EntryView` (a field whose target the token can't read yields `[]` page-wide and its ids are never collected into the batch — not-fetch, not fetch-then-filter). Because **`one()` now delegates to `many()`**, the per-row and batched paths are physically one — the classic batch-N+1 authz regression (a target-less `IN` that drops the per-field constraint) is structurally impossible, and the existing per-row DATA-1 tests now exercise the batch. Mapping is keyed `(from_entry_id, field_id)` with `sort,id` order; id sets are storage-derived ints, bound via the `findMany` house pattern, empty-set short-circuited (no `IN ()`). Media stays un-scope-gated — the **existing accepted model** (public-by-reference), not a new exposure.
- **QA/permanence:** `RelationIntegrityTest` gains multi-row batch cases — **no cross-row graft** (each entry maps to its own targets) and **scope gate page-wide** (denied field `=== []` on every row, not just the first) — the properties the batch newly introduces; the per-row draft-filter / cross-collection-integrity / read-gate tests remain green as the byte-identical guard, now routed through the batch.

Verdict: **security-green.** Hardens: removes the API-6 silent-202 (a client mis-reading silence as success on writes) and forecloses the batch fan-out quota-bypass by design; collapses the DATA-5 N+1 while pinning the DATA-1 guards across a **multi-row page** for the first time (previously only per-row). Leaves open (tracked): no app-level request-body-size bound (Slice F, bounded here by MAX_PER_PAGE); media has no scope boundary (existing accepted model).

### 2026-08-24 · Slice S — Route-dispatch memoization & cache-key correctness (HTTP-5/HTTP-6)
Reviewed via the Fable security burst before building. Security-green. The security-relevant decision here was the **fix choice**, not the finding.

- **HTTP-5 — no finding (either direction).** The memo can't be poisoned (`Route::$pattern` is `public readonly`; the regex derives from it alone, never from request input) and matching is byte-identical. The `{name*}` catch-all compiles to `(?P<path>.+)/?$` — a single greedy quantifier + optional literal → **linear** backtracking, no ReDoS. Internal perf, semantics-preserving.
- **HTTP-6 — Low (latent), fixed by documentation not code.** As-is, the path+`page` key collides across other query params, so with page-cache **on** AND a query-varying theme, a first visitor's body is replayed for later queries (wrong public content; everything cacheable is already anonymous/public — no private data crosses, same scope class as the Slice H analysis). Double-gated and unreachable through the supported theme contract today.
  - **The fix choice was the security call.** "Fold the sorted query into the key" would be a **Medium regression** — an unauthenticated client mints unbounded `.cache` files via `?x=1,2,3…` (no flood limiter on the public surface; re-opens SVM-1/Slice K). A **bucketed** key is worse: it gives the attacker **chosen-key cache poisoning** (pre-seed the bucket a victim's legitimate `?tag=news` will render into) — the rejection of option (b) is therefore *security-load-bearing*, not taste. Shipped option (a): keep path+`page`, document the no-query-vary contract, pin it with tests.
  - **Slice H nonce invariant intact:** the key selects *which* entry; the stored `timestamp\nnonce\nHTML` triple and `Csp::adopt` re-emit are key-independent — a collision serves entry A's body with entry A's own stored nonce (wrong content, never a nonce mismatch). Confirmed.
- **QA/permanence:** `CacheRoutesTest::test_distinct_query_strings_do_not_mint_distinct_cache_files` (file-count guard — fails loudly if someone later folds the query and re-opens the disk-fill) + `::test_the_public_front_end_reads_no_query_input_but_page` (drift guard). Existing HTTP-1 nonce tests remain the guard for the interaction.

Verdict: **security-green.** Hardens: removes per-request regex recompilation with zero semantic/security change; turns HTTP-6's invisible coupling into an explicit, test-guarded contract while keeping SVM-1's disk-fill bound and the Slice H nonce invariant intact. Leaves open (Low, tracked): the latent wrong-content collision remains *possible* for a future third-party query-varying theme with caching on — accepted as documented (same posture as Slice H's A7 self-CSP constraint); the pre-written fix (allow-listed/no-store keying) lands with the first query-varying core consumer.

### 2026-08-24 · Slice R — Admin notice hardening & correctness paper-cuts (ADMIN-10/ADMIN-14)
Reviewed via the Fable security burst before building; security-green, no Critical/High/Medium. ADMIN-10 is a confirmed **Low** (escaped, social-engineering aid only); ADMIN-14 has no security impact (both angles traced and cleared).

- **Reflection channel closed (Low, ADMIN-10 class) — 8 controller sites / 7 render sites.** Post-redirect notices resolve fixed CODES → strings via `Controller::notice()` (unknown code → renders nothing); the query owns only the key, the map owns the text. All raw `$e($error)`/`ucfirst($flash)` renders and template `$flashLabel` maps retired — including `entries/form.php:27` (the singleton path the finding omitted). Guarding tests: `AdminNoticeTest::test_no_admin_notice_surface_reflects_query_text` (all 7 surfaces) + two **drift guards** (no admin controller reads `query('msg'|'err')` outside the resolver; no admin template reflects a raw flash) — the permanence mechanism, stronger than a catalog paragraph.
- **A3 · Media in-use detail imports author-controlled entry titles — Low, closed.** The "In use by: {entry_title}…" detail is server-rendered (not URL round-tripped) and escaped by the view. Guarding test: `MediaRoutesTest::test_deleting_an_in_use_file_renders_the_usage_detail_escaped` (a `"><script>` title renders inert; the in-use file is not deleted).
- **A4 · Media read/write gate divergence — Low, closed.** `index()` is `media:read` but the write actions are only `media:write` (deliberately independent). The error re-render only fires for a `media:read` actor; a write-without-read actor gets a generic `?err=denied` redirect, never the library listing. Guarding test: `MediaRoutesTest::test_a_write_without_read_actor_is_not_handed_the_library_on_error`.
- **ADMIN-14a/b — no security finding (traced).** 14a: a crafted relation `target` was already fail-closed at every read/write path (empty picker, `canRead` gates, `idsInCollection` filter); validating it is a correctness fix, and the handle-existence oracle sits inside the already-accepted management-cap disclosure class. 14b: the success-vs-not-found distinction leaks nothing — a `tokens:write` actor already sees every token id on the page; the MCP **false-audit** removal is a net integrity gain.
- **Watch item (Slice Q → resolved-not-recurred): do NOT promote to the threat catalog.** Slice R is the **fix of the 1st ADMIN-10 sighting**, not a 3rd independent appearance, so the ledger's "once more → promote" bar is unmet. Updated the watch note with the canonical resolutions so a genuine 3rd sighting promotes with the fix pre-written: **error text crosses a redirect only as a code (the string lives server-side); validator text uses the re-render pattern; a computed detail is server-rendered + escaped.**

Verdict: **security-green.** Hardens: the audit's last reflected-text channel into admin notices; a uniform code-keyed notice surface with a drift guard; two correctness paper-cuts with their fail-closed properties intact + one false audit event removed.

### 2026-08-24 · Slice Q — Settings write integrity & operator feedback (SUP-4/SUP-7/SUP-10)
Reviewed via the Fable security burst **before** building; green-to-build with five must-ships, all honoured (or superseded by a safer design). No Critical/High/Medium in the shipped slice.

- **Reflection surface avoided (Low, ADMIN-10 class) — `src/Admin/SettingsController.php::saveSite`, `templates/settings/index.php`.** SUP-4's operator feedback re-renders the POST response (behind CSRF + `settings:write`) with **validator-sourced** messages through `View::e`; no text is round-tripped through the URL, and the one validator message that echoed submitted input (`site.home`) is now a fixed string. Structurally immune to the crafted-link vector. Guarding tests: `SettingsSiteTest::test_a_hostile_submitted_value_is_escaped_in_the_re_render`, `::test_a_partial_failure_re_renders_with_errors_and_writes_nothing`. **Note:** the design originally proposed a session flash; the platform loop corrected it to re-render (no session-flash mechanism exists), which also removed the redirect channel the security loop worried about.
- **Audit ↔ persistence parity (informational) — `src/Mcp/SettingsToolset.php`.** `API_MANAGEMENT_WRITTEN` emits moved **after** the atomic `Settings::setMany`, so a rolled-back batch emits no (lying) audit and every persisted key still audits. Guarding test: `McpSettingsToolsTest::test_set_settings_audits_every_key_after_the_atomic_commit` (+ the rollback test proves nothing persists to audit).
- **Allow-list boundary preserved (Low, over-posting #5) — `Settings::setMany`.** Both callers still build the write map from the registry (never request keys); `setMany` additionally throws on any non-registry key. Guarding test: `McpSettingsToolsTest::test_set_many_refuses_an_unregistered_key`; existing over-posting tests stay green.
- **No SQL-leak on rollback (would-be Medium if mishandled).** The mid-batch `PDOException` is left to propagate to the kernel `\Throwable` boundary (ref-id only; message only under `Config::debug()`); no `catch(PDOException)` puts raw SQL/values into a `ToolResult`/flash. Honoured in the implementation.
- **Watch item (do NOT yet promote):** this is the **2nd** sighting of *a fixed-key flash/notice surface being asked to carry variable text* (1st: ADMIN-10). SUP-4 resolved it by re-render, not by carrying text — so it is not an independent recurrence. If the pattern appears **once more**, promote to the threat catalog: *error text crosses a redirect only as a code; the string lives server-side.*

Verdict at build time: **security-green.** What it hardens: the last partial-write hole on the settings surface; audit integrity under failure; MCP-parity operator feedback without opening a reflection surface. Left open (tracked, not this slice): admin `saveSite` writes remain unaudited (pre-existing asymmetry vs MCP) — follow-up filed.

---

## Findings

### 2026-08-24 · Slice P — trivial P3 cleanup (SUP-5 + PLUG-9/11 + API-7/SVM-5 cross-ref)
- **Status:** fixed. Trivial batch; the Opus review burst 529'd (incident on Opus too), lenses applied
  directly per the triviality exception. No new security exposure introduced.
- **SUP-5 (correctness, sec-adjacent):** `Env` now strips an inline `# comment` from **unquoted**
  values only — a quoted secret containing `#` is preserved (mandatory carve-out); an operator's
  `KEY=secret # note` no longer stores the comment as part of the (often secret) API key. No new
  foot-gun; reduces one. Guard: `EnvTest`.
- **PLUG-9 (accident guard, docs half):** documented "prefix your tables; never touch `nb_*`" —
  the `nb_`-reference lint deferred to FU-11 (accident-only, never a sandbox; needs a real burst to
  vet false positives).
- **PLUG-11 (no vuln):** plugin-health-over-MCP recorded as a deferral (a missing read surface, not
  an exposure); if `list_plugins` is later built, confirm diagnostics don't serialize FS paths.
- **Cross-reference (already guarded):** API-7 MCP authz matrix (Slice A), SVM-5 asset-`%00`/nonce
  (Slices K + H) — the security-relevant regression tests already exist.
- **Deferred (unreviewed-during-incident):** ADMIN-14 relation-target validation + token-lifecycle
  false-success (confirm no authz impact under a real burst).

### 2026-08-24 · Slice O — defense-in-depth P3 sweep (SVM-3/HTTP-7/SUP-8/PLUG-12/PLUG-13)
- **Status:** fixed. Five Low P3s (no live exploit in any). The Fable burst was cut off by a session
  limit; the security lens was applied directly given each is Low + finding-prescribed. ADMIN-10 (the
  one with a design fork) split to its own slice for a real burst.
- **SVM-3 (Low):** uploads served by the front webserver bypass `SecurityHeaders`. Allow-list
  (no HTML/SVG, random name, sniffed MIME) is the primary control → defense-in-depth doc: nginx/
  Apache/Caddy `nosniff` on `/uploads/*` in COMPATIBILITY.
- **HTTP-7 (Low, not reachable):** `Response::redirect` does no destination check. No user-controlled
  target today → **documented** the hazard (SECURITY docblock) rather than ship an unused guard;
  the guard lands with a future `next` feature.
- **SUP-8 (Low):** mail log (live reset/invite links) chmod'd to 0600 after write (best-effort);
  0770 dir remains primary. Guard: `MailerTest`.
- **PLUG-12 (Low):** `FieldType` render methods now carry the escape-`$value` SECURITY docblock +
  COMPATIBILITY note (attribute/markup injection isn't stopped by the nonce CSP).
- **PLUG-13 (Low, product-gap):** "Official" badge → "nimbuscms namespace" (a fact, not a trust
  claim); nothing gates on `$official`.
- **Left open:** ADMIN-10 (`?err=`/`?msg=` reflection — escaped, social-engineering only) → own slice.

### 2026-08-23 · Slice N — login hardening: enumeration oracle + distributed spray closed (AUTH-1/2/3/5)
- **Status:** fixed. Fable two-skill burst — **security-green, no Critical/High.** Two Medium P2s
  (AUTH-1/2 — the audit's last two) + P3s. Catalog: #10 auth/session (enumeration, throttle).
- **Surface:** `src/Auth/Auth.php`, `src/Auth/Password.php`, `src/Admin/AdminController.php`,
  `src/Admin/PasswordResetController.php`, `src/Admin/OAuthController.php`.
- **AUTH-1 (Medium, fixed) — login timing/enumeration oracle.** Unknown email = ~1ms SELECT-return;
  known = full argon2id verify → single-sample enumeration. Fix: single-code-path equal-work — verify
  against the stored hash or `Password::dummyHash()`, return false after. **M1 (the load-bearing
  subtlety):** the dummy is algo-matched to the runtime (`algo()` falls back to bcrypt; a hardcoded
  argon2id dummy on a bcrypt host does different work → oracle re-opens); drift guard asserts
  `algoName` match + `needsRehash===false`. No message/status oracle (generic error). Argon2id cost is
  input-length-independent → no residual signal from password length. Guard: `PasswordTest` drift +
  `AuthRoutesTest` indistinguishability.
- **AUTH-2 (Medium, fixed) — distributed spray.** IP-only throttle → many-IP spray on one account
  never locks. Fix: dual key `login-ip:` + `login-em:`. **M2:** `recordFailure` fires uniformly on the
  unknown-email branch + byte-identical lockout message → a locked known account is indistinguishable
  from a flooded unknown email (doesn't re-open AUTH-1). Lockout time-bounded (1h/15m) = the same
  deliberate-lockout-DoS tradeoff `pwreset-em:` already ships (recorded). **Key-length fix:** the email
  is SHA-256'd into the key so an over-long address can't overflow `nb_login_throttle.id` VARCHAR(190)
  → 1406/500 (a live bug in the existing `pwreset-em:` too — fixed both). Guard: `AuthRoutesTest`
  (vary-IP lockout, no-oracle, clears-both).
- **AUTH-3 (P3):** floor raised to 12 (per-account throttling makes keyspace the brake; only affects
  newly-set passwords). Single `MIN_LENGTH` across 7 surfaces incl. `bin/nimbus`'s drifted inline copy.
- **AUTH-5 (Low, fixed):** OAuth link start moved to an authed CSRF POST; GET stripped of link-intent
  (the callback was already state/session/provider-bound — this is defense-in-depth on the one
  tokenless auth mutation). Avoided token-in-query (Referer/log leak).
- **Left open (recorded):** FU-9 — the **reset-request path has the same timing oracle** AUTH-1 closed
  for login (its docblock overclaimed timing-safety — corrected); FU-10 — throttle-row pruning; the
  `TRUSTED_PROXIES`-unset IP-key degradation (email key still protects per-account) documented.

### 2026-08-23 · Slice M — plugin-boundary containment + contract honesty (PLUG-2/4/6/8)
- **Status:** fixed. Fable two-skill burst — **security-green, no Critical/High/Medium** (all Low or
  informational). Catalog: #12 plugin-boundary-abuse / containment.
- **Surface:** `src/Plugin/PluginLoader.php`, `src/Plugin/MigrationRegistrar.php`,
  `src/Admin/AdminPageRegistry.php`, `src/Plugin/AdminPageRegistrar.php`, `src/Support/CoreEvents.php`.
- **PLUG-2 (Low, fixed) — degenerate manifest id defeats containment.** `"id":"core"` → a failed
  plugin's field type survives rollback (`forgetProvider('core')` no-ops) = silent partial activation;
  a colon id collides `nb_migrations` names so a plugin's table is silently never created. Author-
  controlled but semi-trusted (ADR 0001 contract-not-sandbox), and it defeats a *stated* invariant.
  Fix: id gate `/^[a-z0-9][a-z0-9._-]*$/` + reject `core` + ≤64 in `validate()` before `claimedBy` —
  closes core/colon/empty/over-long. `core` is the only special id in code; `official` keys on the
  package name so `nimbuscms` needs no reservation. Guard: `PluginLoaderTest` (malformed ids →
  INVALID_MANIFEST; core-can't-defeat-rollback).
- **PLUG-4 (Low, fixed) — slug collision, no authz bypass.** A plugin slug shadowing a core section
  resolves to the **core** route (whose capability gate still applies — no privilege bypass); the harm
  is a mislabeled nav entry / unreachable page. Fix: duplicate-throw + RESERVED_SLUGS (incl.
  `dashboard`) + a route-derived drift-guard test. Confusion/anti-spoofing, not a boundary break.
- **PLUG-6 (Low/informational, doc-bless) — Request/Response exposure.** Doc-blessing a read subset
  grants **no new capability** — an in-process plugin already reads `$_POST`/`$_SERVER`/`php://input`
  directly. The real risk is a well-meaning-plugin footgun: a `request.handled` listener that
  serializes the full Request would persist the Authorization bearer / login password (the accidental-
  secret-capture class the `api.*` events redact against). Accepted as-documented (redaction now =
  theater, in-process) **with a mandatory never-log/persist warning** in `CoreEvents` + COMPATIBILITY,
  and the carrier hedged (VO revisit recorded). No test (docs) beyond a grep that the old wording is gone.
- **PLUG-8 (test-gap, fixed):** the reflection tripwire catches the security-relevant regression class
  — drop `events->forgetProvider` from the catch and a FAILED plugin's listeners (storage writes) keep
  firing; the test now fails.
- **Left open (recorded):** plugin migrations run on the core PDO (ADR 0005 contract, not a wall);
  request.handled VO/redaction revisit; PLUG-9 (table-prefix), PLUG-12 (FieldType render escaping) out
  of scope.

### 2026-08-23 · Slice L — write-concurrency lost-update closed + migration self-heal (API-4/DATA-4)
- **Status:** fixed. Fable two-skill burst — **security-green, no Critical/High.** API-4 Medium
  (data-integrity), DATA-4 Low (availability/ops). Catalog: TOCTOU / lost-update (data-integrity).
- **Surface:** `src/Api/EntryOperations.php`, `src/Content/EntryRepository.php`,
  `src/Content/EntryService.php`, `src/Content/EntryConcurrencyConflict.php`,
  `src/Database/Migrator.php`, `src/Database/Connection.php`.
- **API-4 (Medium, fixed) — lost update via the read→check→write gap.** Two writers both holding
  version N both pass `Precondition::evaluate` (nothing mutated at check time), and the version-less
  UPDATE applied both → first writer's change lost with a 200, breaking the If-Match guarantee
  COMPATIBILITY sells. **Medium not High:** both racers already hold `{handle}:write` (a broken
  contract, not a boundary break). **Fix = atomic CAS:** `AND version = :expected`, `rowCount 0` →
  `EntryConcurrencyConflict` → 412. A single atomic UPDATE closes the race with **no `SELECT FOR
  UPDATE`** — InnoDB serializes competing writers on the row lock, the loser re-evaluates the WHERE
  against the committed row → 0 rows. **DELETE also CAS'd** (a stale delete is the destructive half).
  No new leak: the conflict maps to the existing 412 at both call sites (never a 500 — pinned by a
  test), and a CAS-miss 412 is the same envelope whether the row changed or vanished (no oracle
  beyond what the write already reveals). Admin path (expected=null) unchanged. Guard:
  `EntryConcurrencyTest` (stale save/delete → conflict + rollback + no event; 412-not-500).
- **DATA-4 (Low, fixed) — partial migration self-heal.** `runStatements` skips "object already
  exists" (1050/1060/1061/1826) as already-applied; **excludes 1062** (row dup stays fatal). Masking
  property identical to `IF NOT EXISTS` and acceptable under the additive-forward-only contract; a
  hostile in-process plugin could already DDL `nb_*` (pre-existing Low, ADR 0001/0005) — no new
  attacker capability, only a small loss of anomaly *visibility*, restored by the mandatory skip log.
  Genuine errors still fail closed. Guard: `MigrationRecoveryTest`.
- **Left open (recorded):** singleton create-races resolve to an un-CAS'd update (pre-existing);
  hostile in-process plugin DDL (pre-existing Low); DATA-5 N+1; errno 3822 (CHECK dup) to add if
  CHECK constraints ever land.

### 2026-08-23 · Slice K — role-delete authz gap + public page-cache disk-fill (ADMIN-3/SVM-1/SVM-2)
- **Status:** fixed. Fable two-skill burst — **security-green, no Critical/High.** Two Mediums
  (ADMIN-3, SVM-1) + one Low (SVM-2), all fixed in-PR with guarding tests; ADMIN-9 = recorded
  deferral (no vuln — a missing management surface). Catalog: #2 (privilege/authz integrity),
  #13/DoS (public edge), #14 (availability).
- **Surface:** `src/Admin/RolesController.php` (`destroy`), `src/Site/SiteController.php`
  (`renderCollection`, `asset`), `src/Application.php` (`cacheKey`).
- **ADMIN-3 (Medium, fixed) — role delete was the unguarded twin of role edit.** A `roles:write`-only
  actor could POST `/admin/roles/{id}/delete` on a role granting caps they lack → deleted,
  `nb_user_roles` cascades, role-bound tokens resolve to explicit-abilities-only (`principalFor`
  role_id→null → often empty → deny). **Closed-direction sabotage/denial, not escalation** → Medium.
  Fix: `destroy()` runs the same `firstUnheld($role->capabilities)` guard as `update()` (the one
  shared predicate — catalog #2's "reuse, never reimplement"). Verified no OTHER role-mutation path
  has the gap (store/update guarded; `UsersToolset::set_role` + `UsersController` assign guarded both
  ways; admin role is isSystem/undeletable). Guard: `RolesAdminTest` (superior-role survives + a
  role-bound token's scopes survive the blocked delete + admin still can).
- **SVM-1 (Medium, fixed; one level down for the opt-in `PAGE_CACHE_TTL>0`) — unauthenticated
  page-cache disk-fill.** Public `?page` floored at 1, no ceiling; an out-of-range page rendered a
  200 empty list; `cacheKey` keys the raw `?page=N` and `respond()` stores 200s → one `.cache` file
  per N, no site rate-limiter. **A 404 (not clamp-to-200) is what fixes it** — confirmed against
  `cacheKey`/`respond`: a clamped 200 still mints per-N. Two-part fix: `renderCollection` 404s past
  the end (index route) AND `cacheKey` returns null above `MAX_CACHEABLE_PAGE=1000` (covers home +
  entry routes, which 200 but ignore `?page` — the mint the index-only 404 missed). No new oracle
  (total_pages already rendered). Guard: `CacheRoutesTest` asserts **no `.cache` file** is written.
- **SVM-2 (Low, fixed) — NUL in an asset path → ValueError → 500 + log spam** (no disclosure). Fix:
  reject `\0` before `realpath` → 404. Complete for the ValueError class (PHP 8 `realpath` throws
  only on NUL; other bad paths return false → already 404). Guard: `SiteRoutesTest` NUL → 404.
- **ADMIN-9 (no finding):** recording the roles-CRUD-MCP deferral papers over no authz gap **because
  ADMIN-3 ships** — the roles UI is now uniformly `roles:write` + subset-only across create/update/
  destroy. Forward condition recorded: a future `RolesToolset` must carry subset-only on destroy.
- **Left open (recorded):** `destroy()` has no explicit last-admin guard (defused by isSystem on the
  admin role + subset-on-create); the public site has no site-wide flood limiter (inherent — the
  limiter is an API control, consistent with Slice J).

### 2026-08-23 · Slice J — HTTP/CORS/HEAD hardening (HTTP-2/3/4 + API-5)
- **Status:** fixed. Fable two-skill burst — **security-green, no Critical/High/Medium.** Two Lows
  (each with a pinning test) + informationals. Catalog: #13 (HTTP/header/proxy), #6 (CSRF boundary).
- **Surface:** `src/Http/Router.php` (dispatch/405/HEAD), `src/Http/Response.php` (`withoutBody`),
  `src/Application.php` (session skip, fail-open preflight guard, HEAD strip), `src/Http/Cors.php`
  (`isApiPath`, widened preflight), `src/Api/ApiController.php` (injected flood guard).
- **HEAD verb-smuggling (confirmed SAFE, no finding):** HEAD maps to GET **only**; no POST/PATCH/
  DELETE route matches a HEAD, and `Request::fromGlobals` has **no method-override seam** (`_method`/
  `X-HTTP-Method-Override` absent). `Route::run` executes the middleware stack before the handler, so
  `HEAD /api/...` runs ipFlood→auth→quota and `HEAD /admin` runs authMw — HEAD skips no guard. Guard:
  `HttpMethodTest` (HEAD /admin → 302 login; HEAD /api without token → 401).
- **HTTP-3 session drop (confirmed SAFE, strengthens #6):** zero `$_SESSION`/`session_*` under
  `src/Api`+`src/Mcp`; API auth is bearer at the middleware door. Removing the cookie is pure hygiene
  (an unused ambient credential is no longer even minted). Guard: `ApiSessionlessTest` static drift
  test fails loudly if a future /api addition depends on the session the kernel no longer starts.
- **HTTP-4 uncounted preflight (Low, fixed):** the amplified CORS-decorated 204 class is closed (flood
  guard now runs on the preflight, fail-open on DB error). Residual (Info): an Origin-less OPTIONS
  isn't a preflight → routes to a 405, uncounted — but baseline-equivalent to any 404/405 (no DB, no
  amplification). Guard: `ApiCorsTest` (repeated preflights → 429; shared `ip:` bucket).
- **HEAD × page-cache poisoning (Low, unreachable-but-pinned):** `cacheKey` is GET-only and the body
  strip is applied after the cache store, so a HEAD never writes an empty entry. Safe by ordering,
  one refactor from a bug → pinned: `CacheRoutesTest` HEAD-then-GET returns the full body.
- **API-5 CORS widening (confirmed NOT a security change):** no `Access-Control-Allow-Credentials`
  (verified nowhere in `Cors`), bearer-only auth (no ambient cookie, esp. after HTTP-3), origin still
  allow-list-gated and echoed only when approved — advertising POST/PATCH/DELETE+If-Match changes what
  the browser permits, not what the server authorizes. Guard: `ApiCorsTest` asserts no Allow-Credentials.
- **Left open:** Origin-less/non-API uncounted request classes (inherent — the flood limiter is an API
  control, not site-wide DoS); asset/public-site session skip (deferred, recorded); HTTP-5 dispatch
  cost + API-6 MCP batch (out of scope).

### 2026-08-23 · Slice I — mail reliability: recovery-mail DoS closed + guarded resend route (SUP-1/2/6 + ADMIN-7)
- **Status:** fixed. Fable two-skill burst — **security-green conditional on the ADMIN-7 controls (all shipped)**.
  No Critical/High introduced; SUP-1/2/6 are net security improvements. Catalog: **user-editable config →
  audit every downstream sink** now has its 2nd sighting (SUP-2) — PROMOTED below.
- **Surface:** `src/Settings/SettingsRegistry.php` (title validator — the SUP-2 site), `src/Mail/{MailerFactory,LogMailer,NativeMailer}.php`, `src/Admin/UsersController.php` (`resendInvite`), `src/Auth/PasswordResetRepository.php` (pending signal).
- **SUP-2 (Low, fixed) — stored CRLF in `site.title` → silent persistent recovery-mail DoS.** A `settings:write`
  holder stores `"Nimbus\r\nX"` (raw JSON CRLF via MCP `set_settings`; `trim()` strips only ends) → every
  reset/invite subject throws in `NativeMailer::assertHeaderSafe` → the reset flow swallows it (anti-enumeration)
  → recovery + invite mail silently dead. **Confirmed DoS, not injection** — `assertHeaderSafe` already blocks
  real header injection. **Control:** reject `[\x00-\x1F\x7F]` **byte-wise (no `/u`** — fail-open on invalid
  UTF-8) at the shared `SettingsRegistry` validator → closes admin form AND MCP at once. Other title sinks
  confirmed safe (HTML `View::e`, OpenAPI `json_encode`, api-transport JSON). Guard: `SettingsSiteTest` +
  `McpSettingsToolsTest` reject CRLF + `\x01`.
- **ADMIN-7 resend route (Low, shipped with all controls) — a NEW authenticated password-link-emitting POST.**
  Must-ships all present: **CSRF** (`requireCsrf` — else High, catalog #6); **`users:write`**; **`{id}` int-cast +
  `find()`** + send to the **stored** email (no attacker-chosen recipient / IDOR); **subset-only guard** mirroring
  `update()` (reject before token/mail if target holds an ungrantable role — the 5th surface for the escalation-at-
  grant check); **pending-gate** (only an unused `invite` token qualifies) so resend can never arm a password-set
  link for an *active* account. Mitigating context: `/admin/forgot` is public and already mints a (shorter-lived)
  credential token for any email, so resend grants no new capability class → Low. Guard: `UserInvitationTest`
  (pending, expired-still-resendable, active-refused, CSRF, users:write, subset, nonexistent-id, list-gate).
- **SUP-1 (Low, fixed):** silent LogMailer fallback reported false delivery success for recovery/invite mail →
  loud warning **at send** (not ctor — would flood) + `mail:test` CLI (no new web surface).
- **SUP-6 (no finding):** base64 subject encoding is CRLF-free (no injection) and runs AFTER the CR/LF guard —
  ordering locked by a test that a raw-CRLF subject still throws.
- **Left open:** no per-route throttle on resend (FU-8, Low — pending-gate + auth + users:write already bound it).
- **THREAT-CATALOG PROMOTION:** "making a previously-trusted config value user-editable requires auditing every
  downstream sink" — 1st sighting the 2026-08-22 site-title HTML/JSON audit, 2nd sighting SUP-2 (the mail subject
  the first audit missed). Promote as a standing check: when a setting becomes user-editable, enumerate ALL sinks
  (HTML, JSON, mail headers, SQL identifiers, filenames) and confirm each escapes/validates for its context.

### 2026-08-23 · Slice H — CSP nonce persisted with the page cache + exposed to plugins (HTTP-1/PLUG-5)
- **Status:** fixed. Fable two-skill burst — **security-green conditional on one must-fix (shipped)**.
  No Critical/High; no risk ADR needed. Catalog: security-control-through-a-cache (nonce reuse) —
  first sighting, watch for a 2nd; and untrusted-persistence-into-a-security-header.
- **Surface:** `src/Support/PageCache.php` (parse/format — the A1 site), `src/Http/Csp.php`
  (`adopt`/`isValid`), `src/Application.php` (hit-adopt/miss-store + the flush coupling, now
  security-load-bearing), `src/Site/PageContext.php` (`$cspNonce`), `src/Admin/PluginPagesController.php`
  (handler 2nd arg).
- **A1 (Medium, fixed) — legacy/corrupt cache entry → CSP header corruption:** on upgrade, an old
  `timestamp\nHTML` entry read by the new 3-part parse yields the HTML's first line as the "nonce",
  which `adopt()` would emit into `script-src 'nonce-…'` (policy-token injection; no CR/LF so it
  passes header validation) and serve a decapitated body. **Control:** `get()` validates the stored
  nonce against `^[A-Za-z0-9+/]{22}==$` and treats any mismatch (legacy, truncated, tampered) as a
  **miss** (unlink); `adopt()` falls back to a fresh nonce on an invalid value; `put()` refuses to
  persist a non-shape nonce. Guard: `PageCacheTest::test_a_legacy_pre_nonce_entry_is_treated_as_a_miss`,
  `test_an_entry_with_a_non_base64_nonce_is_a_miss`; `CspTest` adopt-rejects-invalid.
- **Q2 verdict (no finding) — stable-per-entry nonce on public cached pages is SAFE:** header-nonce ==
  body-nonce == a byte-identical replay of one server render an attacker cannot write into. The known-
  nonce stored-injection sequence (A3) is **structurally impossible**: every content write flushes the
  cache (`Application.php` `ENTRY_SAVED`/`ENTRY_DELETED` → `flush()`), so the write that plants a
  payload destroys the entry whose nonce it stole; re-render mints a fresh nonce. **This safety is
  architectural, not local** — it rests on: (i) body+nonce stored atomically from one render;
  (ii) flush-on-every-content-write (now a security invariant, guarded by
  `CacheRoutesTest::test_a_content_write_rotates_the_cached_nonce`); (iii) cache = public anon GET 200
  HTML only, no per-user variance; (iv) nonce charset has no newline/quote/space. Revisit if cache
  keying/invalidation changes (HTTP-6).
- **PLUG-5 (no severity):** the nonce was already reachable in-process (`Csp::nonce()` is public
  static); `PageContext::$cspNonce` + the handler arg formalize the supported path, grant no new
  capability, and the nonce is in the served HTML anyway. `PageContext` docblock marks `$cspNonce` the
  one trusted-to-embed value (still `View::e()`-safe).
- **A7 (Low, documented):** `SecurityHeaders::apply` is fill-only, so a page setting its OWN CSP would,
  on a hit, serve that policy with the adopted nonce mismatched — no such public response exists today;
  recorded as a constraint (a cacheable page must not set its own CSP).
- **Left open:** hosted-analytics beacon egress still blocked by `default-src`/absent `connect-src`
  (FU-7 — separate review before any CSP widening); the inherent same-response nonce-scrape limitation
  (escape-by-default remains the primary XSS control, unchanged).

### 2026-08-23 · Slice F — entry-write unbounded-input DoS + input-edge 500s closed (DATA-2/DATA-3/ADMIN-6)
- **Status:** fixed. Fable two-skill burst, security-green conditional on the must-ships (all
  shipped). Catalog: unbounded-input / write-amplification (first sighting — watch for a 2nd to
  promote), input-edge availability. No Critical.
- **Surface:** `src/Content/EntryService.php` (`save`, `uniqueSlug`), `src/Content/Validator.php`,
  `src/Content/FieldTypes/{Base,Text,Textarea,Relation}Type.php`, `src/Content/Publication.php`.
- **Closed:**
  - **Write-amplification DoS (was High while unfixed):** a `{handle}:write` token POSTing 100k
    relation ids = 100k inserts + a 100k-param IN clause per request. Cap (100) in
    `RelationType::validate` fires in the Validator **before** `splitValues`/`idsInCollection`, so
    the oversized list never reaches a DB query. Reject, not truncate (visible). Test asserts
    **zero** rows persisted.
  - **Unbounded JSON `data` sink (Medium):** per-field `maxlength` (text 255 / textarea 50k) +
    a **universal 100k scalar-string ceiling in the `Validator`** — the latter closes the `url`
    (`filter_var`, no length) and `email` uncapped-scalar sinks the per-type approach missed (A3).
    Clamped: a `maxlength` option can only *lower* the ceiling, so `collections:manage` can't set
    `maxlength:10^9` to re-open it.
  - **Input-edge 500s (Medium, availability — no disclosure):** malformed `published_at` and
    over-long title/slug were uncaught exceptions → the `\Throwable` boundary (ref-id only, no
    leak; rate-limited) → now structured `422 invalid`. The slug **suffix headroom** in
    `uniqueSlug` is the real fix — trimming the base so `base+suffix ≤191` (truncation alone was
    defeated by the disambiguation suffix on a long-title collision).
- **Verified:** structured-error parity ({code,message}, additive `invalid`, no new vocabulary,
  static messages — no SQL/exception text); the fix covers **every** write entrypoint (all route
  through `save`; no revision-restore write path exists). Error boundary shows only a ref id.
- **Leaves open (Low, recorded):** no app-level request-body-size bound (deployment config is the
  only guard on parse-time memory — documented in COMPATIBILITY); media cardinality is
  defensive-only until the multi-file field; field-handle-vs-reserved-key collision → reserve-names
  follow-up.

### 2026-08-23 · Slice E — audit-trail blinding via best-effort event starvation closed (SUP-3/PLUG-3)
- **Status:** fixed. Fable two-skill burst, security-green, no Critical/High (Low — audit-loss).
  `EventDispatcher::emitBestEffort` caught around the whole loop, so a plugin listener throwing
  before the audit-log listener suppressed its `API_ACCESS_DENIED`/`API_MANAGEMENT_WRITTEN`
  record (a buggy plugin, or a subtly hostile one throwing selectively to blind the trail).
- **Control:** per-listener try/catch + continue (log with the provider id). Closes the buggy
  case fully and removes a hostile plugin's *quietest* blinding tool; remaining tools
  (`exit()`/fatal/direct DB writes) are loud or outside the event layer's threat model (ADR
  0001 contract-not-sandbox). Per-listener isolation is the proportionate control — audit-first
  ordering / a core audit sink would be drift. Test: `EventDispatcherTest` first-throws→second-runs.
- **Verified:** the logged exception text is operator-only (`error_log`; nothing routes it to a
  response; api.* payloads never carry the token secret). Leaves open (Low, recorded): shared-
  payload tampering by an earlier listener (A3, pre-existing); uncatchable termination (A2).

### 2026-08-23 · Slice D — plugin migration isolation (PLUG-1), availability DoS closed
- **Status:** fixed. Design reviewed via a **Fable two-skill burst** (green, no Critical/High,
  conditional on four must-ships — all shipped). Resolves audit **PLUG-1** (availability: one
  plugin wedging the whole install's migrations). CLI-only surface (no web reachability —
  verified; ADR 0001 no-in-admin-installer holds); plugin = admin-installed, semi-trusted
  in-process code (contract-not-sandbox).
- **Surface:** `src/Database/Migrator.php`, `bin/nimbus` (migrate/install), `tests/bootstrap.php`.
- **Controls shipped (each a regression test in `PluginMigrationTest`):**
  1. **Per-provider isolation** — plugin loop catches `PDOException` per `apply()`, records +
     skips that provider's rest, continues others; a broken plugin can no longer starve the
     rest or block core. Red-on-old-code test: A fails → B still applies + records.
  2. **Structural core/plugin distinction** — halt-vs-isolate is decided by which loop runs,
     **never by `provider === 'core'`** — so a plugin manifest id `"core"` (PLUG-2 is unfixed)
     can't seize core's halt-everything behaviour as a re-wedge/attribution-spoof primitive.
     Test: a registry migration `provider:'core'` is isolated, not halting.
  3. **Fail-closed call sites** — `migrate`/`install` exit non-zero + stderr on failure;
     **core** failure throws `MigrationFailed` (so `install` never seeds a half-migrated schema,
     `bootstrap` dies loudly) while `install` still seeds on a *plugin*-only failure. Closed the
     fail-open the non-throwing design would have opened (same class as Slice B/C).
  4. **Error-string sink** — `MigrationReport.error` (raw DB error: SQL fragments, values) is
     documented **operator-only**, routed to stderr; stdout stays the clean applied-names list.
     Not web/MCP-reachable today (A1 = Low; would be Medium if a status surface ever renders it).
- **Severity:** availability defect, no Critical/High; the isolation removes the one-plugin DoS.
- **Leaves open (recorded, Low, pre-existing):** plugin migrations execute on the **core PDO
  connection** — ADR 0005 "own tables only" is a contract, not a wall (a hostile migration can
  still DDL `nb_*`); **PLUG-2** id validation = named fast-follow; A4 partial-apply state (a
  half-created table missing its UNIQUE) is plugin-owned + now documented for authors; the CLI-
  only assumption gates A1 — re-review the `error` field if a web/MCP migration-status consumer
  ever ships (PLUG-11).

### 2026-08-23 · Slice C — relation cross-collection scope-confusion closed (DATA-1)
- **Status:** fixed. Design reviewed via a **Fable two-skill burst** (green conditional on the
  must-ships, all shipped). Resolves audit **DATA-1** (Medium — cross-collection read leak +
  integrity). Catalog #1 (object-level authz) / #2 (scope confusion). Completes the relation
  half the Slice B ledger entry deferred here.
- **Surface:** `src/Content/EntryService.php` (`splitValues`), `src/Content/EntryRepository.php`
  (`idsInCollection`), `src/Content/RelationRepository.php` (`liveTargets`/`sync`),
  `src/Content/EntryView.php` (`one`).
- **Closed:** a `posts:write` token (or admin) could store a relation `to_entry_id` from any
  collection; on read-back a `posts:read` token without `secret:read` got a live `secret`
  entry's `{id,slug,title}` because expansion gated on the *declared* target, not the entry's
  real collection.
- **Controls shipped (each a regression test in `RelationIntegrityTest`):**
  1. **Write constraint** — `idsInCollection` (bound per-id IN-list + empty-list short-circuit,
     `:target` bound; MediaRepository precedent) filters each relation field's ids to its target
     collection before `sync`, on the one shared write path (admin/API/MCP). Order preserved by
     set-intersection.
  2. **Read filter** — `liveTargets` (required `targetHandle` + collection JOIN) so a stored
     cross-collection row expands to nothing — permanent protection against field *retargeting*
     and any write path that bypasses the service (e.g. in-process plugin SQL, catalog #12).
  3. **Retained** `EntryView::one`'s `canRead($declaredTarget)` scope gate — a field legitimately
     targeting an unreadable collection is still hidden from a token lacking that read scope
     (the two guards are independent: rows ⊆ declared target ∧ declared target readable).
  4. **Uniform silent drop** — cross-collection ≡ nonexistent ≡ non-live, all indistinguishable
     no-ops; this **closed an incidental entry-id existence oracle** (a nonexistent id used to
     `500` via the `to_entry_id` FK on the PATCH echo-back).
- **Severity:** the closed leak was **Medium** (integrity unconditional; confidentiality capped
  because "live" == public on the shipped PHP-theme install — verified `SiteController` serves
  every collection's live entries anonymously). No Critical/High; no risk-acceptance ADR.
  Reachability note: the read gate becomes the *only* reader control if a no-public-site
  deployment mode ever ships.
- **Leaves open (filed, Low):** `sync` remains an unconstrained primitive (now docblock-guarded;
  the read filter neutralizes any violation); `incoming` reverse-lookup is dead code (apply the
  same discipline if ever consumed); ADMIN-14 target-handle validation at schema-create (the
  fail-closed empty-target defuses its integrity sting); the media-field equivalent.

### 2026-08-23 · Slice B — read-boundary / non-enumeration closed on admin + OpenAPI
- **Status:** fixed. Design reviewed via a **Fable two-skill burst** (green conditional on the
  two must-ship shape fixes, both shipped). Resolves audit **ADMIN-1** + **API-3** (Medium
  info-disclosure / broken object-level read authz) and **ADMIN-4** (redirect loop). Catalog
  #1 (object-level authz), #14 (non-enumeration).
- **Surface:** `src/Admin/EntriesController.php` (`mustFind`/`renderForm`),
  `src/Admin/CollectionsController.php` (`index`), `src/Auth/Gate.php` (`reads`),
  `src/Api/OpenApiGenerator.php` + `src/Api/ApiController.php` (`openapi`).
- **Closed:** a signed-in user with a narrow role browsed every collection's entries (drafts
  included) despite ADR 0011's `{handle}:read` gate; a single-collection **token** enumerated
  the whole content model (handles, field schemas) via `openapi.json`.
- **Controls shipped (each a regression test):**
  1. **`{handle}:read` at the object** (`EntriesController::mustFind`, one choke point for all
     entry routes) — unreadable == missing, **byte-identical redirect** (non-enumeration).
     `AdminReadGateTest::test_an_unreadable_collection_is_indistinguishable_from_a_missing_one`
     asserts same status + Location; `..._covers_every_entry_route` loops the verbs.
  2. **Collections index filtered** by `Gate::reads` (counts follow the filtered rows).
  3. **Relation-picker display gated** (`renderForm` → `reads($target) ? titleMap : []`) — the
     admin no longer leaks what the API's relation-expansion gate already denies (security A2).
     Test: `..._relation_picker_does_not_leak_an_unreadable_target` (with an admin control).
  4. **OpenAPI fail-closed + scoped:** `generateFor(TokenPrincipal)` filters collections by
     `read`, write ops + `EntryWrite_` by `write`, in the per-collection loop (no leaked path,
     schema name, or `$ref`); the HTTP `openapi()` resolves the principal via the 401-guarding
     `principal()` helper — **`generate(null)`=full is reachable only from the CLI**, so a
     middleware regression can't emit the full model unauthenticated (both reviewers' top
     must-ship). Tests: `OpenApiGeneratorTest` scope-filter suite (asserts on the whole JSON) +
     `ApiRoutesTest::..._scoped_to_the_presenting_token` + the existing 401-no-token test.
  5. **ADMIN-4:** `requireManage` aborts to the collections index, not a singleton's own URL
     (no loop). Test: `..._singleton_is_not_trapped_in_a_loop`.
- **Behavior-preserving (regression-locked):** seeded admin/editor/author hold `*:read` → still
  browse everything; un-seeded install → any signed-in user still browses (the legacy fallback).
- **Severity:** closes the audit's **Medium**; no Critical/High. The admin and API now answer
  content-read through the one `Authorizer` predicate — the last principal divergence on this
  surface.
- **Leaves open (filed as follow-ups, Low):** A3 tokens/roles/settings *forms* list all
  collection handles to management-cap holders; A4 dashboard aggregate counts; A9 a collection
  handle colliding with an `Authorizer::MANAGEMENT` name; **relation value integrity + API
  relation expansion = DATA-1 / Slice C** (this slice covered picker *display*, not values).

### 2026-08-23 · Slice A — MCP user-tool privilege escalation closed (was latent High)
- **Status:** fixed. Design reviewed via a **Fable two-skill burst** (security: green
  conditional on the must-ship controls, all shipped). Resolves audit **API-2 / ADMIN-2**
  (latent High), **API-1** (broken authority), **AUTH-4** (divergent last-admin guard).
- **Surface:** `src/Mcp/UsersToolset.php` (`create_user`, `set_role`) — catalog #2 (scope
  confusion / escalation-at-mint) and the last-admin invariant.
- **Attack closed:** a non-admin `users:write` token calling `create_user role:"admin"
  password:"known"` (or `set_role role:"admin"`, or a custom god-role) — the tools applied
  **no subset-only guard**, so once the roles plumbing was fixed this was a scoped-token →
  known-password admin = full compromise.
- **Controls shipped (each a regression test in `tests/Http/McpAdminToolsTest.php`):**
  1. **Subset-only over the token's full effective scopes, before any write** — every
     capability of the target role must be held (`Authorizer::holds(array_values($principal->scopes), $cap)`);
     reject `forbidden` first. Delegates to `Authorizer::can`, so **management-immunity is
     inherited** (a `*:write` token does not hold `users:write`) — the content-wildcard-vs-
     management ledger invariant is not regressed (unit-locked in `AuthorizerHoldsTest`).
     Test: `test_create_user_cannot_grant_authority_the_caller_lacks` (admin + editor +
     custom god-role, asserts no user created / no password set).
  2. **Both-directions on `set_role`** — also refuses to strip a role the target holds that
     the caller could not grant (no demote-a-superior). Test:
     `test_set_role_cannot_strip_a_role_the_caller_could_not_grant`.
  3. **Atomic create+assign** (transaction) — a rejected/failed grant leaves no half-user;
     the residual under-grant is fail-safe (an inert account, never an escalation).
  4. **Placeholder `nb_users.role='author'`, never `'admin'`** — cannot elevate via the
     un-seeded `Permissions::isAdmin` fallback; `set_role` stops writing the legacy column.
  5. **AUTH-4:** last-admin counted by `RoleRepository::assignedUserCount(admin)` on both
     surfaces — a role-held admin whose legacy column is `'author'` (as the admin UI leaves
     it) is now counted. Test: `test_set_role_never_demotes_the_last_admin_counted_by_role_
     not_the_legacy_column`. Dead `countByRole` removed.
  6. **Unseeded fail-closed** — no roles → clear error, no write.
- **Defender severity:** the closed escalation was **High** (latent). No Critical/High
  introduced. The subset-only predicate is now unified (`Authorizer::holds`) across Gate,
  Tokens, and Users — one place to audit.
- **Leaves open (Low, documented):** the last-admin `count→write` is not lock-transactional
  (two concurrent demotions of the last two admins could both pass) — **pre-existing in the
  admin UI, not introduced here**; accept consistent with the 2026-08-18 media-delete-guard
  race, row-lock later if it proves real.

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

### 2026-08-24 · Pre-release audit closing sweep (FU-18 reserved-`nb` id gate) — security-green
- **Change under review:** `PluginLoader::validate` rejects plugin ids matching `/^nb([._-]|$)/` (the reserved core namespace) in addition to the existing gate (`/^[a-z0-9][a-z0-9._-]*$/`, not `core`, ≤64). Plus the closing-sweep doc reconciliation (no code surface).
- **🔴 Attacker:** an installed plugin declaring id `nb_stats` (or `nb`, `nb.x`, `nb-y`) would name its own tables `nb_stats_*` etc. This is not a privilege boundary — ADR 0001 already makes an installed plugin trusted in-process code that could hit `nb_*` from runtime/raw PDO regardless — so there is **no exploit delta** here; the risk class is an *honest-accident* collision with core tables and a migration-lint false-signal from the wrong layer, not an attacker capability.
- **⚪ Defender:** correct control, correct layer. The id gate is a manifest-validation invariant (ids claimed by installation, before registration), so the reserved-namespace rule composes with the FU-11 `MigrationRegistrar` lint (statement-level `nb_*` guard) without either standing in for the other. Fail-closed: a bad id → INVALID_MANIFEST, plugin never registers. **Severity: N/A (defense-in-depth / accident guard, no reachable exploit)** — consistent with the FU-11 framing.
- **🟢 QA / permanence:** `PluginLoaderTest::test_a_plugin_id_in_the_reserved_nb_namespace_is_rejected` (`nb_stats` + `nb` → INVALID_MANIFEST) guards the gate; the FU-11 `MigrationLintTest` corpus guards the statement layer. Fixture `nb_allcaps_x`→`allcaps_x` corrected so the PLUG-8 rollback test again exercises its intended `DuplicateFieldType` path (a stale fixture, not a security regression, but it had made a security-relevant rollback test pass for the wrong reason).
- **Verdict: security-green.** No new attacker surface; a defense-in-depth namespace guard with a regression test. No open Critical/High; no ADR needed (accident guard, not a control claim). FU-7 note: the CSP `connect-src` extension for hosted analytics remains **deferred pending its own security review** — widening the CSP is a real surface and was explicitly not built.

### 2026-08-24 · Agent guidance over MCP — Slice 1 (core guide) — security-green
- **Change:** `initialize` gains an `instructions` brief; new `resources/list` + `resources/read` serve markdown guide documents over the already-authenticated, rate-limited MCP endpoint (no new route). Reviewed on the DESIGN before build (`nimbus-security-review`), then built to those controls.
- **🔴 Attacker surfaces considered:** (a) `resources/read` URI → traversal / arbitrary-file read; (b) resource visibility vs non-enumeration / scope leak; (c) DoS via always-in-context instructions; (d) capabilities dishonesty (advertising prompts/sampling we don't implement); (e) guide text as an injection vector on the agent's own principal.
- **⚪ Defender / controls shipped:**
  - **(a) Traversal closed by construction.** The URI is an **exact key** into an in-memory `GuideLibrary` registry, never a path — `readContents()` does `$documents[$uri] ?? null`, no `file_get_contents`/`realpath` on anything request-derived. Authored files are read only at assembly by fixed internal paths in `CoreGuide`. Unknown/`../`/`file://`/empty → uniform `-32002`. **Severity: N/A** (no file-read primitive exists).
  - **(b) Generic docs, any authenticated token.** Resources carry no per-site/secret data (static authored content); readable by any valid token incl. zero-scope — the correct gate (deny-by-default satisfied by requiring a token). No per-scope resource to protect in Slice 1. **Low/none.**
  - **(c) DoS.** `resources/read` inherits the per-IP flood + per-token quota (rides `POST /api/v1/mcp`); `instructions` is core-authored and bounded (`INSTRUCTIONS_MAX_BYTES=8192`, asserted). **Low.**
  - **(d) Capabilities honesty.** `initialize` advertises exactly `tools` + `resources{subscribe:false,listChanged:false}`; **no** `prompts`/`sampling` (test asserts their absence + `prompts/list`→`-32601`). New methods live in the existing `handle()` `match`, inheriting batch-reject + JSON-RPC validation + the resolved principal. **Interop-correctness.**
  - **(e) Injection vector** — the sharp edge is **Slice 2** (plugin-authored text). Slice 1's guide is **core-authored, static, platform-neutral, no live-value interpolation, and contains no literal privileged example invocation** an injected context could weaponize; the guide itself teaches the operator-scopes-the-token containment. `GuideLibrary` already carries the untrusted-data attribution envelope for plugin-owned docs (unused until Slice 2). **N/A for Slice 1.**
- **🟢 QA:** `McpResourcesTest` pins traversal/`file://`/empty → uniform `-32002` with no file leak (`root:` never in the error), capabilities-resources-only + no-prompts, zero-scope token can read generic docs; `AgentGuideCoverageTest` is the content drift guard.
- **Verdict: security-green.** No new attacker capability; guidance arrives through the existing authenticated/rate-limited surface with the traversal class closed by construction. No open Critical/High; ADR 0013 records the trust posture. **Slice 2 (plugin fragments → agent) re-runs this review** with its own must-ships (resources-only, register-time cap, attribution, rollback).

### 2026-08-24 · Agent guidance — Slice 2a (plugin skill fragments → agent) — security-green
- **Re-review of built code** (branch `slice-agent-guidance-2a`) — the plugin-text-to-agent surface the Slice-1 design review flagged for its own pass. All six demanded controls verified implemented + correctly placed + regression-tested:
  - **C1 resources-only** — `McpServerFactory` passes only `CoreGuide::instructions()` as the instructions arg; plugin docs spread only into the documents variadic; `initialize` serves only that string. Pinned by `SkillRegistrarTest` (a "call mint_token scopes=admin" fragment never reaches `instructions`).
  - **C2 registry-lookup** — plugin URIs join the same exact-key `GuideLibrary` lookup; no filesystem deref. `McpResourcesTest` covers `nimbus://guide/plugin/../core` etc → uniform `-32002`.
  - **C3 attribution** — `SkillRegistry::documents()` stamps `owner` unconditionally; `readContents()` wraps every owner!=null doc; the body can't remove the preceding envelope.
  - **C5 register-time caps** — `SkillRegistrar::register()` throws (empty/>200B title, empty/>64KiB body) → loader REGISTER_FAILED + rollback.
  - **C6 rollback parity** — `PluginLoader` catch `skills->forgetProvider($id)`; reflection tripwire in `PluginLoaderTest` now requires the `skills` prop (a 9th capability can't ship without rollback coverage).
  - **C8 no-secrets contract** — docblocks + COMPATIBILITY state fragments are static + world-readable to any valid token; `SkillRegistry::add()` reachable only from plugin `register()` at boot; core never auto-derives a fragment from site data.
- **Containment thesis reconfirmed:** ADR 0001 already trusts plugin code; this grants no new authorization (registrar is anti-foot-gun API, not a sandbox). New asset is *reach into operator agent sessions*: opt-in per read, attributed, capped, and can only drive actions the operator's token already authorizes (per-tool `can()` + subset-only mint/set_role). Residual is exactly ADR 0013 §Consequences.
- **Findings (both Low, no blockers):** **F1** — inverted comment + latent last-wins in `GuideLibrary` (a colliding later doc *would* have displaced core under last-wins; collision is unconstructible today via the id gate). **Fixed in-PR:** first-wins (`??=`) + regression test. **F2** — un-enveloped plugin-authored `title` in `resources/list` (≤200B could hold a short directive). **Hardened:** core guide now teaches that listed titles are plugin-authored data. Both recorded here.
- **Verdict: security-green to merge.** No Critical/High/Medium. Slice 2b (markdown fragment) is a pure consumer — no re-review unless it touches core.

### 2026-08-24 · MCP create_collection `kind` (singleton parity) — security-green
- **Reviewed on the DESIGN before build; conditions verified in the built code.** No Critical/High/Medium.
- **Confirmed no new surface:** `schema:write` gate is upstream of the `kind` parse (arg can't alter the gate; discovery hidden; denial audited); reserved-handle guard is options-independent in `CollectionService::create` (a singleton named `media`/`admin` is rejected identically — test); the one-row invariant lives in `EntryService` + the DB UNIQUE index (MCP is just another caller); singletons stay un-browsable at `/{handle}` (SVM-4, one source — `SiteRoutesTest`), so a singleton *shrinks* the public surface vs the regular collections MCP could already create; `site.home` is `settings:write`-gated and validates an existing handle — no low-priv path to capture `/`.
- **Two controls shipped (the reviewers' conditions):** (1) **reject** unknown/non-string `kind` with `invalid` — the one place coercion would fail *open* toward the browsable kind (test: `singleton` typo + non-string both rejected, nothing created); (2) **over-posting pin** — options built server-side by `optionsForKind()`, never merged from client input (test asserts the stored `options` row is exactly `{kind, permissions:{manage:[]}}` despite a crafted `permissions`/`options` payload).
- **Verdict: security-green.** Read-parity (`kind` on collectionResult/list_collections/describe_collection) leaks nothing — those are already read-gated/filtered; a collection's kind is publicly observable anyway. **Pre-existing, non-blocking:** un-CAS'd singleton create-race recovery (Slice-L); `delete_collection` not clearing a naming `site.home` (FU-14 family — recorded). **Standing check:** MCP `update_collection` gaining `kind` would make single→collection a publication event for `__singleton` → its own review.

### 2026-08-25 · Public `$nav` view-model (docs-sidebar list) — security-green
- **Reviewed on the design before build; controls verified in the built code.** No Critical/High/Medium. `$nav` is a re-arrangement of the already-public live set — no new data path — plus two drift guards the public surface lacked.
- **Controls shipped (the reviewer's four must-ships):** (1) **live-only source** — `$nav` is fed ONLY by `liveForCollection` (the same live predicate as index/sitemap/blocks); no attacker input to the predicate; `SiteNavTest::test_nav_never_contains_a_draft_or_scheduled_entry` pins it on both index and entry pages. (2) **toApi-correct values** — full `view->many(…, canRead:null)`, so nav field values are the public `toApi` scalars (never raw storage, never deleted-field residue — `project()` iterates the schema); a transforming fixture field type proves nav shows the toApi value, not raw. Lean projection deferred with the ruling that it must live inside EntryView + run toApi. (3) **NAV_MAX=200 as the SQL LIMIT** — bounded per-request work, no post-fetch slice, no new page-cache mint (no query input → HTTP-6/SVM-1 intact; CacheRoutes guard green). (4) **browsable gate + data-only** — `$nav` built only when `isPubliclyBrowsable()` (singleton/blocks → `[]`), closing the SVM-4 edge where a kind-changed multi-entry singleton at `/` would list unreachable entries; `$nav` is plain data, never a handle-taking closure (which would have been a High site-wide live-content enumeration primitive).
- **Verdict: security-green.** New class first-sighting: "a view-model that bypasses the `toApi` seam" — watch for a 2nd sighting to promote to the threat catalog. Escaping of author content in nav is the theme's existing `View::e` discipline (a DoD line, unchanged in kind).

### 2026-08-25 · Core /llms.txt (agent-facing site file) — security-green
- **New public route** reviewed on the BUILT code (the "new endpoint = full pass" the AEO design burst required). `SiteController::llmsTxt()` serves `text/plain` beside robots.txt.
- **Controls verified (all implemented + tested):** no private/draft/enumeration leak — lists only `isPubliclyBrowsable()` collection INDEX urls (never singletons/blocks — SVM-4, same gate as robots/sitemap; no per-entry slugs at all, a strict subset of the sitemap); no version leak — neither `Application::VERSION` nor the MCP protocol version (`McpServer::PROTOCOL_VERSION`, negotiated at initialize); **structural/agent-prompt injection blocked** — the only editor-controlled values (site title, description, collection names) are flattened `trim(preg_replace('/\s+/',' '))` (byte-wise, no `/u`, null-guarded) so a newline can't forge a `##` section an agent reads as instruction; base is `Config::appUrl()` (env APP_URL, not a spoofable Host — verified live with X-Forwarded-Host); `text/plain` + `X-Content-Type-Options: nosniff` → no XSS sink; static route registered before `/{collection}` and `llms.txt`→`llms_txt` under Str::handle so no collection can shadow it.
- **The key subtlety, confirmed:** `site.title` has the SUP-2 control-char validator (can't carry a newline) but `site.description` is **length-validated only** — a newline IS storable there, and the **flatten is what neutralizes the description path**. Pinned by `test_llms_txt_flattens_the_site_description_which_has_no_control_char_validator` (added on the reviewer's "chiefly this one" note) + the collection-name + mcp-protocol-version tests.
- **Residual (Low, accepted by design):** a privileged `settings:write`/`schema:write` actor can still place INLINE prose (or break a `[name](url)` link slot with `]`/`)`) in title/description/name — the same power they already hold over every public page; the boundary is anti-section-forgery, not content filtering. **Follow-up filed (Low, opportunistic):** a host-poisoning regression for the robots/sitemap/llms family (APP_URL-authority is structurally guaranteed + tested elsewhere).
- **Verdict: security-green.** No Critical/High/Medium.

### 2026-08-25 · Deploy platform Slice 1 (production stack) — security-green
- **Two-stage review (both skills):** design passed pre-build; a **finished-artifact** `nimbus-security-review` (the exposure boundary — ADR 0010 gate) then attacked the built `deploy/**`. No Critical/High. All designed controls confirmed *correctly encoded* (not just designed): edge origin-lock uses `remote_ip` — the true TCP peer — not the XFF-honoring `client_ip`; `static` trusted_proxies; per-schema `GRANT` on an unpublished DB; non-root + `cap_drop ALL` + `setcap -r` on frankenphp + composer removed; secrets git-ignored (only `*.example` tracked).
- **Two Mediums the design-stage review + local proof structurally could NOT catch — fixed in-slice + empirically re-proven** (this slice is the copy-template every site inherits): **DEP-1** — all sites shared one flat `/24` and each trusted the whole subnet, so a *compromised* site could open a direct connection to a sibling's `:8080` and forge `X-Forwarded-For` against its rate-limiter/audit (DB isolation held; this is integrity of the IP-derived controls). **Fixed:** pin edge Caddy to a fixed IP; each site's `TRUSTED_PROXIES` = that IP ∪ CF ranges, never the `/24`. **Proven:** site-a→site-b:8080 direct with forged `9.9.9.9` recorded as site-a's real IP. **DEP-2** — `/uploads` sits under the `php_server` docroot; the uploader allow-lists non-PHP + random names so nothing writes a `.php` today, but a second write-a-`.php` bug would be RCE. **Fixed (DiD):** `route{}` guard 403s `/uploads/*.php`. **Proven:** `probe.php`→403 empty, normal upload→200.
- **Two Lows fixed:** DEP-3 — DEPLOY.md add-a-site snippet was missing `import cloudflare_only`+`tls` (a hand-added site would lose the origin-lock/TLS) → snippet corrected. DEP-4 — mysql `root@'%'` default-open + root password on the healthcheck cmdline → `MYSQL_ROOT_HOST: localhost` + healthcheck via `MYSQL_PWD`.
- **QA/permanence:** `deploy/check.sh` static artifact-assertions (every edge block origin-locked+TLS; no site trusts `/24`,`/12`,`0.0.0.0/0`; uploads-no-PHP; classic-mode-only; DB unpublished + root confined + password off cmdline; only templates tracked, secrets ignored; `--image`: non-root/no-composer/frankenphp-caps-stripped). Runs green.
- **New standing check (first sighting):** *a flat multi-tenant network makes the "pinned subnet, not /12" trusted-proxy rule insufficient* — the pinned subnet IS the shared tenant subnet, so trust must name the edge's exact IP (or segment per-site networks). Promote to the threat catalog on a 2nd sighting.
- **Verdict: security-green to merge** (PR #177 stack + hardening). Left open (tracked, none cross data/auth): per-site network segmentation is optional DiD beyond the trusted-proxy fix; worker mode remains forbidden (documented + guarded).

### 2026-08-26 · Self-service change-password (account settings) — security-green
- **Reviewed on the DESIGN before build (auth surface); every must-ship control verified in the built code + a regression test.** New `POST /admin/settings/password` in `SettingsController`, inside the `/admin/settings` authMw group; a personal action (any signed-in user, no capability). Closes a real dogfooding gap: there was no logged-in password-change path (only email `/admin/forgot`, and SMTP is optional/often unconfigured).
- **Controls shipped (order is load-bearing):** CSRF (`requireCsrf` first) → per-account throttle **before** the verify (`LoginThrottle` key `changepw:{int uid}` — bounded, unspoofable, writable only via the owner's own session, so no cross-user lockout; closes the current-password-as-guessing-oracle for a session-rider) → **re-auth on the current password** via new `Auth::verifyCurrentPassword()` (the control a CSRF token can't give — the rider holds the token; runs before ANY mutation) → `Password::isWeak` floor + confirm-match + no-op guard → one blessed write (`UserRepository::setPassword`, uid from session ONLY, no over-postable id) → PRG `?flash=password`. Password values are **never** echoed back on error (fields render empty — the `$submitted`-overlay half of the saveSite pattern was deliberately NOT mirrored, per the review's A5) and never logged.
- **A4 (Medium) fixed in-slice, not deferred:** `session_regenerate_id` only rotates the CURRENT session, so a stolen cookie would survive the very rotation a user does *because* they're compromised. Added a password-hash **session stamp** (`Auth::SESSION_STAMP`): set at login (`attempt`/`login`), re-set on self-change (`refreshAfterPasswordChange`), and checked every request in `Auth::user()` — a session whose stamp no longer matches the stored hash is logged out; legacy (pre-feature) sessions are backfilled once. So a change now evicts every OTHER session while keeping the changing one. (Rare caveat: a login-time algorithm rehash re-stamps and would log out other sessions — benign, documented.)
- **Verified safe by construction:** over-post of `user_id`/`role`/`email` cannot touch another account (no id parameter; bound two-column UPDATE) — pinned by test; integer-uid throttle key can't overflow `nb_login_throttle.id` or be written cross-user; uniform argon2id work + generic wrong-current message (no enumeration, no dummy-hash need since the row is always session-resolved).
- **Regression suite (`tests/Http/AccountPasswordTest.php`, 9 tests / 38 assertions, all green):** CSRF required; wrong-current rejected + throttle recorded; throttle blocks even a correct current once locked (proves check-before-verify); weak/mismatch/no-op rejected; success (old fails, new works, current-algo hash, throttle cleared, `?flash=password`); over-post another user untouched; submitted password never in the re-render body; A4 (changing session survives, stale-stamp session logged out). PHPStan L7 + full suite green.
- **Deferred (tracked, non-blocking):** ADR 0012 **Phase 2/3 forward condition** — provider-provisioned users with an unusable hash will need a marked-unusable flag + the form swapped for a "set a password via reset" affordance (not buildable now — a random `Password::hash` is indistinguishable from a user-set one; the current-password gate fails closed for them today). Optional "your password changed" email tied to future mail-reliability work.
- **Verdict: security-green.** No Critical/High/Medium open. New harness note: `actingAs()` now clears `nimbus_pw` on user switch (a fresh login sets uid+stamp together). Watch for a 2nd sighting of the "mirror-the-pattern carries the value-overlay" lesson (A5) to promote against threat-catalog #16.
