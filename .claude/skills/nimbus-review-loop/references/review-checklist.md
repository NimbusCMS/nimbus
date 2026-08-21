# Three-hat review checklist

Run each hat independently, then reconcile. Answer the questions honestly — the
value is in the ones that make the change look worse.

## 🧢 Product Owner

- What real user problem does this solve?
- Which **unrelated** website types benefit (name at least two)?
- Does it complete or improve a real CMS workflow, or is it a loose fragment?
- Is the feature understandable and discoverable by an editor/developer?
- Does it improve editor experience or developer experience?
- Are we building it only to *have* the feature (name-only / chart-parity)?
- Are we accidentally pivoting toward one validation project?
- Can it wait until stronger evidence exists?

→ *Reject weak product justifications.* "A CMS should have X" is not a
justification; "these three unrelated sites are blocked without X" is.

## 🧢 Lead Architect

- Does this belong in **core**? Or is it optional enough to be a **plugin**?
- Is it presentation, and therefore a **theme** concern?
- Is it domain-specific, and therefore **application**-owned?
- Does it expose a **justified reusable capability**, or a one-off dressed as one?
- Is the public API being **frozen too early** (no proven second consumer)?
- Does it increase **coupling** between layers?
- Is there a **simpler, more explicit** design?
- Does it preserve **plugin and theme flexibility**?
- Can official extensions use the **same public APIs** as community ones?

→ Protect [`principles.md`](principles.md). Prefer capabilities over
application-specific logic. When torn between core and plugin, choose plugin.

## 🧢 Principal PHP Engineer

Audit, concretely:

- correctness; input validation; database consistency; transactions; concurrency
  (races on unique indexes, singletons);
- authentication; authorization (per-route, per-collection); CSRF; XSS
  (escape-by-default); SQL injection (named binds only); file handling
  (finfo type, random names, no traversal); session behavior;
- error handling (reference id, no leak); public API stability; backwards
  compatibility;
- performance and **N+1 queries** (relation/media resolution, list counts);
- testability (can it be driven through the real kernel?); static analysis
  (PHPStan level 6 clean); naming; maintainability; dependency impact.

→ Prefer readable PHP 8.2+, narrow explicit objects, `final` where extension
isn't intended, typed properties, and `declare(strict_types=1)`.

## Platform Drift Guard (mandatory before recommending work)

1. Is this solving a **general CMS** problem?
2. Would **multiple unrelated** websites benefit?
3. Is the capability justified by **evidence**, not speculation?
4. **Would I still recommend this if Restaurant, Food Store, and Packkit did not
   exist?** — if **no**, reject or defer.

Confirm it does **not**: assume one app/e-commerce/internal-tool shape; require
Packkit/React/Node for Nimbus or all themes; assume all installs are headless or
all render PHP themes; make optional behavior mandatory; add an "application
framework" without broad evidence.

## Standing surface checks (mandatory — mobile + MCP)

Nimbus serves **two first-class users** on every capability: a **person on a
phone** and an **agent over MCP**. A change is not done until both are considered,
not just the desktop admin. These are as binding as the Drift Guard.

### 📱 Mobile-friendly — *mobile is a first-class user*

Design and review for a phone from the start; verify **live at ~375px**, never
desktop-only.

- Does every affected view work at ~375px with **no page-level horizontal
  scroll**?
- **Tables:** wrapped (`.nb-table-wrap` → scrolls in-panel) or reflowed to stacked
  cards — a bare `.nb-table` that overflows the page is a defect, not a detail.
- Multi-column layouts (`.nb-grid-2`, field-builder grids) collapse to one column;
  **touch targets ≥ 44px**; no **hover-only** affordance (touch has no hover).
- Content padding/spacing adapt; nothing is clipped or unreachable.

### 🤖 MCP-friendly — *the agent is a first-class operator* ([ADR 0009](../../../docs/adr/0009-mcp-control-surface.md))

Nimbus is MCP-native: an agent runs the whole CMS, the admin UI is optional. A new
capability that only the human UI can reach quietly breaks that promise.

- Is this action reachable by an agent **over MCP**, or does it silently make the
  admin UI the only way to do it? A new management action should get an MCP tool,
  gated by the **same capability**, non-enumerating, and audited — the way
  schema/media/users/tokens already are.
- Does the content/permission shape stay **legible to an agent** — typed inputs,
  deny-by-default, no human-only side effects hidden in a controller?
- If MCP exposure is **deferred**, is that a deliberate, recorded decision (ledger
  / roadmap) — not an oversight?

Pure-presentation work (a theme, a CSS signature) is exempt from the MCP check but
**not** from the mobile one. Back-end capability work is subject to both.

## Review output template

```
### <change name> — review

✅ Excellent decisions
- …

⚠️ Things to watch
- …

❌ Problems to fix now
- …            (empty is allowed — say so)

💡 Ideas for later (do not over-engineer)
- …

Product verdict:      <ship / revise / reject / defer> — <one line>
Architecture verdict: <…> — <one line>
Engineering verdict:  <…> — <one line>

Classification:  Core | Official plugin | Community capability | Theme | Application | Tooling | Deferred
Enables later:   …
Makes harder:    …
Debt acceptable? yes / no — <why>

Recommended next action: …
Definition of done:
- [ ] implemented
- [ ] integrated into the real runtime
- [ ] verified by tests / smoke
- [ ] documented accurately
- [ ] mobile: works at ~375px (verified live); MCP: agent-reachable or deferral recorded
```

## Definition-of-done gate

Do not call anything done until: implemented **and** integrated into the runtime
**and** verified by relevant tests or smoke tests **and** documented accurately
**and** the standing surface checks are satisfied — mobile-friendly (verified live
at a phone width) and, for back-end capability work, MCP-reachable (or the deferral
recorded). `composer check` (PHPStan level 6 + full suite) green, plus
`tests/smoke.sh` / `tests/Integration/package-boundary.sh` where the change touches
install or the plugin boundary.
