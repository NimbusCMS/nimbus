# Content, fields, validation & DB — audit findings (2026-08)

Domain: `src/Content/**`, `src/Database/**`. Reviewed with both disciplines
(platform three-hat + Platform Drift Guard; Attacker/Defender/QA security lenses),
grounded in CHARTER/COMPATIBILITY/ROADMAP, both ledgers, ADRs 0002/0007/0009/0011,
and a live MySQL 8 (`STRICT_TRANS_TABLES` on) + PHP 8.3 container.

The data layer is genuinely solid: SQL is bound everywhere, the two unbindable
LIMIT/OFFSET spots are typed-int under `strict_types`, writes are transactional
(entry + relations + media-usage in one boundary), the unique index — not a read —
is the concurrency authority, and JSON hydrate throws rather than silently
emptying. The gaps are at the **input-validation edge**, not the SQL: values that
reach the DB layer un-length-checked, un-type-guarded, or un-scoped, plus one
migration-recovery hole. Most are reachable identically from the admin **and** the
scoped write API/MCP, which is where they matter.

Priority counts: **P0 0 · P1 1 · P2 3 · P3 2**.

---

### DATA-1 · Relation values are not constrained to the field's declared target collection
- **✅ RESOLVED** (Slice C, 2026-08-23) — constrained at write (`EntryRepository::idsInCollection`) + read (`liveTargets` real-collection filter); retained the `canRead` scope gate.
- **Priority:** P1
- **Type:** correctness (secondary: security)
- **Severity (if security):** Medium
- **Where:** `src/Content/EntryService.php:159-182` (`splitValues`), `src/Content/RelationRepository.php:75-93` (`sync`) + `:48-68` (`liveTargets`), `src/Content/EntryView.php:54-65`, `src/Admin/EntriesController.php:299-320` (submit not re-validated against `relationOptions`)
- **What:** A relation field declares a `target` collection, but nothing validates that the submitted `to_entry_id`s actually belong to that collection — any entry id in the whole DB is accepted and stored.
- **Evidence:** `splitValues` only does `intval` + `>0` filtering; `RelationRepository::sync` inserts the ids; the only integrity guard is the `to_entry_id` FK (entry must *exist*, not *belong to target*). Concrete: a collection `posts` has a relation field with `target: "posts"`. `POST /api/v1/posts` (or the admin form with a crafted `f[rel][]`) with `{"fields":{"rel":[<id of a live entry in a private collection "secret">]}}` stores the cross-collection link. On read-back, `EntryView::one` gates expansion on the **declared** target handle — `canRead("posts")` — not the target entry's real collection, so `liveTargets` JOINs `nb_entries` by id irrespective of collection and returns the secret entry's `{id,slug,title}`. A token holding `posts:read`/`posts:write` but **not** `secret:read` thus reads a `secret` entry's id/slug/title (scope confusion, catalog #1/#2). Reachability caveat that caps it at Medium: `liveTargets` returns only *live* (published) entries, which are already public via the site; the confidentiality win only exists on headless/private installs. But the **integrity** defect is unconditional — a "posts" relation silently holds non-posts, and every theme/API/MCP consumer that trusts `target` is wrong.
- **Fix:** In `EntryService` (the one shared write path), filter the relation ids to those whose `collection_id` equals the field's `target` collection before `sync` — one `SELECT id FROM nb_entries WHERE collection_id = :t AND id IN (...)` (bound), drop the rest. Belongs in the service so admin + API + MCP all inherit it. Optionally also gate `EntryView` expansion on the real collection, but constraining at write is the correct primary layer.
- **Effort:** M

