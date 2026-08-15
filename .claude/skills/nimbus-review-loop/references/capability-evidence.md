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
| **Events** (`EventDispatcher`, `CoreEvents`) | core (`EntryService`) only | **0** | **Internal** — not on `PluginContext` | n/a | plugins cannot subscribe yet; payload shapes not frozen | a concrete official plugin (revisions / activity log / webhooks) that needs to listen → then design an event capability |
| **Plugin routes / admin pages** | core controllers only | **0** | **Not exposed** | n/a | no way for a plugin to add a route or admin page | an official plugin that genuinely needs an admin screen (e.g. Redirects, Search) → smallest route capability |
| **Permissions** | per-collection manage roles; admin override | **0** external | **Internal** | n/a | fixed roles (admin/editor/author); no custom capabilities | an extension or app that needs a role/capability it can't express |
| **Migrations** (plugin-owned) | 3 core migrations | **0** | **Not exposed** | n/a | a plugin cannot ship its own tables | an official plugin needing persistent storage of its own |
| **Admin navigation** | core nav (hardcoded) | **0** | **Not exposed** | n/a | plugins/apps cannot add nav items | a plugin/app that needs a nav entry alongside an admin page |
| **Themes / rendering** | admin "Nimbus" theme; public `starter` theme (core) | **0** external | Public theme contract ([ADR 0003](../../../../docs/adr/0003-public-rendering-and-theme-contract.md), COMPATIBILITY) | pre-1.0 | presentation-only (no plugin can *provide* a theme yet); partials, per-collection specialization, assets, menus, blocks, head slot | a **plugin-provided** theme, or a community theme, for independent proof |
| **Media foundation** (`MediaUploader`, `MediaRepository`) | core media field; admin library | **0** external | **Internal** | Stable | single-file fields; no resizing/thumbnails; local disk only | an "advanced media" plugin or an app that needs remote storage / crops |

## Reading this table

- **Two capabilities now have independent proof — field types (Markdown) and
  head contributions (SEO) — each with exactly one official consumer.** Both
  want a *second, unrelated* (ideally community) consumer before they are
  considered broadly proven. Everything else is internal or has zero external
  consumers. This is the correct state for a young platform: capabilities are
  added when an extension needs them, not before.
- A capability with **0 unrelated proven** should not be widened, generalized,
  or frozen. If a change proposes to, the Platform Drift Guard likely rejects it.
- When a capability gains or loses a real consumer, update its row and add a
  decision-ledger entry with the evidence link.
