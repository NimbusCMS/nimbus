# 24. Official theme naming + theming a plugin section (Aurora)

- **Status:** Accepted; design-first, not yet implemented. Slice 3 of the Foodmart
  build — the presentation layer for the storefront (ADR 0023). Consumer: the
  Foodmart grocery, which will run **Aurora**.
- **Date:** 2026-08-30
- **Related:** [ADR 0023](0023-themed-plugin-pages.md) (page sections — the
  templates a theme overrides), [ADR 0018](0018-site-theme-picker.md) (the theme
  picker + `ThemeCatalog`, which already sources a theme's display name from
  `theme.json`), [ADR 0022](0022-inventory-item-master.md) (the item fields Aurora
  renders, under the store-raw/escape-on-render contract).
- **Reviewed by:** `nimbus-review-loop` (is a new official theme justified, or
  drift?) and `nimbus-security-review` (a theme renders author content publicly —
  a focused escape-on-render pass) — both before build.

## Context

The storefront (ADR 0023) renders through the active theme, and a theme may
**override** a section's default templates (`shop-index`, `shop-product`) — but no
theme exercises that yet, so the seam is unproven in practice and there is no
reference for theming a plugin section. Separately, the official themes are named
after *use cases* — `theme-docs` shows as "Docs", `theme-cafe` as "Storefront" —
which reads as a category label, not a theme, and couples the display name to the
first thing the theme was built for.

## Decision

**1. Official themes get generic, evocative display names, decoupled from their
repo slugs.** The repo/directory slug stays stable (URLs, mounts, the theme
setting all key on it); the **display name** in `theme.json` is a real name, not a
use-case label:

| repo slug (unchanged) | display name |
| --- | --- |
| `theme-docs`  | **Lumos** |
| `theme-cafe`  | **Willow** |
| `theme-aurora` (new) | **Aurora** |

This needs no core change — `ThemeCatalog` already reads the name from
`theme.json` (slug fallback), and the admin picker escapes it. A theme is thereby
named like a product, and is free to be used for anything (a "Storefront"-named
theme wrongly implied one use).

**2. A theme may fully theme a plugin page-section, and Aurora is the reference.**
Aurora ships `shop-index` + `shop-product` that override the storefront plugin's
defaults (ADR 0023 theme-first resolution), styling the catalog to match the rest
of the site. This proves the section-override path end-to-end and gives community
themes a worked example.

**3. Aurora is a generic theme, not a grocery theme.** Its aesthetic — "magical
sky": deep-indigo night grounds, luminous aurora-gradient accents, starlight-gold
hairlines, light "moonlit parchment" content surfaces — is a brand vibe any site
can wear. It ships **generic** `entry`/`collection` templates (no grocery or
café specializations) plus the storefront overrides. It uses **system font
stacks and CSS-only motifs — no external or binary assets** — because the public
CSP is `default-src 'self'` (no external fonts/CDN) and this keeps a theme
zero-dependency.

**Escape-on-render is the theme's job.** Every Aurora template escapes author
values through the injected `$e` (item name/description/unit/category, entry
title/fields, menu labels, block bodies, the reflected `q`/`category`/`sort`
filter values), matching the storefront defaults. The only unescaped output is
`$head` (trusted, plugin-rendered, isolated) and pre-rendered field values from
core's field-type helper. Render tests ship with the theme.

## Consequences

**Enables.** A finished-looking storefront and a reference for theming any future
section; a named theme gallery (Starter · Willow · Lumos · Aurora) users can pick
from; Foodmart's look (Slice 4).

**Costs / makes harder.** A fourth official theme is a standing maintenance
commitment against the template contract — accepted because Aurora earns its place
by proving section-overrides and staying generic. Names change in the picker (not
the slugs), so anyone who referenced "Docs"/"Storefront" by display name sees the
new name; the setting value (slug) is unaffected.

**Considered and rejected.** *Tell users to copy `starter`* — no reference for
theming a section, and no polished storefront for the demo/Foodmart. *A
grocery-specific theme* — would be app-shaped drift; Aurora stays generic. *A
light/dark toggle* — Aurora is a committed single aesthetic; a variant can come
later with evidence.

**Deferred / not built (tracked):** a `prefers-color-scheme` day variant; an
optional hero-image slot for `entry-home`; applying the render-test harness
pattern back to the other theme repos.

**Debt.** Low. Self-contained theme repo, zero core coupling, no new API. The only
risk surface — public rendering of author content — is closed by escape-by-default
with render-test guards.
