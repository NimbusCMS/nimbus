# 29. A read-only content capability for plugins

- **Status:** Accepted
- **Date:** 2026-09-05
- **Supersedes:** —
- **Related:** [ADR 0005](0005-plugin-storage.md) (plugin storage — the write side
  of a plugin's *own* tables), [ADR 0019](0019-plugin-service-ports.md) (typed
  service ports between plugins), the plugin boundary in
  `src/Plugin/PluginContext.php`.

## Context

The plugin boundary deliberately hands a plugin **no** core services and **no**
service locator: `PluginContext` gives a plugin its own storage, migrations,
admin pages, MCP toolsets, routes, events and typed service ports, and its
docblock explicitly refuses "a generic get() or service locator", because direct
access to core data would bypass the services that own transactions and
validation.

That is right for **writes**. But it also left a plugin with no supported way to
**read a collection** it does not own. The first real application built on the
platform — the Restaurant Management System, whose orders must let staff pick from
a `menu_items` **collection** and snapshot the chosen item's name and price — hit
this immediately. The only workarounds were to read core's `nb_*` tables raw
(coupling the app to core's internal schema, the very thing the boundary exists to
prevent) or to call the app's own HTTP read API from inside the process (absurd).
This was predicted as findings **F1/F2/A2** in that app's validation ledger, and
is exactly the kind of broadly-reusable capability the validation initiative
exists to surface: *any* application plugin that composes with content (bookings
reading services, events reading venues) wants it.

## Decision

Add a small, read-only, **published-only** content read capability for plugins:

- `Nimbus\Content\ContentReader` — a thin facade over the existing
  `CollectionRepository::findByHandle`, `EntryRepository` live-reads, and the
  `EntryView` serializer. Methods: `entries(handle, limit, offset)`,
  `count(handle)`, `entry(handle, id)`, `entryBySlug(handle, slug)`.
- Exposed as `PluginContext::content()`, lazily built from the kernel's
  connection and field-type registry — the read-only complement to
  `storage()` (ADR 0005).
- `EntryRepository::findLive(collectionId, id)` — the by-id twin of the existing
  `findLiveBySlug`, carrying the identical published predicate.

It returns entries in the **same shape** as `GET /api/v1/collections/{handle}/entries`,
with references expanded (there is no token in-process, so, like a theme, it sees
the full published entry).

### Why this shape, and not the alternatives

- **Not raw table access.** The facade is the boundary: it returns published,
  serialized entries, never raw rows, and cannot write — so no plugin couples to
  `nb_*` and no write path is bypassed.
- **Not a general service locator.** Only *read* content is exposed, and only the
  *published* slice of it; the boundary's refusal of arbitrary core access stands.
- **A `PluginContext` accessor, not an ADR-0019 service.** Reading content is a
  capability essentially every content-composing plugin wants, so it is a
  first-class, discoverable accessor rather than a service some other plugin must
  happen to provide.

## Consequences

- **Security — no new exposure.** `ContentReader` surfaces only what the public
  API and themes already surface: published entries (`status='published'`,
  `published_at <= NOW()`). It is strictly less powerful than the public API (no
  writes, no other-status reads). The load-bearing property — that a draft,
  scheduled-but-not-due, or wrong-collection entry is **never** returned by any
  method (including by id) — is pinned by a regression test; `findLive` reuses the
  exact predicate of `findLiveBySlug` so the by-id path cannot drift into a draft
  leak. There is no per-token scope filtering (there is no token); this is the same
  full-entry view a theme renders, and is intended.
- **Reuse.** Any application plugin can now compose with content it does not own,
  in-process, without coupling to core internals — the read complement to ADR
  0005's storage.
- **Bounded.** Read-only and published-only by design. A plugin that needs to read
  drafts uses the existing authorized preview path (ADR 0021), not this; a plugin
  that needs to write content still goes through `EntryService`. Neither is added
  here.

## Follow-ups

- Documented as a plugin capability in the plugin/extension docs.
- The Restaurant rebuild's Orders vertical is the first consumer; its ledger
  findings F1/A2 are closed by this, F2 by ADR-0001 in that repo.
