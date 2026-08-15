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
- **Status:** proposed (three-hat review done; milestone awaits maintainer approval; nothing implemented)
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
