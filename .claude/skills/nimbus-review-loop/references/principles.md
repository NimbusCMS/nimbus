# Foundational principles

These are the load-bearing beliefs behind Nimbus's design. The Lead Architect hat
protects them. **They are not changed silently** — a change here is a proposal
that requires maintainer approval, recorded in the decision ledger.

They restate and extend [`docs/CHARTER.md`](../../../../docs/CHARTER.md). The
charter is the authority; this is the working detail.

## Architecture

- **Simplicity over cleverness.** The best code here is the code a new
  contributor understands in an afternoon.
- **Explicit over magic.** No hidden globals, no auto-wiring, no convention that
  can't be traced by reading. `Request` is passed, not fetched; routes are
  registered in one visible place.
- **Composition over inheritance.** Narrow objects wired together. Inheritance
  only for a genuine "is-a" (field types extend `BaseType`).
- **The database is the source of truth for invariants.** Uniqueness, foreign
  keys, cascades live in the schema. Application checks are for friendly
  feedback, not correctness. (See duplicate-handle, singleton slug.)
- **JSON is for flexibility, not weak modeling.** Entry field values live in a
  JSON column so a new field is not an `ALTER TABLE`. Anything queried, sorted,
  or constrained (status, slug, `published_at`) is a real indexed column.
- **Controllers orchestrate.** They map request → input → service → response and
  render. They do not own business rules; services do (`EntryService`,
  `CollectionService`).
- **Field types own field behavior.** render / normalize / validate / `toApi`.
  Adding a type is one class; core does not grow.
- **Plugins extend without modifying core.** Official plugins use the *same*
  public APIs as community plugins. If an official plugin needs an internal API,
  the API is evaluated for promotion — never privately reached.
- **No plugin bypasses the services that own core's data.** A plugin may own and
  query its *own* tables (scoped interface, [ADR 0005](../../../../docs/adr/0005-plugin-owned-storage.md)),
  but never touches core's connection, tables, or repositories directly. Core
  *data* access, when it comes, is a governed **operation** capability (read via
  the read model, write through services) — never raw SQL with a permission
  layer, which would grant access without integrity.
- **Events are post-commit notifications.** They fire only after a successful
  commit, and truthfully (only when the state change happened). They cannot veto
  a write. Listener exceptions surface at the error boundary.
- **Config lives in files; admin-editable content lives in the DB.** Deploy/env
  configuration — DB credentials, `APP_URL`, debug, trusted proxies, upload
  limits, rate limits, enabled plugins, the active public theme — stays in
  `.env` + `config/*.php`: it is per-environment, set at deploy, and some of it
  is needed *before* the database is available, so `Support\Config` is a static,
  **DB-free** facade and must stay that way. Values an editor changes at runtime
  (site home, description, and future site content) live in `nb_settings` behind
  the typed `Settings` service, with the `config/*.php` value as the **default**
  the DB overrides — no seed migration, so a fresh install works from the file
  and a set value wins. Do not couple `Config` to the DB, and do not move
  deploy/env config into the settings store. A setting's default *source* is
  "whatever the file layer says for that key" and may differ per setting
  (`site.home`/`site.description` default from `config/site.php`; `site.title`
  defaults from `.env`'s `APP_NAME`, the established app-identity var) — expected,
  not a bug: whether a value is editable (a DB override) is separate from where
  its shipped default lives.

## Dependencies and abstraction

- **No dependency unless it solves a hard problem well.** Runtime deps are PHP +
  core extensions. Dev-only: PHPUnit, PHPStan, PHP-CS-Fixer. Adding a runtime
  dependency is a reviewed decision, not a convenience.
- **No generic service locator, no unnecessary container.** Wiring is manual and
  visible in `Application`. A `$context->get('anything')` is the absence of an
  API, not an API.
- **Every abstraction must remove real duplication or enable real
  extensibility.** No `FactoryProviderRegistry`. If it has one consumer and no
  proven second, it is premature.
- **The public surface is small and deliberate.** See
  [`docs/COMPATIBILITY.md`](../../../../docs/COMPATIBILITY.md). Do not freeze an
  API before a real consumer has exercised it.

## Security posture (non-negotiable)

- Writes are CSRF-guarded; the admin is gated by auth middleware; permissions are
  checked in the controller.
- Output is escaped by default. Uploads are validated by **content** (finfo), not
  the client's Content-Type; stored under random names derived from the validated
  type; SVG excluded.
- `X-Forwarded-*` is trusted only from configured proxies. Sessions rotate on
  login. Errors log a reference id and never leak internals.
- Strict field-type lookup on write paths: an unknown type raises, it never
  silently becomes text and rewrites data.

## Anti-pivot (the platform stays general)

Nimbus must not bend toward any one thing:

- not Restaurant, Food Store, e-commerce, or internal-tools specifically;
- not a single frontend — PHP templates, HTMX, Alpine, Vue, React, Next, Astro
  must all be viable; **never require React or Node** for all themes;
- not headless-only and not PHP-theme-only — both must remain first-class;
- Packkit is an optional companion that *may* scaffold frontends; **Nimbus must
  never require Packkit**.

Validation projects (Restaurant, Food Store, Packkit) are **acceptance tests**.
They may reveal a limitation; they do not own the roadmap, and they stay
standalone repositories.

## First-class surfaces (mobile + MCP)

Two users are first-class on every capability, and neither is an afterthought:

- **Mobile is a first-class user.** Most web traffic is mobile; the admin (and any
  UI) must be genuinely usable on a phone, designed for ~375px from the start —
  not desktop-first with a patch. No page-level horizontal scroll; tables wrap or
  reflow; layouts collapse; touch targets ≥ 44px; no hover-only affordance. Verify
  **live at a phone width**, not just desktop.
- **The agent is a first-class operator** (MCP-native, [ADR 0009](../../../docs/adr/0009-mcp-control-surface.md)).
  An agent can run the whole CMS over MCP; the admin UI is optional. A new back-end
  capability is reachable over MCP (a tool gated by the same capability,
  non-enumerating, audited) or its deferral is recorded — the human UI never
  silently becomes the only way to do something.

These are enforced by the [Standing surface checks](review-checklist.md) and the
definition-of-done gate.
