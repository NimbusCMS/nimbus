# NimbusCMS Product & Engineering Charter

This document governs what goes into Nimbus and what does not. It overrides
earlier implementation guidance. When a decision is unclear, this is the tie-breaker.

> **North star:** Nimbus should be *opinionated about architecture, but
> unopinionated about what people build with it.*

For the human-facing introduction to *why* these rules read the way they do,
see [architecture/CORE_PRINCIPLES.md](architecture/CORE_PRINCIPLES.md). This
document is the enforceable version.

Nimbus is not trying to be the largest CMS. It is trying to be the **best
engineered lightweight PHP CMS** — the one developers enjoy reading, extending,
and maintaining. We compete on elegance, flexibility, and maintainability, not
on the length of a feature comparison chart.

Core stays: lightweight, explicit, modern, dependency-light, understandable,
extensible, production-ready. A new contributor should understand Core in days,
not weeks.

## What Nimbus is for

Nimbus should be flexible enough to power many kinds of sites and applications —
marketing sites, blogs, docs, business sites, portfolios, headless frontends,
membership sites, e-commerce, dashboards, internal tools. These examples
**validate flexibility; they do not define the roadmap.** Nimbus must never
become "the CMS optimized for X."

## Validation projects are acceptance tests

Restaurant Management, Food Store, and Packkit exist to prove Nimbus is flexible
enough. **They are acceptance tests, not product requirements. Do not build
Nimbus around them.**

- If Nimbus naturally supports what they need — good.
- If it cannot, investigate *why*, and add a capability **only if it is broadly
  reusable**.
- Restaurant-specific behaviour (tables, kitchen queues, reservations, cart
  rules) stays in the application, never in Core.

## Where a feature belongs — classify before building

| Layer | Belongs here when it is… | Examples |
|-------|--------------------------|----------|
| **Core** | foundational for many CMS installs | collections, entries, fields, auth, authorization primitives, media foundation, rendering, routing, API, plugin/theme loading |
| **Official plugin** | useful across many projects but optional | Markdown, SEO, Forms, Redirects, Search, advanced media, AI |
| **Theme** | presentation only, no business logic | layouts, styling, templates, frontend assets, React/Packkit frontends |
| **Application** | domain-specific | restaurant workflows, payment rules, inventory, reservations, cart behaviour |

Core exposes **capabilities**. Applications implement **business rules**.

## Capability rules

A capability is added **only** when:

- multiple *unrelated* use cases need it, **or**
- it unlocks an entire category of reusable extensions.

Good capability: **field types** — dozens of plugins reuse it.
Bad capability: **a Kitchen Display API** — only Restaurant needs it.

## Plugins and themes

Plugins **extend** Nimbus; they do not **redefine** it. Official plugins use the
exact same public APIs available to community developers — if an official plugin
needs an internal API, that API is evaluated for promotion into the public
surface, not privately reached.

Themes are presentation. Nimbus must support many frontends (PHP templates,
HTMX, Alpine, Vue, React, Next, Astro). **Nimbus must never require Packkit and
never require React.**

## The three-hat review

Every significant change passes three independent reviews before it lands.

**Product Owner** — Does this make Nimbus better for *many* users? Would
unrelated sites benefit? Does it improve the editor or developer experience? Is
it solving today's problem? *Reject features built around one future project.*

**Lead Architect** — Does this belong in Core, or would a plugin or theme be
better? Is it reusable? Does it add unnecessary coupling? Does it preserve
long-term flexibility? *Prefer capabilities over application-specific logic.*

**Principal PHP Engineer** — Is it understandable, testable, secure,
maintainable? Does it add technical debt? Can it be simpler? *Would a mature
open-source project accept this?*

## Deciding a roadmap item

Every candidate answers: Why does this exist? Who benefits? Does it belong in
Core? Would a plugin suffice? Can it wait? What future maintenance does it
create? **If the answers are weak, it is not built.**

Do not build a feature because another CMS has it, because it might be useful,
or because infrastructure sounds extensible.

## Current priority: production readiness

The priority is not more features — it is making Nimbus something people would
confidently deploy. Focus: installation, upgrades, media, editor experience,
public rendering, API maturity, documentation, testing, performance, security,
release process. These improve *every* Nimbus project.

## Success

Nimbus succeeds when developers can build many kinds of sites **without
modifying Core** — plugins add reusable optional functionality, themes add
presentation, applications add domain behaviour, and Core stays small enough to
learn in days.

*Never build a feature for the sake of having a feature. Build capabilities that
unlock broad classes of solutions.*
