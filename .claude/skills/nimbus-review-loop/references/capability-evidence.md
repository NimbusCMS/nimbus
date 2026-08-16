# Capability evidence

Tracks the maturity of Nimbus's **extension** capabilities by real, independent
consumers. The rule this table enforces:

> A capability is **not** broadly proven just because one official plugin uses
> it. Broad proof needs multiple **unrelated** consumers — ideally including a
> community one.

"Unrelated proven consumers" counts distinct extensions that exercise the
capability for genuinely different purposes. Core's own built-ins are noted but
do not count as *independent* proof (they were written by the same hand as the
capability).

| Capability | Current consumers | Unrelated proven | Public API | Stability | Known limitations | Next evidence required |
|---|---|---|---|---|---|---|
| **Field types** (`PluginContext::fieldTypes()`, `FieldType`/`BaseType`) | 10 core types; `nimbuscms/markdown` (official) | **1** (Markdown) | Public ([COMPATIBILITY](../../../../docs/COMPATIBILITY.md)) | Stable-ish; pre-1.0 | single-value assumptions in some UIs; no async/remote validation | a **community** field-type plugin, and a *second* official one, for genuinely independent proof |
| **Head contributions** (`PluginContext::head()`, `HeadContributor`, `PageContext`) | `nimbuscms/seo` (official, JSON-LD) | **1** (SEO) | Public ([ADR 0004](../../../../docs/adr/0004-plugin-head-contributions.md)) | pre-1.0 | render-time only; data-only `PageContext` (no data access); one `$head` slot | a **community** head contributor, or a 2nd official one (e.g. analytics snippet), for independent proof |
| **`toApi()` serialization** | every field type; API + (future) themes | n/a (part of field types) | Public (via `FieldType`) | Stable | relations/media resolved at the serializer edge, not by the type | a 3rd reference-resolving type → promote a reference-expansion capability (see F1) |
| **Event subscription** (`PluginContext::events()`) | `nimbuscms/analytics` (page views, `request.handled`); `nimbuscms/api-advanced` (API failure audit, `api.token_rejected`/`api.access_denied`) | **2** (Analytics, Api-advanced) | Public ([COMPATIBILITY](../../../../docs/COMPATIBILITY.md)) | pre-1.0 | best-effort/isolated; entry-event payloads not frozen; `api.*` names pre-1.0 | **broadly proven** — a community listener would widen it further |
| **Admin pages** (`PluginContext::adminPages()`) | `nimbuscms/analytics` (dashboard); `nimbuscms/api-advanced` (audit log) | **2** (Analytics, Api-advanced) | Public | pre-1.0 | GET pages only (no POST/forms — needs a CSRF-token decision); trusted HTML | **broadly proven** for read views; a plugin with an admin **form** is the next gap |
| **Permissions** | per-collection manage roles; admin override | **0** external | **Internal** | n/a | fixed roles (admin/editor/author); no custom capabilities | an extension or app that needs a role/capability it can't express |
| **Migrations** (`PluginContext::migrations()`, ADR 0005) | 4 core; `nimbuscms/analytics` (`hits`); `nimbuscms/api-advanced` (`api_audit_log`) | **2** (Analytics, Api-advanced) | Public | pre-1.0 | own tables only; no uninstall/drop; no retention/prune helper | **broadly proven**; a retention/prune helper is the next need |
| **Storage** (`PluginContext::storage()`, ADR 0005) | `nimbuscms/analytics`; `nimbuscms/api-advanced` | **2** (Analytics, Api-advanced) | Public | pre-1.0 | own tables only — a **contract, not a sandbox**; no core-data access (a later tiered contract) | **broadly proven**; Tier 1 (read core content) when a plugin needs it |
| **Admin navigation** | core nav; `nimbuscms/analytics`; `nimbuscms/api-advanced` (both via their admin pages) | **2** (Analytics, Api-advanced) | Public (via admin pages) | pre-1.0 | nav entry comes with an admin page; no standalone nav or ordering | a plugin wanting nav without a page, or nav ordering |
| **Themes / rendering** | admin "Nimbus" theme; public `starter` theme (core) | **0** external | Public theme contract ([ADR 0003](../../../../docs/adr/0003-public-rendering-and-theme-contract.md), COMPATIBILITY) | pre-1.0 | presentation-only (no plugin can *provide* a theme yet); partials, per-collection specialization, assets, menus, blocks, head slot | a **plugin-provided** theme, or a community theme, for independent proof |
| **Media foundation** (`MediaUploader`, `MediaRepository`) | core media field; admin library | **0** external | **Internal** | Stable | single-file fields; no resizing/thumbnails; local disk only | an "advanced media" plugin or an app that needs remote storage / crops |

## Reading this table

- **Four capabilities are now *broadly* proven — events, migrations, storage, and
  admin pages — each with two unrelated official consumers (Analytics *and*
  Api-advanced).** That second, independent consumer is the signal we were
  waiting for: the capability worked for a genuinely different purpose than the
  one it was built for. **Field types (Markdown) and head contributions (SEO)
  still have exactly one** consumer each and want a second — ideally community.
  Capabilities were added when an extension needed them, not before, and only
  now — with independent reuse — are they treated as settled.
- A capability with **0 unrelated proven** should not be widened, generalized,
  or frozen. If a change proposes to, the Platform Drift Guard likely rejects it.
- When a capability gains or loses a real consumer, update its row and add a
  decision-ledger entry with the evidence link.
