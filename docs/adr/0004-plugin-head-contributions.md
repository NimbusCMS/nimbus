# 4. Plugins may contribute to the document head

- **Status:** Accepted
- **Date:** 2026-08-15
- **Supersedes:** —
- **Related:** [ADR 0001](0001-plugin-contract.md) (plugin contract),
  [ADR 0003](0003-public-rendering-and-theme-contract.md) (public rendering)

## Context

`PluginContext` exposes exactly one capability — field types — and ADR 0001 is
explicit that further capabilities are added "one at a time, each alongside a
plugin that actually needs it."

We now have that concrete need. The core SEO foundations (per-page meta,
`sitemap.xml`, `robots.txt`) are in place, but the *opinionated* layer —
JSON-LD structured data, richer Open Graph, per-page keywords/author — is, per
the charter, an official plugin (`plugin-seo`). That plugin's central job is to
add markup to the document `<head>` of public pages. Nothing in the contract
lets it.

The question is what the smallest capability is that unblocks it without opening
surfaces the contract deliberately refuses (the database, repositories,
controllers, a service locator).

## Decision

Add a **head-contribution** capability.

A plugin registers a `HeadContributor`:

```php
interface HeadContributor
{
    /** HTML for this page's <head>, or '' for none. */
    public function head(PageContext $page): string;
}
```

`PageContext` is a **data-only** value object describing the page being
rendered — the same information the theme's view-model already carries:

```php
final class PageContext
{
    public string $kind;        // 'home' | 'collection' | 'entry'
    public string $canonical;   // absolute URL
    public string $title;       // page title (no site-name suffix)
    public string $siteName;
    public ?array $entry;       // the entry view-model, on an entry page
    public ?array $collection;  // { handle, name }, on a collection page
}
```

Registration mirrors field types exactly:

- `PluginContext::head()` returns a provider-scoped `HeadRegistrar`.
- Registrations land in a shared `HeadContributorRegistry`, stamped with the
  plugin's id, so a failing plugin's contributors are rolled back with its
  field types (provider-scoped `forgetProvider`).
- At render time, `SiteController` builds a `PageContext` and asks the registry
  for the combined head HTML, which the theme prints in `<head>`.

### Why this shape

- **No new data surface.** A contributor is *handed* the page's prepared
  view-model; it never queries. The contract keeps refusing repositories and the
  database — this capability does not need them, so it does not get them.
- **Render-time, isolated.** Unlike registration (cheap, side-effect free) and
  unlike post-commit events (which propagate loudly), a head contributor runs
  while rendering a public page. A throwing contributor is **caught, logged, and
  skipped** — a broken SEO plugin must never turn a live page into a 500. This
  is a deliberate difference from the event contract, justified by where it runs.
- **Presentation, but core-mediated.** Themes own *layout*; a head contributor
  adds machine-readable metadata (structured data, meta tags), which is not a
  layout decision and must work across any theme. The theme prints one `$head`
  slot; it does not need to know which plugins contributed.

## Consequences

- `PluginContext` gains a second capability; `PluginLoader::load` and the
  contributor registry are threaded through `Application` beside the field-type
  registry, and into `SiteController`.
- `PageContext` becomes public API the moment `plugin-seo` depends on it. It is
  intentionally small and data-only; new keys are additive.
- `SiteController`'s constructor grows another dependency. If it grows again,
  fold the site-scoped dependencies (home, theme path, head registry) into a
  single value object rather than adding a sixth parameter.

## Alternatives considered

- **A routes capability first (for RSS/Atom, OG-image endpoints).** Rejected as
  the *first* extension: a feed needs both routes **and** a way to read content,
  two commitments at once. Head contribution needs neither. Routes will come with
  their own concrete consumer.
- **Core emits JSON-LD directly.** Rejected: which schema.org types to emit and
  how to map fields is opinionated and site-shaped — exactly what the charter
  keeps out of core. Core provides the *capability*; the plugin holds the policy.
- **Let the theme emit structured data.** Rejected: it would have to be
  reimplemented in every theme, and it is metadata, not layout.