### DATA-2 · Invalid `published_at` throws an uncaught exception → HTTP 500 instead of a 422 validation error
- **✅ RESOLVED** (Slice F, 2026-08-23) — `Publication::isValidTime` guard in `EntryService::save` → structured 422; `isLive` also tolerant so the admin re-render can't 500.
- **Priority:** P2
- **Type:** error-handling
- **Where:** `src/Content/Publication.php:100-113` (`resolvePublishedAt` → `new DateTimeImmutable($requested)`), reached from `src/Content/EntryService.php:77`, `src/Api/EntryOperations.php:295` (`inputFrom` passes the raw string through), `src/Admin/EntriesController.php:323-327`
- **What:** A malformed `published_at` string on a publish reaches `new DateTimeImmutable(...)`, which throws `DateMalformedStringException`; nothing on the write path catches it, so it surfaces as a generic 500.
- **Evidence:** `EntryOperations::inputFrom` accepts any non-empty `published_at` string verbatim; `Publication::resolvePublishedAt` only runs when `status === published` and does `new DateTimeImmutable($requested)`. Verified live: `new DateTimeImmutable("soon")` → `DateMalformedStringException: Failed to parse time string (soon)`. That class is not a `PDOException`, so `EntryService::save`'s duplicate-key catch ignores it and it propagates to `Application::handle`'s `\Throwable` boundary → 500 with a reference id. Repro: `POST /api/v1/{coll}` `{"status":"published","published_at":"soon"}` → 500 (should be a 422/`invalid` field error). Same path from the admin form if the datetime-local value is bypassed.
- **Fix:** Validate/parse `published_at` at the input edge — either in `inputFrom`/the admin input builder (reject → structured `invalid` error keyed to `published_at`), or wrap the parse in `resolvePublishedAt` to return a validation failure. Smallest correct spot is a guarded parse producing a `FieldError`-style rejection before `save` reaches persistence.
- **Effort:** S

### DATA-3 · No length/size validation on stored values → over-long input 500s under strict MySQL; JSON blob and relation/media counts unbounded
- **✅ RESOLVED** (Slice F, 2026-08-23) — title/slug length + slug suffix-headroom; `maxlength` field option (text 255 / textarea 50k) + a universal 100k scalar ceiling in the Validator (covers url/email); relation cardinality cap (100) before any DB write. Media cap deferred (single-value today).
- **Priority:** P2
- **Type:** error-handling (secondary: product-gap / DoS)
- **Where:** `src/Content/Validator.php:23-46` (no length rules), `src/Content/FieldTypes/TextType.php` / `TextareaType.php` (no `validate`), `src/Content/EntryRepository.php:122-147` (title `VARCHAR(255)`, slug `VARCHAR(191)`), `src/Content/EntryService.php:147-153` (unbounded relation/media sync)
- **What:** No field type or the Validator enforces a maximum length, and column widths are smaller than what the API accepts; with `STRICT_TRANS_TABLES` on, an over-long value is a DB error, not a validation error, and the JSON `data` column / relation set have no size cap.
- **Evidence:** Server `@@sql_mode` includes `STRICT_TRANS_TABLES` (verified live). A title > 255 chars (or slug > 191) → `PDOException` 1406 "Data too long", not a duplicate key, so it bypasses the recovery catch and 500s. Text/textarea fields have no `validate()` override, so an API/MCP client with `{handle}:write` can push a multi-megabyte string into the JSON `data` column (bounded only by `max_allowed_packet`), and `RelationRepository::sync` / `MediaUsageRepository::sync` loop over *every* submitted id with no cap — a payload of 100k relation ids is 100k inserts in one transaction. Storage-growth / write-amplification DoS from a single scoped token, plus the correctness surprise of a 500 on ordinary too-long input.
- **Fix:** Add a length ceiling for scalar text types (a `maxlength` field option with a sane default, validated centrally or in `BaseType::validate`), validate `title`/`slug` length against the column widths before persist (return `invalid`), and cap relation/media array cardinality. Keep it lightweight — a couple of bounds checks, not a schema framework.
- **Effort:** M

