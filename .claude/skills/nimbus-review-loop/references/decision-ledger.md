# Decision ledger

Append-only. Newest at the top. Supersede entries — never delete them. Do not
copy full ADR content here; link to the ADR. Each entry:

- **Date** · **Decision** · **Status** (proposed / accepted / superseded / rejected)
- **Evidence** (commits, PRs, tests, ADRs) · **Product** · **Architecture** ·
  **Engineering consequences** · **Revisit trigger**

Statuses: `proposed` awaits maintainer approval; `accepted` is in force;
`superseded` was replaced (link the successor); `rejected` was considered and
declined (keep it — it stops the idea returning without new evidence).

---

### 2026-08-24 · Slice Y — Plugin `nb_`-reference migration lint [Core] · resolves FU-11
- **Status:** accepted + built. Reviewed via the Fable two-skill burst before building (both hats: **ship it**). PR (slice-y-migration-lint). Completes PLUG-9 (docs half shipped Slice P) with its enforcement half.
- **What:** `MigrationRegistrar::register` rejects (→ existing `REGISTER_FAILED` path, plugin skipped, core+others unaffected) any migration statement mutating a core `nb_*` table — before `nimbus migrate` hands it to MySQL's **irreversible auto-commit DDL**. The rare lint whose false-positive class is empty *by contract* (plugin tables are slug-prefixed, never `nb_`), so the only FP vector is DDL-looking text inside a string literal — closed by stripping literals+comments first.
- **Match set (both reviews' corrections folded):** verb-anchored on the **target** identifier — CREATE/ALTER/DROP/TRUNCATE TABLE (optional TABLE for `TRUNCATE nb_x`, `IF [NOT] EXISTS`, backticks), DROP-TABLE **comma lists**, RENAME **both operands** (+ the ALTER RENAME TO form), CREATE/DROP INDEX **… ON nb_***, and **target-keyed DML** (INSERT/REPLACE/UPDATE/DELETE). DML included over the platform hat's initial "DDL-only" lean because (security hat) a direct `nb_user_roles` INSERT dodges the Slice-A subset-only chokepoint, and target-keying (`INSERT INTO nb_*`, not a `…SELECT FROM nb_*` source) gives it the same near-empty FP class as the DDL rules — reconciling both hats. A `(?<![\w.])` guard stops a plugin table named `analytics_nb_x` false-flagging; the FK `REFERENCES nb_users` carve-out falls out of verb-anchoring for free.
- **Robustness:** normalize first (strip `--`/`#`/`/* */` comments + `'…'`/`"…"` literals, **keep** versioned-comment bodies which MySQL runs), **fail-closed** on a PCRE error (safe: trusted-author input, rejection lands in the loader's containment path), linear matching (no ReDoS).
- **Framing (must-ship, honored):** an accident guard, **not a sandbox** (ADR 0001 — a plugin bypasses via dynamic SQL/raw PDO) — stated in the docblock, the teaching diagnostic (names table + cites ADR 0005), and COMPATIBILITY. The security ledger records the uncovered surface (FU-19) and keeps catalog #12's hostile-plugin Low **open** — the lint is NOT a mitigation for it.
- **DoD met.** Test: `MigrationLintTest` — the evasion corpus (22 cases) IS the spec + carve-outs (FK/own-table/mid-name/comment/literal/read-source) + a drift test flagging every core migration statement + the teaching-message assertion. Loader integration rides the existing register()-throw → REGISTER_FAILED path (already tested). PHPStan L6 + cs-fixer + audit clean; full suite via CI. **Residuals filed:** FU-18 (reserve `nb`-prefixed plugin ids — namespace symmetry, P4), FU-19 (recorded uncovered surface — runtime/dynamic/reads — NOT a gap). MCP: rides the recorded PLUG-11 deferral.

### 2026-08-24 · Slice X — Collection-delete integrity + settings-audit decision [Core] · resolves FU-14 (built) + FU-13 (documented decision)
- **Status:** accepted + built. Reviewed via the Fable two-skill burst before building. PR (slice-x-collection-delete).
- **FU-14 (built, Core) — refuse to delete a targeted collection.** `CollectionService::delete` (the shared chokepoint) now checks `CollectionRepository::relationFieldsTargeting(handle, excludeCollectionId)` and throws `CollectionInUse` (mirrors `MediaInUse`) when a relation field **in another collection** targets it — the reverse of ADMIN-14a's write-time validation, completing the schema-integrity triangle (validate-on-write, guard-on-media-delete, guard-on-collection-delete). **Refuse, not null/re-point** (both reviews): re-pointing would silently mutate a sibling collection's schema + bump its version (breaking an agent's read-before-write). **Exclude-by-id** so a self-targeting collection still deletes (its field dies with it). Check+delete in one `transaction()` (TOCTOU parity with media). Admin `destroy` catches → **server-renders the escaped detail** (ADMIN-10: the message names operator-authored field labels, never round-tripped through `?err=`); MCP `delete_collection` → `in_use` ToolResult with the usage; `confirm` flow untouched. PHP-side JSON decode over the handful of relation fields (no `JSON_EXTRACT` dialect coupling).
- **FU-13 (documented decision, NO code) — admin settings writes stay outside the token-audit scope.** Both hats: the `api.*` audit is a **token trail** by design (ADR 0006; payload is `token_id`/`token_name`), and admin interactive writes have never been actor-audited anywhere. Emitting a settings-only admin event would freeze a NEW pre-1.0 event family with **zero consumers** (Drift-Guard #3 fail) and create a misleading **partial** audit. Rated Low (no secrets on the settings surface — OAuth is env-only). The coherent capability is an admin activity log (dormant `nb_activity` table = its home), deferred. **This is the review-loop-correct closure of a follow-up that should NOT be built speculatively** — recorded with revisit triggers (a security-relevant settings key; a compliance/multi-admin request; the nb_activity use-or-drop call).
- **Slice coherence:** bundle works only because FU-13 collapses to a ledger/docs deliverable riding alongside FU-14's code (the two share no files). Had FU-13 gone the emit route it would have been its own slice.
- **DoD met.** Tests: `CollectionRoutesTest` (targeted-refused, self-target-deletes, untargeted-deletes) + `McpSchemaToolsTest` (in_use + usage parity). `delete_collection` tool description mentions the refusal. PHPStan L6 + cs-fixer + audit clean; full suite via CI.

### 2026-08-24 · Slice W — Reserve names at schema-create [Core] · resolves FU-4 + FU-6
- **Status:** accepted + built. Reviewed via the Fable two-skill burst before building. PR (slice-w-reserved-names).
- **Two distinct reserved sets (NOT unified — both reviews).** `CollectionService::RESERVED_COLLECTION_HANDLES` = `Authorizer::MANAGEMENT ∪ {admin}` + route prefixes `api`/`uploads`/`theme` (FU-4); `RESERVED_FIELD_HANDLES` = `title`/`slug`/`published_at` (FU-6). Unifying would ban `media` as a *field* handle (a natural name) to guard a collision that can't occur (field handles aren't authz resources); a collection named `title` collides with nothing. The split also self-documents why each set exists.
- **Service-as-sole-authority (mirror `DuplicateHandle`).** New `ReservedHandle` exception thrown from the shared `CollectionService::create()` (collection handle + all field handles) and `update()` (**new** field handles only), caught by both surfaces → friendly error (admin field error; MCP `ToolResult` naming the set for agent self-correction). One chokepoint, no per-surface reimplementation; the seedable-website roadmap is the concrete 3rd caller that inherits the guard.
- **Load-bearing correctness (reviews caught):** (1) check the **normalized** `Str::handle` output, not raw input — else `"Media"`/`" media "` bypass (A2). (2) **Grandfathering:** collection-handle check is **create-only** (validateDraft runs on edit too; a naive check bricks editing a pre-existing `media` collection) and field-handle check is **new-only** (syncFields matches by handle → rejecting a stored `title` field forces a data-lossy DELETE+INSERT rename). (3) drift-guard test asserts the const ⊇ `Authorizer::MANAGEMENT ∪ {admin}` (consts can't merge arrays; a future 7th management cap must not silently miss reservation).
- **FU-6 is correctness-only** (security review: native title/slug validations run after the field validator and win the map key → not maskable; the bug was a silently-dropped custom-field error).
- **DoD met.** Tests: `ReservedHandleTest` (drift ×2), `CollectionRoutesTest` (reject each management name incl. case-variant; reject title/slug/published_at field; **grandfathered** media collection edits + title field survives), `McpSchemaToolsTest` (create + add_field parity). COMPATIBILITY reserved-handle rule; CHANGELOG notes the MCP tightening. FU-17 filed (grandfathered-collision residual — doctor/warning). PHPStan L6 + cs-fixer + audit clean; full suite via CI.

### 2026-08-24 · Slice V — Auth/account hardening [Core] · resolves FU-1 + FU-9 + FU-10
- **Status:** accepted + built. Reviewed via the Fable two-skill burst before building. First slice of the FU-tail burn-down (Dan: "finish all FU first, then agent skill, then docs+website"). PR (slice-v-auth-hardening).
- **FU-1 (Medium, the P2) — `roles:seed` re-run no longer widens authority.** The seed's user loop now assigns the legacy-`role`-derived system role ONLY to a user with zero `nb_user_roles` (`RoleRepository::hasAnyRole`). Both reviews confirmed the "zero assignments" condition is correct AND doesn't break a real workflow (`bin/nimbus create-user` makes zero-role users the re-run legitimately activates) — do NOT narrow to "first-boot-only". Closes the placeholder re-arm, the demoted-legacy-admin re-arm (when ≥1 role remained), and the transitive Slice-A subset-only violation. CLI-only → capped below High. Residual (deliberately zero-role user) filed **FU-16**.
- **FU-9 (Low) — accepted risk, NO code (both reviews).** Partial equalization (mint-and-discard) would be theater: unlike AUTH-1 (CPU-bound hash both branches), the reset path's dominant timing signal is the I/O-bound mail send, which can't be faked without decoy mail. Compensating control = the dual digest-keyed reset throttle recorded before the service call; the docblock already documents the residual. Recorded in the security ledger (Low → ledger, not ADR). Revisit: async mail dispatch closes it for free.
- **FU-10 (Low, but the fix had a High trap) — `LoginThrottle::prune` with a lockout-safe predicate.** `WHERE last_attempt < :cutoff AND (locked_until IS NULL OR locked_until < :now)`, cutoff ≥ MAX_LOCK; wired into `nimbus prune`. Copying `ApiRateLimiter::prune`'s `updated_at`-only predicate would delete an actively-locking row = an AUTH-2 lockout bypass — the preserve-an-active-lockout test is the merge gate.
- **DoD met.** Tests: `RoleSeederTest` (3: no-widen-narrowed, no-re-arm-demoted-admin, first-boot-still-seeds) + `LoginThrottleTest` (3: prune-stale, preserve-active-lockout, noop-empty). RoleSeeder docblock re-states the never-widening invariant. MCP: no new tool (roles:seed/prune are operator CLI maintenance — deliberate). PHPStan L6 + cs-fixer + audit clean; full suite via CI.

### 2026-08-24 · Slice U — Public fragment/single-kind routing [Core] · resolves SVM-4
- **Status:** accepted + built. Reviewed via the Fable two-skill burst before building (security-green, no findings — a public-surface *reduction*). PR (slice-u-fragment-routing).
- **One shared predicate.** `SiteController::isPubliclyBrowsable(Collection)` (`handle !== BLOCKS_HANDLE && !isSingle()`) now gates BOTH `index()` and `show()` and drives `sitemap()` — the served surface and the crawled surface consult one rule and can't drift again (the exact class of drift the audit caught: the sitemap already excluded these, the routes never asked). `/blocks`, `/blocks/{slug}`, `/{single-handle}` + its `__singleton` entry all `404`.
- **404 over 301 (both reviews).** Redirect policy already belongs to `config/redirects.php` (applied pre-routing, supports 301); `isSingle()` ≠ "is the home", so a non-home single has no redirect target and a uniform 301→`/` would need the mutable `settings.home` and outlive a home change (permanent browser cache). 404 is uniform, and it's the *reversible* direction (404→200 later is compat-safe; the 200→404 this slice does is the breaking one — ship the conservative answer). Reuses `notFound()` verbatim → no enumeration oracle vs an absent collection.
- **Scope discipline (review ❌ caught):** the gate covers `show()` too — `/{single}/__singleton` was the *worse* duplicate (a full entry render with its own canonical), which a "index-only" fix would miss; the single predicate in both handlers fixes it for free. `homePage()` is NOT gated (a single legitimately renders at `/`). `BLOCKS_HANDLE` const hoisted, shared with the `blocks()` loader.
- **Deferred (recorded, not built):** a regular-collection home still duplicates `/` and `/{handle}` (a canonical-tag policy question, not a 404 — filed as a P4 observation); a `kind: fragment` schema concept over the magic handle (no requesting consumer; the const gets 90% of the sturdiness). Closes the 2026-08-15 "Reusable blocks" ledger item's deferred routing arm on this audit's evidence.
- **DoD met.** Tests: `SiteRoutesTest` (blocks + single 404 on both routes; absent-parity; home still 200 at `/`; normal collection still 200) + `CacheRoutesTest` (a non-browsable 404 mints no cache file). COMPATIBILITY note (not routable + API-unaffected + redirects escape hatch). PHPStan L6 + cs-fixer + audit clean; full suite via CI.

### 2026-08-24 · Slice T — API read-path: batch interop & the N+1 [Core] · resolves API-6 + DATA-5
- **Status:** accepted + built (two commits per the reviews). Reviewed via the Fable two-skill burst before building. PR (slice-t-api-readpath).
- **API-6 — reject batches, do not fan out (security-load-bearing).** First statement of `McpServer::handle` rejects any list-shaped body (`array_is_list`, no `!== []` carve-out — so an empty/malformed body the transport collapsed to `[]` is repaired too) with `-32600` `id:null`. Fan-out is not implemented: MCP 2025-06-18 removed batching, AND one HTTP request → N tool calls would bypass the per-request rate limit (both flood + token quota count HTTP hits). Guard sits in the shared protocol layer → stdio inherits it; controller untouched.
- **DATA-5 — batch inside `EntryView`, `one()` delegates to `many()` (structural single path).** `many()` pools all media ids → one `findMany` (its first consumer — the primitive shipped unused), and resolves each relation field in one new `RelationRepository::liveTargetsFor(entryIds, fieldId, target)` keyed by `from_entry_id`. **The DATA-1 guards survive by construction:** the batched query carries the identical declared-target JOIN + live predicate; the per-field scope gate stays in `EntryView::project` (denied field → `[]` page-wide, ids never fetched); and because `one() = many([$row])[0]` the two paths *cannot* drift (stronger than a byte-identical test — there is one path). Per-field batching (not one target-less mega-`IN`) keeps the target constraint a single `:target` bind, structurally identical to the old `liveTargets`.
- **Capability call (ledger-bound):** NO `ReferenceExpander` capability extracted — the capability-evidence promotion trigger is "a 3rd reference-resolving field type" and there are still only two (media, relation), both core-authored. Batched as an **internal** `EntryView` mechanism. **This slice closes the F1 (2026-08-15) N+1 arm; it explicitly RE-OPENS / leaves standing the F1 "3rd type → public capability" arm** — do not misread DATA-5 as satisfying it. The per-field batched shape is exactly what promotes later, so no rework when the third type arrives.
- **Honest framing:** resolved on **arithmetic** evidence (1+50+50 at MAX_PER_PAGE), not a load report (the Slice S honest-framing precedent).
- **DoD met.** Tests: `McpTest` batch-reject (−32600, id null, not 202); `RelationIntegrityTest` batch cases (no cross-row graft; scope gate page-wide) + the full per-row guard suite (now routed through the batch) green; `ApiRoutesTest` byte-identical. **Query-count test omitted deliberately:** `Connection` is `final` with no counting seam, and un-finaling a class this central for a perf-assertion isn't warranted — the O(1) property is structural (one `findMany` + one query per relation field, N-independent, visible in `many()`). PHPStan L6 + cs-fixer + audit clean; full suite via CI.
- **Revisit trigger:** a 3rd reference-resolving field type → extract the public reference-expansion capability (the F1 second arm); a future protocol revision reintroducing batching + a real client → implement with per-message metering (never naive fan-out).

### 2026-08-24 · Slice S — Route-dispatch memoization & cache-key correctness [Core/docs] · resolves HTTP-5 + HTTP-6
- **Status:** accepted + built. Reviewed via the Fable two-skill burst before building. PR (slice-s-dispatch-cache).
- **HTTP-5** — memoize `Route::regex()` on the instance (`$pattern` readonly → invariant regex; semantics byte-identical). **Honest framing (platform ⚠️):** the per-request benefit is ~nil (single-pass dispatch, per-request Route objects, PHP's PCRE cache already caches the compiled pattern) — a ≤5-line zero-risk hygiene change that pays off only in repeated-dispatch/long-running modes; the commit does not claim a per-request win. Did NOT memoize `Application::routes()` (behavior change — SiteController captures config at construction).
- **HTTP-6 — kept path+page, documented the contract; REJECTED "fold the query" (security-load-bearing).** Both reviews: folding the query string into the cache key re-opens SVM-1 (unauthenticated disk-fill, one file per `?x=N`); a **bucketed** key (option b) is *worse* — it hands the attacker chosen-key **cache poisoning** (pre-seed the bucket a victim's `?tag=news` renders into), converting a latent self-inflicted correctness bug into an attacker-steerable one; an allow-list (option c) is a speculative API with no consumer. Chosen (option a): the key stays path+`?page`, and the invisible theme-author coupling becomes an **explicit, tested contract** in COMPATIBILITY.md — cached output must be a pure function of path+`page`; a query-varying public page runs `PAGE_CACHE_TTL=0`. Safe *by construction* today: core reads no query but `page`, and themes have no header channel to opt out. **Named + deferred extension point:** a store-side `Cache-Control: no-store` check in `respond()` built alongside a future search/filter feature (HTTP-7/Slice O precedent) — recorded so the bucketed-key idea isn't reinvented.
- **DoD met.** Tests: `RouterTest` (memo transparent — same params on repeat, catch-all still spans slashes), `CacheRoutesTest` (distinct query strings mint ONE file — the SVM-1-bound guard that fails if anyone folds the query; + a static drift guard that SiteController reads no query key but `page`). PHPStan L6 + cs-fixer + audit clean; full suite via CI. HTTP-1 nonce invariant + SVM-1 bound both provably untouched.
- **Revisit trigger:** a query-varying public page entering core → build the `no-store` opt-out (option a's deferred half); never fold the raw query or bucket it (the security rejection stands).

### 2026-08-24 · Slice R — Admin notice hardening & correctness paper-cuts [Core] · resolves ADMIN-10 + ADMIN-14
- **Status:** accepted + built. Reviewed via the Fable two-skill burst before building. PR (slice-r-admin-notices).
- **ADMIN-10 — code-not-text notices (the successor to template-side flash maps).** Post-redirect admin notices now resolve fixed CODES → strings through one shared `Controller::notice(Request, $ok, $err): ?array` (unknown code → null → renders nothing), mirroring `oauthFlash`. All 6 controllers emit codes; the template-side `$flashLabel` maps and `ucfirst($flash)` reflections are **retired**. **This supersedes the audit's "What's solid" blessing of template-side maps** — controller-side resolution is the one blessed pattern now (stronger unknown→null property, one `{kind,message}` shape), guarded by the `AdminNoticeTest` drift test. Dynamic messages genericized (accepted UX cost: subset-only errors lose the capability-name detail — cheap re-render path exists if it stings).
- **Reviews caught (design corrections adopted):** (1) a **missed reflection site** — `entries/form.php:27` via the singleton path (absent from the finding); (2) ADMIN-14b lives on **three** surfaces, not one — admin + MCP `TokensToolset` (which also emitted a **false audit event** for a revoke that never happened) + `bin/nimbus`; (3) media error paths **server-render** rather than genericize `UploadError`, but must honor the **media read/write split** (A4) and **escape** author-controlled entry titles (A3).
- **ADMIN-14b — existence-first, not affected-rows (security A6).** Chose `ApiTokenRepository::exists()` before the verb over the reviewed void→int rowCount: rowCount 0 can't distinguish "missing" from "already in that state" (the verbs are conditional UPDATEs), so void→int would falsely error a legitimate **idempotent re-revoke**. find-first cleanly reports a missing id AND keeps re-revoke a success, on all three surfaces.
- **ADMIN-14a** — relation `target` validated against existing handles in `validateDraft` (admin) and `SchemaToolset::fieldDef` (MCP parity); blank/bogus/deleted rejected; self-target-on-create rejected (matches the dropdown). entries `?msg=error` (a green "Error." bug) → `?err=save-failed` (error-kind).
- **DoD met.** Tests: `AdminNoticeTest` (7 surfaces reflect nothing + 2 static drift guards), `MediaRoutesTest` (in-use A3 escape + A4 no-listing; 2 upload tests updated to the re-render), `CollectionRoutesTest` (relation target bogus/blank/valid), `TokenAdminTest` (missing-id error + idempotent re-revoke). PHPStan L6 + cs-fixer + audit clean; full suite via CI.
- **Revisit trigger:** a new admin page must use `Controller::notice()` (the drift test enforces it); a 3rd sighting of "a fixed-key notice asked to carry variable text" promotes the class to the threat catalog (see security ledger). Follow-up filed: delete-time dead-relations (deleting a collection other relation fields target) — not built here.

### 2026-08-24 · Slice Q — Settings write integrity & operator feedback [Core] · resolves SUP-4 + SUP-7 + SUP-10
- **Status:** accepted + built. **Reviewed via the Fable two-skill burst (both skills, models recovered from the 529 incident) BEFORE building.** PR (slice-q-settings-integrity).
- **Two review corrections adopted:**
  - **SUP-4 mechanism (platform ❌):** the design first proposed a *session flash*; the reviewer showed there is **no session-flash mechanism** in the codebase and a redirect+flash would lose the operator's input. Corrected to the established **re-render-the-POST-response** pattern (`EntriesController::save()` lineage): `saveSite()` collects all failures, re-renders (200) with per-field messages + submitted values overlaid via `View::e`. This is also *strictly safer* for the ADMIN-10 reflection class (no redirect round-trip), and it superseded the security loop's "session-flash carriage" must-ship while satisfying its intent.
  - **SUP-7 transaction placement (deviation from the reviewed service-level, with rationale):** both reviews wanted the transaction "inside `Settings::setMany`" (service level). Building it revealed a correctness trap — `Settings` holds a `SettingsRepository` (its own connection) *and* would hold a separately-injected `Connection`; a transaction on the second only wraps writes on the first **if the caller passes matching instances** (true in prod, fragile by construction). Moved the transaction into `SettingsRepository::setMany` — the object that **owns the connection and performs the writes** — so it provably wraps them. `Settings::setMany` stays the orchestration entry (registry-key assertion + memo drop + audit-ordering contract). The codebase "transactions at service level" rule targets *multi-repository* operations; a single-repo batch belongs with its repository. **Net: more correct than the reviewed design.**
- **Other decisions:** the `site.home` validator message stopped echoing the submitted value (fixed string) — removes the one attacker-influenced string channel + bounds re-render bloat. MCP `API_MANAGEMENT_WRITTEN` emits moved **post-commit** (no audit for a rolled-back batch; parity with the recorded post-commit-events principle). SUP-10 threaded the composed `Settings` through the admin base `Controller` (required ctor param) + all 11 admin controllers + ApiController + SiteController + the 2 CLI paths (via the new `Application::settings()` accessor) — **eight** construction sites removed (the finding named six; two more lived in `bin/nimbus`).
- **DoD met:** `Settings::setMany` (registry assertion + delegate), `SettingsRepository::setMany` (atomic), re-render `saveSite`, composed-once + accessor, ~13-file threading. Tests: `McpSettingsToolsTest` (real rollback via TEXT-overflow; audit parity; unregistered-key refusal), `SettingsSiteTest` (partial-failure re-render + input preserved + writes nothing; hostile-value escaped; 5 rejection tests → 200+message), `SettingsCompositionTest` (drift: `new Settings(` only in Application). PHPStan L6 + cs-fixer + audit clean; full suite via CI.
- **Revisit trigger:** a future setting that must vary per-request, or a cross-cutting settings consumer (head contributor) — both now inherit atomic writes + the composed instance for free. Admin `saveSite` writes remain unaudited (pre-existing asymmetry vs MCP) — filed as a follow-up, not this slice.

### 2026-08-24 · Slice P — trivial P3 cleanup [Core/docs] · resolves API-8 + SUP-5 + ADMIN-11 + PLUG-9 + PLUG-11 + SUP-9 + SVM-5 + API-7
- **Status:** accepted + built. **Reviewed via an Opus-4.8 burst (Dan's incident workaround since Fable was erroring) — but BOTH Opus agents also hit 529 Overloaded** (the model incident spread to Opus). Per the skill's "skip the loop for trivial changes" exception, the lenses were applied directly to this trivial batch; the one item with real logic was deferred (below).
- **Fixed:** stale `ApiResponse` docblock (API-8); `Env` inline-comment strip for unquoted values (SUP-5); dead "Fields" button (ADMIN-11); per-plugin table-prefix convention documented (PLUG-9, docs); plugin-health-over-MCP deferral recorded (PLUG-11); `Env` + clear-to-empty test gaps (SUP-9); + two resolved-by-cross-reference (SVM-5 by H+K, API-7 by A+L — no rework).
- **Decisions (lenses applied):** PLUG-9 → **docs-only**, the `nb_`-reference lint **deferred to FU-11** (a false-positive-prone regex deserves a real burst). PLUG-11 → **record the deferral** (ADMIN-9 precedent). SUP-9 clear-to-empty → the semantic is correct but non-vacuously untestable against the shipped empty-default config (noted). **ADMIN-14 deferred** to the substantive-P3 group — its relation-target validation + changing 3 token-repo method signatures (void→int) is real logic I won't land unreviewed during the incident.
- **DoD met:** API-8 docblock; `Env` strip + `export` + quoted carve-out; `canSchema` gate; MigrationRegistrar/COMPATIBILITY prefix note; ledger deferral (PLUG-11). Tests: `EnvTest` (new), `AdminListingTest` (Fields gate). PHPStan L6 + audit + cs-fixer clean. FU-11 filed (the deferred lint).
- **Lesson:** with BOTH review models down (Fable + Opus overloaded), split the batch by decision-weight and ship only what the skill's own triviality exception covers unreviewed; defer anything with genuine logic (ADMIN-14, the PLUG-9 lint) to a reviewed slice rather than force it through. Two of the "test-gap" findings were already satisfied by earlier slices — check before rebuilding.

### 2026-08-24 · Slice O — security-hardening P3s [Core/docs] · resolves SVM-3 + HTTP-7 + SUP-8 + PLUG-12 + PLUG-13
- **Status:** accepted + built. **Fable burst was killed mid-run by a session limit** — so for these five *trivial, finding-prescribed* Low P3s I applied the two review lenses directly (the skill's "skip the loop for trivial, obviously-correct changes" exception). ADMIN-10 (the one item with a real design decision) was **split out** to its own slice for a proper burst.
- **Fixed:** uploads served without `nosniff` (SVM-3, docs); latent open-redirect in `Response::redirect` (HTTP-7, documented); LogMailer file perms (SUP-8, chmod 0600); FieldType render-escape warning (PLUG-12, docblock); "Official" badge trusts vendor prefix (PLUG-13, relabel).
- **Decisions (lenses applied):** HTTP-7 → **document, not a speculative `Url::safeInternal` helper** (principle: refuse extension APIs with no consumer; not reachable today — a SECURITY docblock on `redirect` warns the next `next`-param author to guard). SVM-3 → **docs snippet only** (the upload allow-list is the primary control; PHP media-serving fallback deferred). PLUG-13 → **relabel to "nimbuscms namespace"** (keep the flag, honest wording).
- **DoD met:** `@chmod(...,0600)` best-effort; `redirect` SECURITY docblock; `FieldType` SECURITY docblock + COMPATIBILITY row; `providerLabel()` neutral + plugins-page copy; COMPATIBILITY "Serving uploaded media" (nginx/Apache/Caddy). Tests: `MailerTest` (log 0600), `PluginsPageTest` (new badge label). PHPStan L6 + audit + cs-fixer clean. Mobile: N/A (copy/docblock only). MCP: N/A.
- **Lesson:** when the burst is unavailable, split the slice by decision-weight — ship the trivial finding-prescribed fixes directly (they meet the skill's own triviality exception) and reserve the one item with a genuine design fork for a real burst, rather than either blocking everything or making the hard call unreviewed.

### 2026-08-23 · Slice N — login hardening [Core] · resolves AUTH-1 + AUTH-2 + AUTH-3 + AUTH-5 + AUTH-6 (the last two P2s)
- **Status:** accepted + built. Fable two-skill burst — platform **ship (revise)**, security **green (no Crit/High)**. Closes the audit's final two P2s (both Medium security) + the related auth P3s.
- **Fixed:** login timing/enumeration oracle (AUTH-1); IP-only throttle → distributed spray (AUTH-2); password floor 8-vs-UI-12 mismatch across surfaces (AUTH-3); OAuth `start` CSRF-on-GET (AUTH-5, Low); the guarding tests (AUTH-6).
- **Fix:** `Auth::attempt` single-code-path equal-work vs `Password::dummyHash()`; dual digest-keyed throttle (`login-ip:`/`login-em:`) with uniform failure recording + generic message; `Password::MIN_LENGTH=12` swept across 7 surfaces; OAuth link moved to an authed CSRF **POST** with GET stripped of link-intent.
- **Review-forced (both, sharp):** (M1) the dummy hash must be **algo-matched to the runtime** — `Password::algo()` falls back to bcrypt, so a hardcoded argon2id dummy on a bcrypt host re-opens the oracle; `dummyHash()` picks per-algo + a `password_get_info` algoName drift guard (not just `needsRehash`). (M2) `recordFailure` fires **uniformly** on the unknown-email branch + byte-identical lockout message, or AUTH-2 re-opens AUTH-1. (❌1) the `login-em:`/`pwreset-em:` keys **overflow `nb_login_throttle.id` VARCHAR(190)** with an unbounded email → 1406/500 (a live bug in the reset flow the naive mirror would copy) → hash the email into the key; fixed `pwreset-em:` too. (❌2) the floor sweep is **7 surfaces incl. `bin/nimbus`'s inline-duplicated predicate** (→ now calls `isWeak`) and the MCP schema description. (❌3/❌4) AUTH-5 must **strip link-intent from GET** (a POST alone gates nothing); AUTH-1 as a **single code path** makes equal-work structural (kills the flaky wall-clock test).
- **Decisions:** AUTH-3 floor → **12** (per-account throttling makes keyspace the brake; only affects newly-set passwords). AUTH-5 → **fix as POST form** (not token-in-query — Referer/log leak). AUTH-2 lockout-DoS is time-bounded (1h/15m, same as reset) — **recorded tradeoff**. Threshold 5 for both keys (no per-key API — no second consumer).
- **DoD met:** equal-work + algo-matched dummy + drift guard; dual digest throttle + uniform record; floor 12 ×7; OAuth POST link + GET stripped. Tests: `PasswordTest`, `AuthRoutesTest` (single-account lockout, no-oracle, clears-both), `OAuthFlowTest` (link CSRF/auth/GET-405). PHPStan L6 + audit + cs-fixer clean. Corrected the `PasswordResetService` docblock overclaim. Filed FU-9 (reset-request timing oracle), FU-10 (throttle-row pruning). Mobile: settings Connect button + reset/accept copy at 375px (copy/button-only). MCP: no new tool; the floor propagates via the shared const + tool description (noted evolving).
- **Lesson:** the third sighting of the derived-value-column-width trap (throttle key after Slice G handle-80 and PLUG-2 id-length) — "mirror the reset flow exactly" would have faithfully copied a live 500. And the equal-work fix's real subtlety wasn't the dummy hash but its **algorithm-agnosticism** (bcrypt fallback) — a security-lens catch a platform-only pass would have missed.

### 2026-08-23 · Slice M — plugin contract + docs [Core] · resolves PLUG-2 + PLUG-4 + PLUG-6 + PLUG-7 + PLUG-8
- **Status:** accepted + built. Fable two-skill burst — platform **ship (revise)**, security **green (no Crit/High)**. The "make the plugin contract trustworthy before release" cluster: 4 small hardening/test fixes + one contract decision (PLUG-6).
- **Fixed:** unvalidated plugin id (`core` defeats rollback, colon collides migration names, empty, over-long → 500) (PLUG-2); duplicate/core-colliding admin-page slugs silently accepted (PLUG-4); COMPATIBILITY calls Request/Response internal while plugins must use them (PLUG-6); COMPATIBILITY drift — maintenance capability missing, stale "events aren't a capability" (PLUG-7); only field-type rollback was tested (PLUG-8).
- **Fix:** id gate in `PluginLoader::validate` (`/^[a-z0-9][a-z0-9._-]*$/` + reject `core` + ≤64) + `MigrationRegistrar` name ≤120; `AdminPageRegistry::add` throws on duplicate slug + `AdminPageRegistrar` RESERVED_SLUGS; COMPATIBILITY carves a stable-for-plugins Request/Response read subset + adds MaintenanceRegistrar + fixes the events sentence; `AllCapabilitiesBrokenPlugin` fixture + all-registries rollback test with a reflection tripwire.
- **Review-forced (both, identical):** (1) PLUG-2 id must be **length-bounded** (≤64) — it flows into `nb_migrations.migration` VARCHAR(191) via `pluginId:name`, an over-long id is a 1406→500 at migrate (the Slice G derived-value-column-width lesson again); also bounded the migration name (≤120). (2) PLUG-4 RESERVED_SLUGS **must include `dashboard`** (the design omitted it) and can't be computed at registration (routes build lazily) → a **Router-derived drift-guard test** guards the hand list. (3) PLUG-6 blessed subset **verified against the two shipped consumers** (they use Request `path/method/header`, return `Response::html/json`), plus `download` per the documented handler-return contract.
- **PLUG-6 = doc-bless, not VO** (evidence-following: two unrelated consumers exercised exactly these members; VOs now fail the "abstraction must remove real duplication" rule with zero demanding consumers). Carving a stable-read-subset out of blanket-internal is the house style (theme blesses `$cspNonce` not `View`; `CoreEvents` blesses names not payloads). `request.handled` full-Request payload **accepted as-documented** (in-process plugins read superglobals anyway) BUT with a **mandatory never-log/persist warning** (Authorization/login body) in `CoreEvents` + COMPATIBILITY, carrier hedged (may become a VO before 1.0). **Supersedes the 2026-08-15 request.handled payload-VO revisit** with a new trigger: a consumer needing an out-of-subset member, or the pre-1.0 freeze sweep.
- **DoD met:** id gate + name bound; duplicate-throw + reserved-slug + drift guard; COMPATIBILITY carve-out (explicit member list + never-persist warning + hedged carrier) + MaintenanceRegistrar row + events-sentence rewrite + accessor sweep; all-caps rollback fixture + reflection tripwire. Tests: `PluginLoaderTest` (6 malformed ids + core-can't-defeat-rollback + all-caps rollback), `AdminPageRegistryTest` (duplicate + 7 reserved), `PluginAdminPageTest` (route-derived drift guard). PHPStan L6 + audit + cs-fixer clean; package-boundary.sh runs in CI. Mobile: N/A (failures render in the existing plugins page). MCP: no new action (plugin-health-over-MCP visibility is the recorded PLUG-11 gap).
- **Lesson:** both reviews independently caught the same three gaps — the derived-column overflow (id length, the recurring VARCHAR-budget trap), a new hand-maintained list shipped without a tripwire (RESERVED_SLUGS), and a blessed API surface asserted-not-verified against real consumers. And the evidence-following test for "did the doc-bless the right members?" was to grep the two shipped plugins, not to reason about it.

### 2026-08-23 · Slice L — concurrency + migrations [Core] · resolves API-4 + DATA-4
- **Status:** accepted + built. Fable two-skill burst — platform **ship (revise)**, security **green (no Crit/High)**. Two Medium correctness findings, landed as separable commits (Api/Content vs Database).
- **Fixed:** optimistic concurrency was check-then-act (read version → compare → version-less UPDATE) → lost update (API-4, Medium); a multi-statement migration failing partway was unrecoverable (DATA-4, Medium).
- **Fix:** API-4 — atomic CAS: `EntryOperations` passes the read `version` → `EntryService::save`/`delete` (optional `?int $expectedVersion`) → `EntryRepository` appends `AND version = :expected`; `rowCount 0` (non-null expected) throws `EntryConcurrencyConflict` (final, non-PDOException) → caught in `EntryOperations` → `preconditionFailed` (412). Covers PATCH AND DELETE. DATA-4 — `Migrator::runStatements` skips a "schema object already exists" error (`Connection::isDuplicateObject`: 1050/1060/1061/1826) as an already-applied no-op, logged.
- **Review-forced (both ❌, identical):** (1) the naive "two writers holding v3" **kernel test is vacuous** — the second re-reads and the existing precondition catches it before the CAS; the CAS must be tested with a genuinely stale `expectedVersion` at the service/repo level + the 412-mapping via an injected double (which required un-`final`-ing `EntryService`). (2) the duplicate-object check must read `errorInfo[1]` on the **original** exception — `runStatements` re-wraps the PDOException (no `errorInfo`), so checking the wrapper would silently disable the skip. (3) skips must be **logged** (name + statement index), not silent — visibility is what's traded for the self-heal.
- **Q1 DATA-4 = executor-skip** (not `IF NOT EXISTS`): one place, uniform over CREATE/ALTER/INDEX/FK, and heals the 011_token_role ALTER pair `IF NOT EXISTS` can't (no MySQL 8 `ADD COLUMN IF NOT EXISTS`). Doesn't violate the Slice D fail-closed contract (genuine errors still throw `MigrationFailed`; `record` still after the full file); strengthens the docblock's idempotency claim to be true at statement granularity. Errno 1062 (row dup) stays fatal; dropped 1022.
- **Q2 = exception over a result-state** (dead-code-free on the admin path; covers save+delete with one type; rides the existing transaction rollback + sails past save's dup-key catch). **Q3 = DELETE gets the CAS** (the If-Match contract binds PATCH+DELETE; a stale delete is the worse lost update). **Q4 = creates never CAS** (entryId null).
- **rowCount invariant is load-bearing** (version+1 guarantees a matched row changes → rowCount reflects a match without MYSQL_ATTR_FOUND_ROWS) — commented + pinned by a no-change-still-succeeds test; a future dirty-check must not break it. `If-Match: *` becomes a versioned CAS (stricter, accepted, documented in COMPATIBILITY).
- **DoD met:** CAS through save/delete/repo + exception + EntryOperations catch (PATCH+DELETE); `isDuplicateObject` + original-exception check + logged skip; `EntryService` un-`final` for the test seam. Tests: `EntryConcurrencyTest` (6), `MigrationRecoveryTest` (3), admin last-write-wins. PHPStan L6 + audit + cs-fixer clean. COMPATIBILITY If-Match CAS note; Migrator docblock truthed-up; supersedes the 2026-08-18 ops-gotcha for the re-run case. Mobile: N/A. MCP: API-4 makes the MCP `version` precondition genuinely atomic (agent-surface improvement).
- **Lesson:** both reviews independently caught the same three traps — a test that passes without the fix, a self-heal that never fires (errorInfo on the wrapper), and a silent skip. The fixes were sound; the *verification* was where the risk lived. And the finding's own suggested minimal fix (`IF NOT EXISTS`) was incomplete — it can't heal an ALTER file, which the statement audit surfaced.

### 2026-08-23 · Slice K — authz / admin cleanup [Core] · resolves ADMIN-3 + ADMIN-9 + SVM-1 + SVM-2
- **Status:** accepted + built. Fable two-skill burst — platform **ship (revise)**, security **green (no Crit/High)**. Four authz/public-edge findings in one slice.
- **Fixed:** role `destroy()` skipped the subset guard `update()` enforces (ADMIN-3, Medium); unbounded public `?page` minted unbounded page-cache files (SVM-1, Medium DoS); a NUL in an asset path → 500 (SVM-2, Low); roles CRUD has no MCP surface + no complete deferral record (ADMIN-9).
- **Fix:** `RolesController::destroy()` mirrors `update()`'s `firstUnheld($role->capabilities)` guard; `SiteController::renderCollection` 404s a page past the last; `Application::cacheKey()` returns null above `MAX_CACHEABLE_PAGE=1000`; `asset()` rejects a NUL before `realpath`; ADMIN-9 recorded (not built).
- **Review-forced (platform ❌):** (1) **the `cacheKey` ceiling is load-bearing, not belt-and-braces** — the `renderCollection` 404 only covers the collection index, but `cacheKey` appends `?page=N` for *any* cached GET, so `GET /?page=N` (home) and `GET /posts/slug?page=N` (entry) 200 and ignore the param → without the ceiling they stay an unbounded anonymous cache-mint. (2) grounding was stale — role **assignment** IS MCP-reachable (`UsersToolset::set_role`); the true ADMIN-9 gap is role **CRUD only**. (3) a partial deferral already existed (Slice A ledger) → *complete* it (revisit trigger + corrected the false "entire CMS over MCP" wording in ROADMAP + ADR 0009).
- **SVM-1 shape (Q1):** 404, not clamp-to-200 — `cacheKey` keys on the raw `?page=N` and `respond()` stores only 200s, so a clamped 200 still mints one file per N; only a non-200 stops the mint. Divergence from the admin *clamp* is principled (public = cached+anon → status is the control; admin = uncached+authed → clamp is UX) — recorded so a future slice doesn't "harmonize" it back.
- **ADMIN-9 (Q2): record, don't build** — no concrete agent consumer needs role composition; an L-effort toolset on the highest-privilege boundary is speculative; the standing check accepts reachable-**or**-recorded. A future `RolesToolset` must carry subset-only on **destroy** too (the ADMIN-3 lesson) — stated in the revisit trigger.
- **DoD met:** destroy guard; renderCollection 404; cacheKey ceiling; asset NUL guard; ADMIN-9 record (ledger revisit trigger + ROADMAP + ADR 0009 wording). Tests: `RolesAdminTest` (subset-delete + token-caps-survive + admin-still-can), `SiteRoutesTest` (page 404 / empty page-1 200 / valid deep page 200 / NUL asset 404), `CacheRoutesTest` (out-of-range + absurd `?page` mint no `.cache` file). PHPStan L6 + audit + cs-fixer clean. COMPATIBILITY: public 200→404 pagination note. Mobile: N/A (error renders in the existing admin notice). MCP: recorded deferral (ADMIN-9).
- **Lesson:** the finding's own fix note ("clamp stops the per-N cache entry") was wrong — clamping renders a 200 that `cacheKey` still keys per N; the design caught that but under-scoped the fix to the collection route, and the reviewer caught that the mint lives in `cacheKey` (fires on home + entry too). The DoS half was in the kernel key-builder, not the paginated controller.

### 2026-08-23 · Slice J — HTTP / CORS / HEAD [Core] · resolves HTTP-2 + HTTP-3 + HTTP-4 + API-5
- **Status:** accepted + built. Fable two-skill burst — platform **ship (revise)**, security **green (no Crit/High/Med)**. Four kernel/CORS findings in one slice (all live in `Router::dispatch` + `Application::run/handle` + `Cors`).
- **Fixed:** HEAD→404 + no 405/Allow (HTTP-2); a session cookie minted on `/api` + preflight (HTTP-3); the preflight bypassed the IP flood limiter (HTTP-4); the preflight advertised only GET/OPTIONS, blocking cross-origin writes+MCP (API-5).
- **Fix:** `Router::dispatch` single-pass with HEAD→GET + a 405/`Allow` when the path matches but not the method; `Response::withoutBody()` applied in `handle()` for HEAD (after headers, before `notifyHandled`); `run()` skips `startSession` when `Cors::isApiPath()`; one `RateLimitMiddleware` built in `Application` and injected into `ApiController`, applied fail-open to the preflight; `Cors::preflight` advertises `GET,POST,PATCH,DELETE,OPTIONS` + `If-Match`.
- **Review-forced (platform ❌):** (1) the preflight flood guard **fails open** — it hits the DB before `respond()`'s try/catch and the readiness gates, and `index.php` has no net, so DB-down/not-installed must still answer 204 (wrapped in try/catch + log ref). (2) **one flood-guard construction** — Application owns it and injects into ApiController (rejected a static Config-reading factory, which is the two-call-site drift it claimed to kill). (3) HTTP-3's test seam — the session cookie is emitted by `session_start()`, never on the `Response`, so a "no Set-Cookie via `handle()`" assertion is vacuous → extract `Cors::isApiPath` (shared so the CORS prefix and session-skip can't drift), unit-test it, add a static drift guard (no session use under Api/Mcp) + a `smoke.sh` curl. (4) `RouterTest:93` asserted wrong-method→null; updated to 405+Allow.
- **Pinned as deliberate:** the site `/{collection}` catch-alls make `POST /anything` (1–2 seg) a **405 (Allow: GET, HEAD)**, not 404 — uniform, no oracle; `HttpMethodTest` documents it. HEAD never poisons the page cache (`cacheKey` GET-only + strip after store) — `CacheRoutesTest` pins HEAD-then-GET returns the full body.
- **Security (both lenses):** confirmed HEAD reaches only GET routes (no verb-smuggling; no method-override seam in `Request`) and still runs every guard (auth/flood on HEAD); dropping the `/api` session is pure hygiene (zero `$_SESSION` there) and *strengthens* the bearer-only boundary. API-5 is not a security change (no Allow-Credentials, allow-list-gated echo).
- **Deferral recorded:** session-skip for `/theme/assets/` + public GETs (finding names assets) is a behavior change (themes could touch session) → its own later slice.
- **DoD met:** dispatch 405/HEAD; `withoutBody`; session skip via shared predicate; fail-open injected flood guard; CORS methods/headers. Tests: `RouterTest`, `HttpMethodTest`, `ApiCorsTest` (API-5 + preflight 429 + shared bucket + no Allow-Credentials), `ApiSessionlessTest` (predicate + drift guard), `CacheRoutesTest` (HEAD-cache), updated `RouterTest`, `smoke.sh` (no Set-Cookie on /api). PHPStan L6 (via `--memory-limit=512M`) + audit + cs-fixer clean. Mobile: N/A (no UI). MCP: API-5 *improves* the agent surface (browser MCP clients can POST /mcp cross-origin) — no new tool.
- **Lesson:** three of four ❌s were placement/seam, not logic — the *correct* fix in the wrong spot would have shipped a DB-down fatal (preflight guard outside every net), a config-drift second construction, and a vacuous test. The reviewer tracing the pre-`respond()` execution order + the cookie's actual emission point (session_start, not the Response) is what made the slice safe and testable.

### 2026-08-23 · Slice I — mail reliability [Core] · resolves SUP-1 + SUP-2 + SUP-6 + ADMIN-7
- **Status:** accepted + built. Fable two-skill burst — both **ship (revise then build)**; security-green conditional on the ADMIN-7 controls (all in-design). Four coherent mail-path findings in one slice.
- **Fixed:** a `MAIL_TRANSPORT` typo silently routed all mail to the log AND reported success (SUP-1); a stored CRLF in `site.title` silently killed every reset/invite (subject throws, reset flow swallows it) (SUP-2); non-ASCII subjects mojibake'd (SUP-6); a failed/expired invite could not be re-sent though the UI said "re-invite" (ADMIN-7).
- **Fix:** loud send-time fallback (flagged `LogMailer`) + `nimbus mail:test` CLI; byte-wise control-char reject in the `site.title` validator (shared by admin form + MCP); `mb_encode_mimeheader` after the CR/LF guard; `POST /admin/users/{id}/invite` (CSRF + users:write + id-validate + subset guard + pending-gate) with a row action + reworked flashes.
- **Review-forced (platform ❌):** (1) SUP-1 warning at **send** not in the factory ctor — `MailerFactory::fromConfig()` runs in `Application::__construct` on every request → a ctor `error_log` floods logs; the flagged `LogMailer` warns only when a mis-routed message is actually sent. (2) SUP-2 regex **without `/u`** — `preg_match(…, /u)` returns `false` on invalid UTF-8, admitting a broken-UTF-8+CRLF title (fail-open). (3) SUP-6 `mb_encode_mimeheader` not a single unbounded encoded-word (RFC 2047's 75-char limit; mbstring already required). (4) named the `mail()` test seam — a `Nimbus\Mail\mail()` namespace-function shim, test-only, no prod edit.
- **Q1 RESOLVED (both lenses):** gate resend to genuinely-pending users via the no-schema signal — an unused `purpose='invite'` row in `nb_password_resets` (issued before send so a delivery failure counts; survives expiry; cleared on accept). Enforced server-side + a one-query list flag (no N+1). So resend can never arm a password-set link for an active account. **New coupling for later:** a future `nb_password_resets` prune must NOT drop unused invite rows (comment + test pin it).
- **MCP parity:** the deferred `invite_user` MCP tool (ROADMAP) stays deferred — resend is UI-only, consistent with the record (re-affirmed here, not silently UI-only).
- **DoD met:** flagged-fallback `LogMailer` + `mail:test`; `site.title` control-char reject (admin + MCP); `mb_encode_mimeheader`; resend route + row action + flashes; `PasswordResetRepository::hasPendingInvite`/`pendingInviteUserIds`. Tests: `MailerTest` (SUP-1 warn/silent, SUP-6 encode/verbatim/still-throws via the `mail()` shim), `MailerFactoryTest`, `SettingsSiteTest` + `McpSettingsToolsTest` (SUP-2 both paths), `UserInvitationTest` (8 resend cases). PHPStan L7 + audit + cs-fixer clean. Mobile: resend button reuses `.nb-link` (12px mobile tap target in `.nb-row-actions`). FU-8 filed (resend throttle).
- **Lesson:** the two silent failure modes compose — SUP-2 silently kills recovery mail, SUP-1 silently reports false success — an operational blind spot on the most security-critical flow; fixing both together (plus `mail:test` for proactive verification) is why "mail works" is now checkable. And the sharpest platform catch was placement: the *correct* warning in the *wrong lifecycle spot* (ctor vs send) would have shipped a per-request log flood.

### 2026-08-23 · Slice H — CSP nonce × page cache & plugin surface [Core] · resolves HTTP-1 + PLUG-5
- **Status:** accepted + built. Fable two-skill burst — both **ship** (security-green conditional on one must-fix, which was already the platform ❌1). Q1/Q3/Q4 decisions confirmed by both lenses.
- **Fixed:** the page cache stored HTML with a baked-in `<script nonce=X>` while `SecurityHeaders` emitted a freshly-rotated `nonce-Y` on every request incl. hits → inline scripts/styles blocked on cached public pages (HTTP-1); the nonce was not on the plugin surface, so a head-contribution / admin-page script couldn't carry it (PLUG-5) — the exact thing that arms HTTP-1, so fixed together.
- **Fix:** `PageCache` stores `timestamp\nnonce\nHTML`; `get()` returns `{html,nonce}`; `Application::respond` calls `Csp::adopt($hit['nonce'])` on a hit so the header re-emits the stored nonce (both `script-src` and `style-src`), and stores `Csp::nonce()` on a miss. `Csp::adopt()` + `isValid()` added; the `Csp` docblock now documents two lifecycle verbs (rotate = fresh; adopt = re-emit on hit). `PageContext` gained a required `public readonly string $cspNonce` (5th param); admin handlers invoked `$handler($request, Csp::nonce())` (additive 2nd arg).
- **Q1 (persist-and-re-emit vs drop-the-nonce):** persist chosen — drop would forbid all inline script/style on cached pages AND make the CSP posture config-dependent (cache off in dev works, on in prod breaks), defeating PLUG-5's analytics path. Also fixes `style-src` for free.
- **Review-forced must-fix (platform ❌1 + security A1, Medium):** on upgrade day a pre-nonce `timestamp\nHTML` entry parsed with `explode(...,3)` would read the HTML's first line as the "nonce" and `adopt()` it into the CSP header (policy-token injection) + serve a decapitated body. `get()` now validates the stored nonce against the exact emitted shape (`^[A-Za-z0-9+/]{22}==$`) and treats any mismatch as a **miss** (unlink); `adopt()`/`put()` reject non-shape values too. Fail-to-miss versions the format and hard-guards the only untrusted boundary.
- **Q2 safety (both lenses):** a stable-per-cache-entry nonce on **public** cached pages opens no XSS bypass — the cached body is immutable, an attacker can't write into it, and any content write flushes → re-render mints a fresh nonce. This **depends on flush-on-write staying wired** (now commented as security-load-bearing at `Application.php`); re-check if cache invalidation is reworked (HTTP-6).
- **Q3/Q4:** admin 2nd-arg is additive (PHP ignores extra args; both shipped consumers register plain closures — verified); `cspNonce` required (not optional-`''`, which would silently emit `nonce=""` → the fail-open shape prior slices killed).
- **Cross-repo (coordinated, like the bundle refactor):** `nimbus-plugin-seo`'s 7 positional `PageContext` constructions bumped for the new required param.
- **DoD met:** `Csp::adopt/isValid`; `PageCache` format+guard; `Application` hit-adopt/miss-store; `PageContext::$cspNonce`; admin 2nd-arg + registrar docblock; **COMPATIBILITY theme cache-caveat rewritten** (inline nonce'd tags now work on cached pages) + connect-src beacon limitation recorded. Tests: `CacheRoutesTest` (hit re-emits stored nonce for script+style, byte-identical bodies; write rotates the nonce), `PageCacheTest` (legacy/invalid entry = miss), `CspTest` (adopt/isValid), `HeadContributionTest` + `PluginAdminPageTest` (nonce reaches contributor + handler; 1-arg BC). PHPStan L7 + audit + cs-fixer clean. FU-7 filed (hosted-analytics connect-src). Mobile/MCP: N/A (no UI/management surface).
- **Lesson:** exposing a value to plugins (PLUG-5) is what converted a latent cache bug (HTTP-1) into a live one — coupling them was correct; and the sharpest catch (upgrade-day misparse feeding the CSP header) was in the *persistence format*, not the feature — the reviewer tracing the on-disk upgrade path is why the slice is safe to deploy over an existing cache dir.

### 2026-08-23 · Slice G — admin-form validation hardening [Core] · resolves ADMIN-5 + ADMIN-8 + ADMIN-12
- **Status:** accepted + built. Fable two-skill burst — both green (security: all Low, admin-only, no boundary crossed) after two platform ❌ fixes + the MCP-parity add.
- **Fixed:** duplicate field handles → 500 on edit / mislabel on create / **silent overwrite** of a sibling field (ADMIN-5); no length caps → 1406 → 500 under strict MySQL (ADMIN-8); array-shaped field input → `TypeError` 500 (ADMIN-12).
- **Fix:** `CollectionsController::validateDraft` rejects duplicate **normalized, intra-submission** handles (the edit form re-submits every field, so a collision always appears as two rows — closes the silent-overwrite too) + name/description/label/handle length; `fieldDefs` coerces `type`/`handle`/`target` with `is_string` (both TypeError paths); per-row errors keyed `fields.{i}` and rendered in `_field_row.php`; single-parse of `fieldDefs` (was parsed twice). Management forms (Users/Roles/Tokens/Media) use a shared `Admin\Controller::tooLong` + per-column caps. Limit constants live once on `CollectionService` (`HANDLE_MAX 80`/`NAME_MAX 120`/`DESC_MAX 255`/`LABEL_MAX 120`) — shared by admin + MCP, no drift.
- **Review-forced (platform ❌):** the handle caps are **80** not 120 (`nb_collections.handle`/`nb_fields.handle` are VARCHAR(80); `Str::handle` never truncates → a valid ≤120 name/label would derive a >80 handle → 500) — validated on the normalized handle; and the per-row error needed a **render slot** (`fields.{i}` in `_field_row.php`) or the message was invisible.
- **MCP parity (standing check honored):** `SchemaToolset` reuses `CollectionService`, so the same dup/length defects were live for an agent — `fieldDefs` now dedups + `fieldDef`/`create_collection` enforce the same caps.
- **A1 adjudicated integrity-not-authz** (security): the silent overwrite crosses no boundary (`schema:write` can already retype a field deliberately); rejecting dups closes the *accidental* destructive-redefinition foot-gun.
- **DoD met:** coercion + dup + caps + `fields.{i}` rendering + single-parse; `Controller::tooLong`; MCP dedup/caps; `AdminFormValidationTest` (9 — dup create/update, no-false-positive, array-shape, over-long incl. handle-80, at-cap succeeds, management forms, MCP dup + over-long); an existing "rollback via 500" test updated to the new validate-before-write behavior; PHPStan L7. Admin-HTML/MCP contracts untouched.
- **Lesson:** the flagship 500 (over-long collection name) was in the *derived handle* (VARCHAR 80), not the field the finding named — the reviewer catching the column width is why the slice actually closes ADMIN-8 on its own form.

### 2026-08-23 · Slice F — entry-write input validation [Core] · resolves DATA-2 + DATA-3 + ADMIN-6
- **Status:** accepted + built. Fable two-skill burst — both green after three ❌ fixes (platform) + the url/email sink (security). All folds into `EntryService::save`'s existing structured-error block, so admin/API/MCP inherit it (the Slice C pattern).
- **Fixed:** malformed `published_at` → uncaught `DateMalformedStringException` → 500; over-long title/slug → 1406 → 500 under strict MySQL; unbounded text into the JSON `data` column + unbounded relation cardinality (100k ids = 100k inserts / a huge IN clause) — a write-amplification DoS from one `{handle}:write` token.
- **Fix:** `Publication::isValidTime` guard → `errors['published_at']` (and `isLive` made tolerant so the admin re-render can't 500); title ≤255 / explicit slug ≤191 validated; **`uniqueSlug` trims the base so base+suffix ≤191** (the ❌1 catch — truncation alone was defeated by the `-N`/`-{hex}` suffixes → the collision path still 500'd); `maxlength` field option in Text/Textarea (defaults 255 / 50 000, clamped to a 100k ceiling so `maxlength:10^9` can't re-open the DoS); a **universal 100k scalar-string ceiling in the `Validator`** (guarded by `is_string`, so number/boolean/relation are untouched) — closes the `url`/`email` uncapped sinks the per-type approach missed (security A3); relation **cardinality cap (100) in `RelationType::validate`**, which runs before `splitValues`/`idsInCollection` so an oversized list never reaches a DB query (the DoS bound).
- **Reviews reconciled:** platform wanted length in the text types (not force a char rule on number/boolean); security wanted a central backstop for url/email — resolved by a Validator ceiling that only checks strings + per-type `maxlength`. Media cardinality cap dropped (dead code — `MediaType::normalize` is single-value today; ships with a multi-file field).
- **DoD met:** `isValidTime`/`isLive`; `Validator` ceiling; `BaseType::maxLength` + Text/Textarea/Relation `validate`; `save` title/slug/published_at + `uniqueSlug` headroom; `toDatetimeLocal` blank-not-1970; form shows the `published_at` error; `EntryValidationTest` (9 — published_at API+admin, long-title-no-500, collision slug ≤191, text/url over-limit, relation-cardinality-0-inserts, valid-future schedules, draft asymmetry); COMPATIBILITY (error keys + bounds); DATA-2/DATA-3/ADMIN-6 resolved; PHPStan L7.
- **Recorded (Low follow-ups):** no app-level request-body-size bound (deployment config — `post_max_size`/`max_allowed_packet`); the field-handle-vs-reserved-key collision (a field named `published_at`/`title`/`slug`) → the reserve-names-at-schema-create family (FU-4 / ADMIN-14).
- **Lesson:** two reviewers disagreeing on the layer (text-type vs central) both had half the answer — a string-guarded universal ceiling + per-type option satisfies both, and it was the security lens that found the `url`/`email` sink the per-type design silently left open.

### 2026-08-23 · Slice E — per-listener isolation in emitBestEffort [Core] · resolves SUP-3 + PLUG-3
- **Status:** accepted + built. First P2 of the burn-down. Fable two-skill burst — both **green** (small, prescribed; the code catches up to a docblock that already promised per-listener isolation).
- **Fixed:** `emitBestEffort` wrapped the whole dispatch loop in one try/catch, so the first throwing listener starved every later one — including a plugin audit-log's `API_ACCESS_DENIED`/`API_MANAGEMENT_WRITTEN` records and analytics' `request.handled`. A buggy (or subtly hostile) plugin could blind the audit trail.
- **Fix:** per-listener try/catch inside `emitBestEffort` — logs `[nimbus {event}] {provider|core}: …` (matches the `HeadContributorRegistry` precedent) and **continues**. `dispatch()` is byte-identical (post-commit `entry.*` listeners are allowed to matter and must propagate — asymmetry test-locked).
- **Reviews confirmed:** inline over a `dispatchIsolated()` (no second consumer); log the provider (attribution is the point); the sink is operator-only (`error_log`; verified nothing routes it to a response — same posture as Slice D's `MigrationReport.error`); per-listener isolation is the *proportionate* control (audit-first ordering or a core audit sink would be drift — a hostile in-process plugin has blunter tools per ADR 0001).
- **DoD met:** `emitBestEffort` per-listener; `CoreEvents` class docblock scoped (entry events propagate; best-effort isolates) + stale "isolating is deferred" clause removed; `EventDispatcherTest` +3 (isolation red-first, never-propagates, dispatch-still-propagates asymmetry lock); SUP-3+PLUG-3 resolved, SUP-9(3) test-gap closed; PHPStan L7. Leaves open (Low, recorded): A2 uncatchable-termination + A3 shared-payload tamper — pre-existing, trust-model-bounded.

### 2026-08-23 · Slice D — plugin migration isolation [Core] · resolves PLUG-1 (+PLUG-10)
- **Status:** accepted + built. The **last audit P1**. Design-first via a **Fable two-skill burst**; both **green** after folding in the split-contract (core throws / plugins isolate) and the structural core/plugin distinction.
- **Fixed:** a failing plugin migration wedged `nimbus migrate` for the WHOLE install (MySQL DDL auto-commits, `nb_migrations` recorded only on full success, no per-provider isolation) — one broken plugin starved every other plugin and blocked core upgrades.
- **Design:** `Migrator::migrate()` returns a `MigrationReport {applied, failures, ok()}`. The **core file loop** throws `MigrationFailed` on failure (halts — plugin tables may FK core; fail closed). The **plugin registry loop** catches `PDOException` per `apply()`, records `{provider,migration,error}` (with the failing statement index), skips that provider's remaining migrations, and continues to the next provider. `record()` (the `nb_migrations` INSERT) and `ensureLog`/`applied` bookkeeping propagate (genuinely catastrophic). `pending()` now compares name **sets** not counts (PLUG-10).
- **Review-forced (❌):** the halt/isolate decision is **structural — which loop runs, never `provider === 'core'`** (a plugin manifest id `"core"` must stay isolated, else a re-wedge primitive; test locks it); a **fully non-throwing** `migrate()` was fail-open at `bin/nimbus install` (would seed into a half-migrated schema) and `tests/bootstrap.php` (would run the suite on a broken schema) — so core stays throwing (the exact fail-open shape Slice B/C reviews killed). `install` still seeds on a *plugin* failure (you must be able to log in to fix it) but exits non-zero; `bootstrap` asserts `report->ok()`.
- **Docs:** idempotency + partial-state hazard on `MigrationRegistrar::register()` (where authors look — PLUG-9: ADR-only contracts "don't exist") + COMPATIBILITY row + ADR 0005 note. `MigrationReport.error` documented operator-only (raw DB error — never web/MCP).
- **Open items (recorded):** plugin migrations run on the **core PDO** connection — ADR 0005 "own tables only" is a contract, not a wall (accepted, contract-not-sandbox); **PLUG-2** id-validation is the named fast-follow (erases the `"id":"core"` cosmetic + the name-collision skip); an MCP `migration_status` tool is deferred (the report makes it trivial — ties to PLUG-11).
- **DoD met:** `MigrationFailed` + `MigrationReport`; `Migrator` split-contract + set-based `pending()`; `bin/nimbus` migrate/install stderr + exit codes; `bootstrap` fail-closed; tests (`PluginMigrationTest`: isolation red-first, plugin-named-core-isolated, core-failure-throws, set-based pending; `CoreMigrationTest`/happy-paths adapted); docs; both ledgers; PLUG-1+PLUG-10 resolved. PHPStan L7. Mobile: N/A (CLI). MCP: deferral recorded.
- **Lesson:** "the throw is the bug" was only true of the plugin loop; the same throw was **load-bearing fail-closed** for core at `install`/`bootstrap` — a uniform no-throw contract would have re-introduced the fail-open the last two slices existed to kill. The core/plugin split must be structural, never a trusted string.

### 2026-08-23 · Slice C — relation value integrity [Core] · resolves DATA-1
- **Status:** accepted + built. Design-first via a **Fable two-skill burst**; both **green** after folding in three ❌ shape fixes.
- **Fixed:** a relation field's stored `to_entry_id`s weren't constrained to the field's declared `target` collection — a `posts` relation could silently hold a `secret` entry, and expansion (gated on the *declared* target, not the entry's real collection) leaked its `{id,slug,title}` to a token without `secret:read` (scope confusion).
- **Design (two layers, both permanent):** WRITE — `EntryService::splitValues` filters each relation field's ids to the target collection via `EntryRepository::idsInCollection` (bound IN-list, status-agnostic, order preserved by set-intersection); the shared write path, so admin/API/MCP inherit it. READ — `RelationRepository::liveTargets` gains a **required** `targetHandle` + collection JOIN, so a stored cross-collection row (legacy, or one created after the field is **retargeted**) expands to nothing. The read gate is NOT transitional: retargeting a field is a legitimate action the write path never sees, and the invariant is inexpressible as a DB FK (target lives in field JSON).
- **Review-forced (❌):** `liveTargets` param **required non-nullable** (the nullable-means-unfiltered footgun Slice B's review killed — same shape, one slice later); order via set-intersection against the submitted array (link order is a wire contract), not `SELECT` row order; the membership SQL lives in `EntryRepository`, not inline in `EntryService`.
- **Retained (security must-ship):** `EntryView::one`'s `canRead($declaredTarget)` scope gate stays alongside the collection filter — they answer different questions (a field *legitimately* targeting `secret` needs the authz check; the filter only enforces "rows ⊆ declared target"). Together: expanded rows ⊆ declared target ∧ declared target readable.
- **Incidental win:** silent drop closes a live entry-id **existence oracle** (a nonexistent id used to 500 via the `to_entry_id` FK on the PATCH echo-back) — nonexistent ≡ cross-collection, both graceful no-ops. Lazy cleanup (no migration): the PATCH/submit echo re-filters stored ids on any save, so bad rows are reaped without a destructive migration (an accidental retarget stays reversible).
- **Notes filed:** D5 — `sync` is now documented as requiring pre-constrained ids (the read filter neutralizes any future violation); `incoming` reverse-lookup is dead code (must apply the same discipline if ever consumed); ADMIN-14(a) pull-forward (a typo'd `target` now black-holes writes → validate at schema-create soon).
- **DoD met:** `EntryRepository::idsInCollection`; write filter; `liveTargets` required-handle + JOIN; `EntryView` passes the target + keeps `canRead`; `sync` invariant docblock; `RelationIntegrityTest` (9 — write-drop, order, oracle-closure/no-500, empty-target, read-gate-vs-stored-row, canRead-scope-gate, same-collection, end-to-end scoped-API, lazy-clean); COMPATIBILITY reference-fields note; DATA-1 resolved / DATA-6 partial; PHPStan L7.
- **Lesson:** the read gate's real justification is **retargeting**, not "legacy data" — a field's target is mutable config, so the value↔target relationship can go stale through a legitimate edit; only a read-time re-check keeps it honest. Reusing `idsInCollection` (bound IN-list, MediaRepository precedent) kept it injection-safe with no new pattern.

### 2026-08-23 · Slice B — read-boundary [Core] · resolves ADMIN-1/ADMIN-4/API-3
- **Status:** accepted + built. Design-first via a **Fable two-skill burst**; both **green** after folding in the fail-closed OpenAPI shape and the ADR-0008 amendment.
- **Fixed:** the admin let any signed-in user browse/enumerate every collection despite ADR 0011's `{handle}:read` promise (ADMIN-1); `openapi.json` handed a single-collection token the whole model (API-3); a singleton denial looped (ADMIN-4).
- **Design:** (1) `Gate::reads(Collection)` — seeded → `Authorizer::can(handle,'read')`; **unseeded → any signed-in user** (legacy content read was never gated — the ONLY behavior-preserving fallback; routing through `can()`'s admin-only fallback would lock non-admins out on an un-seeded upgrade). (2) Read-gate inside **`EntriesController::mustFind`** (one choke point, all entry routes) — an unreadable collection redirects **byte-identically to a missing one** (non-enumeration). (3) `CollectionsController::index` filters rows via `reads()`; the singleton "Edit" link is gated on `manages()` (no dead link); empty-state "create one" copy gated on `$isAdmin`. (4) `requireManage` aborts to the collections index (kills the ADMIN-4 loop). (5) **`OpenApiGenerator::generateFor(TokenPrincipal)` + `generateFull()`** — the HTTP `openapi()` resolves the principal via the 401-guarding `principal()` helper (fail-closed; `generate(null)` reachable only from the CLI); filters collections by read, write ops + `EntryWrite_` schema by write, in the per-collection loop (no leaked schema name or dangling `$ref`).
- **Review-forced:** fail-closed generator shape (no nullable-default-means-full reachable from HTTP — both reviewers ❌); **ADR 0008 amended** (it recorded "full model shown; scope-filter is a later refinement" — this slice is that refinement, superseded visibly); relation-**picker** display gated in-slice (security A2 — `renderForm` leaked an unreadable target's titles, the exact admin/API divergence); singleton dead-link + empty-state copy + `nav()` comment folded in.
- **Scope:** ADMIN-1 + API-3 + ADMIN-4 (+ relation-picker DISPLAY). Relation **value/expansion** integrity = DATA-1 (Slice C, different subsystem) — so the non-enumeration claim is scoped to *browsing + spec + picker display*, not relation values yet.
- **Follow-ups filed (Low, not this slice):** A3 — tokens/roles/settings *forms* still list every collection handle to management-cap holders; A4 — dashboard shows raw collection/entry `COUNT(*)` to any signed-in user; A9 — a collection handle equal to an `Authorizer::MANAGEMENT` name crosses content/management domains (reserve those names at collection create).
- **DoD met:** `Gate::reads`; mustFind gate; requireManage retarget; index filter + template; `nav()` comment; `generateFor`/`generateFull` + fail-closed `openapi()`; CLI `generateFull()`; tests (`AdminReadGateTest` 6 incl. indistinguishability + every-route + singleton-no-loop + relation-picker; `OpenApiGeneratorTest` scope-filter 5; API endpoint scope + 401); docs (ADR 0008, COMPATIBILITY, ROLES.md); findings resolved; PHPStan L7. Mobile: filtered/empty collections index (existing components) — verify at 375px.
- **Lesson:** `reads()` needed a *different* legacy fallback than `can()`/`manages()` — content read was historically open, structural/write was admin-only; a blind reuse would have been a breaking upgrade regression. The security value came free from reusing `Authorizer::can` (spec predicate == endpoint predicate, can't drift).

### 2026-08-23 · Slice A — roles single source of truth [Core] · resolves ADMIN-2/API-1/API-2/AUTH-4
- **Status:** accepted + built. The #1 pre-release-audit cluster. Design-first via a **Fable two-skill burst**; both **green** (platform: revise-before-build on two spec gaps, then green; security: green conditional on must-ship controls — all folded in).
- **Root cause fixed:** the MCP user tools wrote only the legacy `nb_users.role` string, never `nb_user_roles` where authority resolves — so MCP-created users had zero real capabilities (API-1) AND, once fixed, the missing subset-only guard would be account-takeover (API-2/ADMIN-2 latent High); two last-admin counters over two columns could disagree (AUTH-4).
- **Design:** (1) extract **`Authorizer::holds`** — the one grant-side predicate (`admin` + `resource:action`→`can`, inheriting management-immunity); `Gate::holds` (seeded path only — unseeded `Permissions` fallback preserved) and `TokensToolset::holds` now delegate to it. (2) `UsersToolset` rebuilt on `RoleRepository`: role resolved by **name** (any role, via `list_roles`), **subset-only** over the token's scopes before any write, atomic create+`syncUserRoles`, placeholder `nb_users.role='author'` (never `admin`). `set_role` is **both-directions** (new role AND target's existing roles — no stripping a superior), last-admin via `assignedUserCount(admin)` (same counter as the admin UI). `list_users` reports assigned roles; `list_roles` added. (3) dead `UserRepository::countByRole` + orphaned `setRole` removed.
- **Review-forced fixes:** `set_role` both-directions guard (was single); last-admin trigger reads the role assignment not `$user->role`; `Gate::holds` keeps its unseeded fallback branch; guard docblock pins the `principalFor`-unions-role-caps dependency; `list_roles` in-scope as the usability floor.
- **Deliberate change:** MCP `create_user`/`set_role` now **require seeded roles** (fail-closed clear error) — pre-seed they used to no-op-write the legacy column; MCP tool shapes changed (`role` is any role name, not the 3 legacy strings; `list_users` returns `roles[]`) — covered by COMPATIBILITY's "MCP evolving until 1.0".
- **Deferred (recorded):** full **RolesToolset** (create/edit/delete roles over MCP) = ADMIN-9, not built (Slice A adds only read-only `list_roles`). Follow-up finding: the `roles:seed` re-run trap (seeder assigns every user the role matching their legacy column → re-run would grant `author` to placeholder users; fix = one-line seeder guard skipping users who already hold any `nb_user_roles` row) — file separately, not Slice A.
- **DoD met:** `Authorizer::holds` + delegations; `UsersToolset` rebuild + `list_roles`; wiring; `countByRole`/`setRole` removed; tests (`AuthorizerHoldsTest` 7, `McpAdminToolsTest` user block rewritten incl. escalation-rejected + both-directions-strip + AUTH-4 role-counted + unseeded); docs (MCP.md, ROLES.md matrix/surfaces); findings marked resolved; PHPStan L7; full role/user/token suite green (263). MCP check: this slice IS the MCP-parity check honored.
- **Lesson:** the fix was cheap because `Authorizer::can` already carried management-immunity — `holds` delegating to it means the new guard inherits the invariant instead of re-deriving it; the copy-pasted predicate becoming one function is net-negative debt.

### 2026-08-23 · Trusted-proxy URL handling — env-authoritative, diagnostic not derivation [Core]
- **Status:** accepted. Design reviewed by a **Fable two-skill burst** (the new default: parallel `general-purpose`+`model:fable` agents, one per skill) — both green-to-build.
- **The decision:** the ROADMAP item "trusted-proxy config for URL generation" is resolved by *not* deriving URLs from the request. Every absolute link (reset/invite email, OAuth redirect, canonical/sitemap) stays built from `APP_URL`; a forwarded `Host`/`X-Forwarded-Host` is **client-spoofable even behind a correct proxy** (proxies pass it through), so deriving links from it re-opens reset-link account-takeover. Env stays the single authority.
- **What shipped instead:** `Request::viaTrustedProxy()` (trusted-peer signal only) + `Support\DeploymentCheck` → a misconfiguration warning on the admin Plugins page when `APP_URL` is still localhost, or `http://` while the request is HTTPS-via-proxy. Non-spoofable signals only; **no forwarded-host accessor** (the security burst flagged it as a standing footgun a future dev would mint links from).
- **Crown-jewel guard:** `TrustedProxyUrlTest` — a forged `X-Forwarded-Host` through a trusted proxy does NOT change the reset link (asserted APP_URL-host, never `evil.example`); OAuth redirect URI stays APP_URL-derived. Makes the security ledger's standing "reset-link poisoning" watch executable.
- **Trims the review forced:** dropped `baseUrl()` (no consumer) and a `nimbus doctor` command (can't see a live request — gold-plating); accessor not added to COMPATIBILITY's public surface.
- **Deferred (recorded per the standing MCP check):** an MCP `health`/diagnostics tool exposing the same `DeploymentCheck` warnings to an agent operator — revisit when ≥3 static checks justify a shared health surface (`nimbus doctor` + MCP tool over one check set).
- **DoD met:** `viaTrustedProxy()`; `DeploymentCheck`; Plugins-page banner (escaped, warn style); `DeploymentCheckTest` (7) + `TrustedProxyUrlTest` (2); docs (`.env.example`, COMPATIBILITY "Deployment behind a proxy", ROADMAP reworded); PHPStan L7; no change to any security-sensitive link (diff-provable).

### 2026-08-22 · OAuth SSO — Phase 1 [Core] · ADR 0012
- **Status:** accepted. Both skills before code (platform loop = detailed design review; [security loop](../../nimbus-security-review/references/security-ledger.md) = green-to-build with the listed controls). Dan: "this is huge — a detailed design for the reviewers," then "Build ADR 0012 + Phase 1 now."
- **Classification: Core** (auth is foundational-for-many; the plugin boundary deliberately excludes routes + auth hooks, so "SSO as a plugin" would force opening far riskier speculative surfaces for one consumer). Passes the drift guard: general admin auth, not a Restaurant/Food-Store/Packkit shape — would recommend absent all three.
- **Crux decisions (Q1–Q6):** (Q1) CORE subsystem, not a plugin. (Q2) **userinfo, not id_token JWT** — provenance is the TLS-verified token endpoint, so no JWT/JWKS library enters core (charter: avoid the hard problem rather than take a dep for it). (Q3) `nb_oauth_identities` keyed on the **immutable subject** `(provider, provider_user_id)`, `UNIQUE`, FK-cascade; **never email**. (Q4) smallest cut = subsystem + **sign-in-for-linked** + **explicit-link-from-settings**; invite-accept (P2), verified-email auto-link (P3), allow-list signup (P4) each deferred behind their own review. (Q5) ADR 0012 = yes (auth is a hard-to-reverse contract). (Q6) a 3-method `OAuthProvider` interface + Google/GitHub adapters is the right minimal seam.
- **Off by default:** a provider turns on only when BOTH its id and secret are set; no config → no buttons, password login is the only method. Password sign-in always stays available (additive, never replaces).
- **DoD met:** ADR 0012; migration 015; `OAuth\{OAuthProvider,OAuthIdentity,OAuthException,OAuthHttp,GoogleProvider,GitHubProvider,OAuthIdentityRepository,OAuthProviders,OAuthOutcome,OAuthResult,OAuthService}`; `Auth::login()`; `OAuthController` (public start/callback + authed disconnect); `Config::oauthProviders()`; login buttons + Settings "Connected accounts"; Application wiring + a `?OAuthProviders` test seam; `tests/Support/FakeOAuthProvider`; `OAuthFlowTest` (16, all controls); docs (README, COMPATIBILITY, `.env.example`); both ledgers. PHPStan L7, php-cs-fixer clean.
- **Lesson:** the whole flow depends only on the `OAuthProvider` interface, so a network-free fake drives the *real* kernel path (genuine `start`→session→`callback`) — every security control (state single-use, PKCE=S256(verifier), provider-binding, open-redirect, session rotation, link-bound-to-session-user, no-steal-on-UNIQUE) is a regression test with no mocking of the controller or session.

### 2026-08-15 · Boolean fields serialize as JSON booleans
- **Status:** accepted
- **Evidence:** PR (fix/boolean-toapi); `src/Content/FieldTypes/BooleanType.php`;
  `tests/Http/ApiRoutesTest.php`. Found live in Docker (a `featured` toggle
  rendered as "1" on the public site) while validating public rendering.
- **Product:** a toggle field is `true`/`false` for API clients and themes,
  not `1`/`0` — the starter theme's Yes/No branch now works.
- **Architecture:** `BooleanType` overrides `toApi()` (was inheriting the
  pass-through `BaseType::toApi`). Field-type edge, no core change.
- **Engineering:** wire change for boolean fields (int → bool); pre-1.0, noted
  in COMPATIBILITY. Covered by a new API test.
- **Revisit:** audit other field types for wire-shape correctness if a client
  reports a surprising value (none known now).

### 2026-08-15 · Capability D built: plugin admin pages
- **Status:** accepted (analytics milestone, slice D of A–D — capabilities complete)
- **Evidence:** PR (feat/plugin-admin-pages); `src/Admin/AdminPageRegistry.php`,
  `src/Plugin/AdminPageRegistrar.php`, `PluginContext::adminPages()`;
  `src/Admin/PluginPagesController.php`; `Admin\Controller` nav + `shell()`;
  `tests/Unit/AdminPageRegistryTest.php`, `tests/Http/PluginAdminPageTest.php`
- **Product:** a plugin registers a login-gated admin page rendered in the admin
  shell with a sidebar entry — the analytics dashboard's home.
- **Architecture:** registry + provider-scoped registrar + rollback, like the
  others. A slug (validated `[a-z0-9-]`) → `GET /admin/{slug}` under the auth
  group; the handler returns HTML (wrapped in the shell) or a Response
  (passthrough). Registered **last** among admin controllers so a plugin slug
  can't shadow a core route. Nav integration threaded the registry through the
  base `Controller` + the four admin controllers (optional arg — the bundle
  refactor kept this to defaulted params, no test churn beyond the constructors).
- **Engineering:** GET-only for v1 — POST/forms deferred (needs a CSRF-token
  exposure decision); plugin content is trusted HTML (escape-your-values, like
  head contributions); login-gated + shell-render + nav all kernel-tested.
- **Revisit:** admin POST/forms (CSRF token to plugins); public plugin routes
  (a beacon endpoint); per-page permission beyond "logged in".

### 2026-08-15 · Capability C built: scoped plugin storage (ADR 0005)
- **Status:** accepted (analytics milestone, slice C of A–D)
- **Evidence:** PR (feat/plugin-storage); `src/Plugin/PluginStorage.php`,
  `PluginContext::storage()`; `PluginCapabilities` carries the `Connection`;
  `tests/Integration/PluginStorageTest.php`
- **Product:** a plugin reads/writes the tables it created with its migrations —
  the runtime half of ADR 0005, what analytics' charts need.
- **Architecture:** a narrow parameterised interface (`select/selectOne/execute/
  insert/transaction`) — **not** the core `Connection`, not a repository. Built
  lazily from the kernel's connection; requires a DB (throws otherwise). Amended
  `PluginContext`'s "deliberately absent" note: **core** connection/tables/repos
  stay absent; a plugin may own and query its **own** tables.
- **Engineering (honest boundary):** "own tables only" is a **contract, not a
  sandbox** — an in-process PHP plugin has the whole runtime and could open its
  own connection anyway, so there is no enforcement here a determined plugin
  couldn't bypass. `PluginStorage` provides the *intended* path (parameterised,
  no core connection handed over) and the boundary docs/reviews hold plugins to.
- **Revisit:** core-*data* access (Tiers 1–3 in ADR 0005) remains a separate,
  later, operation-level contract — never raw core-table SQL.

### 2026-08-15 · Refactor: plugin capabilities bundled into PluginCapabilities
- **Cross-repo lesson (self-learning):** the bundle refactor revealed
  plugin-markdown's *tests* had been broken against nimbus dev-main since the
  head capability (#47) — a plugin's CI runs only on its own pushes, and the
  boundary test exercises plugin *production* code, not the plugin's suite. Guard
  later (scheduled plugin CI, or run plugin suites in the boundary job). Fixed
  both plugin repos as part of the refactor.
- **Status:** accepted
- **Evidence:** PR (refactor/plugin-capabilities-bundle); `src/Plugin/PluginCapabilities.php`;
  `PluginContext` + `PluginLoader::load` now take one value; `Application` composes it
- **Product:** none (pure refactor); no behaviour change.
- **Architecture:** the four capability registries (field types, head, events,
  migrations) were growing `PluginContext::__construct` and `PluginLoader::load`
  and every test that built them. Bundled into one `PluginCapabilities` value
  object (each registry `new`-defaulted, so a caller names only what it needs).
  Adding capability #5/#6 is now one field, not two signature changes. Done
  **before** C/D deliberately, to stop the churn.
- **Engineering:** plugins' *production* code is untouched (they receive
  `PluginContext`, whose methods are unchanged); only the internal loader
  signature changed, so the two plugin repos' package-integration **tests** need a
  one-line update (coordinated). The cross-repo boundary test exercises plugin
  production code through HTTP, so it stays green.

### 2026-08-15 · Capability B built: plugin-owned migrations
- **Status:** accepted (analytics milestone, slice B of A–D)
- **Evidence:** PR (feat/plugin-migrations); `src/Database/MigrationRegistry.php`,
  `src/Plugin/MigrationRegistrar.php`, `PluginContext::migrations()`;
  `Migrator` runs plugin migrations after core; `bin/nimbus` boots the app to
  collect them; `tests/Unit/MigrationRegistryTest.php`,
  `tests/Integration/PluginMigrationTest.php`
- **Product:** a plugin ships and evolves its own tables — the storage analytics
  (and forms/search/comments) need.
- **Architecture:** mirrors the capability pattern — shared `MigrationRegistry`,
  provider-scoped `MigrationRegistrar`, rollback via `forgetProvider`. Migration
  names are prefixed with the plugin id (globally unique in `nb_migrations`),
  run **after** core's (a plugin's tables may reference core's). The CLI boots the
  app (no DB touched in the constructor) so plugins declare migrations, then hands
  the registry to the Migrator. Per ADR 0005: own tables only, never core `nb_*`.
- **Engineering:** loader threads the registry as an **optional** 4th arg (keeps
  plugin package tests green); idempotent + integration-tested against a real DB.
- **Watch:** `PluginContext`/`load` arg count is climbing (5/4). A capabilities
  **bundle** should precede C/D to stop the churn (and coordinated plugin-test
  updates). Uninstall/table-drop deferred.

### 2026-08-15 · Capability A built: plugin event subscription + request.handled
- **Status:** accepted (analytics milestone, slice A of A–D)
- **Evidence:** PR (feat/plugin-events); `src/Plugin/EventRegistrar.php`,
  `PluginContext::events()`; `EventDispatcher` (provider tag + `forgetProvider`);
  `CoreEvents::REQUEST_HANDLED`; `Application::notifyHandled`;
  `tests/Unit/EventDispatcherTest.php`, `tests/Http/RequestHandledEventTest.php`
- **Product:** a plugin can subscribe to events, and there is a per-request
  `request.handled` event to subscribe to — what analytics needs to count hits.
- **Architecture:** mirrors the field-type/head pattern — a provider-scoped
  `EventRegistrar` (not the dispatcher), rollback via `EventDispatcher::forgetProvider`.
  Loader threads the dispatcher as an **optional** arg (keeps plugin-markdown /
  plugin-seo package tests green). `request.handled` has **distinct** semantics
  from the entry events (documented in CoreEvents): best-effort, post-response,
  **isolated** — a throwing listener is caught, never 500s a served page.
- **Engineering:** guarded by `hasListeners` so a plugin-free install pays
  nothing; fires for every request, listener filters on path.
- **Revisit:** async/buffered delivery if a listener gets heavy; a `request.handled`
  payload value object if the array shape proves awkward.

### 2026-08-15 · Analytics-portal milestone planned; plugins may own their storage (ADR 0005)
- **Status:** accepted (direction); capabilities designed/built in their own slices
- **Evidence:** [ADR 0005](../../../../docs/adr/0005-plugin-owned-storage.md);
  three-hat analysis this date; maintainer approval
- **Product:** a first-party analytics portal (admin dashboard + charts, first-party
  hit collection) plus third-party agent injection (GA/Fathom/Plausible). Broad,
  general-CMS need.
- **Architecture:** the portal forces **four** new core capabilities, sequenced,
  each minimal and separately reusable: (A) event subscription + a `request.handled`
  event; (B) plugin-owned migrations; (C) scoped storage/data-access to a plugin's
  **own** tables (ADR 0005 — amends the "no DB for plugins" boundary to "no
  **core** DB"); (D) plugin admin pages (route + nav + authed shell render). Agent
  injection reuses the existing head capability (2nd consumer). Charts are
  server-rendered SVG (no JS/asset capability). Core-*data* access is explicitly a
  **later, tiered, operation-level** contract (read model / services + scopes +
  audit — same substrate as MCP), never raw core-table SQL.
- **Engineering:** per-request hit recording must be cheap and skip admin/api/assets;
  privacy-safe (no PII/cookies first-party); plugin SQL via bound-param helpers;
  admin XSS/CSRF; migration ordering.
- **Sequence:** ADR 0005 (this) → plugin-analytics v0.1 (agents, now) → A → B → C
  → D → plugin-analytics v1.0 (portal).
- **Unlocks:** redirects-admin, search, forms, comments, webhooks, activity log,
  revisions — the "observe → store → show an admin view" class of plugins.
- **Revisit:** each capability's concrete contract in its slice; the Tier 1–3
  core-data-access model when a concrete plugin needs it.

### 2026-08-15 · Plugin capability #2: head contributions (ADR 0004)
- **Status:** accepted
- **Evidence:** [ADR 0004](../../../../docs/adr/0004-plugin-head-contributions.md);
  `src/Site/{HeadContributor,HeadContributorRegistry,PageContext}.php`,
  `src/Plugin/HeadRegistrar.php`, `PluginContext::head()`; `PluginLoader` wiring;
  `SiteController` integration; `tests/Unit/HeadContributorRegistryTest.php`,
  `tests/Http/HeadContributionTest.php`
- **Product:** plugins can add markup to a public page's `<head>` (structured
  data, extra meta) — the capability `plugin-seo` needs. First `PluginContext`
  capability beyond field types, added with a concrete consumer (ADR 0001 rule).
- **Architecture:** mirrors the field-type pattern exactly — shared
  `HeadContributorRegistry`, provider-scoped `HeadRegistrar`, rollback via
  `forgetProvider`. Contributor receives a **data-only** `PageContext` (the page's
  view-model), so the contract keeps refusing repositories/DB. Chose head
  contribution over a routes capability as the first extension: it needs no data
  access, where a feed would need routes **and** content-query at once.
- **Engineering:** render-time contributions are **isolated** — a throwing
  contributor is logged and skipped, never 500s a public page (a deliberate
  divergence from the loud, propagating event contract, justified by where it
  runs). `PageContext` is public API now (small, data-only, additive).
- **Revisit:** a **routes** capability (for RSS/Atom, OG-image endpoints) with its
  own consumer; folding SiteController's site-scoped deps into a value object if a
  6th constructor param appears.

### 2026-08-15 · SEO split: foundational meta/sitemap/robots in core, rich SEO a future plugin
- **Status:** accepted (all 3 core slices done: per-page meta PR #44, sitemap.xml PR #45, robots.txt)
- **Evidence:** PR (feat/seo-meta); `src/Site/SiteController.php` (`meta()`,
  `describe()`); `Config::siteDescription()`; `themes/starter/templates/layout.php`;
  `tests/Http/SiteRoutesTest.php`
- **Product:** every public page gets a title, meta description, canonical URL,
  and Open Graph tags — table-stakes for search and social. Description comes from
  an entry's `excerpt`/`summary`/`description` field, then the collection's
  description, then `config/site.php`'s `description`.
- **Architecture:** the charter lists SEO as an *official plugin*, but (a) the
  plugin system hosts only field types — no route or head hooks — and (b) meta,
  `sitemap.xml`, and `robots.txt` are rendering/crawlability **correctness** every
  site needs, not an optional add-on. So the **foundational** layer is core;
  the **opinionated** layer (JSON-LD, social-card images, RSS/Atom, meta-editing
  UI, per-template OG) is deferred to a future `plugin-seo`, which will concretely
  require — and thus justify — a plugin **routes** + **head-injection** capability
  (ADR 0001 discipline). Request path threaded to the render for the canonical.
- **Engineering:** description is stripped of tags, whitespace-flattened, and
  clipped to ~160 chars; `$meta` guarded in the layout so a template rendered
  without it still works.
- **Revisit:** `og:image` (needs a media-field convention + absolute URLs);
  the `plugin-seo` extension capabilities when that plugin is built.
- **`sitemap.xml`** lists home + browsable collection indexes + live entries
  (excludes `blocks`, single collections, drafts); **`robots.txt`** welcomes
  crawlers, disallows `/admin` + `/api`, and advertises the sitemap. Both
  registered before the `{collection}` catch-all.

### 2026-08-15 · Opt-in page caching at the kernel, flushed on content writes
- **Status:** accepted
- **Evidence:** PR (feat/page-cache); `src/Support/PageCache.php`,
  `Config::pageCacheTtl/pageCachePath`; `src/Application.php` (cache read/write +
  event flush); `tests/Unit/PageCacheTest.php`, `tests/Http/CacheRoutesTest.php`
- **Product:** rendered public pages can be cached for speed; off by default
  (`PAGE_CACHE_TTL=0`), opt-in with a positive TTL.
- **Architecture:** cached at the **kernel**, not in SiteController — GET requests
  whose path is not `/admin`, `/api`, or `/theme/assets`, storing only 200 HTML.
  Filesystem store under `storage/`, dependency-free. Invalidation is
  **event-driven** (flush on `entry.saved`/`entry.deleted`) plus the **TTL** as
  the safety net for time-based changes (a scheduled entry going live fires no
  write event) — neither alone suffices. Full-flush on write, not dependency
  tracking (simpler and safe). Injectable into Application for tests.
- **Engineering:** atomic write (temp file + rename); hashed keys can't escape the
  cache dir; only the `page` query varies a key; clock injectable so expiry is
  tested without sleeping. Default-off means existing behaviour is unchanged.
- **Revisit:** ETag/Last-Modified + conditional GET; per-collection or tag-based
  invalidation if full-flush churns under heavy write load; caching for logged-in
  previews (currently only anonymous public pages) — each on evidence.

### 2026-08-15 · Reusable blocks are live entries of a conventional `blocks` collection
- **Status:** accepted
- **Evidence:** PR (feat/blocks); `src/Site/SiteController.php` (`blocks()`,
  `renderPage()`); `themes/starter/templates/layout.php`; `tests/Http/SiteRoutesTest.php`
- **Product:** an editor defines a shared fragment once (an announcement, CTA,
  colophon) as an entry in the `blocks` collection; the theme renders it site-wide
  by slug (`$blocks['announcement']`). The starter shows it as an announcement bar.
- **Architecture:** **no new content concept** — blocks reuse collections/entries;
  the only new code loads the `blocks` collection's live entries into the theme
  view-model. Convention over config (handle `blocks`), consistent with the
  single-kind "Homepage" convention. Loaded **lazily** (a memoized `blocks()`
  threaded through `renderPage()`), so admin/API requests and pages with no
  `blocks` collection pay nothing — SiteController is constructed on every request
  for route registration, so eager loading would have taxed all of them.
- **Engineering:** only the live set is exposed (a draft block never renders);
  capped at MAX_BLOCKS; templates still receive data only (no service fetches a
  block). Labels/values escaped in the theme.
- **Revisit:** in-content block insertion (needs the rich-text/block editor);
  hiding `blocks` from public `/blocks` routes and sitemaps; configurable blocks
  collection handle — each on evidence.

### 2026-08-15 · Navigation menus via config/menus.php, rendered by the theme
- **Status:** accepted
- **Evidence:** PR (feat/menus); `config/menus.php`, `Config::menus()`;
  `SiteController` shared data; `themes/starter/templates/header.php`;
  `tests/Unit/ViewTest.php`, `tests/Http/SiteRoutesTest.php`
- **Product:** a site defines named navigation menus in one place; the starter
  header renders `main`. Every site type wants navigation.
- **Architecture:** config-driven (`config/menus.php`), consistent with
  `plugins`/`theme`/`site`. Menus flow through the theme's shared view-model as
  `$menus`; the theme renders — no menu logic in core beyond parse+validate.
  **Editor-managed menus (admin builder + storage) deferred** until evidence
  editors need it, not built speculatively.
- **Engineering:** `Config::menus()` drops malformed entries, so a config typo
  never reaches a template; labels/urls escaped in the theme.
- **Revisit:** active-item highlighting (needs the current path in the
  view-model); nested/child menus; an editor-facing menu builder — each on
  evidence.

### 2026-08-15 · Theme static assets served at /theme/assets, plus a Router catch-all
- **Status:** accepted
- **Evidence:** PR (feat/theme-assets); `src/Http/Route.php` (`{name*}` wildcard),
  `src/Http/Response.php` (`file()`), `src/Site/SiteController.php` (`asset()`);
  `themes/starter/assets/app.css`; `tests/Unit/RouterTest.php`, `SiteRoutesTest.php`
- **Product:** themes ship real `.css`/`.js`/images/fonts under `assets/`, served
  at `/theme/assets/<path>`, instead of inlining everything. Starter dogfoods it
  (its CSS moved to `assets/app.css`), which also drops the public site's reliance
  on inline `<style>`.
- **Architecture:** needed a route that captures a nested path, so `Route` gained
  a `{name*}` wildcard (`.+`) — a small, general, reusable core addition with a
  concrete consumer, not speculative. `Response::file()` serves a typed body.
  Asset route registered first among the site routes (specific literal prefix).
- **Engineering:** the URL path is resolved with `realpath()` and confirmed to
  sit inside `assets/`, so `..`/absolute paths 404 (tested against the theme's own
  templates one level up). Extension allowlist → a theme's PHP is never served.
  Bodies pass through PHP (fine for modest theme files; a webserver can bypass in
  prod). `Cache-Control: public, max-age=3600`.
- **Revisit:** ETag/Last-Modified + conditional requests; asset fingerprinting;
  reading `theme.json` for real — each on evidence.

### 2026-08-15 · Theme capabilities: partials, per-collection specialization, themed 404
- **Status:** accepted
- **Evidence:** PR (feat/theme-capabilities); `src/View/View.php`
  (`partial` injection, `exists()`, traversal-guarded `file()`);
  `src/Site/SiteController.php` (`specialize()`, themed `notFound()`);
  `themes/starter/*`; `tests/Unit/ViewTest.php`, `tests/Http/SiteRoutesTest.php`
- **Product:** themes stop being one monolithic file — a shared `header`/`footer`
  compose via `$partial`, a collection (or a home page) can have its own template,
  and a theme can brand its 404. Useful to every theme, tied to no app.
- **Architecture:** one specialization rule — `entry-{handle}` → `entry`,
  `collection-{handle}` → `collection` — subsumes the "home needs its own
  template" need without a special `home.php` concept. Helpers (`$partial`, `$e`)
  are injected into template scope; templates still receive no services. Theme
  path is injectable into `SiteController` for testing.
- **Engineering:** template names are restricted to `[A-Za-z0-9_-]`, so a name
  derived from a collection handle can never traverse out of the theme
  (`exists('../../etc/passwd')` is false — tested). Themed 404 falls back to a
  built-in page when the theme omits `404`.
- **Revisit:** static asset serving (`themes/{active}/assets/*`) is the **next**
  slice; nested template directories; reading `theme.json` for real (still
  decorative) — each on evidence.

### 2026-08-15 · Home page: designated via config/site.php, reusing the single kind
- **Status:** accepted
- **Evidence:** PR (feat/home-page); `config/site.php`, `Config::home()`;
  `SiteController::homePage()`; `tests/Http/SiteRoutesTest.php`
- **Product:** `/` renders a chosen collection — a `single`-kind Homepage shows
  its one live entry, a regular collection shows its index (a blog at the root).
  Every public-site shape (brochure, blog, docs, portfolio) is served.
- **Architecture:** **reused the existing `single` collection kind** (which the
  code already named "Homepage, Settings") instead of adding a `home` flag to
  collection options. A scalar `config/site.php['home']` models "a site has one
  home" correctly, needs no schema change/migration, and mirrors
  `config/theme.php`. Home handle is injected into `SiteController` (testable),
  resolved from `Config::home()` by the kernel. `/` moved from `Application` into
  `SiteController`, consolidating all public rendering.
- **Engineering:** the single entry is fetched with `findLiveBySlug(id,
  __singleton)`, so a draft home never leaks; unknown handle / unset / draft all
  fall through to an un-themed placeholder (never a 500). No new content concept.
- **Revisit:** designating a specific *entry* as home; an optional `home.php`
  theme template; broader `config/site.php` settings (meta, etc.) — each on
  evidence, not speculatively. Supersedes ADR 0003's "home deferred" decision.

### 2026-08-15 · Public rendering, first vertical slice (starter theme + site router)
- **Status:** accepted
- **Evidence:** PR (feat/public-rendering); `src/Site/SiteController.php`,
  `themes/starter/*`, `config/theme.php`, `Config::theme()/themePath()`;
  `tests/Http/SiteRoutesTest.php`
- **Product:** a Nimbus site renders its own live content — a collection's
  entries and a single entry — through a plain-PHP theme, no build step. Basic
  but real; the home page and richer theming are explicitly later.
- **Architecture:** themes are a directory of plain-PHP templates + `theme.json`
  under `themes/{name}/`, rendered by the existing `View`; a template gets the
  EntryView view-model + an escaping helper, never a service or the DB. Theme
  selected by `config/theme.php` (mirrors `config/plugins.php`). `SiteController`
  registered **last** so `{collection}` routes never shadow /admin or /api
  (first-match-wins; verified by test). Combined slices 2–4 because a theme with
  no router and a router with no theme each fail the integrated+verified gate.
- **Engineering:** live predicate reused (drafts/scheduled 404, indistinguishable
  from absent); output escape-by-default in templates (escaping test); 404 is a
  minimal un-themed page so a theme only owes two content templates; themes/ and
  config/ sit outside the phpstan/cs-fixer paths, like the admin theme already does.
- **Revisit:** designated home page (needs a home-collection mechanism); theme
  capabilities (asset pipeline, partial/template overrides, per-collection
  templates) — each added on concrete evidence, not speculatively.

### 2026-08-15 · Relation fields expand at the EntryView edge, live-only (F1 resolved)
- **Status:** accepted
- **Evidence:** PR (feat/relation-expansion); `src/Content/EntryView.php`,
  `RelationRepository::liveTargets()`; `tests/Http/ApiRoutesTest.php`; COMPATIBILITY.md
- **Product:** a headless client (or a theme) gets `{id,slug,title}` per linked
  entry in one request instead of bare ids — reusable across any frontend.
- **Architecture:** a second reference-expanding edge case alongside media, not a
  resolver abstraction — still two, so the "third → extract a capability" trigger
  is not yet met. Relation expansion bypasses `RelationType::toApi()` at the edge,
  exactly as media does. One live predicate reused (published, publish time due).
- **Engineering:** the JOIN filters to the live set, so a link to a draft /
  scheduled / archived entry leaks nothing — not even its existence. One query per
  relation field per entry (same N+1 shape as media; acceptable, revisit below).
- **Revisit:** a **third** reference-resolving field type, or list-view N+1 showing
  up under load → extract a batched reference-expansion capability then, not before.

### 2026-08-14 · Public rendering + theme contract accepted (ADR 0003)
- **Status:** accepted
- **Evidence:** [ADR 0003](../../../../docs/adr/0003-public-rendering-and-theme-contract.md), PR #31
- **Product:** a Nimbus site can render its own live content server-side with a
  plain-PHP theme and no build step — the last unbuilt production-readiness pillar.
- **Architecture:** theme = directory of plain-PHP templates + `theme.json`,
  rendered by the existing `View`; templates receive a data-only view-model +
  escaping helper (no services/DB/logic). One content shape for API and themes:
  `Nimbus\Api\EntrySerializer` → `Nimbus\Content\EntryView` (internal refactor,
  wire contract unchanged), folding in F1 relation expansion. Public router
  registered after and never shadowing `/admin` or `/api`. Theme chosen via
  `config/theme.php`, matching `config/plugins.php`. Home page (`/`) deferred
  until a collection can be designated home — collection/entry routes ship first.
- **Engineering:** only the live set renders; escape-by-default in templates;
  each slice (EntryView extract, `themes/starter/`, router, tests) its own PR.
- **Revisit:** a designated-home mechanism; theme capabilities beyond templates
  (assets pipeline, partial overrides) — each on concrete evidence.

### 2026-08-03 · MCP as an official companion, gated behind a scoped write API
- **Status:** ✅ accepted → **implemented** (currency-corrected 2026-08-24). The scoped write API + MCP control surface shipped: [ADR 0009](../../../../docs/adr/0009-mcp-control-surface.md) (MCP), [ADR 0006](../../../../docs/adr/0006-non-human-authentication.md) (non-human token principals/scopes), enforced token scopes, `McpServer`/`ContentToolset`/`SchemaToolset`, and `api.*` token-principal audit events — all covered by CI. The original "nothing implemented" wording below is the state *as of 2026-08-03* and is preserved for history.
- **Evidence:** review this date; grounding — authz lives in controllers not
  services (`EntryService`/`CollectionService` enforce none); API is read-only;
  `nb_api_tokens.abilities` exists but is **never enforced**
  (`ApiAuthMiddleware`).
- **Why general-purpose, not a pivot:** MCP is one client of a **scoped,
  authenticated write API**. That API benefits REST consumers, CLI, and automation
  equally — agents are not privileged. Nimbus must run identically with **zero**
  MCP installed. Rejected any framing where Nimbus depends on agents.
- **Core capability gaps MCP reveals** (all broadly useful, none MCP-specific):
  (1) **enforced token scopes** — activate the dead `abilities` column;
  (2) a **scoped write API** (`POST/PATCH/DELETE /api/v1/...`) calling the
  existing services, enforcing scope ∩ collection-permission;
  (3) **token→principal binding** so `Permissions` applies to token callers;
  (4) an **authenticated read** that can see drafts the principal may access
  (distinct from the public live-only read);
  (5) an **audit log** for authenticated writes (`nb_activity` is unused).
- **Ownership:** **separate official companion `NimbusCMS/mcp`**, talking to
  Nimbus over **HTTP** only. Not core (optional integration). Not an in-process
  plugin (MCP is a separate process; importing services would bypass the authz
  that lives at the HTTP boundary). "First-class" = maintained, CI'd,
  compatibility-tested — never mandatory.
- **Tools rejected:** any generic `execute` / `query` / `run` / `call`; arbitrary
  SQL / PHP / filesystem; session-cookie auth; MCP-specific fields in content
  models; schema-mutation (collection create/edit), `users:write`, and media
  upload in v1 (defer for stronger auth + audit).
- **Contracts that would become public:** the scoped write API and token-scope
  vocabulary; the MCP tool schemas (versioned with the package).
- **Assumptions to revisit after the first real agent integration:** create
  idempotency (slug auto-resolve duplicates on retry); update concurrency (no
  optimistic lock → lost updates); API rate limiting; whether scopes should be
  coarse (admin-only) or fine from day one.
- **Revisit trigger:** maintainer approval of the enabling write-API milestone.
  Capability evidence is **not** updated until an end-to-end test proves an agent
  operates Nimbus through public contracts only.

### 2026-08-03 · Charter governs; validation projects are acceptance tests
- **Status:** accepted
- **Evidence:** [`docs/CHARTER.md`](../../../../docs/CHARTER.md), PR #28
- **Product:** Nimbus stays a general CMS; Restaurant/Food Store/Packkit prove
  flexibility but do not own the roadmap.
- **Architecture:** classify every change (core/plugin/theme/app); capability
  added only on broad reuse; three-hat gate.
- **Engineering:** roadmap items gated; production readiness is the priority.
- **Revisit:** only via a charter change (maintainer-approved).

### 2026-08-03 · Media field expansion resolved at the serializer edge, like relations
- **Status:** accepted
- **Evidence:** PR #27; `src/Api/EntrySerializer.php`; `tests/Http/ApiRoutesTest.php`
- **Product:** clients get a media object (url/alt/dims) in one request.
- **Architecture:** field types stay pure; two edge special-cases (relation,
  media) rather than a resolver abstraction — no second consumer yet justifies one.
- **Engineering:** a dangling media id serializes to `null`, never a 500.
- **Revisit:** a **third** reference-resolving field type → extract a reference-
  expansion capability instead of a third special-case. (Ties to finding F1.)

### 2026-08-02 · Publication: cron-free scheduling; "scheduled" is derived, not stored
- **Status:** accepted
- **Evidence:** [ADR 0002](../../../../docs/adr/0002-publication-lifecycle.md); PRs #23, #24
- **Product:** draft / published / scheduled / archived without a scheduler to run.
- **Architecture:** one live predicate (`published AND published_at <= now`) used
  by admin badges and the API; stored status is three values, "scheduled" derived.
- **Engineering:** indexable, no background job, no state that lies about liveness.
- **Revisit:** if per-entry timezones or publish-time side effects (webhooks) are
  needed — those need a different, event-driven trigger.

### 2026-08-02 · Read API is read-only, serves only the live set, token-authed
- **Status:** accepted
- **Evidence:** PR #25; `src/Api/*`; `tests/Http/ApiRoutesTest.php`
- **Product:** any frontend can consume published content over HTTP+JSON.
- **Architecture:** the API is an HTTP contract (not a PHP public class surface);
  every value serialized via field `toApi()`; no writes over the API in this slice.
- **Engineering:** tokens stored as SHA-256; drafts/scheduled indistinguishable
  from absent (no leak); pagination hard-capped.
- **Revisit:** write endpoints, per-token scopes, CORS, ETags — each its own decision.

### 2026-07 · Plugin contract minimal; capabilities added one proven consumer at a time
- **Status:** accepted
- **Evidence:** [ADR 0001](../../../../docs/adr/0001-plugin-contract.md); PRs #14, #18, #19
- **Product:** third parties extend Nimbus via Composer packages.
- **Architecture:** `Plugin::register(PluginContext)`; `PluginContext` exposes only
  `fieldTypes()` today; loader is two-phase (validate/claim ids, then register with
  rollback); first-registration-wins; provider id bound by the loader.
- **Engineering:** a failing plugin is contained and rolled back, never partial;
  ids claimed on install so a disabled plugin can't have its id stolen.
- **Revisit:** add a `PluginContext` capability (routes/events/permissions/nav)
  **only** when an official plugin concretely needs it — see capability-evidence.md.

### 2026-07 · Plugin infrastructure is frozen
- **Status:** accepted
- **Evidence:** charter; three-hat review consensus
- **Product/Architecture/Engineering:** the loader/registry/lifecycle are done;
  further polishing is diminishing returns.
- **Revisit:** a concrete official plugin blocked by a missing, broadly-reusable
  extension point.

### 2026-08-15 · Analytics portal ships; four new plugin capabilities proven by one plugin
- **Status:** accepted (milestone complete)
- **Evidence:** [ADR 0005](../../../../docs/adr/0005-plugin-owned-storage.md);
  `nimbuscms/analytics` ([repo](https://github.com/NimbusCMS/plugin-analytics),
  CI green: 21 tests / 51 assertions on PHP 8.2 + 8.3); live Docker verification
  (migration created `analytics_hits`; page views recorded with external referrer
  captured and admin/bot/internal navigation filtered; `/admin/analytics`
  auth-gated); capability-evidence.md rows for events / admin pages / migrations /
  storage / admin navigation.
- **Product:** first-party, privacy-first analytics (path + referrer host +
  timestamp; no cookies/PII) with an admin dashboard, **plus** optional injection
  of a third-party agent (Plausible / Fathom / GA) via env — one plugin, two
  independent uses, both on the public contract.
- **Architecture:** to build it the plugin contract grew four capabilities, each
  added only because this concrete plugin needed it (ADR-0001 discipline) —
  **event subscription** (`request.handled`, best-effort/isolated),
  **plugin-owned migrations + storage** (ADR-0005: own tables only, a contract not
  a sandbox — in-process PHP can't be sandboxed), and **admin pages + nav**
  (GET-only for v1). Reused **head contributions** for the agent snippet.
  `PluginCapabilities` value object bundles the registries so `PluginContext`
  hands out capabilities, never the objects that implement them. Server-rendered
  SVG charts avoided introducing a JS/asset capability.
- **Engineering:** recording runs *after* the response (`request.handled` listener
  is isolated — a throwing listener is logged and skipped, never a 500); storage
  resolved lazily so `register()` runs no query and loads without a DB; dashboard
  rendering is pure and unit-tested without a database; all untrusted values
  escaped in both the dashboard and the agent snippet.
- **Lessons (self-learning):** plugin CI only runs on plugin pushes and the
  boundary test exercises only a plugin's *production* code through HTTP — a core
  change that breaks a plugin's *own tests* stays green until that plugin is
  touched (found via plugin-markdown's second test file). Each of the four new
  capabilities now has exactly **one** consumer: a strong first signal, **not**
  broad proof — do not widen or freeze any of them until a second, unrelated
  consumer appears (see capability-evidence.md "Next evidence required").
- **Revisit:** an admin **form** capability (needs a CSRF-token exposure decision);
  a second storage-owning plugin; the tiered core-data-access contract (Tier 1
  read via read-model, Tier 2 write via services + scopes + audit) when a plugin
  first needs core data — never raw core-table SQL.

### 2026-08-16 · Programmatic Access Hardening milestone complete (API tokens)
- **Status:** accepted (milestone complete)
- **Evidence:** [ADR 0006](../../../../docs/adr/0006-non-human-authentication.md);
  PRs #60 (ADR), #61 (lifecycle/principal), #62 (admin UI), #63 (scopes+matrix),
  #64 (error contract), #65 (rate limiting + CORS). 435 core tests green.
  Governed by the new [`nimbus-security-review`](../../nimbus-security-review/SKILL.md)
  companion skill, which ran on every slice.
- **Product:** the read API is safe for non-human clients — scoped, expirable,
  pausable, revocable tokens; a clean error contract; rate limiting + CORS. This
  is what a static frontend (e.g. on Cloudflare Pages) needs to consume Nimbus
  cross-origin. **Classify: Core** (API maturity) — passed the Platform Drift
  Guard: every headless CMS needs it, independent of any validation app.
- **Architecture:** API tokens are **standalone principals** with their own
  **per-collection `resource:action` scopes**, enforced deny-by-default at the
  query layer; a request-scoped `ApiAuthContext` carries the principal (Request
  stays immutable, no global singleton). Relation expansion respects scope
  (`EntryView` gained an optional `canRead` predicate; out-of-scope targets leak
  nothing, reusing the non-live-target semantics). Two fixed-window rate limiters
  (per-IP flood before auth, per-token quota after), DB-backed. Minimal CORS via
  an `Application::handle` decoration seam (the pipeline only pre-processes).
- **Engineering / security lessons (evidence-linked):**
  - *Scope before existence* (#63): checking scope before collection existence
    stops a narrow token enumerating collections by 403-vs-404. A design decision
    the security review surfaced, not a bug.
  - *Reload-resubmit* (#62): the show-once mint renders (can't redirect a secret),
    so a reload re-POSTed and minted a duplicate — found in **browser** verification,
    not the passing unit tests. Fixed with a single-use `FormNonce` (secret never
    touches session/URL). Lesson: adversarial "what does a reload do?" catches what
    happy-path tests miss.
  - *Ambiguous column* (#65): the `INSERT … AS new … ON DUPLICATE KEY UPDATE`
    row-alias makes a bare column reference ambiguous — qualify the existing row
    (`table.col`). Caught by a direct probe before CI.
  - *Local-env drift:* a stale `vendor/nimbuscms/analytics` (not in the lock) made
    a local-only test failure; `composer install` re-synced. Local should match a
    clean CI install.
- **Revisit / follow-ups:**
  - `nb_api_rate` rows don't self-expire — prune periodically (or a cache adapter).
  - Legacy null-ability tokens are compat-granted `*:read`; **remove that grant
    when the write API lands**.
  - **Failure events** (`api.token_rejected` / `api.access_denied`) were
    deliberately deferred to the `nimbuscms/api-advanced` plugin (their consumer,
    ADR-0001) — isolated + `hasListeners`-guarded, consumer must aggregate (a
    per-request failure event is a DoS amplifier). Building this next gives the
    events + storage capabilities their **second unrelated consumer** (broad proof).

### 2026-08-16 · api-advanced ships → four plugin capabilities broadly proven
- **Status:** accepted
- **Evidence:** core PR #67 (`api.token_rejected` / `api.access_denied` +
  `EventDispatcher::emitBestEffort`); [`nimbuscms/api-advanced`](https://github.com/NimbusCMS/plugin-api-advanced)
  (CI green on PHP 8.2 + 8.3; its `PackageIntegrationTest` loads the package
  through a real Composer install and registers its migration, **both** failure
  listeners, and its admin page).
- **Product:** an official **Advanced API** plugin — a home for programmatic
  "pro" features. First feature: a **security audit log** of API access failures
  (rejected tokens, scope denials), never storing a presented token. A CF-Pages
  frontend + this = a headless deployment an operator can actually monitor.
- **Architecture / the loop closes:** api-advanced is the **second unrelated
  consumer** of the plugin **events**, **storage**, **migrations**, and **admin
  pages** capabilities (after Analytics). All four move from "one consumer — a
  first signal" to **broadly proven** in capability-evidence.md. The failure
  events were emitted **with** their consumer (ADR-0001), not before — the same
  discipline as `request.handled` → Analytics.
- **Engineering:** events are best-effort + isolated (a throwing listener never
  500s) via the shared `emitBestEffort`; `api.token_rejected` fires only after the
  per-IP flood guard, so the rate limiter bounds the audit's write volume —
  the recorder need not aggregate for v1. Payloads carry no secret.
- **Revisit:** a retention/prune helper for plugin-owned tables (`api_audit_log`,
  `analytics_hits`, and core's `nb_api_rate` all accumulate); the other
  api-advanced features on the roadmap (webhooks, per-token analytics/quotas).

### 2026-08-16 · Maintenance capability + `nimbus prune` (table retention)
- **Status:** accepted
- **Evidence:** core PR #69; retention tasks in `nimbuscms/analytics` (PR #1) and
  `nimbuscms/api-advanced` (PR #1), both CI-green.
- **Product:** three tables accumulated with nothing pruning them (core
  `nb_api_rate`, plugin `analytics_hits` / `api_audit_log`). `nimbus prune` (cron)
  now cleans core's own rate rows and runs every plugin retention task.
- **Architecture:** a **seventh** `PluginContext` capability, `maintenance()` —
  and the first capability **born broadly proven**, shipped with two consumers at
  once. Same registry/registrar/bundle/rollback shape as the others; tasks are
  `callable():int` run only by the CLI (no scheduler in core yet).
- **Engineering:** while completing the rollback for the new capability, found the
  loader was **not** rolling back `adminPages` either — a plugin that registered an
  admin page then threw would leave it behind. Rollback is now complete (head,
  events, migrations, adminPages, maintenance, fieldTypes).
- **Revisit:** a scheduler (so `prune` and future tasks run without operator cron)
  is the natural next step, when a task needs it.

### 2026-08-17 · Write API milestone complete
- **Status:** accepted (milestone complete)
- **Evidence:** [ADR 0007](../../../../docs/adr/0007-write-api.md); PRs #72 (version
  + ETag), #73 (endpoints), #74 (`api.entry_written`), plus `api-advanced` PR #2
  (write audit). 457 core tests; a live curl run exercised the real `php://input`
  path. Each slice closed with a `nimbus-security-review` pass — no Critical/High.
- **Product:** the API can now create / update / delete content, not just read —
  a real headless CMS for integrations, CI, and (next) MCP.
- **Architecture — the guiding call that paid off:** the write API is a **new
  transport in front of `EntryService`, not a second write path.** The JSON body
  maps to `EntryInput` and goes through the same service the admin uses, so
  validation, slugs, the transaction, events, and the **allow-list field binding**
  (the mass-assignment guard) are reused, never reimplemented. A single
  `{handle}:write` scope, enforced deny-by-default, scope-before-existence.
  Optimistic concurrency via a monotonic entry `version` → `ETag`/`If-Match`
  (428/412). `api.entry_written` (best-effort) gives `api-advanced` a
  who-changed-what trail.
- **Security (the highest-risk surface, reviewed hardest):** mass-assignment is
  neutralised by the `EntryService` reuse (undeclared fields + top-level
  privileged keys ignored); no enumeration (403 before existence); lost updates
  blocked by mandatory `If-Match`. Accepted low notes: no `415` on non-JSON bodies
  (they read empty → `422`); API-created entries have a null author (a token is
  not a user — the token-level trail is the audit event).
- **Lessons:** the tests inject `rawBody` directly, so they never exercised
  `php://input` — a **live curl** did, and confirmed the real body path. Worth a
  live pass on any transport-layer change. The `api.entry_written` payload uses
  `collection`/`slug` while failure events use `resource`; the audit recorder maps
  both — a reminder to keep event payload keys consistent (a future cleanup).
- **Revisit:** finer `publish`/`delete` scopes; bulk writes; media upload over the
  API; idempotency keys — each on evidence. Next milestones: **OpenAPI**, then **MCP**.

### 2026-08-17 · OpenAPI milestone complete
- **Status:** accepted (milestone complete)
- **Evidence:** [ADR 0008](../../../../docs/adr/0008-openapi.md); PRs #77
  (`jsonSchema()`), #78 (`OpenApiGenerator`), + serving. 466 tests; live curl:
  `GET /api/v1/openapi.json` is 401 without a token, 200 with.
- **Product:** the API now has a machine-readable contract — Swagger UI, typed
  SDKs, and (next) MCP can consume it.
- **Architecture:** the spec is **generated from the live model**, not
  hand-written, so it can never lie about the shapes. Field types **describe
  their own JSON Schema** via a new `FieldType::jsonSchema()` (defaulted in
  `BaseType`, so no field-type or plugin broke — the Markdown plugin inherited the
  default). Served two ways: `GET /api/v1/openapi.json` behind the group's bearer
  auth (inside the rate-limited group), and a `nimbus openapi` CLI dump.
- **Security:** the endpoint is auth-gated and rate-limited. Accepted low: it
  serves the **full** model regardless of the token's scopes (a scope-filtered
  per-token spec is a documented later refinement — it would vary per caller and
  break caching).
- **Revisit:** a bundled Swagger UI page; a scope-filtered spec; OpenAPI 3.1.
  **Next:** MCP, deriving its tool list from this contract.

---

## Open findings (proposed — awaiting classification into work)

From the Restaurant **Menu** acceptance test (Menu itself needed zero core changes):

- **F1 — API returns relations/references as bare ids.** **Resolved** —
  relations now expand at the EntryView edge to `{id,slug,title}`, live-only.
  See the 2026-08-15 accepted entry above. Evidence: Restaurant
  `docs/PLATFORM-VALIDATION.md` F1.
- **F2 — no supported way to consume Nimbus from a separate app repo** (root-only,
  not on Packagist, no library mode/image). Classify **Core / release process**.
- **F3 — number decimals (`8.00`→`8`).** Classify **application concern** —
  rejected for core unless several apps want a shared money field type.

### 2026-08-17 · MCP Slice 1 — capability model + shared EntryOperations (Core)
- **Merged:** PR #81 (code), #82 (roadmap). CI-green, 469 tests, PHPStan L6.
- **Decision:** the scope-checked content path is one service (`EntryOperations`)
  that both HTTP and MCP call; `ApiController` is now a thin HTTP adapter. The
  extraction was behavior-preserving — the entire existing API suite passed
  unchanged, which is the evidence the shared path did not weaken authz,
  concurrency, mass-assignment binding, or auditing.
- **Capability model:** `admin` super-grant + granular management capabilities
  (schema/media/users/tokens/settings) as the atoms of a future roles system.
  They are inert until Slices 4–6 consume them.
- **Assumption corrected in review (not after):** management capabilities sharing
  the `resource:action` namespace let the content wildcard `*:write` transitively
  grant `users:write` etc. Fixed so `*:{action}` is collection-only; `admin` is
  the sole cross-cutting grant. Lesson: when a new privilege class joins an
  existing namespace, re-examine every wildcard/`*` rule that spans it. Recorded
  in the security ledger (scope confusion, catalog #2, 1st sighting).
- **Design note (validated later):** the shared-service extraction as the *first*
  MCP slice is paying off — Slice 2 adds only a JSON-RPC transport over an
  already-authz/concurrency/audit-complete service, not a second content path.

### 2026-08-18 · MCP Slice 4 — schema tools + the Toolset seam (Core)
- **Decision:** management tools plug in via a `Toolset` interface the `McpServer`
  aggregates (management-first ordering, so a fixed name like `create_collection`
  is claimed before a content verb could parse it). `ContentToolset` now
  implements it; `SchemaToolset` is the first management group. This is the seam
  Slices 5–6 extend without touching existing toolsets.
- **Decision:** schema tools reuse the admin's `CollectionService` (one write
  path); field-level tools read-current→mutate→re-sync (safe), with `set_fields`
  the deliberate full-replace power tool. Destructive `delete_collection` gated by
  `confirm:true` + surfaced entry count (Dan's call: a real need exists).
- **Decision:** added a `version` column to `nb_collections` now (bumped on every
  shape change), so a read-before-write guard on schema can land later without a
  migration then. Guard deferred; column tracks + is surfaced in `describe_collection`.
- **Lesson (self-learning):** the HTTP suite passes against a **migrated** DB and
  masked an ordering gap — a live smoke against the un-migrated dev DB 500'd on
  `add_field` because `repo->update` writes the new `version` column. `create`
  masked it (INSERT omits the column → default). Takeaway: any slice adding a
  column + code that writes it must be smoked against a freshly-migrated env, and
  the deploy release gate (ADR 0010) must run `migrate` before serving. No code
  defect; a process signal.

### 2026-08-18 · Slice 5a — media usage tracking as a CORE capability (Core)
- **Decision (user-driven):** "don't let users delete media that's in use." Built
  as a **core content-integrity capability**, NOT MCP logic — a reverse index
  (`nb_media_usage`, migration 009) synced by EntryService on save (mirroring
  relations), a `MediaUsage` query service, and a shared `MediaService::delete`
  guard that **blocks + pinpoints** (returns the referencing entries/fields). The
  admin, API and MCP all inherit it via the one delete path. Rationale: keeping the
  guard in a shared service (not the MCP tool) means the admin is protected too and
  honors "MCP adds no business rules".
- **Scope boundary:** "used" = referenced by a media **field** (structured,
  indexable). A raw media URL pasted in freetext is deliberately out of scope
  (unreliable to detect). Stated in the migration + guard.
- **Delete semantics (user's call):** block when in use, never silently orphan;
  the caller clears the reference first (via normal edit) then deletes. Structured
  usage is returned so a future explicit "detach optional fields + delete"
  convenience can consume it (a required media field can't be nulled — must be
  reassigned; noted for later).
- **Design note:** `media_id` is not an FK (dangling refs are legitimate and must
  not fail a save); entry/field FKs give the cascades that free media automatically.
- **Backfill:** `nimbus media:reindex` rebuilds the index for pre-existing content.
- **Ops lesson:** correcting an already-applied local migration means resetting it
  by hand — drop the table + `DELETE FROM nb_migrations WHERE migration='NNN.php'`
  (column is `migration`, not `name`); the test DB (`nimbus_test`) needs the
  root creds from tests/bootstrap.php, not the app user.

### 2026-08-18 · MCP Slice 5b — media tools (Core/MCP)
- **Decision:** `MediaToolset` on the shared seam (ordered Schema→Media→Content so
  `list_media`/`delete_media` are claimed before a content verb could parse
  them). Tools: `upload_media` (base64), `list_media`, `get_media`, `delete_media`
  (via the Slice-5a guard — block + pinpoint), and `media_usage` (read, so an
  agent can check before deleting). Gated `media:read`/`media:write`.
- **Upload:** base64 → temp file → the admin's MediaUploader with a **copy mover**
  (not move_uploaded_file). All validation reused (finfo sniff + allow-list + random
  name + size cap). get_media returns metadata + URL, not bytes (byte read-back
  deferred; the public URL already serves the file).
- **ToolResult gained an `extra` param** so a structured error (the in-use usage
  list) rides on the error object — used by delete_media's `in_use`.
- **Milestone note:** Slices 1–5 done — MCP is now a working control surface for
  content, schema and media over both transports. Remaining: users/tokens/settings
  (S6), management-audit recording + docs + final review (S7).

### 2026-08-18 · MCP Slice 6 — users + tokens; settings DEFERRED (Core/MCP)
- **Discovery:** `nb_settings` (key/value) exists but is **unused** — site config
  lives in `config/*.php` files. So there is no settings store to expose, and
  writing PHP config from an agent is the wrong approach. **`settings:write`
  deferred** to a future slice that first builds a real DB-backed settings store
  (activate `nb_settings`, migrate a few values out of `site.php`). The scope
  stays reserved. This is the review-loop working: don't build a tool for a store
  nothing reads.
- **Delivered:** `UsersToolset` (`users:write`: list/create/set_role) + a small new
  `UserRepository`; `TokensToolset` (`tokens:write`: list/mint/revoke/pause/resume).
  On the seam as [Schema, Media, Users, Tokens, Content].
- **Key control:** mint = subset-only (can't grant scopes you don't hold) — the
  RBAC substrate. Secrets/passwords are show-once (never persisted/audited/logged).
  `create_user` password optional → strong generated one returned once; roles
  validated; last-admin demotion refused. `delete_user` deferred (sharp; rarely
  agent-driven).
- **Reuse:** `Password::isWeak` extracted from the installer's rule (shared floor).
- **Milestone:** Slices 1–6 done — MCP reaches content, schema, media, users and
  tokens. Remaining: S7 (api-advanced records `api.management_written` + docs +
  final review) and the deferred settings-store slice.

### 2026-08-18 · MCP milestone CLOSE — final three-hat review (Core)
- **Product:** MCP is a general agent-control surface for *any* Nimbus site
  (content/schema/media/users/tokens), not shaped to Restaurant/Foodmart — the
  "MCP-native" differentiator. Passes the Platform Drift Guard.
- **Architecture:** Core. The `Toolset` seam + shared services (`EntryOperations`,
  `CollectionService`, `MediaService`/`MediaUploader`, `UserRepository`,
  `ApiTokenRepository`) mean MCP adds a transport + generated tool list, never
  business logic — one backend, two transports (HTTP + stdio). Capabilities are
  the RBAC substrate. No app-shape assumptions.
- **Engineering:** 511 core tests + the plugin's audit tests, PHPStan L6, a
  security-review per slice + this composition pass. Writes transactional; no N+1
  introduced.
- **Slices 1–7 done.** `settings:write` deferred to a future DB-backed settings
  store (nb_settings is unused; config is file-based). ADR 0009 → Implemented.
- **Enables:** agent-run CMS; the capability model bundles into named roles later.
- **Makes harder:** the largest privilege surface in the product — mitigated by
  deny-by-default caps, mint-subset-only, full audit, and per-slice review.

### 2026-08-18 · Roles Slice 1 — store + shared Authorizer + seed (Core)
- **Delivered:** `nb_roles` + `nb_user_roles` (migration 010); `Role`/`RoleRepository`;
  a shared `Authorizer` (extracted from `TokenPrincipal::can()`, now delegated) used
  by a new `UserPrincipal` whose capabilities are the **union** of its roles;
  `RoleSeeder` (system roles admin/editor/author, folding collection manage-lists
  into caps, assigning users) run by `install` + `nimbus roles:seed`.
- **Compat bridge:** enforcement is UNCHANGED (Slice 3 flips it). The seed is
  behavior-preserving — editor/author get `*:read` + their granted `{handle}:write`,
  each user their current role → union == today's access. Verified on dev.
- **Decision realized:** `{handle}:write` implies `{handle}:read` for content (in
  the Authorizer); `roles` added to the management set (subset-only later).
- **Tooling:** composer `process-timeout` bumped to 900 (suite > 5 min; CI runs
  phpunit directly so was unaffected).
- **Next:** Slice 2 (roles admin UI + user assignment + users page).

### 2026-08-19 · Roles Slice 2 — roles + users admin UI (Core)
- **Delivered:** `RolesController` (`/admin/roles`, admin-only nav) — CRUD roles as
  a grouped capability checklist (Full admin / Content per-collection read+manage /
  Administration), built-in roles protected; `UsersController` (`/admin/users`,
  fills the pre-existing dead nav slot — removed the AdminController stub) — create
  users (email/name/password + roles) and assign roles, last-admin guard. Reuses
  `RoleRepository`/`UserRepository`/`Password::isWeak`.
- **Scope call:** the collection-creation "grant manage to: [roles]" shortcut was
  MOVED to Slice 3 (it's coupled to the enforcement flip + `managerRoles` retirement;
  building it now would mean dual-writing during the transition).
- **Still enforcement-inert:** both pages gate on `requireAdmin` (legacy); assigned
  roles/edited caps are not yet the enforcement source (Slice 3 flips it). Safe:
  under-grants until then.
- **Verified:** 531 tests (9 new) + live browser check of both pages.
- **Next:** Slice 3 (the risky heart) — migrate `Permissions`/`requireAdmin`/
  `canManage` to `can()` over the user's roles; retire `managerRoles`; add the
  collection "grant to roles" shortcut; require the seed has run.

### 2026-08-19 · Roles Slice 3 — the enforcement flip (Core) + both review loops
- **Delivered:** admin authorization moved to capabilities via a per-request
  `Gate` (lazy user resolution via `Auth`, memoized). `requireAdmin→requireCan`
  (schema/tokens/users/roles:write; plugins→admin); `canManage→Gate::manages`;
  nav gated per-cap; `Permissions::canView` deleted; collection form's dead
  managerRoles picker replaced by a hint to the Roles page (`managerRoles` dormant).
- **Both review skills materially improved the design pre-build:**
  - review-loop caught that `canView` is dead code → do NOT newly read-gate the
    collections list (would silently tighten); and replaced my leaky per-user
    fallback with an **all-or-nothing legacy fallback** (un-seeded → legacy
    Permissions verbatim), which also lets the existing suite pass in legacy mode.
  - security-review escalated **A2 (assignment subset-only)** from a design
    question to a **required High control**, and required the authorization-matrix
    test. Media-gating deferred as a tracked Medium.
- **Behavior-preserving proof:** full suite green (538). Test helpers updated
  (actingAs assigns the system role; makeCollection grants role caps) so seeded-mode
  tests reflect the real model; a dedicated un-seeded-fallback test covers legacy.
- **Lesson:** an enforcement *model flip* is behavior-preserving at the *production*
  layer (via the seed + fallback), but the test suite's authz *setup* must migrate
  — the fallback made that far cheaper than feared.
- **Next:** Slice 4 (roles for tokens) + the media-gating fast-follow.

### 2026-08-20 · Roles Slice 4 — roles for tokens (LIVE reference) [Core]
- **Classification:** Core (the payoff of the ADR 0009 capability model: one
  authority vocabulary now spans users *and* tokens). Reviewed via both skills
  pre-build; both endorsed **LIVE** over SNAPSHOT.
- **Delivered:** a token can be minted **bound to a role**; its authority is the
  role's *current* capabilities, unioned with any explicit abilities, resolved in
  **one place** — `ApiTokenRepository::principalFor()` — called by both the HTTP
  middleware and the `nimbus mcp` stdio path (DRY across transports). Migration
  011 adds `role_id` (FK ON DELETE SET NULL). Surfaces this slice: CLI
  `token:create --role`, MCP `mint_token` role param, role shown in listings;
  admin token-form dropdown deferred (Slice 4b).
- **LIVE vs SNAPSHOT (the load-bearing call):** LIVE chosen — tightening/deleting a
  role reaches its tokens immediately (central partial revocation), consistent with
  how roles already work for users. Its extra cost (a schema column + a resolution
  point + the deleted-role edge) is paid down by keeping *stored* abilities the
  explicit set and computing *effective* caps only at the one boundary. SNAPSHOT
  would have frozen caps at mint and forced per-token revocation on every role edit.
- **Security-required simplification:** removing the legacy empty→`['*:read']`
  grant is what makes deleted-role fail *safe* (deny, not read-all) — the review
  turned a compat cleanup into a correctness requirement. See security-ledger
  2026-08-20.
- **Coupling check:** `ApiTokenRepository`→`RoleRepository` is acceptable — token
  resolution *is* an authorization concern; the alternative (a snapshot) only moves
  the coupling to mint time and loses the live property.
- **Evidence:** full suite (548) green; PHPStan clean; `docs/COMPATIBILITY.md`
  documents the null-ability behavior change.
- **Next:** Slice 4b (admin token-form role dropdown) + Slice 3b (media gating) +
  Slice 5 (docs/ROLES.md + final security review).

### 2026-08-20 · Roles Slice 3b — gate admin media + first data migration [Core]
- **Classification:** Core (authorization enforcement on a core admin surface + a
  system-role seed). Behavior-preserving. Reviewed via both skills pre-build.
- **Delivered:** `/admin/media` moved from auth-only to `media:read`/`media:write`
  (per-action), matching the MCP media tools; nav + dashboard media card gated;
  editor/author keep media via `RoleSeeder::CONTENT_MEDIA_CAPS` (fresh) + migration
  012 (existing installs). PR #100 (4bd2eeb), 562 tests green.
- **New pattern — the first DATA migration.** Precedent set and bounded: additive
  JSON union on **system** roles only, `JSON_CONTAINS`-guarded (idempotent),
  `is_system=1 AND name IN(...)`, never removes, runs once. The "runs once
  (tracked in nb_migrations)" property is what makes an additive backfill safe
  against a later admin edit — a reusable rule for future behavior-preserving
  backfills.
- **Lesson reinforced (2nd time, after Slice 3):** a behavior-preserving
  enforcement change must migrate the **test helpers** in lockstep — `actingAs`
  AND `makeCollection` base maps both had to gain the media caps, and their
  ordering (which creates the role first) mattered. Test-setup drift is the
  recurring cost of these flips; a shared source (`CONTENT_MEDIA_CAPS`) + a parity
  test is the mitigation.
- **Key correctness fact captured:** management caps have **no** read↔write
  implication (only content does), so a media surface needs BOTH `media:read` and
  `media:write` seeded — seeding only write would silently break listing.
- **Deferred (unchanged):** Slice 4b-UI (token-form role dropdown, rides the admin
  redesign), Slice 5 (authz-matrix docs + `docs/ROLES.md` — the milestone closer).
  Noted a pre-existing minor issue out of scope: the dashboard **Users** card is a
  dead link for a non-`users:write` user (same class as the media card, not fixed
  here).

### 2026-08-20 · Roles milestone CLOSED (Slice 5) [Docs + closer]
- **Classification:** Tooling/Docs (secondary: a trivial Core dashboard fix). PR
  #102 (9b291e1). Milestone marked done in ROADMAP.
- **Delivered:** `docs/ROLES.md` (guide + authorization matrix pointing at the
  tests as source of truth), README + COMPATIBILITY roles notes, ADR 0011 →
  Implemented, a final holistic security sweep (no open High), the
  management-boundary test extended to `roles`, and the dashboard Users-card
  dead-link fix. 563 tests green.
- **Stability call (Q1):** promise the **model and guarantees** (deny-by-default,
  the management boundary, subset-only, undeletable system roles) but mark the
  capability **names + role schema evolving until 1.0** — same framing as the MCP
  tool set. A closer documents; it does not freeze.
- **Matrix as docs (Q3):** the tests ARE the authorization matrix; the doc is a
  readable VIEW that links them. No generated-doc mechanism, no second asserter —
  seeded-cap claims are already guarded by RoleSeederTest, enforcement by
  RolesEnforcementTest. Reusable rule: a doc that restates a security guarantee
  must cite the test that enforces it.
- **Milestone retrospective (evidence-linked):**
  - The **one shared `Authorizer`** (Slice 1) paid off across every later slice —
    users, tokens, admin, MCP, CLI all judge by it; the management short-circuit
    is the single invariant that keeps content-only actors out of management.
  - **Subset-only** generalized cleanly from one mint control (ADR 0009) to five
    surfaces via one predicate shape (`firstUnheld`/`holds`) — the escalation-at-
    mint threat-catalog entry (promoted after Slice 4/4b) is now standing.
  - Recurring cost, seen 3× (Slices 3, 3b, and the 4b security fix): a
    behavior-preserving enforcement flip must migrate the **test helpers** in
    lockstep. Mitigation adopted: shared constants (`CONTENT_MEDIA_CAPS`) + parity
    tests + the un-seeded legacy fallback that let the suite pass mid-migration.
  - The **first data migration** (012) established a safe pattern: additive,
    idempotent, scoped, once-only.
- **Deferred/carried:** Slice 4b-UI → Admin Experience initiative; future
  `settings:write` gate; plugin admin-page self-gating (Low, plugin boundary).

### 2026-08-20 · Admin Experience — drift-guard + Increment 1 [Tooling / Theme]
- **Initiative:** turn Fable's `docs/design/admin-experience.md` into a uniquely-Nimbus, themeable admin. Drift-guard passed: a themeable admin + per-user theme preference is a general-CMS good (every install benefits), opt-out-able via the token layer, admin-only, no framework/asset-pipeline. Classification per part: token refactor = **Tooling**; signatures + the four themes = **Theme**; the theme-system mechanism + picker = small **Core** (+ a security surface at the picker's write).
- **Increment 1 delivered** (PR #104, commit ad09d14): `theme.css` refactored onto the `--nb-*` token set (each default = the current literal → no *unintended* visual change), the substrate the theme system needs. Four confirmed defects fixed (undefined `var(--nb-border)` with no fallback; duplicate conflicting `.nb-check`; used-but-undefined `.nb-link`; phantom Inter/Sora font vars) + a11y (`:focus-visible`, `prefers-reduced-motion`, `aria-current`). Zero security surface. 563 tests green; verified live across login/dashboard/tokens/collections.
- **Review calls that held:**
  - "Zero visual change" was corrected to "no *unintended* change + N named, verified deltas" — the honest framing that makes a screenshot-diff a real gate, not a rubber stamp. Standing lesson for re-skin work.
  - Sequencing: pure-CSS increments 1–2 carry no security surface; the picker's `nb_users.theme` write (Increment 3) is gated behind `nimbus-security-review`. Don't security-review a CSS refactor; do review the write path.
  - Budget honesty: Fable's "weight-neutral ≤18 KB base" estimate was optimistic — the expanded token set lands the base at ~19 KB. Kept the substrate tokens and corrected the budget comment; total-with-themes still ≤24 KB (the binding ceiling). Lesson: verify a design's stated weight against the actual bytes, don't inherit the estimate.
- **Live-DB gotcha recurred:** the dev DB on :8080 was two migrations behind (011/012 unrun) → a `role_id` PDOException on the tokens page that looked like a regression but wasn't. `nimbus migrate` on the live dev DB before smoke-testing, every time a slice adds a column.
- **Next:** Increment 2 (signatures, pure CSS), then Increment 3 (theme system + picker, security-gated) which also lands Slice 4b-UI.

### 2026-08-20 · Admin Experience — Increment 2 (signatures) [Theme]
- **Delivered** (PR #106, commit 55ead28): the five signature elements (Sky glow + twinkle, Charm Line, constellation empty states, the token Reveal, the "summoned in N ms" Whisper). Pure CSS + three template tweaks (`NB_START` in `public/index.php`, the Whisper in `layout.php`, the Reveal copy in `tokens/index.php`). No security surface — verified inline (no auth/write/SQL/user-input). 563 tests green.
- **Review posture:** the initiative-level drift-guard already blessed Increment 2 specifically (pure-CSS Theme, security deferred to Increment 3), so this was execution, not a new design decision — built directly rather than re-running the skill (signal-not-ceremony). Security-review correctly N/A here.
- **Verified LIVE** (browser): Charm Line under every h1, horizon glow + Whisper on the dashboard, the constellation on the empty media panel, and a real token mint showing the gold-washed Reveal. Screenshots are the gate for presentation work.
- **Guard that held:** both new animations are compositor-only and dead under `prefers-reduced-motion` via Increment 1's global guard — no per-animation reduced-motion code needed.
- **Budget watch:** base CSS now 21.4 KB (< 24 KB ceiling). The three additional theme blocks (Nocturne/Daybreak/Grimoire; Nimbus is the base :root, not a block) must stay tight (~1.1 KB each) to hold 24 KB — will trim base comments in Increment 3 if the real total needs it.
- **Next:** Increment 3 (theme system + Nocturne + the Settings picker) — the first security-gated increment (the `nb_users.theme` write), and it carries Slice 4b-UI.

### 2026-08-20 · Admin Experience — mobile-first revision + M1 [Theme]
- **Trigger:** a mid-build mobile audit (Dan asked before starting the theme system) found the admin was desktop-first with one breakpoint; three list tables (roles/users/tokens) rendered bare → page-level horizontal scroll on phones. Dan's call: **mobile is a first-class user** — now a codified standing check (PR #108).
- **Design:** Fable revised `docs/design/admin-experience.md` in place (new §1.6 Responsive: strategy, the nav-drawer centerpiece, the two-tier table fix, forms/touch, signatures on mobile, a byte-budget ledger, staged M1–M4). Drift-reviewed and blessed with corrections.
- **Review calls that held (evidence for future mobile work):**
  - **Desktop-default CSS + two `max-width` blocks**, not a min-width rewrite of 350 live lines — the honest architecture; mobile-first is the *design doctrine*, not a mechanical mandate.
  - **CSS-only checkbox-hack drawer** (the sidebar slid off-canvas, Sky intact) is the right default over a JS `button+aria-expanded`; the "Menu, checkbox" SR announcement + no-focus-trap + no-scroll-lock are acceptable lightweight trades, with the JS fallback kept documented as a one-step escalation — don't gold-plate a hypothetical.
  - **Byte ceiling is a guardrail, not a religion:** do zero-loss cuts first (comment diet, drop back-compat aliases), and **raise the 24 KB ceiling a hair (still ~5 KB gzipped) before cutting M4** (a real UX win). Reordered Fable's ledger accordingly.
  - **Tier-1 (wrap all) is the mandatory floor; Tier-2 stacked cards (M4) is cuttable** — ship the floor first, don't block on the upgrade.
  - **MCP standing check = N/A** for the M-track (pure admin-chrome presentation, no new back-end capability); the **mobile** check is the one in force and this design satisfies it. Security-review N/A for M1–M4 (no auth/write/SQL/untrusted input; the drawer JS takes no input; `data-label` is `$e()`-escaped) — but Increment 3's theme picker (`nb_users.theme` write) still needs it.
- **M1 delivered** (PR #109, commit bcaaa7a): wrapped roles/users/tokens tables. Templates only, byte-identical CSS, no security surface. **Verified live at 375px AND 320px** — `overflowByPx = 0`, table scrolls in-panel. Shipped ahead of the theme system (highest value-to-risk in the initiative).
- **New standing rule (from §1.6.4):** a bare `.nb-table` in a template is a bug.
- **Next:** M2 (forms/touch/spacing CSS), M3 (drawer), M4 (stacked cards) — interleave with theme Increments 3–4.

### 2026-08-20 · Admin Experience — mobile M2 + M3 [Theme]
- **M2** (PR #111, pure CSS): grid collapse, 16px inputs (iOS focus-zoom guard), ≥44px touch targets, stepped spacing, page-head wrap. `theme.css` 21,442 → 22,909 B. Verified live 375/320px.
- **M3 — the nav drawer** (PR #112, commit 1c79f90): the phone-native win. Replaced the clipping horizontal rail with a **CSS-only off-canvas drawer** — the sidebar slides in via a checkbox-hack (hidden `<input>` + `<label>` hamburger + `<label>` scrim), the whole Sky + Whisper riding along; a 6-line Escape/focus-return script; legacy rail deleted. Verified live: open → transform none + scrim + Whisper; closed → off-canvas + `visibility:hidden` (no ghost tab stops); overflowByPx 0. No security surface (no user data in the markup; the script takes no input). 563 tests green.
- **Budget lesson (evidence for the theme increments):** the drawer hit the 24 KB ceiling (24,543 B), so the review's recovery order fired **now, not later**: cheap zero-loss cuts first — **dropped the two back-compat aliases** (`--nb-night`/`--nb-shadow`, unused since Increment 1 repointed usages) + a **comment diet** — landing 23,347 B with real headroom. The pattern: when a CSS increment bumps the ceiling, run the zero-loss levers immediately; state `wc -c` on every CSS PR. The theme blocks (Increment 3–4) still need tightening or a small ceiling raise — comment-diet + aliases alone won't hold 3 full theme blocks; prefer a hair-higher ceiling (imperceptible gzipped) over cutting M4.
- **Live-verification technique:** the browser JS console (`getComputedStyle` on `.nb-side` transform/visibility, `matchMedia`, `overflowByPx`) is the reliable gate for a CSS-only interaction like the drawer — but read AFTER the transition settles (a mid-transition read shows the interpolated value and misled once).
- **Next:** M4 (stacked cards, cuttable) + the theme system (Increment 3, security-gated for the picker write).

### 2026-08-20 · Admin Experience — mobile M4 + M-track COMPLETE [Theme]
- **M4** (PR #114, commit bdfa984): stacked-card reflow for `entries` + `tokens` (`.nb-stack` + `td[data-label]::before`), scoped to the two real phone jobs; the other four tables keep the M1 scroll-wrap. `data-label`s are `$e()`-escaped → no security surface. `display:block` strips table semantics at mobile only (the correct linear label→value SR read). `theme.css` 23,347 → 24,100 B. Verified live 375px. 563 green.
- **The mobile-hardening M-track is COMPLETE** (M1 wrap tables → M2 forms/touch/spacing → M3 the drawer → M4 stacked cards). The admin is phone-native: no page-level horizontal scroll at 320px anywhere, a real off-canvas nav drawer that keeps the Sky identity, collapsing forms, ≥44px targets, and card-reflowed tables for the two phone-critical lists.
- **Milestone retrospective (evidence for future mobile work):**
  - The audit-driven pivot worked: the maintainer's instinct ("check mobile, especially tables") found real breakage a desktop-first design had shipped; codifying the **mobile standing check** (PR #108) makes it structural, not luck.
  - **Fable revising the existing spec in place** (vs a new doc) kept one source of truth and let the existing `theme.css §2` references stay valid — good pattern for design updates.
  - The **CSS-only checkbox drawer** delivered a first-class mobile nav with 6 lines of JS and no framework — evidence that "lightweight" and "good mobile UX" aren't in tension when the design reuses existing structure (the drawer IS the sidebar).
  - **Byte budget:** the whole mobile track (M1–M4) net **+658 B** on `theme.css` (21,442 → 24,100) after the M3 alias-drop + comment-diet recovery — the two ambient signatures and the drawer fit inside the envelope. The theme increments are where the ceiling is genuinely decided.
- **Next:** the theme system (Increment 3 — Nocturne + the Settings picker, security-gated for the `nb_users.theme` write; carries Slice 4b-UI) then Increment 4 (Daybreak + Grimoire). The stacked-card CSS is the designated cut if the theme blocks force the 24 KB ceiling.

### 2026-08-20 · Admin Experience — Increment 3 (theme system + Nocturne) [Core + Theme]
- **Delivered** (PR #116, commit 8cf6626): the token-only theme system (`AdminTheme` allow-list, `data-theme` render wiring), Nocturne (dark mode), and the Settings swatch picker with a security-reviewed self-only `nb_users.theme` write. Removed the dead settings stub + `stub.php`. 571 tests green. Verified live incl. Nocturne on the mobile drawer.
- **Classification:** Core (small — a persisted per-user preference + a new controller/write path) with a Theme component (Nocturne palette). Dedicated `SettingsController` (not folded into AdminController) — matches the one-controller-per-section pattern; Settings will grow.
- **Byte-ceiling decision (maintainer-relevant):** raised the documented ceiling **24 → 28 KB**. The original 24 KB was an *estimate* from before the ~60-token layer; four full themes legitimately need the bytes; inlined CSS gzips to ~5.5 KB with zero extra requests, so the charter's "fast/lightweight" (about architecture: no framework/build/webfonts/requests) is untouched. A design-doc figure, not a principle — surfaced in the review + PR, not changed silently. Lesson: a stated byte *budget* is a guardrail to force economy; when an estimate is proven obsolete by real, justified content, correct the figure openly rather than cutting UX to defend it.
- **The MCP standing check's first real test:** correctly classified a **per-user theme preference as N/A** (presentation preference, not a management capability) — validating the codified exemption. The distinct future *site-settings* store WILL be MCP-relevant.
- **Token-only theming proved out end-to-end:** Nocturne is a pure `[data-theme]` token override; the component CSS, the mobile drawer, the stacked cards, and the signatures all inherited dark mode with zero per-theme selectors — the discipline (§2.2) held. This is the evidence that the four-theme plan is cheap.
- **Next:** Increment 4 (Daybreak + Grimoire + the 8-line preview JS) — no new mechanism, just two more token blocks + swatch chips. Slice 4b-UI separately (its own security surface).

### 2026-08-20 · Admin Experience — Increment 4 (Daybreak + Grimoire); theme track COMPLETE [Theme]
- **Delivered** (PR #118, commit a3ffda2): the last two themes (Daybreak — dawn blue/sun-gold, light; Grimoire — bottle-green/parchment/brass, warm), the swatch chips, and the 8-line instant-preview JS. Pure token blocks + a progressive enhancement; no new mechanism, no new write path/security surface. `SettingsThemeTest` now selects+renders all four. 572 tests green. All four verified live on the dashboard.
- **Theme track COMPLETE:** Inc 1 (token layer) → Inc 2 (signatures) → Inc 3 (theme system + Nocturne + picker) → Inc 4 (Daybreak + Grimoire). The admin ships four selectable, per-user themes on one component layer.
- **Byte budget — final resolution:** the ceiling moved 24 → 28 → 30 KB across the theme increments as the true cost of the ~60-token layer + four full themes became known; now **settled at 30 KB** for the complete set (a fifth theme must prove its bytes). `theme.css` 29,293 B (~6 KB gzipped, zero requests). **Lesson:** a byte *estimate* made before the token layer existed was wrong twice; the honest move each time was cheap-cuts-first (two comment diets, dropped aliases) then correct the figure openly — never cut a shipped feature or a theme to defend a guessed line. The charter's "lightweight" is architecture (no framework/build/webfonts/requests), which held throughout.
- **Token-only theming — proven at full scale:** four maximally-different palettes (two light, one dark, one warm) all ride the *same* component CSS, drawer, stacked cards, and signatures with **zero per-theme selectors** (the one bounded exception: picker swatch chips). §2.2's discipline delivered exactly what it promised — this is the capability-evidence that the theme mechanism is cheap and extensible.
- **Next:** Slice 4b-UI (token-form role dropdown) is the last open initiative item — separate, small, its own security surface, now buildable in the finished design language.

### 2026-08-20 · Roles Slice 4b-UI + Admin-Experience initiative COMPLETE [Core + initiative wrap]
- **Slice 4b-UI** (PR #120, commit e0918e4): the admin token-form role dropdown — mint a role-bound token from the web form, grantable-roles filtered, server-side subset-only over the full role cap set (union guarded on both scope + role paths), the bound role shown in the list. Security-green (7 tests). 579 tests green. Verified live in Nocturne. **Completes ADR 0011 roles-for-tokens across admin + CLI + MCP.**
- **Deferred-then-built cleanly:** 4b was split at design time into 4b-security (the load-bearing control, shipped immediately) and 4b-UI (the visuals, deferred to the finished design language). Building the UI last — after the token layer, signatures, mobile, and themes existed — meant it landed once, in the real design system, with the security control already in place and tested. Evidence that "split the security fix from the UI, ship the fix first" is a good pattern.
- **ADMIN-EXPERIENCE INITIATIVE COMPLETE.** Theme track (Inc 1 tokens → Inc 2 signatures → Inc 3 theme system + Nocturne → Inc 4 Daybreak + Grimoire) + Mobile track (M1 tables → M2 forms → M3 drawer → M4 stacked cards) + 4b-UI. The admin went from generic chrome to a uniquely-Nimbus, four-theme, phone-native experience — every slice through both skills, verified live, lightweight (≤30 KB inlined CSS, no framework/build/webfonts).
- **What the initiative proved (capability evidence):** token-only theming scales to four maximally-different palettes with zero per-theme selectors; a CSS-only drawer gives first-class mobile nav with ~6 lines of JS; "mobile is a first-class user" and "MCP-friendly" are now codified standing checks that will guard every future slice.
- **Remaining Nimbus threads (unrelated to this initiative):** the settings-store slice (site `settings:write` + MCP tool), plugin admin-page self-gating (Low), and the deferred "Auto"/"Owl" themes — all noted, none blocking.

### 2026-08-22 · Post-initiative small follow-ups: plugin gating + Auto/Owl themes + docs [Core + Theme + Docs]
- **Docs (PR #122):** README announces the **alpha (0.x)** stage + current feature set (roles, MCP, six-theme mobile-native admin), honest caveats (no tagged release / upgrade path / password reset).
- **Plugin admin-page capability gating (PR #123, Core-small):** optional required capability on plugin page registration, validated to admin/management at the boundary (the footgun-closing control), enforced at the route + nav; BC-preserving. Both skills; security-green. Reusable pattern: validate a code-path-selecting value at the boundary. Option-B (plugin-defined caps) recorded, not built.
- **Auto + Owl themes (PR #124, Theme):** completes a **six-theme** set. Auto = the one media-driven theme (inherits Nimbus in light, Nocturne under `prefers-color-scheme: dark`); Owl = high-contrast (handled the gold-on-black coupling: bright-amber `--nb-gold`, stars off, heavier focus). Token-only; verified live in both color-schemes.
- **Byte ceiling 30→32 KB (third adjustment), now with a stated exit:** each theme's real cost only shows when it lands; the file header now says a **7th** theme must override *only* differing tokens (a lean block) or move themes to a separate concern — not another blanket raise. Cheap cuts first each time (three comment diets, dropped aliases + section headers). ~6.5 KB gzipped, zero requests — the charter's lightweight (architecture) holds. **Lesson: when content legitimately grows a guardrail, correct the figure openly AND record the condition that stops the next raise.**
- **Flake noted (not caused here):** one full-suite run showed a single failure that vanished on re-run — a pre-existing timing/rate-limit flake, unrelated to CSS/allow-list changes. Candidate to stabilise later.

### 2026-08-22 · Settings store (site.home, site.description) [Core]
- **Classification: Core** — a general-CMS capability (every site has editable site-level config); passes the Platform Drift Guard *without* the validation apps (a CMS needs a home page + default meta regardless of Restaurant/Food Store). Ships the smallest slice that proves the store: two settings, one small table, per-request-memoized reads.
- **Design-first, both skills before code** (Dan's standing rule). Review-loop verdicts adopted verbatim: (Q1) **typed registry**, not free key/value — the allow-list is the safety property *and* it drives the admin form + MCP schema from one source, and it's not over-engineering at two keys; (Q2/boundary) **deploy/env config stays in files, admin-editable site content goes in the DB** — codified in `principles.md` (Config stays DB-free); (Q3) **file-as-default + DB-override, no seed migration** (BC: fresh install works from the file, a set value wins) over a seed-from-file migration; (Q4) **ship home + description, defer the site title** (`APP_NAME`, ~8 consumers — a broad change, fast-follow); (Q5) a **separate `Settings` service + repository**, never fold into the static `Config`; (Q6) stays lightweight, no app-shape drift.
- **The registry-driven write is the load-bearing detail:** iterate the *registry* and pull each key from the request (admin), and registry-look-up *every* submitted key (MCP) — never iterate request keys and write. This is what makes over-posting structurally impossible; it's the same "allow-list a value at the boundary" pattern as the theme slug and the plugin-page capability (now three consumers of that pattern).
- **MCP standing check honored:** the new management capability is MCP-reachable — `get_settings`/`set_settings`, gated by the same cap, non-enumerating, audited (`API_MANAGEMENT_WRITTEN`). Mobile check: the admin form is the existing mobile-native Settings page (stacked form fields), no new responsive surface.
- **Definition of done — met:** implemented (`src/Settings/*`, `SettingsToolset`, admin form) + integrated (`Application`/`ApiController`/`bin/nimbus` wiring; `SiteController` reads via the service) + verified (3 new HTTP test files; PHPStan L6 clean; `composer format` clean; full suite) + documented (README, ROADMAP, `docs/MCP.md`, `docs/COMPATIBILITY.md`, `principles.md` boundary, both ledgers). No migration (nb_settings ships in 001).
- **Next thread:** site title (`APP_NAME`) as a fast-follow behind the same registry + controls.

### 2026-08-22 · Site title (site.title) — the settings-store fast-follow [Core]
- **Classification: Core** (extends the already-core settings store). Passes the Drift Guard on all four: naming your site without a redeploy is baseline CMS, universal across unrelated site types, backed by 8 real existing consumers (not speculation), recommended even absent Restaurant/Food Store/Packkit.
- **Design-first, both skills before code.** Verdicts: (Q1 boundary) the site title is admin-editable **brand content**, not deploy/env config — belongs in the store with `.env` APP_NAME as the DEFAULT the DB overrides. The staging-indicator use survives (a fresh staging install shows its `.env` default; only a copied prod DB masks it, same as all content). (Q2) the **lazy render-time** threading is the smallest correct approach — resolving at controller construction would add a query to every request incl. /api/redirects (routes() builds all controllers eagerly); memoized `siteTitle()` injected via `page()/shell()/bare()` + `SiteController` render paths keeps it to one query on rendered requests only. (Q3) a new `'text'` (single-line) registry type is justified over jamming a title into a textarea — bounded vocabulary (text/textarea/collection), not a field framework. (Q4) editable OpenAPI title leaks nothing (already public, behind auth). (Q5) title-only; APP_URL stays env, logo/tagline are YAGNI.
- **Implementation:** `site.title` registry key (default `Config::appName()`, validate non-empty + ≤80) → auto-appears in the admin form + MCP (registry-driven, no new write surface); `Settings::title()`; base admin `Controller` lazy memoized `siteTitle()` injected at render; `SiteController` render paths + PageContext + placeholder; `OpenApiGenerator` optional title ctor arg resolved by ApiController (live endpoint) + the CLI dump. `Config::appName()` kept as the DB-free default.
- **Refinement:** admin `saveSite` now skips request-omitted keys (partial-update, matches MCP), still registry-driven → A1 intact.
- **Default-source note codified in principles.md:** a setting's default comes from "whatever the file layer says for that key" and may differ per setting (site.title from `.env`, home/description from `config/site.php`) — expected, not a bug.
- **Security:** green (sibling loop). Only-new item was A3 (latent trust flip: a formerly-`.env`-trusted value now user-editable could bite a plugin head-contributor that skipped escaping) → documented `PageContext` values as untrusted; `plugin-seo` escaping flagged as an out-of-repo follow-up.
- **DoD met:** implemented + integrated (all 8 consumers read the resolved title) + verified (tests across admin/public/MCP/OpenAPI; PHPStan L6; full suite) + documented (README, COMPATIBILITY, MCP.md, principles.md, both ledgers).
- **Settings store now has 3 keys + 2 scalar types + the lazy render-time resolution pattern — the template for logo/tagline later.**

### 2026-08-22 · Admin listing hardening (entry pagination + collections N+1) [Core]
- **Classification: Core** (admin scalability/hardening). Two coupled items from the production-readiness backlog, one slice.
- **Grounding caught a stale ROADMAP note:** the chosen "next" item F1 (relation expansion) was ALREADY shipped (PR #34 — `EntryView` expands relations to `{id,slug,title}`, live-only + scope-filtered, with tests in `ApiRoutesTest`). Fixed the stale "candidate next" note in the same PR. **Lesson (reinforced): never review from memory — verify the backlog against the code; a doc checkbox is not ground truth.** Also found the "PHP-CS-Fixer in CI" backlog line stale (ci.yml already runs `php-cs-fixer --dry-run`).
- **Design (both skills before code):** (A) `EntryRepository::forCollection($id,$q,?$limit,$offset)` (BC: `$limit` null → full set, so the existing `forCollection($id)` caller keeps working) + `countForCollection($id,$q)`; admin `PER_PAGE=25` (a touch larger than the site's reader-facing 20); count → total_pages → **clamp page to `[1,max(1,total_pages)]`** so a too-high `?page` can't produce a huge OFFSET or a dead Next; mobile pager preserving `q`. (B) `CollectionRepository::fieldCounts()`/`entryCounts()` — grouped `GROUP BY collection_id`, **map-with-default** for zero-count collections (no LEFT JOIN) → 2N+1 becomes 3 queries.
- **Standing checks:** mobile — pager verified at 375px; MCP — N/A (admin-list presentation/efficiency, no new management capability; the read API already paginates). Both confirmed by the review.
- **DoD met:** implemented + integrated (`EntriesController`/`CollectionsController` use the new methods) + verified (`AdminListingTest` HTTP + `ListingRepositoryTest` integration; PHPStan L6; full suite; live) + documented (ROADMAP items `[x]`, F1 note fixed, easy-install research added under release-readiness, both ledgers).
- **Net debt reduction:** removed an unbounded admin query and an N+1. Reusable admin-pagination shape now exists if media/users/tokens lists ever need it.

### 2026-08-22 · Structured (AI-friendly) validation errors [Core]
- **Classification: Core** (public API + MCP error contract). Done pre-`0.1.0` because the ROADMAP gates it "before freezing the error contract" — after a tag it would be breaking; now it is free. Passes the Drift Guard (every headless/agent consumer benefits; two real consumers API+MCP; on-brand for "operated by agents").
- **Design (both skills before code) — Option A:** per-field errors became `{code,message}`; `code` is **core-assigned** (`required` for required-empty; `invalid` wrapping a field type's `?string` failure) — `FieldType::validate(): ?string` (the plugin contract) is UNCHANGED. This delivers the distinction agents act on (omitted vs malformed vs server-misconfigured) without freezing a per-type code vocabulary we haven't designed. **Forward-compatible with Option B**: the shape already accommodates a *more specific* code later (`invalid` → `invalid_email`) with no shape change, so B is a purely additive refinement when a consumer needs it.
- **One source, three surfaces:** `Validator` → `array<handle,FieldError>` → `EntryService`(+`SaveEntryResult`) reused by `EntryOperations`(+`EntryOpResult`). API + MCP emit `{code,message}`; the **admin keeps prose** via a `messages()` projection (template essentially unchanged) — "AI-friendly *as well*," not instead.
- **Cleaned a real wart:** `__title`/`__types` were LEAKING into the public `fields` map (EntryOperations reuses EntryService). Normalized: `__title` → `title`/`required`; `__types` → **top-level** `missing_provider` (a config fault, not a per-field user error).
- **Vocabulary correction from the security pass:** dropped the speculative `duplicate` code — entry slugs auto-uniquify, so nothing produces it (a documented-but-never-emitted code is a confusing contract). Frozen set: `required`, `invalid`, `missing_provider`; **additive-only; unknown ⇒ treat as `invalid`** (documented in COMPATIBILITY + OpenAPI + MCP.md).
- **DoD met:** `FieldError` VO; Validator/EntryService/EntryOpResult/SaveEntryResult reshaped; API/MCP/admin surfaces + OpenAPI Error schema updated; docs; tests (new `ValidationErrorsTest` across API+MCP+admin, updated `EntryServiceTest`/`NumberTypeTest`); PHPStan L6; full suite. **Lesson: enrich the single validation source, freeze the *shape* + a small additive vocabulary, keep the plugin contract untouched — the reshape is representation-only (no authz/validation-logic change), guarded by the existing mass-assignment/scope suite.**

### 2026-08-22 · Password reset + a small Mailer [Core]
- **Classification: Core** (auth + a mailer capability). Both skills before code; the security loop gated it hard (account-takeover surface).
- **Scope calls:** registration-confirmation is OUT (no public signup exists — nothing to confirm; building it would invent a feature). User-INVITATION ("set your password") is the natural sibling on the same token primitive — deferred fast-follow. Shipped: the reset flow + the Mailer.
- **The mailer question (Dan asked for alternatives to hand-rolling one):** core defines a tiny `Mailer` interface; **never hand-roll SMTP** (hard problem — MIME/STARTTLS/auth). Default `LogMailer` (writes to a file → zero-config, dev/CI, capturable link). `NativeMailer` (`mail()`) for MTA hosts. **Dan then chose "provider email in this slice"** → added `ApiMailer`: a transactional provider's HTTPS API (bearer key + JSON `{from,to,subject,text}`, Resend-compatible, endpoint-overridable) — dep-free (one HTTPS POST), the "email ecosystem, one key at install" answer. **Pushed back on Gmail specifically** (API=OAuth, SMTP=needs a lib → both heavy); recorded that SMTP-via-audited-lib and a mailer *plugin* are future transports behind the same interface, and the Gmail/GitHub *identity* angle (SSO) is a separate initiative, not this slice.
- **Token model:** dedicated `nb_password_resets` table, high-entropy token, **SHA-256 hash-at-rest** (mirrors `nb_api_tokens` — a random secret needs no slow hash), single-use + 1h expiry. Public `/admin/forgot` + `/admin/reset` (like login/logout, outside authMw).
- **Drift guard:** password reset is universal CMS; passes all four. The `Mailer` interface + safe defaults keeps SMTP/providers out of core's hard dependencies.
- **DoD met:** migration 013; `Mailer`/`LogMailer`/`NativeMailer`/`ApiMailer`/`MailerFactory`; `PasswordResetRepository`/`Service`/`Outcome`; `PasswordResetController` + forgot/reset templates + login link/notice; Application wiring + a mailer test seam; config `MAIL_*`; docs (README, `.env.example`, ROADMAP); both ledgers. Tests: `PasswordResetTest` (13) + `MailerTest` (6). PHPStan L6, full suite.
- **Lesson:** a `Mailer` interface + a zero-config log default is what makes a security-critical, mail-dependent flow shippable and fully testable *now*, with real delivery (provider API / SMTP-lib / plugin) as additive transports — no hand-rolled SMTP, no forced mail dep.

### 2026-08-22 · User invitation email [Core]
- **Classification: Core** (auth/onboarding). Both skills before code. Reuses the reset primitive rather than a second token system.
- **Where GitHub/Gmail "signup" lands (Dan's question):** Nimbus has NO public registration by design (closed membership — you don't want any Google account to get an editor login). So SSO splits into sign-IN for known/invited accounts (login page) and open sign-UP (only sensible behind an allow-list policy). **Invitation is the clean landing spot**: admin authorizes an email → invitee accepts; the accept step (`/admin/accept`) is the future home of "Continue with Google/GitHub." OAuth SSO stays a separate future initiative.
- **Design (both skills):** (Q1) generalize the token table IN PLACE — a `purpose` column (`reset`|`invite`, default `reset`) + per-issue TTL, PURPOSE-SCOPED lookups/consume/invalidate (the correctness win: reset & invite never satisfy or invalidate each other). No rename (avoid churn); no second table (avoid duplication). (Q2) admin flow: blank password on the create form → invite; typed password → direct create (fallback for no-mail installs); install/CLI/MCP unchanged. (Q3) dedicated public `/admin/accept`. (Q4) no `invite_user` MCP tool now — `create_user` already covers programmatic creation. (Q5) provider seam = the accept page; zero OAuth now.
- **Shared, not copy-pasted:** extracted `AccountTokenService` (issue / isValid / setPassword-via-token) — the single hardened routine both reset and invite use; `PasswordResetService` refactored to delegate (reset tests stayed green). `InvitationService` = invite-issuance + accept.
- **DoD met:** migration 014 (purpose column); repo purpose/TTL params; `AccountTokenService` + `InvitationService`; `UsersController` invite branch (reuses `firstUngrantableRole`); `/admin/accept` + template + login "welcome" notice; Application wiring (mailer+events → UsersController); tests (`UserInvitationTest` 11, reset suite still green); PHPStan L6; docs; ledgers.
- **Lesson:** when a shared token table serves two flows, purpose-scope EVERY query — the isolation is the correctness property, and extracting the one set-password routine kept the security controls in a single place.

### 2026-08-22 · Nonce-based CSP (script-src) [Core] — pre-OAuth hardening
- **Classification: Core** (HTTP security posture). Both skills before code. Option B: harden `script-src` to a per-request nonce; **keep `style-src 'unsafe-inline'`** (documented deferral — CSS injection is lower severity, and a dynamic theme swatch uses an inline `style=`).
- **Plumbing decision:** a request-scoped `Http\Csp::nonce()` (memoized `base64(random_bytes(16))`) + `rotate()` called once at `Application::handle()` top. Chosen over threading the nonce through every controller/View + the single `SecurityHeaders::apply()` choke point. Framed (and accepted) as a **bounded exception** to "no hidden globals": a per-request *secret* with an explicit lifecycle (`rotate()`), not application state — the principle targets service-locator magic, not this. `rotate()` gives per-request freshness + test isolation.
- **The load-bearing detail:** `'unsafe-inline'` is REMOVED from `script-src` (a browser ignores it once a nonce is present, so leaving it would mask a missed block). A missed nonce fails **closed** (blocked JS, visible in smoke) — never a silent hole.
- **Inline handlers → delegated:** the 5 `onsubmit="return confirm()"` became `data-confirm="…"` + one delegated nonced listener in the layout (progressive enhancement: JS-off submits; the confirm is UX, the routes stay CSRF+cap gated).
- **Theme-contract change (documented):** a public theme's inline `<script>` now needs `nonce="<?= $e($cspNonce) ?>"`; the `$cspNonce` global is exposed to the public View. Starter theme has no inline script, so nothing broke.
- **DoD met:** `Http\Csp`; `SecurityHeaders` nonce'd script-src; `handle()` rotate; `$cspNonce` globals (admin + public); nonce on 3 admin `<script>` + delegated confirm; `CspNonceTest` (nonce-only/no-unsafe-inline, fresh-per-request, header-matches-rendered, data-confirm); PHPStan L7; docs (SecurityHeaders, COMPATIBILITY theme note, ROADMAP); both ledgers. **Verified live: zero CSP console violations across login/collections/settings; a nonced inline script executed (theme preview flipped `data-theme`).**

### 2026-08-22 · Consume named routes in controllers [Core — maintainability]
- **Classification: Core (maintainability), zero security value.** I pushed back (74 sites, cosmetic, regression risk); Dan chose to proceed. Done as the 4th/last pre-OAuth hardening item.
- **Mechanism:** `Http\Url` facade (mirrors `Http\Csp`) — `bind(Router)` once in `Application` right before dispatch (and in `HttpTestCase::rebuildRouter` for directly-dispatched tests), `to(name, params)` delegates to `Router::url()`. Controllers call `Url::to('admin.…')`; query strings appended manually (not part of a route). Dynamic entry redirects use params: `Url::to('admin.entries.index', ['handle' => $h])`.
- **Two gotchas a mechanical convert hit (worth remembering):** (1) route **registrations** (`$r->get('/admin/login', …)`, `->group('/admin/collections')`) must stay **literal** — converting them to `Url::to()` is circular and breaks registration; (2) **default parameter values** (`requireCsrf(..., $abortTo = '/admin/collections')`) must be **constant** — `Url::to()` there is a fatal "constant expression contains invalid operations." A blind string-replace clobbered both; restored them. The full suite (many assert exact redirect targets) + PHPStan caught it immediately — the safety net worked.
- **Verified:** 128 admin/auth tests (exact redirect assertions) green + full suite; PHPStan L7. **Lesson: for a mechanical refactor, the exact-assertion tests ARE the design safety net — convert, run, fix what fails; and never touch route registrations or const-default args.**

### 2026-08-24 · Pre-release audit closing sweep [Core — currency + FU tail]
- **Context:** Dan's directive before starting the agent skill / docs / website: "do one final sweep of the backlog and roadmap to make sure that we don't have any deferred task in there. If there is let's get them wrapped up... And have those documents reflecting the current state of the code." This entry consolidates the sweep; the per-finding resolutions for Slices Q–Y live in `docs/backlog/audit-2026-08/` (the audit initiative tracks resolution in the backlog files, not one dated ledger entry per micro-slice).
- **Buildable item shipped (FU-18):** `PluginLoader::validate` id-gate now also rejects the reserved `nb` namespace — `preg_match('/^nb([._-]|$)/', $id)` → INVALID_MANIFEST. Closes the gap where a plugin id like `nb_stats` would name tables `nb_stats_*`, colliding with core `nb_*` and tripping the FU-11 migration lint from the wrong layer. Reviewed as an accident-guard, not a sandbox (ADR 0001 keeps installed plugins trusted). Test: `PluginLoaderTest::test_a_plugin_id_in_the_reserved_nb_namespace_is_rejected` (ids `nb_stats` and `nb` → both rejected).
- **Test-currency fix caught by the sweep:** `PluginLoaderTest` fixture `CREATE TABLE nb_allcaps_x` → `CREATE TABLE allcaps_x (id INT)`. The FU-11 migration lint (Slice Y) would reject that fixture at registration, making the PLUG-8 all-registries-rollback test pass for the *wrong* reason (throwing before its intended `DuplicateFieldType` trigger, never registering capabilities 5–6). Exactly the drift a currency sweep exists to catch.
- **Deliberate deferrals recorded (each with a revisit trigger in the backlog):** ADMIN-13 (media-library pagination + alt-text + lazy picker → the gated admin-experience redesign); FU-2/3/5/7/8/15/16/17 (documented deferrals). FU-7's supported path (self-hosted / reverse-proxied analytics served from `'self'`) is already documented in COMPATIBILITY; only the config-driven CSP `connect-src` extension is deferred (needs its own security review). FU-16 (normalizing the legacy `nb_users.role` column) accepted-no-code: `Auth` hydrates `User->role` from that column (`Auth.php`), so a rename is a live-read risk for no gain.
- **Documents reconciled to current code:** ROADMAP "last audited" line rewritten to the audit burn-down; four verified-done items flipped to `[x]` (structured validation errors, read-only admin plugin screen, public-API-surface decision via COMPATIBILITY, SemVer + CHANGELOG), release-artifact automation split to `[~]`, community files + docs-tree status corrected; the stale 2026-08-03 "MCP proposed — nothing implemented" ledger status currency-corrected to accepted→implemented (original wording preserved).
- **Verified:** `PluginLoader|MigrationLint` suites green (OK, 66 tests / 199 assertions) + PHPStan L6 clean at the point of the FU-18 change; full suite + CI on the branch PR.
- **Lesson:** a closing currency sweep is not ceremony — it caught a false-passing test fixture and a stale "nothing implemented" status that would have misled the docs/website seed. Run one before any docs-from-code initiative.

### 2026-08-24 · Agent guidance over MCP — Slice 1 (core guide) [Core]
- **Classification: Core** (the MCP surface describing itself, à la OpenAPI/ADR 0008) + a shipped content-authoring concern. Reviewed by the Fable two-skill burst on the DESIGN before build; both → ship(revise). ADR 0013 (amends 0009's "resources out of scope until a concrete need appears" — the need arrived).
- **The crux, decided:** deliver agent guidance *through MCP* (`initialize.instructions` + `resources/list`/`read`), not as generated per-agent skill files. This is the only mechanism that is free-with-the-CMS, agent-agnostic, install-aware and version-true; per-agent artifacts are a deferred Phase-2 convenience from the same source. Passes the Drift Guard (serves the MCP surface itself; no app shape; would build absent Restaurant/Food Store/Packkit).
- **Structural cure first:** one `McpServerFactory::build(...)` now assembles the six toolsets + the guide + `serverInfo.version` for BOTH transports; `ApiController` and `bin/nimbus` call it and add nothing. This kills the two-hand-assembled-sites drift that had produced the live stdio fatal (PR #170).
- **Shape:** `src/Mcp/Guide/{GuideDocument,GuideLibrary,CoreGuide}`; `McpServer(GuideLibrary $guide, string $version, Toolset ...)`; `initialize` emits `instructions` + `capabilities.resources{subscribe,listChanged:false}` (NEVER prompts/sampling) + version from new `Application::VERSION`; `resources/read` is a **registry lookup by exact URI** (never a path) → unknown/`../`/`file://`/empty = uniform `-32002` (`JsonRpc::RESOURCE_NOT_FOUND`), the non-enumerating parity of "unknown tool". Guide authored in `docs/agent/{instructions.md,core.md}` — static, platform-neutral, no live-value interpolation, no weaponizable grant-admin example.
- **Coverage as a CI property:** `AgentGuideCoverageTest` reflects every `*TOOLS` const across the five management toolsets → each must appear in `core.md` (a new tool forces a guide update), plus every API error code + the content-verb pattern + the instructions byte cap. Turns "truly covers all core functionality" from intent into a test.
- **DoD met:** implemented + integrated into both transports + verified (`McpResourcesTest`, `AgentGuideCoverageTest`, `McpTest`, `StdioTransportTest` green; PHPStan L7 + cs-fixer clean) + documented (MCP.md, COMPATIBILITY, CHANGELOG, ADR 0013). Fixed a pre-existing `McpTest` that used `resources/list` as its "unknown method" example (now implemented). Mobile: N/A (no UI). 
- **Deferred to Slice 2:** the plugin skill capability (data-only registrar + `nimbus://guide/plugin/{id}` aggregation + register-time size cap + `forgetProvider` rollback + the markdown fragment as first consumer). Prompts deferred (Phase 2, no demonstrated need).
- **Also (found by the review while grounding, shipped separately — PR #170):** the stdio `nimbus mcp` `UsersToolset` arg-count fatal + the PHPStan blind spot (`bin/nimbus` extension-less → unanalyzed) closed by listing the file explicitly. Lesson: an extension-less entrypoint silently escapes `paths: [bin]`; list it by name.

### 2026-08-24 · Agent guidance — Slice 2a (plugin skill capability) [Core]
- **Classification: Core** (an 8th plugin capability + MCP aggregation). Design reviewed in the Slice-1 burst; the built code re-reviewed by `nimbus-security-review` (the reviewer's required re-check of the plugin-text surface) → **security-green**.
- **Shape (house pattern, data-only):** `SkillRegistry` (composed-once, `Nimbus\Mcp\Guide`) + `SkillRegistrar` (`Nimbus\Plugin`, per-plugin, `register($title,$body)` with register-time caps `MAX_TITLE_BYTES=200`/`MAX_BODY_BYTES=65536` → throw → REGISTER_FAILED). 8th field on `PluginCapabilities`; `PluginContext::skills()`; `PluginLoader` catch rolls back via `forgetProvider`; `Application::agentSkills()`; `McpServerFactory` aggregates `CoreGuide::document` + `$skills->documents()` (core FIRST). One fragment per plugin, keyed on the loader-validated provider id → `nimbus://guide/plugin/{id}`.
- **Landed WITH a consumer per the house rule** — here a **test-fixture** consumer (`AllCapabilitiesBrokenPlugin` registers a fragment; `SkillRegistrarTest` drives the registrar/registry/GuideLibrary). The **real** first consumer (markdown-plugin fragment) is Slice 2b in `nimbus-plugin-markdown` — a pure consumer of this contract, no core re-review needed unless it touches core.
- **DoD met:** implemented + integrated (both transports via the factory) + verified (`SkillRegistrarTest`, extended `PluginLoaderTest` rollback + reflection tripwire now requires the `skills` prop, full suite green, PHPStan L7 + cs-fixer + audit clean) + documented (COMPATIBILITY registrar row, CHANGELOG, ADR 0013).
- **Security findings (both Low, addressed):** F1 — `GuideLibrary` insertion was last-wins with a *backwards* comment claiming core-added-first protected `nimbus://guide/core`; **fixed in-PR** → first-wins (`??=`) so the claim is true + a regression test (a plugin doc claiming the core URI can't displace core). F2 — `resources/list` `title` is plugin-authored and served without the untrusted-data envelope (≤200B); Low under ADR 0001; **hardened** by teaching in the core guide that listed titles are plugin-authored data (not commands).

### 2026-08-24 · MCP create_collection gains `kind` (singleton parity) [Core]
- **Classification: Core (MCP schema surface — parity on an existing capability, no new abstraction).** Reviewed by the two-skill Fable burst before build; both → ship (revise). Found by real dogfooding: the MCP-driven seed of nimbuscms.dev couldn't create the `home` singleton a landing page needs (`SiteController::homePage` renders a *list* for a regular collection, one entry only for an `isSingle()` one).
- **Change:** `SchemaToolset::createCollection` accepts `kind` (`collection`|`single`, default collection); options built **server-side** via `optionsForKind()` (never merges client input — over-posting guard); `kind` exposed on read for parity in `collectionResult`, `list_collections`, and `describe_collection`. The singleton's one entry already populates through the shared `EntryService` path (first `create_{handle}` makes it, slug `__singleton`, second create targets the same row) — no content-toolset change needed.
- **Reject-not-coerce (the open question, both reviewers agreed):** an unknown/typo/non-string `kind` is rejected with `invalid` naming the valid values, NOT coerced. Rationale: coercion would silently fall to `collection` — the *publicly browsable* kind — failing open toward exposure; and the house style at this boundary is friendly-fail-loud (unknown field types / reserved handles both name the valid set). `Collection::kind()`'s normalization stays as the storage-layer net. The admin coerces only because an HTML dropdown can't submit a bad value.
- **Guide (last-mile, per platform ❌):** `docs/agent/core.md` now documents the full singleton flow end to end — create `kind:single`, populate the one entry via `create_{handle}` with `status:published`, thereafter `get_`+`update_` (a repeat create overwrites without a version check), and point `set_settings site.home` at it for the landing (`/` renders only a *published* singleton). So "an agent can fully seed a site" is now true, not false-complete.
- **DoD met:** implemented + integrated (both transports via the factory/kernel) + verified (`McpSchemaToolsTest` +8: create-singleton+kind-report, default kind, reject unknown+non-string, over-posting pin asserted against the stored options row, one-row e2e, reserved×kind, read-parity on list+describe; PHPStan L7 + cs-fixer + full suite) + documented (guide; COMPATIBILITY MCP shape is pre-1.0 evolving). SVM-4 non-browsability of MCP-created singletons is guaranteed by construction (keyed off `isSingle()`, one source) + already covered by `SiteRoutesTest` — not duplicated. Mobile: N/A. MCP: this IS the MCP-reachability fix.
- **Pre-existing follow-up recorded (both reviewers, non-blocking, FU-14 family):** `delete_collection` doesn't clear a `site.home` that names it (fail-safe placeholder today; the dangling-home→recreate chain is kind-independent + top-privilege + audited). **Standing check:** if MCP `update_collection` ever gains `kind`, the single→collection flip is a *publication event* for the `__singleton` entry and needs its own review.
- **Lesson:** an admin/MCP capability asymmetry is a charter defect regardless of which project surfaces it — dogfooding the MCP seed is what made this one concrete.
