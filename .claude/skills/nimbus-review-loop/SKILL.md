---
name: nimbus-review-loop
description: >-
  Repository-specific self-learning review loop for NimbusCMS. Invoke before
  planning, implementing, or reviewing any meaningful Nimbus change, and after a
  milestone merges. Runs a three-hat review (Product Owner, Lead Architect,
  Principal PHP Engineer), classifies the work (core / plugin / theme /
  application / tooling / deferred), guards against pivoting Nimbus toward any
  single app or frontend, and records durable lessons in a decision ledger. Use
  when the task mentions Nimbus roadmap, capability, plugin, theme, review,
  "should this be in core", or "what's next".
---

# NimbusCMS review loop

A discipline, not a rubber stamp. Nimbus is a **general-purpose, flexible,
lightweight PHP CMS**. This skill exists to keep it that way while it grows:
every meaningful change is reviewed through three independent perspectives,
classified, and justified by broad reuse — never by one application's needs.

**North star:** Nimbus is *opinionated about architecture, unopinionated about
what people build with it.* It serves many unrelated website types through a
small stable core, explicit reusable capabilities, optional plugins, flexible
themes, and application-owned business logic.

This skill is governed by [`docs/CHARTER.md`](../../../docs/CHARTER.md); where
this skill and the charter ever disagree, the charter wins and this skill is
corrected.

**Companion:** this loop reviews whether a change is *good for the platform*. Its
sibling [`nimbus-security-review`](../nimbus-security-review/SKILL.md) reviews
whether a change is *safe* — an adversarial Attacker / Defender / QA pass. Run the
security loop **in addition** to this one on any security-relevant change (auth,
tokens/scopes, permissions, SQL, templates, upload, redirects, the plugin
boundary, HTTP/proxy handling). The Principal-Engineer hat here flags security
concerns; the security loop is where they are actually exploited, rated, and
gated.

## When to run it

- **Before** planning or implementing a meaningful change — to review and classify.
- **Before** recommending a roadmap item — to apply the Platform Drift Guard.
- **After** a milestone merges — to run the self-learning loop and update the ledger.

Skip it for trivial, obviously-correct changes (a typo, a comment, a test-only
tweak). Use judgement — the point is signal, not ceremony.

## The loop

### 1. Ground yourself in reality (never review from memory)

Inspect the actual repository before forming an opinion:

- `ROADMAP.md` — what is claimed done/deferred/next, and the completion legend.
- `README.md` — the "available now / experimental / roadmap" tiers.
- `docs/CHARTER.md` — the governing gate.
- `docs/COMPATIBILITY.md` — the public API surface and versioning promise.
- `docs/adr/*` — decisions already made (do not relitigate a merged ADR).
- the relevant `src/` and `tests/` — what is actually implemented, integrated, verified.

Identify what is **implemented**, **integrated into the real runtime**, and
**verified by tests/smoke** — three different things. A class existing is not
"done".

### 2. Review through three hats — independently

Run each perspective on its own terms before reconciling. The full question sets
and the review-output template live in
[`references/review-checklist.md`](references/review-checklist.md). In short:

- **Product Owner** — what real problem, for which *unrelated* website types, and
  are we accidentally building for one validation project?
- **Lead Architect** — core vs plugin vs theme vs application; is a reusable
  capability justified; is the public API being frozen too early; simpler design?
- **Principal PHP Engineer** — correctness, security (authz/CSRF/XSS/SQLi/file/
  session), transactions, N+1, testability, static analysis, maintainability.

The principles the Architect hat protects are in
[`references/principles.md`](references/principles.md). Treat them as
foundational: **never change them silently** — propose a change and get
maintainer approval.

### 3. Classify the work

Exactly one primary bucket (a change may note secondary effects):

`Core` · `Official plugin` · `Community/plugin capability` · `Theme` ·
`Application-specific behavior` · `Tooling` · `Deferred / rejected`

Default suspicion: **most things are not core.** Core is foundational-for-many.
When unsure between core and plugin, prefer the plugin — it is reversible; a
core capability, once public, is not.

### 4. Apply the Platform Drift Guard (mandatory before recommending work)

Answer explicitly:

1. Is this solving a **general CMS** problem?
2. Would **multiple unrelated** websites benefit?
3. Is the capability justified by **evidence**, not speculation?
4. **Would I still recommend this if Restaurant, Food Store and Packkit did not
   exist?**

**If #4 is "no", reject or defer.** Nimbus evolves because it becomes a better
CMS, not because one future application might need something.

And confirm the change does **not**:

- assume a Restaurant / Food Store / e-commerce / internal-tool shape;
- make Packkit, React, or Node a runtime dependency of Nimbus or of all themes;
- assume every install is headless, or that every install renders PHP themes;
- turn optional functionality into mandatory core behavior;
- introduce an "application framework" abstraction without broad evidence.

### 5. Recommend the smallest broadly-reusable solution

- Refuse speculative extension APIs with no concrete reusable consumer.
- Prefer one explicit capability that unlocks a category over a bespoke feature.
- If it can wait for stronger evidence, say so and defer.

### 6. Produce implementation instructions + a definition of done

Concrete steps and a DoD that requires **implemented + integrated + verified +
documented** (see Completion rules below). Then implement — small PRs, CI green,
`composer check` (PHPStan level 6 + tests) and the smoke/package-boundary scripts
where relevant.

### 7. Close the loop after it merges (self-learning)

Run [the self-learning loop](#self-learning-loop) and append to
[`references/decision-ledger.md`](references/decision-ledger.md) and, when an
extension capability gains or loses a consumer,
[`references/capability-evidence.md`](references/capability-evidence.md).

## Review output format

Every review uses these, in this order:

- ✅ **Excellent decisions**
- ⚠️ **Things to watch**
- ❌ **Problems to fix now**
- 💡 **Ideas for later** — do not over-engineer

Then the verdicts and framing:

- **Product verdict** · **Architecture verdict** · **Principal-engineering verdict**
- **Classification** — core / plugin / theme / application / tooling / deferred
- **What this enables later** · **What it makes harder later**
- **Is the technical debt acceptable?**
- **Recommended next action** · **Definition of done**

Be willing to say ❌ and to defer. A review that only praises is not a review.

## Completion rules

A roadmap item is complete only when it is **implemented**, **integrated into the
real runtime**, **verified** by relevant tests or smoke tests, and **documented
accurately**. Marks:

- `[ ]` planned
- `[~]` implemented or partially integrated (not yet verified/integrated)
- `[x]` integrated **and** verified

Never mark `[x]` because classes exist.

## Self-learning loop

Learn only from **explicit, reviewable artifacts** — merged code, passing tests,
static analysis, smoke tests, documented behavior, maintainer-approved decisions.

After a milestone:

1. Compare intended behavior with the merged implementation.
2. Check tests, PHPStan, smoke/package-boundary, and documented behavior.
3. Record, with evidence links (commits, tests, ADRs): assumptions correct /
   wrong; defects found after review; abstractions that helped / created
   friction; repeated review findings; capabilities proven by real extensions;
   maintainer-approved decisions.
4. Propose updates to review heuristics, checklists, capability evidence, ADRs,
   roadmap status.
5. Never modify foundational principles silently — present as a proposal for
   approval.
6. Never treat an unmerged experiment as an established pattern.
7. Never infer broad platform requirements from one application.
8. Mark every lesson with an evidence link.
9. Retire obsolete guidance once current code and approved decisions supersede it.

The ledger is append-only; supersede entries, do not delete them. Do not
duplicate full ADR content — link to the ADR.
