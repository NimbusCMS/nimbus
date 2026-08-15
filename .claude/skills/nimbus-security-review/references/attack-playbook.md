# Attack playbook — the three lenses

Run each lens on its own terms, in order, before reconciling. The value is in the
questions that make the change look **worse**. Pair this with the
[threat catalog](threat-catalog.md), which maps each class to its Nimbus surface.

---

## 🔴 The Attacker — offense

You are hostile. You control every byte of every request. You have, in turn: no
account; a low-privilege account (author); a narrowly-scoped API token; a
malicious installed plugin. For each, ask *what can I make this change do that it
shouldn't?*

- **Enumerate the new surface.** What routes, params, fields, tokens, files, or
  plugin hooks did this PR add or widen? List them before attacking.
- **Object access.** Can I reach another user's / another collection's / another
  scope's object by changing an id or slug? (→ IDOR, catalog #1)
- **Privilege.** Can a read token write? Can an author publish/own/escalate? Does
  a scope widen through a relation or expansion? (→ #2)
- **Injection.** Where does my input reach SQL, HTML, a header, a file path, a
  redirect, a template name, a log line? Is any of it unbound / un-escaped /
  un-allow-listed? (→ #3, #4, #7, #9, #13)
- **Over-post.** What happens if I add `role`, `owner`, `status`,
  `published_at`, or an unexpected key to the payload? (→ #5)
- **State change without a token.** Can I forge a cross-site write? Does the API
  accept the session cookie anywhere? (→ #6)
- **Upload.** Can I upload something that executes, or an SVG that scripts, or a
  name that traverses? (→ #8)
- **Secrets & leaks.** Can I read a token, a stack trace, SQL, a path, or infer a
  hidden draft's existence via status/timing? (→ #11, #14)
- **Chain it.** Do two "minor" issues compose into a real breach?

**Output:** concrete scenarios — the request, param, or payload that would work
*if the hole is real* — not "consider XSS." If you can't state the input and the
bad outcome, you don't have a finding.

---

## ⚪ The Defender — white-hat, controls & severity

For **each** attacker scenario, don't re-attack — locate the defense:

- **Name the control** that should stop it (object-level authz, bound parameter,
  escape-by-default, allow-list, CSRF token, MIME-sniff, `hash_equals`…).
- **Is it present?** Point to the line. "Should be somewhere" = absent.
- **Is it correct?** Right check, right comparison, right encoding for the
  context.
- **Is it in the right place?** At the **object/query/service** layer, not only
  the route door. A guard the attacker's path doesn't pass through is no guard.
- **Does it fail closed?** Unknown scope/role/input → deny.
- **Assign severity** (Critical / High / Medium / Low — definitions in SKILL.md)
  by realistic impact **and** reachability. Uncertain reachability → rate one
  level down, record the assumption that would raise it.
- **Reject theater.** Drop the attacker's unreachable findings, with a one-line
  why. Keep the review honest in both directions.

**Output:** per finding — *control that should stop it · present/correct/placed?
· severity · (if rejected) why unreachable.*

---

## 🟢 The QA Engineer — permanence

The fix is not done until it can't silently come back.

- **Regression test first.** For each confirmed finding, write the test that
  **fails on the current (vulnerable) code and passes after the fix** — an HTTP
  test for authz/CSRF/injection reachable over the wire, a unit test for a pure
  control. No failing test → the finding is an unproven claim.
- **Authorization matrix.** Add/extend rows: actor (anon, author, editor, admin) ×
  token scope × object (own/other collection/other owner) × action (read, write,
  publish, delete) → expected allow/deny. Every new scope or role must fill in its
  column before merge.
- **Negative tests.** Assert the *deny* paths, not just the happy path — the
  wrong-scope 403, the traversal 400, the over-post rejection.
- **Tooling gate.** `composer audit` clean (dependency CVEs), PHPStan level 6
  clean, security suites green in CI. Flag any new dependency for audit.
- **Reproduction for accepted risk.** If a High is ADR-accepted rather than fixed,
  still capture the reproduction so the revisit has something concrete.

**Output:** the exact tests added (path + what they assert), matrix rows added,
tooling-gate status.

---

## Reconciling

The Defender's severities + the QA gate produce the verdict:

- Any **Critical/High** without a fix or an accepted-risk ADR → **block**.
- Any confirmed finding without a regression test or tracked ticket → **not
  green** (QA gate).
- Otherwise → security-green; record everything in the
  [ledger](security-ledger.md) and, for any twice-seen class, promote it into the
  [threat catalog](threat-catalog.md).
