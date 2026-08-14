# ADR 0003 — Public rendering & the theme contract

- **Status:** Accepted (maintainer approved 2026-08-14; the three open questions are resolved below)
- **Date:** 2026-08-03
- **Context:** the production-readiness milestone. Nimbus serves `/admin` and
  `/api` only; `src/Site/` is empty. It cannot render a public page, which
  excludes the largest category of CMS users (marketing sites, blogs, docs,
  portfolios, business sites).
- **Governed by:** [`docs/CHARTER.md`](../CHARTER.md),
  [`architecture/CORE_PRINCIPLES.md`](../architecture/CORE_PRINCIPLES.md)

This ADR decides the **contract** for public rendering. It does not build the
renderer — that follows in small PRs once the contract is approved.

## The problem, and the trap

A CMS must be able to render its own content. But adding rendering is the single
easiest way to violate the north star — *unopinionated about what people build* —
by quietly coupling Nimbus to one frontend (PHP-only, or React-only) or by
letting business logic leak into templates. The contract below exists to make
that impossible.

## Decision

### 1. A theme is a directory of plain-PHP templates + a manifest

```
themes/<name>/
    theme.json        name, version, which templates it provides
    templates/*.php    plain PHP, rendered in an isolated scope
    assets/            optional static CSS/JS (no build step required)
```

This reuses the existing `Nimbus\View\View` renderer, which already powers the
admin "nimbus" theme (isolated scope, shared data, an `e()` escaping helper). No
new templating engine, no new dependency. The active public theme is chosen by
config, with a built-in minimal default so a fresh install can serve a page.

**Plain PHP with no build step is first-class and always will be.** A theme
*may* ship its own JS/CSS or a build pipeline, but **Nimbus requires none — never
React, never Node, never Packkit.** Because a theme only receives data (below),
it is free to render server HTML, or emit JSON for a JS island, or anything else.

### 2. Templates receive a data-only view-model — never services

A template is handed a prepared, plain-data view-model (arrays / small readonly
DTOs) and an escaping helper. It has **no** database connection, **no**
repositories or services, and holds **no** business logic. It renders what it is
given. This is the same discipline the admin templates already follow, made a
rule: *themes are presentation only.*

Escaping: templates escape with the provided `e()` helper (as the admin does);
the contract requires it for any content value. PHP is escape-*by-helper*, not
auto-escape — honest naming. If themes prove leaky in practice, an auto-escaping
value wrapper (`__toString()` escapes, `->raw()` opts out) is the documented
upgrade path (see Deferred).

### 3. One content shape for the API *and* themes

The API already turns an entry into resolved, `toApi()`-serialized data via
`Nimbus\Api\EntrySerializer` (id, slug, title, published_at, fields — with media
expanded). **Themes need exactly that same resolved shape.** So the serializer
becomes a shared **content view-model**, used by both the read API and the
renderer, rather than each growing its own.

Concretely: move `EntrySerializer` to a neutral home (`Nimbus\Content\EntryView`
or similar) and let both consumers use it. This is a *capability with two
genuinely unrelated consumers* (headless clients and server themes) — exactly
what the charter's capability rule blesses — and it means there is **one**
definition of "what an entry looks like outside core."

This also absorbs **finding F1** (the API returns relations as bare ids): making
the shared view-model expand references serves the API and themes at once, so F1
is folded into this milestone rather than done separately.

### 4. Only the live set renders; the router yields to admin/api

Public routes serve exactly the **live** publication set (`published` and
`published_at <= now`) — the same predicate the API and admin badges use. A
draft or not-yet-due entry is a 404, indistinguishable from absent. No auth on
public pages (they are public); GET-only, so CSRF is not in play.

The public router is registered **after** `/admin` and `/api` and never shadows
them (first-match wins; public patterns are specific).

## First slice (what actually ships first, smallest useful)

1. A theme-contract ADR — this document — approved.
2. Extract the shared content view-model (`EntryView`) from the API serializer;
   the API keeps working through it (pure refactor, no wire change) + add
   relation expansion (F1) to it.
3. A minimal built-in default public theme (`themes/starter/`, plain PHP).
4. A public router with the smallest useful routes:
   - `GET /{collection}/{slug}` → render one live entry;
   - `GET /{collection}` → render a collection's live entries (paginated list).
   - (`GET /` home is a **deferred** choice — see open questions.)
5. HTTP-functional tests: a live entry renders; a draft/scheduled 404s; the
   route never shadows `/admin` or `/api`; templates receive no services;
   output is escaped.

Each is its own PR, three-hat reviewed, CI green.

## Deferred (explicitly not in the first slice)

- Configurable route patterns (`/blog/{slug}`, nested paths, per-collection).
- A home-page model (which collection/entry/template is "home").
- Menus / navigation, global site settings, reusable blocks.
- Preview mode (render drafts via a signed token).
- Page caching + invalidation on publish; a "rendered in N ms" signal.
- SEO surface (meta, Open Graph, sitemap.xml, RSS) — likely an **official
  plugin**, not core.
- Auto-escaping value wrapper (only if helper-convention proves leaky).
- Themes shipped/overridden by **plugins** (start with a themes directory; a
  plugin theme-provider capability waits for a plugin that needs it).

## Resolved decisions (were open questions)

1. **Home page — deferred.** `/` keeps its current placeholder in this slice. A
   real home page waits until a collection can be *designated* as home, and that
   designation is itself deferred until collections and the entry/collection
   routes exist and there is evidence for what a home page should show. We ship
   `GET /{collection}` and `GET /{collection}/{slug}` first; `/` is decided
   later, with the routing in place, rather than guessed at now.
2. **Theme selection — `config/theme.php`.** A config file returning the active
   theme name, matching the existing `config/plugins.php` convention, not an
   `THEME=` environment variable. Selection stays in the same place, and in the
   same form, as the rest of Nimbus configuration; no new configuration channel
   is introduced for one setting.
3. **Serializer move — renamed to `Nimbus\Content\EntryView`.** `Nimbus\Api\
   EntrySerializer` moves to `Nimbus\Content\EntryView`. It is an internal
   refactor (both consumers — the read API and the public renderer — are core),
   so it does not change the wire contract. The one content shape now lives in
   `Content`, where both the API and themes draw from it, rather than themes
   depending on a class that reads as API-only.

## Three-hat summary

- **Product Owner:** unlocks the largest category of unrelated sites (blog, docs,
  marketing, portfolio, business) that cannot use Nimbus today. General, not
  tied to any validation project. ✓
- **Lead Architect:** rendering/router/theme-loading are Core per the charter;
  the shared content view-model is a capability with two unrelated consumers;
  data-only templates preserve frontend freedom; nothing is frozen prematurely
  (route patterns, home, plugin themes all deferred). ✓
- **Principal Engineer:** reuses `View` (no new engine/dep); one content shape
  reduces duplication; live-only rendering reuses the tested publication
  predicate (no draft leak); GET-only public routes; escaping required. Watch:
  route precedence vs admin/api, and the honesty of "escape-by-helper". ✓

**Platform Drift Guard:** general CMS problem ✓; many unrelated sites benefit ✓;
evidence not speculation (a CMS can't render a page today) ✓; **would build it
even if Restaurant/Food Store/Packkit did not exist — yes.** Does not require
React/Node/Packkit; does not assume headless-only or PHP-theme-only; adds no
application-framework abstraction.

**Classification:** Core (rendering, public router, content view-model) + a
starter Theme.
