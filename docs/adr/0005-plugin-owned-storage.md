# 5. Plugins may own and query their own storage

- **Status:** Accepted (direction approved; the capability's concrete API is
  designed in its implementation PR)
- **Date:** 2026-08-15
- **Related:** [ADR 0001](0001-plugin-contract.md) (plugin contract),
  [ADR 0004](0004-plugin-head-contributions.md) (head contributions)
- **Drives:** the first-party analytics portal, and the "observe → store → show"
  class of plugins (search, forms, comments, webhooks, activity log, redirects).

## Context

`PluginContext` today exposes two capabilities — field types and head
contributions — and its docblock lists, under *"Deliberately absent, and
staying absent,"* **the database connection and repositories**, because *"direct
table access bypasses the services owning transactions, validation, slugs and
events."*

A first-party analytics portal (and a whole category of plugins) needs to
**store data of its own** and **query it back** for an admin view. Nothing in
the contract allows it. This ADR decides whether, and how, a plugin may have
storage — without reopening the door the contract deliberately shut.

The key observation: the refusal's rationale is about **core's** tables. A
plugin writing to its *own* `analytics_hits` table bypasses no core service and
breaks no core invariant. "No database" was always shorthand for "no bypassing
the services that own core's data."

## Decision

A plugin may **own and query its own tables** — and only its own.

1. **Own tables.** A plugin declares migrations that create tables in its own
   namespace (a per-plugin prefix), run by core's `Migrator` and recorded in
   `nb_migrations`. A plugin never issues DDL against `nb_*` core tables.
2. **Scoped data access.** The plugin is handed a narrow query interface bound
   to its own tables — parameterised statements only — **not** the core
   `Connection`, and **not** any repository. It can read and write its own data;
   it cannot reach core tables through it.
3. **Core data stays behind services.** A plugin still may not touch core tables
   directly. Reading or writing *core content* is a **separate, later** decision
   (see below), and when it comes it will be a governed operation API, never raw
   SQL.

This is expressed as new capabilities on `PluginContext` (designed in their own
slices): a **migrations** capability and a **scoped storage** capability. They
follow the established pattern — a plugin receives a capability, never the object
that implements it.

### Amendment to the plugin boundary

`PluginContext`'s "staying absent" list is amended, when the storage capability
lands, from *"the database connection and repositories"* to:

> the **core** database connection, core tables, and repositories — the things
> that own core's invariants. A plugin may own and query its **own** tables
> through a scoped interface; it may never reach core's storage directly.

The principle it protects is unchanged: **no plugin bypasses the services that
own core's data.** Own-table storage does not bypass anything.

## The tiered model for *core* data access (documented future, not built now)

Plugins will eventually want core data (an importer, a related-content plugin, a
bulk editor). Raw SQL to `nb_*` tables — even behind a permission layer — is
rejected as the mechanism: **permissions on SQL give access control without
integrity.** You can gate *which* table, but not stop a plugin writing a
malformed entry, skipping the publish event, or corrupting a slug. Core data
access must be governed at the **operation** level, in tiers, each its own ADR
with a concrete consumer:

| Tier | Access | Mechanism | Integrity |
|------|--------|-----------|-----------|
| 0 | a plugin's **own** tables | this ADR — scoped query interface | nothing to bypass |
| 1 | **read** core content | a scoped read capability over the existing `EntryView` read model | read-only |
| 2 | **write** core content | *through* core services (`EntryService` …), gated by scopes, audited | services still own every invariant + event |
| 3 | raw core-table SQL | resisted indefinitely; if ever, an explicit, admin-granted, audited, narrowly-scoped grant with a loud warning | — |

Tiers 1–3 share one substrate with the MCP integration and any future public
write API: **per-capability scopes** (the reserved `nb_api_tokens.abilities`
column), a **principal binding** the loader/token owns so a grant cannot be
spoofed, and an **audit log**. Build that governance once, well — not three
times. This ADR does not build it; it records the shape so Tier 0 does not
preclude it.

## Consequences

- The plugin contract gains storage; the "observe → store → show" plugin class
  becomes buildable without touching core (analytics, search, forms, comments,
  webhooks, activity log, an admin-managed Redirects).
- Core stays the sole owner of core data. The boundary moved from "no plugin
  storage at all" to "no plugin access to *core's* storage."
- A migration-and-storage-owning plugin raises new concerns handled in the
  capability slices: table-namespace collisions, migration ordering, uninstall
  cleanup (deferred), and SQL safety (bound-param helpers; plugin code is trusted
  like a field type, but the interface should make the safe path the easy one).

## Alternatives considered

- **A narrow core key/value or counter store** (no plugin tables). Rejected for
  the portal: it cannot answer the time-series and top-N queries rich charts
  need. May still be offered as a convenience later.
- **Client-side-only analytics** (agent injection + the provider's dashboard, no
  first-party storage). Kept as the *v0.1* quick win, but it is not a first-party
  portal.
- **Hand plugins the core `Connection`.** Rejected — it is precisely what the
  contract refuses, and it makes every core-schema change a plugin break.
