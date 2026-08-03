# Core principles

*A short guide to how NimbusCMS is designed, and why. If you are about to
contribute, extend, or evaluate Nimbus, start here.*

---

## The one sentence

**Nimbus is opinionated about architecture, and unopinionated about what you
build with it.**

Everything below follows from that. Nimbus takes strong positions on *how* it is
put together — small core, explicit code, clear layers — precisely so that it
takes *no* position on whether you use it for a blog, a documentation site, a
marketing page, a headless backend, a portfolio, or something nobody has thought
of yet. The architecture is rigid so the possibilities can stay open.

## What Nimbus is trying to be

Not the biggest CMS. Not the one with the longest feature list or the most boxes
ticked on a comparison chart. Nimbus is trying to be the **best-engineered
lightweight PHP CMS** — the one a developer can read in an afternoon, extend
without fighting it, and maintain without dread.

Most PHP content systems are either enormous or abandoned. Nimbus aims for a
third thing: small enough to understand completely, modern enough to enjoy, and
solid enough to trust. It runs on PHP 8.2+ with PDO and effectively no runtime
dependencies. A new contributor should be able to hold the whole core in their
head within days, not weeks. If the core ever grows past that, something has
gone wrong.

## The four layers

Nimbus separates everything you might want to change into four places. Knowing
which layer a piece of work belongs to is the single most important design skill
when working on Nimbus, so most of this document is about that boundary.

```
Core          the small, stable engine
Plugins       optional, reusable extensions
Themes        presentation
Applications  your domain-specific behaviour
```

### Why some things are in Core

Core holds what almost every Nimbus site needs and what nothing else can
sensibly provide: collections and entries, fields, authentication and
authorization primitives, the media foundation, routing, the read API, and the
machinery that loads plugins and (soon) themes. These are foundational — take
any of them away and Nimbus stops being a CMS.

Core earns its small size by being suspicious of itself. A capability is added
to Core only when **multiple unrelated use cases** need it, or when it unlocks a
whole category of reusable extensions. "Field types" is the model example: it is
one small contract, and dozens of plugins can build on it. That is a good
capability. "A kitchen-display endpoint" is the opposite — exactly one
imaginary application wants it — so it never belongs in Core.

When a change *could* live in Core but doesn't strictly *need* to, it goes in a
plugin instead. This is a deliberate bias, and the reason is asymmetry: a plugin
can be changed or removed later, but a public Core capability, once other people
depend on it, is close to permanent. We would rather add a capability late, with
evidence, than carry a mistake forever.

### Why other things are Plugins

Plugins are how Nimbus stays small without staying limited. Anything genuinely
useful but *optional* — Markdown, SEO, forms, redirects, search, advanced media,
AI helpers — belongs in a plugin. Plugins are ordinary Composer packages; you
add one with `composer require` and it works, and disabling one never puts your
content at risk.

There is one rule that keeps this honest: **plugins extend Nimbus; they do not
redefine it.** Official plugins, written by the Nimbus team, use the *exact same*
public APIs available to any community developer. There is no privileged back
door. If an official plugin finds it needs something only an internal API can
provide, that is treated as a signal that the internal API might deserve
promotion to the public surface — not as licence to reach around the contract.
The health of the extension system is measured by whether an outsider could have
built the same plugin.

Plugins prove the extension architecture works. The current public extension
surface is deliberately tiny — today a plugin can register field types, and the
Markdown plugin is the proof. Every further extension point (routes, events,
permissions, navigation) will be added the same way: once, when a real plugin
concretely needs it, never as a speculative batch of hooks designed in advance.

### Why Themes are presentation only

A theme decides how content *looks*. It owns layouts, templates, styling, and
frontend assets — and nothing else. A theme must never contain business logic,
never reach into the database directly, and never decide *what* the content
means; it receives prepared, escape-by-default data and renders it.

This boundary exists so Nimbus can stay unopinionated about frontends. A theme
might be plain PHP templates, or use HTMX, Alpine, Vue, React, Next, or Astro —
all are equally valid. Nimbus must run perfectly with a plain PHP theme and no
build step at all, and it must **never require React, Node, or any particular
tool** to render a page. Companion projects (such as Packkit) may one day help
scaffold richer frontends, but Nimbus will never depend on them. Keeping logic
out of themes is what makes this promise keepable: if themes were allowed to
make decisions, swapping one for another would risk changing behaviour, and the
frontend choice would stop being free.

### Why Applications own the business rules

The interesting, domain-specific behaviour — a restaurant's kitchen queue, a
shop's pricing rules, a reservation scheduler, a cart — lives in the application
built *on* Nimbus, not in Nimbus. Nimbus exposes **capabilities**; applications
implement **rules**. A capability is reusable and belongs to many; a rule is
specific and belongs to one. Keeping rules in the application is what lets Nimbus
serve wildly different products from the same small core.

## Why validation projects don't drive the roadmap

Nimbus is exercised by real applications — a Restaurant Management System, a Food
Store, and others — to make sure it is genuinely flexible. This is how mature
platforms stay honest: the framework is continuously used to build real things.

But these projects are **acceptance tests, not requirements.** They exist to
answer one question — *can a full application be built on Nimbus without changing
its core?* — and to reveal, by failing, where Nimbus is too rigid. What they do
**not** get to do is dictate features. When one of them hits a wall, we do not
bend Nimbus around it. We ask a narrower question: is the missing thing broadly
reusable, useful to many unrelated sites? If yes, it may become a capability. If
it is specific to that one application, it stays in that application, and Nimbus
learns nothing except that the boundary held.

There is a blunt test for this, applied before any roadmap item is accepted:

> **Would we still build this if the Restaurant, Food Store, and Packkit
> projects did not exist?**

If the answer is no, the item is rejected or deferred. Nimbus evolves because it
becomes a better *general* CMS — never because one future application might want
something. The validation projects remain standalone repositories, and always
will.

## The three-hat review

Every significant change is reviewed from three independent perspectives before
it lands. They are meant to disagree; the tension is the point.

**The Product Owner** asks whether this solves a real problem for *many* kinds of
site. Which unrelated website types benefit? Does it improve the experience of an
editor or a developer? Or are we building it just to have it, or quietly bending
toward one project? A feature that only one hypothetical site needs is rejected
here.

**The Lead Architect** asks where the change belongs — Core, plugin, theme, or
application — and pushes it outward whenever it can go outward. Is a reusable
capability actually justified, or is this a one-off in disguise? Is a public API
being frozen before anything has proven its shape? Is there a simpler, more
explicit design? The architect protects the small core, the clean layers, and
the freedom of plugins and themes.

**The Principal Engineer** asks whether the implementation is correct, secure,
and maintainable. Are inputs validated, writes transactional, queries free of
N+1 surprises? Is authorization enforced, output escaped, CSRF covered, file
handling safe? Is it tested, does static analysis pass, would a mature
open-source project accept this code?

A change ships only when all three are satisfied. A review that only finds things
to praise has not done its job — the useful output is the objection, the
"this should be a plugin," the "this can wait for more evidence." Nimbus is built
by taking those objections seriously.

## In short

Keep the core small and understandable. Push everything optional into plugins,
everything visual into themes, and everything domain-specific into the
application. Add a capability only when the evidence is real and the reuse is
broad. Let the architecture be strict so that what people build with it can be
free.

---

*The enforceable, day-to-day version of these principles lives in
[`docs/CHARTER.md`](../CHARTER.md); specific decisions are recorded as
[ADRs](../adr/). This document is the human introduction to why they read the
way they do.*