### DATA-4 · A multi-statement migration that fails partway is unrecoverable (no per-statement idempotency; recorded only on full success) ✅ RESOLVED
- **Resolved:** Slice L. `Migrator::runStatements` now treats a "schema object already exists" error as an already-applied no-op (skip + `error_log`, continue), so a partially-applied migration self-heals on re-run — retiring the DROP + `DELETE FROM nb_migrations` ops dance (superseding the 2026-08-18 ledger gotcha for the re-run case). Chosen over the finding's minimal `CREATE TABLE IF NOT EXISTS` (both lenses): the executor approach is one place, covers CREATE/ALTER/INDEX/FK uniformly, and — critically — heals the **011_token_role ALTER pair** that `IF NOT EXISTS` cannot (MySQL 8 has no `ADD COLUMN IF NOT EXISTS`). New `Connection::isDuplicateObject` matches errno **1050/1060/1061/1826** (table/column/key-name/FK-name); deliberately **excludes 1062** (row-level dup — a genuine data error stays fatal) and the shaky 1022. Two must-gets both reviews flagged, both done: (1) the check reads `errorInfo[1]` on the **original** exception, before `runStatements` re-wraps it (the wrapper has no `errorInfo` — would have silently disabled the skip); (2) every skip is **logged** (name + statement index), not silent — visibility is what's traded for the self-heal. A genuine error (syntax, missing FK target, wrong type) is not in the errno set → still throws → the core loop stays fail-closed (`MigrationFailed`). Severity: Low (availability/ops). Tests: `MigrationRecoveryTest` (partial CREATE self-heals; partial ALTER self-heals — the case justifying the approach; a bad statement still fails closed).
- **Priority:** P2
- **Type:** error-handling (migration-safety)
- **Where:** `src/Database/Migrator.php:66-78` (`apply`)
- **What:** `apply()` runs each statement in a file with `PDO::exec` (MySQL auto-commits DDL — no rollback) and only records the migration in `nb_migrations` *after all* statements succeed, while the statements themselves are plain `CREATE TABLE` / `ALTER` (no `IF NOT EXISTS`), so a mid-file failure leaves a partially-applied, unrecorded migration that can never be re-run cleanly.
- **Evidence:** `001_core.php` is ~9 `CREATE TABLE`s in one file. If statement 5 fails (a transient error, a pre-existing table, an FK ordering issue), statements 1–4 have already created their tables and committed (DDL is non-transactional in MySQL), but the `INSERT INTO nb_migrations` at the end never runs. On the next `migrate()`, `apply('001_core.php')` restarts at statement 1 → `CREATE TABLE nb_users` → "table already exists" → the whole run aborts again. Recovery requires the manual DROP + `DELETE FROM nb_migrations` dance already documented as an ops gotcha in the decision ledger (2026-08-18 / 2026-08-20) — i.e. this has bitten in practice. The `Migrator` docblock claims idempotency it does not provide at statement granularity.
- **Fix:** Make each statement individually safe — prefer `CREATE TABLE IF NOT EXISTS` / guarded `ALTER` in migration files, or split one file = one statement, or record progress per statement. Minimal: adopt `IF NOT EXISTS` in the core CREATE files (they're forward-only and additive) so a partial 001 self-heals on re-run.
- **Effort:** M

### DATA-5 · API list path re-introduces the N+1 (per-row media + relation expansion), now that both reference types exist
- **Priority:** P3
- **Type:** performance
- **Where:** `src/Content/EntryView.php:40-70` + `:86-89` (`many` → `one` per row), `src/Content/RelationRepository.php:48` (`liveTargets` one query/relation-field/entry), `src/Media/MediaRepository::find` (one query/media-field/entry), driven by `src/Api/EntryOperations.php:66-90` (`list`)
- **What:** Listing live entries expands media and relations per row, so a page of N entries with a media field and a relation field issues up to ~2N extra queries on top of the list query.
- **Evidence:** `EntryView::many` maps `one()` over every row; `one()` calls `$this->media->find()` per media field and `$this->relations->liveTargets()` per relation field. A `list` of `per_page=50` (the `MAX_PER_PAGE` cap) on a collection with one media + one relation field ≈ 1 + 50 + 50 = 101 queries per request, on the **public/token read API** (not just admin). The decision ledger (2026-08-15 F1) accepted this "same N+1 shape as media" and set the revisit trigger as *"a third reference-resolving field type, or list-view N+1 under load."* The admin listing N+1 was fixed (2026-08-22) but this API path was not — and media + relation are already two per-row resolvers hit together, so the exposure is live now, not hypothetical. Bounded by `MAX_PER_PAGE`, hence P3.
- **Fix:** Batch-expand at the `many()` level — collect all media ids / (entry_id,field_id) pairs across the page and resolve with one `WHERE id IN (...)` / one grouped relation query each, then map back. This is the "third consumer → extract a batched reference-expansion capability" the ledger anticipated.
- **Effort:** M

### DATA-6 · Thin validation coverage for the relation/media/number wire round-trips (test-gap)
- **✅ PARTIAL** (Slice C, 2026-08-23) — the relation round-trip is now covered by `RelationIntegrityTest` (write-constraint, order, read-gate, scope, lazy-clean). Media/number round-trips remain.
- **Priority:** P3
- **Type:** test-gap
- **Where:** `tests/Unit/ValidatorTest.php`, `tests/Integration/EntryServiceTest.php`, `tests/Http/ApiWriteTest.php`
- **What:** The scenarios behind DATA-1/DATA-2/DATA-3 have no guarding test, so a future refactor can silently re-open them.
- **Evidence:** No test asserts that a relation id outside the declared target collection is rejected (DATA-1), that a malformed `published_at` yields a structured `invalid` rather than a 500 (DATA-2), or that an over-long/oversized value is rejected before it reaches strict MySQL (DATA-3). `NumberTypeTest` covers code-vs-message but not the boundary coercions. Each confirmed finding above needs a red-on-vulnerable / green-on-fixed test as part of its fix.
- **Fix:** Add the three regression tests alongside the fixes (relation-target rejection via the shared service; `published_at="soon"` → 422/`invalid`; over-long title/text → validation error, not 500).
- **Effort:** S

---

## What's solid

- **SQL injection:** clean. Every value is `:name`-bound through the `Connection`
  facade; the only interpolated fragments (`LIMIT`/`OFFSET` in
  `EntryRepository::liveForCollection` and the paged `forCollection`) are typed
  `int` under `strict_types`, so a non-int `TypeError`s before touching SQL. No
  dynamic ORDER BY / identifier is taken from input.
- **Transaction boundaries:** entry + relations + media-usage persist in one
  `Connection::transaction` (`EntryService::persist`); collection + fields in one
  (`CollectionService`). The reentrant `transaction()` joins an outer one instead
  of nesting. Events dispatch only *after* commit.
- **Concurrency:** the unique index is the authority — duplicate-key is caught and
  recovered (singleton re-targets the existing row; a slug collision re-uniquifies)
  rather than pre-checked-then-raced. Optimistic `version`/If-Match on the write API.
- **Field-type discipline:** strict `get()` on write paths (unknown type raises,
  never silently becomes text and rewrites data); `forDisplay()`/`MissingType`
  degrades the admin without corrupting; `EntryService` refuses the whole save when
  a provider is missing. Boolean/relation/media wire shapes are correct (bool as
  JSON bool, relations/media expanded at the edge).
- **Schema:** FK cascades are right (fields/entries/relations/revisions/media-usage
  all `ON DELETE CASCADE` from their parents; `role_id` `SET NULL`), the live
  predicate is indexed (`idx_entry_live`), and the "queryable → real column, else
  JSON" principle holds. `media_id` deliberately non-FK is a reasoned, documented
  choice, not an oversight.
- The number scientific-notation coercion I suspected does **not** exist on PHP 8
  (`(int)"1e3"` → 1000, verified) — no finding there. The `8.00`→`8` case (F3) is
  already a recorded application-concern rejection, not re-litigated.
