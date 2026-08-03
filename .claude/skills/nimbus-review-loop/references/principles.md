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
- **Events are post-commit notifications.** They fire only after a successful
  commit, and truthfully (only when the state change happened). They cannot veto
  a write. Listener exceptions surface at the error boundary.

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
