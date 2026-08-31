# 25. Foodmart — a real grocery built on Nimbus (platform validation)

- **Status:** Accepted; design-first. The payoff of the Inventory → Storefront →
  Aurora arc (ADRs 0022–0024): a real grocery, assembled by **configuring** the
  platform and **seeding** data — no application code, no core/plugin/theme
  changes — and deployed publicly, where Aurora debuts.
- **Date:** 2026-08-30
- **Related:** [ADR 0010](0010-deployment.md) (one box, one bill — the co-located
  platform this deploys onto), [ADR 0022](0022-inventory-item-master.md) /
  [ADR 0023](0023-themed-plugin-pages.md) / [ADR 0024](0024-official-theme-naming.md)
  (the item master, storefront section, and Aurora theme Foodmart composes),
  [ADR 0009](0009-mcp-control-surface.md) (seeding via MCP validates the
  agent-operator story).
- **Reviewed by:** `nimbus-review-loop` (is the platform actually sufficient, or
  does building Foodmart force a core/plugin hack?) and `nimbus-security-review`
  (a new public site's deploy/data-hygiene exposure) — both before build.

## Context

The Restaurant → Food Store validation initiative asked one question: *can you
build a real, unrelated site by configuring Nimbus — not by writing application
code against it?* Foodmart, rebuilt as an online grocery, is the test. Everything
a grocery needs already shipped as **platform capabilities**: a catalog with
prices and stock (Inventory item master, ADR 0022), a public, themed, filterable
storefront (ADR 0023), a striking theme (Aurora, ADR 0024), content pages,
menus, and settings. If Foodmart goes live touching **none** of core, the
plugins, or the theme, the platform is validated.

Two forks had to be settled:

- **Infrastructure.** An *older* Foodmart exists — a modernized-PHP5 cart that is
  itself an elaborate infra showcase (Caddy load-balancer across app replicas +
  MySQL primary/replica + Redis + Terraform). Rebuilding that here would
  contradict "one box, one bill" (ADR 0010) and duplicate a story the old project
  already tells. **Decided (maintainer): Foodmart is a clean co-located Nimbus
  site** — the showcase is the *platform* running several real, hardened sites on
  one box, not bespoke per-app infra. The old local stack was temporary and has
  been torn down.
- **Checkout.** The storefront is browse-only; Commerce has orders + a
  `ReservationPort` but no public checkout. **Recommended: ship browse-only now**
  (the grocery goes live — the visible payoff) and treat **Commerce checkout as
  its own slice** (public `/ext` cart routes, session cart, order placement via
  the port, payment) — a large, security-heavy surface that shouldn't gate the
  live grocery. *(Left to the maintainer; a catalogue grocery is a legitimate
  milestone, checkout the natural finisher.)*

## Decision

Ship Foodmart as **Application configuration + seed data + deploy tooling only** —
the classification is the point.

**Seed (no app code).** A grocery catalog is created through the **existing MCP
tools / admin** — Inventory categories (a two-level grocery taxonomy) and items
(name, price, unit, category, stock, a subset with media images), content pages
(About, Delivery), menus, a home landing, and `theme = aurora` — then captured as
`golden.sql` (`mysqldump --default-character-set=utf8mb4`, **not**
`--skip-comments` — the UTF-8 lesson from Slices 1–2). Building the catalog via
MCP is itself a validation of the agent-operator story (ADR 0009).

**Deploy (co-located, mirrors the demo).** A `foodmart` site on the platform:
`docker-compose.foodmart.yml` (`db-foodmart` with its own least-privilege user +
`foodmart` app on `nimbus-demo:local`), `sites/foodmart.env`, config mounts
(`theme.php`=aurora / `site.php` / `menus.php`) and the Aurora theme mounted
**read-only**, `NIMBUS_DEMO` on (public sandbox posture), a Cloudflare-only Caddy
route `foodmart.nimbuscms.dev`, and an **hourly reset** (a real cron calling a
Foodmart `reset.sh`, plus the disk-cap watchdog).

**The headline invariant: zero diffs to core, the plugins, or the theme.** If a
seed step needs one, that is a **platform gap → its own reviewed slice**, never a
Foodmart patch. (Two candidate gaps were checked and are **not** gaps: shop-as-
home is a content landing or a `config/redirects.php` rule; pretty category URLs
are a future *Storefront* enhancement, not a Foodmart need.)

**Security is deploy-config correctness** (no code). The pre-go-live runbook, from
the security review, gates on: per-site DB isolation (least-priv user scoped to
`foodmart.*`, no shared creds); `reset.sh` targets **only** the foodmart DB
(a wrong target is the one catastrophic mistake); `golden.sql` carries **no
secrets** (empty `nb_api_tokens`/`nb_oauth_identities`/`nb_password_resets`, only
the intentional public demo admin); the Caddy block proxies **only** `foodmart:8080`
and imports `cloudflare_only`; theme/config mounts `:ro`; container non-root +
`cap_drop: ALL`; and the cert covers the subdomain (Cloudflare DNS is the
maintainer's action).

## Consequences

**Enables.** Proof that Nimbus is a platform you *configure*, not a framework you
*code against* — a real grocery, live, from capabilities alone. Aurora's public
debut. A second hardened site on the one-box platform (the actual infra showcase).

**Costs / makes harder.** A browse-only grocery is a slightly softer story until
checkout lands; recorded as the recommended next slice. A second live site is
more to reset/monitor, mitigated by reusing the demo's proven pattern.

**Considered and rejected.** *Elaborate per-app infra (LB/replica/Redis)* — off,
per the fork above. *A general "seed a site from a spec" framework* — speculative
until a second site needs it; a one-off seed script + golden is the right size.

**Deferred / not built (tracked):** Commerce checkout (its own slice); pretty
category landing pages + product structured data (a Storefront slice); a
platform-scaling story (LB / read-replica / Redis page-cache) as *platform*
infra, not Foodmart.

**Debt.** None in the platform — the slice adds no code. Operational only: a
second site to reset and watch, on the reviewed, hardened pattern.
