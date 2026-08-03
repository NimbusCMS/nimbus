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

---

## Open findings (proposed — awaiting classification into work)

From the Restaurant **Menu** acceptance test (Menu itself needed zero core changes):

- **F1 — API returns relations/references as bare ids.** *Proposed capability:*
  reference expansion in the read API. Classify **Core** (API maturity) — reusable
  across headless frontends, independent of Restaurant. Revisit trigger already
  half-met (media expands; relations don't). Evidence: Restaurant
  `docs/PLATFORM-VALIDATION.md` F1.
- **F2 — no supported way to consume Nimbus from a separate app repo** (root-only,
  not on Packagist, no library mode/image). Classify **Core / release process**.
- **F3 — number decimals (`8.00`→`8`).** Classify **application concern** —
  rejected for core unless several apps want a shared money field type.
