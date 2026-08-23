# Plugin subsystem & boundary — audit findings

**Domain summary.** The boundary's architecture is genuinely sound: capability-not-object,
provider ids bound by the loader, two-phase id claiming, provider-scoped rollback, and
every capability added with a named consumer (the evidence table is real and current).
The confirmed weaknesses are at the edges, not the core: the loader's own containment
promise can be defeated by an unvalidated manifest id (`"core"`), a broken plugin's
migration can wedge `nimbus migrate` for the whole install, best-effort event isolation
is per-event rather than per-listener (one broken plugin silently starves another
plugin's audit/analytics), and the CSP nonce plus the internal `Request`/`Response`
classes are quietly load-bearing for plugins while the docs say they don't exist.

---

### PLUG-1 · A failing plugin migration wedges `nimbus migrate` for the whole install
- **✅ RESOLVED** (Slice D, 2026-08-23) — per-provider isolation in `Migrator` (plugin loop catches + skips that provider's rest; core loop throws `MigrationFailed`); `MigrationReport` + non-zero CLI exit; idempotency contract documented on `MigrationRegistrar::register()`.
- **Priority:** P1
- **Type:** error-handling
- **Where:** `src/Database/Migrator.php:66-79` (`apply()`), `:44-51` (plugin loop); caller `bin/nimbus:42`
- **What:** Plugin migrations are multi-statement, non-transactional (MySQL DDL auto-commits), and the `nb_migrations` record is written only after *all* statements succeed — a third-party migration that fails on statement 2 leaves statement 1 applied and nothing recorded, and there is no per-provider isolation, so migration runs fail permanently and later plugins never migrate.
- **Evidence:** Plugin ships `register('001_init', ['CREATE TABLE acme_hits (…)', 'CREATE INDEX <typo>'])`. First run: `CREATE TABLE` applies, `CREATE INDEX` throws out of `Migrator::migrate()` — name unrecorded, every subsequent plugin's migrations skipped this run. Every later `nimbus migrate` re-runs statement 1 and dies on `acme_hits already exists` — the install can no longer take *core* upgrades past that point without hand-editing the DB. Core migrations share the shape (0 uses of `IF NOT EXISTS` in `src/Database/migrations/`) but are first-party-tested; the plugin path imports unreviewed third-party SQL into it. `tests/Integration/PluginMigrationTest.php` has no failing-migration test.
- **Fix:** In the plugin loop, wrap each provider's migrations in try/catch: on failure report the provider + migration name (diagnostic/stderr), skip that provider's remaining migrations, continue with other providers and return the failure in the result. Document that plugin DDL statements should be individually idempotent (`IF NOT EXISTS`) since MySQL cannot roll DDL back. Add the failing-migration integration test.
- **Effort:** M

### PLUG-2 · Plugin ids are unvalidated — `"id": "core"` defeats field-type rollback; colons collide namespaces
- **Priority:** P2
- **Type:** security
- **Severity (if security):** Low (semi-trusted author; but it breaks the loader's stated containment invariant)
- **Where:** `src/Plugin/PluginLoader.php:108-115` (`validate()` — no id format check); `src/Content/FieldTypeRegistry.php:88-91` (`forgetProvider()` early-returns for `'core'`); `src/Plugin/MigrationRegistrar.php:33`, `src/Plugin/MaintenanceRegistrar.php:28`
- **What:** `extra.nimbus.id` is taken verbatim from the plugin's own composer manifest with no format validation — empty string, `"core"`, and colon-bearing ids are all accepted, each breaking a different invariant that assumes ids are well-formed.
- **Evidence:** (a) A manifest declaring `"id": "core"` gets a `PluginContext` whose registrations are stamped provider `core`. If its `register()` throws after registering a field type, the loader's rollback calls `fieldTypes->forgetProvider('core')`, which returns `[]` by design — the failed plugin's field type **stays active** while its status says FAILED, exactly the partial-activation the two-phase loader exists to prevent. (`FieldTypeRegistry::forgetProvider`'s docblock claims "a plugin id can never be 'core', because the loader binds the id" — the loader binds it *from the manifest*, so it can.) Any type it registers also displays as core-provided via `providerOf()`. (b) Migration/maintenance names are `pluginId . ':' . $name`, so id `a` + name `b:c` and id `a:b` + name `c` both produce `a:b:c`; `nb_migrations`' UNIQUE + the `in_array($applied)` check make the second plugin's migration silently "already applied" — its table is never created and its `storage()` queries fail at runtime with no diagnostic. (c) `""` is a valid id (`is_string` passes) and threads into enabled-map lookups, statuses and prefixes. `PluginLoaderTest::test_a_plugin_cannot_claim_to_be_another_provider` covers only that the loader binds the manifest id — not a hostile/degenerate id value.
- **Fix:** In `validate()`, reject ids not matching `/^[a-z0-9][a-z0-9._-]*$/` and the reserved `core` with an `INVALID_MANIFEST` diagnostic (colon excluded by the pattern). One check, before `claimedBy`. Add loader tests for `core`, `""`, and a colon id.
- **Effort:** S

### PLUG-3 · Best-effort events isolate the event, not each listener — one broken plugin starves another's audit trail
- **Priority:** P2
- **Type:** error-handling
- **Severity (if security):** Low (audit-loss angle)
- **Where:** `src/Support/EventDispatcher.php` (`emitBestEffort()` wraps `dispatch()` in one try/catch; `dispatch()` loops listeners unguarded); consumers `src/Application.php:242-245`, all `api.*` emit sites
- **What:** `emitBestEffort` catches around the whole dispatch loop, so the first throwing listener aborts delivery to every listener registered after it — "isolated" holds for the *caller*, not between *plugins*.
- **Evidence:** Broken plugin A (loaded first, Composer order) subscribes to `request.handled` and throws; plugin-analytics' listener, registered after, never runs — every page hit is silently uncounted while A's error is logged as if it were the only effect. Same mechanics on `api.access_denied` / `api.management_written`: a throwing earlier listener suppresses the api-advanced audit log's record of a denial or a management write — an audit-integrity gap (and a deliberate one-line way for a hostile plugin to blind the audit trail, though an in-process plugin has blunter tools). `Application::notifyHandled`'s docblock ("a listener that throws is logged, never allowed to break a response") and CoreEvents' "isolated" both read as per-listener; the code is per-event.
- **Fix:** Add a best-effort flag or a `dispatchIsolated()` path that puts try/catch *inside* the listener loop (log per listener, continue). Keep the loud post-commit entry events propagating as designed. Unit test: two listeners, first throws, second still fires.
- **Effort:** S

### PLUG-4 · Duplicate or core-colliding admin-page slugs are silently accepted
- **Priority:** P2
- **Type:** correctness
- **Where:** `src/Admin/AdminPageRegistry.php:20-31` (`add()` — no uniqueness check); `src/Admin/PluginPagesController.php:36-48`; nav in `src/Admin/Controller.php:76-82`
- **What:** Unlike field types (`DuplicateFieldType`, first-wins-loudly), the admin-page registry accepts any number of pages with the same slug, and a slug equal to a core route registers an unreachable route with a live nav entry — both silently.
- **Evidence:** Two plugins register slug `reports`: both land in the registry, `PluginPagesController` registers `GET /admin/reports` twice (first match wins — the second plugin's page is unreachable), the sidebar shows two entries with identical URLs and both render `active` together (`key === $active` matches both), and no diagnostic is produced anywhere. A plugin registering slug `plugins` or `collections` gets a sidebar entry that opens the *core* page (route registered after core, never matches) — the plugin's page simply doesn't exist and nothing says so.
- **Fix:** In `AdminPageRegistry::add()`, throw on a duplicate slug (the loader already converts a throwing `register()` into REGISTER_FAILED + full rollback — the machinery exists); reject a fixed list of core-reserved slugs in `AdminPageRegistrar::register()` next to the existing format check. Two small tests.
- **Effort:** S

### PLUG-5 · The CSP nonce is not part of the plugin surface — script-bearing head contributions and admin pages cannot work
- **Priority:** P2
- **Type:** product-gap
- **Where:** `src/Site/PageContext.php` (no nonce field); `src/Plugin/AdminPageRegistrar.php:52` (handler gets only `Request`); `src/Http/SecurityHeaders.php:31` (`script-src 'self' 'nonce-…'`); contrast `docs/COMPATIBILITY.md` theme contract (`$cspNonce` documented for themes only)
- **What:** Any executable `<script>` a head contributor or a plugin admin page emits — inline or external-src — is blocked by the nonce-only CSP, and no public surface exposes the nonce; the only working path is calling internal `Nimbus\Http\Csp::nonce()`, which violates "official plugins use the same public APIs".
- **Evidence:** The capability-evidence table names "analytics snippet" as head-contribution's next consumer, and the 2026-08-15 ledger entry planned agent injection (GA/Fathom/Plausible) on the head capability. A contributor returning `<script src="https://plausible.io/js/script.js"></script>` is refused by `script-src 'self' 'nonce-…'` (external scripts need the nonce too under a nonce policy) — the flagship use the capability was justified by cannot ship against the documented surface. plugin-seo's JSON-LD is unaffected (data blocks aren't executed, CSP doesn't block them), which is why nothing has visibly broken yet. Plugin admin pages have the same gap for any interactivity beyond server-rendered SVG.
- **Fix:** Add `public readonly string $cspNonce` to `PageContext` (additive, data-only — consistent with its contract) and pass the nonce to admin-page handlers (e.g. a documented request attribute or second callable arg); document both in COMPATIBILITY beside the theme's `$cspNonce`.
- **Effort:** S

### PLUG-6 · The public plugin surface depends on classes COMPATIBILITY declares internal (`Request`, `Response`)
- **Priority:** P2
- **Type:** architecture
- **Where:** `src/Plugin/AdminPageRegistrar.php:47-52` (handler typed `callable(Nimbus\Http\Request):(string|Nimbus\Http\Response)`); `src/Support/CoreEvents.php` `REQUEST_HANDLED` payload (`['request' => Request, 'response' => Response]`); `docs/COMPATIBILITY.md:33-36`
- **What:** COMPATIBILITY states `Request` and `Response` are internal and "a plugin depending on any of them will break, and that is not a bug in Nimbus" — yet every admin-page plugin must accept a `Request` (and may construct a `Response`), and every `request.handled` listener is handed both objects, so the two shipped consumers (analytics, api-advanced) necessarily violate the compatibility policy to function.
- **Evidence:** An analytics listener must call `$request->path` / `$response->status` — on internal classes. Per the doc's letter, a `0.x` patch could rename `Request::$path` and breaking plugin-analytics "is not a bug"; per the boundary's reality it plainly is. This is the accidental-freeze the review loop hunts for: the internal classes are becoming public API through the side door without a decision. (The `request.handled` payload also carries the full `Request` — Authorization headers, login POST bodies — where the array-payload `api.*` events deliberately redact; a data-only payload would close both. The payload-shape revisit is already in the decision ledger — this finding is its trigger arriving, not a re-litigation.)
- **Fix:** Decide explicitly, in COMPATIBILITY: either bless a narrow documented read surface of `Request`/`Response` for plugin handlers (smallest change — a "stable for plugins" subsection), or introduce data-only values (a request-facts array/VO for `request.handled`; a thin request wrapper for admin pages). Either way the doc and the shipped surface must agree before the API is called frozen.
- **Effort:** S (docs decision) / M (value objects)

### PLUG-7 · COMPATIBILITY has drifted from the shipped plugin API (maintenance missing; stale "events are not a capability")
- **Priority:** P2
- **Type:** product-gap
- **Where:** `docs/COMPATIBILITY.md:15-31` (public API table), `:288-289` ("What is not covered"); `src/Plugin/MaintenanceRegistrar.php`; `src/Plugin/PluginContext.php` docblock ("Seven capabilities today")
- **What:** The frozen-ish contract document omits `MaintenanceRegistrar` (a shipped, two-consumer public capability, `PluginContext::maintenance()`) from the public-API table, and the closing section still says "events are not a plugin capability at all yet" while `EventRegistrar` is listed as public in the same file's table.
- **Evidence:** A plugin author reading COMPATIBILITY today would conclude maintenance tasks are internal (free to change without notice) and event subscription both is and is not public depending on which paragraph they trust. The capability-evidence table records both as public with 2 unrelated consumers each. Pre-release, this is the document the audit is meant to make trustworthy.
- **Fix:** Add `Nimbus\Plugin\MaintenanceRegistrar` to the table; rewrite the stale sentence to what is true now ("`CoreEvents` names are stable; payload arrays are not frozen"). Sweep the file once against `PluginContext`'s capability list.
- **Effort:** S

### PLUG-8 · Rollback coverage is asserted for field types only — the other five registries are unguarded by tests
- **Priority:** P2
- **Type:** test-gap
- **Where:** `tests/Unit/PluginLoaderTest.php:331-347` (`test_a_failed_registration_leaves_nothing_behind`); `src/Plugin/PluginLoader.php:168-175` (the hand-maintained six-call rollback list)
- **What:** The loader's catch block rolls back six registries by explicit enumeration, but the failed-registration test asserts only the field-type registry is clean — nothing proves head/events/migrations/adminPages/maintenance are rolled back, and nothing forces capability #8's author to extend the catch.
- **Evidence:** Delete `$capabilities->events->forgetProvider($id);` from the catch and the whole suite stays green — a failed plugin's event listeners would keep firing on every request (analytics-style listeners doing storage writes included) while the admin shows the plugin as FAILED. The exact silent-partial-activation the two-phase design exists to prevent, one refactor away, with no tripwire. The bundle refactor ledger entry already recorded how easily this list and its tests drift.
- **Fix:** Extend the `HalfBrokenPlugin` fixture to register one of *each* capability before throwing; assert every registry in `PluginCapabilities` is empty afterwards (iterate the bundle's public properties so a new capability fails the test until rollback handles it).
- **Effort:** S

### PLUG-9 · ADR 0005's "per-plugin table prefix" was never implemented, documented, or linted
- **Priority:** P3
- **Type:** architecture
- **Where:** `src/Plugin/MigrationRegistrar.php` (raw statements pass through), `src/Plugin/PluginStorage.php`; `docs/adr/0005-plugin-owned-storage.md` Decision §1 ("tables in its own namespace (a per-plugin prefix)")
- **What:** The accepted ADR promises namespaced tables; the shipped capability namespaces only the *migration name* — table names are whatever the plugin's SQL says, so two plugins can accidentally collide on a generic name, and accidental DDL against `nb_*` isn't even flagged. (Not re-litigating contract-not-sandbox — the ledger accepts that a *determined* plugin bypasses everything; this is about honest accidents, where the ADR's own words are "the interface should make the safe path the easy one".)
- **Evidence:** plugin-analytics creates `analytics_hits` by convention; a second plugin also creating `hits`/`analytics_hits` fails at `CREATE TABLE` during `nimbus migrate` (composing with PLUG-1 into a wedged install). Nothing in the registrar docblock, COMPATIBILITY, or a check tells a plugin author a prefix is expected — the ADR's promise exists only in the ADR.
- **Fix:** Document the required prefix convention (derive from the plugin id) in `MigrationRegistrar`'s docblock + COMPATIBILITY; optionally add a cheap lint in `MigrationRegistrar::register()` rejecting statements that reference `nb_`-prefixed tables — one regex that catches the accident class without pretending to be a sandbox.
- **Effort:** S

### PLUG-10 · `Migrator::pending()` miscounts after a plugin uninstall
- **✅ RESOLVED** (Slice D, 2026-08-23) — `pending()` now compares name **sets**, not counts.
- **Priority:** P3
- **Type:** correctness
- **Where:** `src/Database/Migrator.php:56-63`; only consumer `tests/Integration/CoreMigrationTest.php:27`
- **What:** `pending()` compares row *counts* — an uninstalled plugin's recorded migrations inflate `applied()` and can mask genuinely pending core migrations.
- **Evidence:** 15 core applied + 3 plugin-X applied = 18 recorded. Uninstall plugin X, ship core migration 016: total = 16, applied = 18, `18 < 16` is false → `pending()` says nothing pending while 016 has not run. Currently near-dead code (one test), but it is the obvious future "are migrations pending?" health check and will lie precisely on installs that have churned plugins.
- **Fix:** Compare sets, not counts: pending = any known name (core files + registry) not in `applied()`.
- **Effort:** S

### PLUG-11 · Plugin health is invisible to the MCP operator (standing surface check)
- **Priority:** P3
- **Type:** product-gap
- **Where:** `src/Mcp/*` (no plugin toolset); `src/Admin/AdminController.php:53` (the only status surface); no deferral recorded in the ledgers
- **What:** Plugin statuses/diagnostics are reachable only through the admin page (and error_log); an agent operating the CMS over MCP (ADR 0009's first-class operator) cannot see that a plugin failed to load — and the standing surface check requires either MCP reachability or a recorded deferral, and neither exists.
- **Evidence:** A plugin breaks on deploy; field entries of its types go read-only and an MCP agent's writes start failing `missing_provider` — but no MCP tool can answer "which plugin, why". The human must open `/admin/plugins`. (Disable itself is `config/plugins.php` deploy config and correctly stays CLI/file — this is about *reading* status.)
- **Fix:** Either a read-only `list_plugins` MCP tool gated `admin` (statuses are already data-only values, safe to serialize), or record the deferral in the decision ledger — one line either way; the omission being undecided is the defect.
- **Effort:** S

### PLUG-12 · `FieldType` contract carries no untrusted-value warning for `renderInput`/`renderCell`
- **Priority:** P3
- **Type:** security
- **Severity (if security):** Low (nonce-only CSP blunts the payoff)
- **Where:** `src/Content/FieldType.php:26-29` docblocks; contrast `src/Site/PageContext.php` (explicit SECURITY note)
- **What:** A plugin field type's `renderInput()`/`renderCell()` return HTML embedded raw in the admin, and `$value` can originate from a low-privilege author or a write-scoped API token — but the contract says nothing about escaping, unlike `PageContext`, which sets the house precedent with an explicit SECURITY paragraph.
- **Evidence:** An author (or `posts:write` token) stores `"><img src=x onerror=…>` in a field of a community type whose `renderCell` interpolates `$value` unescaped → markup injection in the entries list every admin views. Inline script/handlers are blocked by the nonce-only CSP (hence Low), but attribute/markup injection, layout breaking, and any future CSP relaxation remain. The oldest, most-copied plugin surface is the one without the warning.
- **Fix:** Add the same SECURITY docblock to `FieldType` (escape `$value` — `View::e()` — in both render methods; core types are the reference), and mention it in COMPATIBILITY's field-type row.
- **Effort:** S

### PLUG-13 · "Official" badge trusts the composer vendor prefix
- **Priority:** P3
- **Type:** product-gap
- **Severity (if security):** Low
- **Where:** `src/Plugin/PluginLoader.php:105` (`$official = str_starts_with($name, 'nimbuscms/')`); rendered `src/View/themes/nimbus/templates/plugins.php`
- **What:** Any package installed from a VCS/path repository can name itself `nimbuscms/anything` and earn the "Official" badge on the plugins page — the flag is a trust signal derived from an unverified string.
- **Evidence:** `composer require nimbuscms/totally-legit` from a custom repository renders the Official badge to every admin. The installing admin chose the package, so exposure is limited to misleading *other* admins on a multi-admin install — display-only, nothing gates on `official`.
- **Fix:** Either drop the badge until provenance can mean something (Packagist verification is out of scope), or relabel to the neutral fact ("nimbuscms namespace"). One-liner; a footnote in the plugins page copy also suffices.
- **Effort:** S

---

## What's solid

The core boundary decisions have held up under adversarial reading: discovery is
Composer-only (no in-admin installer — the RCE-shaped feature was correctly refused in
ADR 0001); provider ids are bound by the loader so a plugin cannot register or roll back
under another's name (tested); ids are claimed by installation, not success, so a broken
or disabled plugin never hands its identity to a squatter (both cases tested); the
registrars are genuinely narrower than their registries (add-only, no read/remove);
admin-page capability gating is wildcard-immune, enforced at the route as well as the
nav, and well-tested including the `*:read`-holder denial; the plugins page is
admin-gated, fully escaped (tested), action-free, and mobile-wrapped; `nimbus prune`
isolates per task; and the capability-evidence discipline is real — every capability
shipped with a named consumer and the two-consumer promotions are recorded. The
GET-only admin-page and contract-not-sandbox storage decisions are deliberate, recorded,
and were respected (not re-litigated) here.
