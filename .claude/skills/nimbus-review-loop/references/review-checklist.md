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
```

## Definition-of-done gate

Do not call anything done until: implemented **and** integrated into the runtime
**and** verified by relevant tests or smoke tests **and** documented accurately.
`composer check` (PHPStan level 6 + full suite) green, plus `tests/smoke.sh` /
`tests/Integration/package-boundary.sh` where the change touches install or the
plugin boundary.
