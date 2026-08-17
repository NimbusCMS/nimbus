# Security findings ledger

Append-only record of confirmed security findings and accepted risks from the
`nimbus-security-review` loop. Supersede entries, never delete them. Every
confirmed finding links the commit that fixed it **and** the regression test that
guards it; every accepted risk links its ADR and revisit date.

When a finding **class** appears here twice, promote it into
[`threat-catalog.md`](threat-catalog.md) as a standing check and note that here.

## How to read a row

- **Severity** — Critical / High / Medium / Low (see SKILL.md).
- **Status** — `fixed` (+ commit + guarding test) · `accepted-risk` (+ ADR +
  revisit date) · `superseded`.
- **Surface** — the file(s)/route the finding touched (catalog class in parens).

---

## Findings

### 2026-08-17 · Content wildcard reaches management capabilities — Medium (latent High)
- **Status:** fixed
- **Surface:** `src/Api/TokenPrincipal.php::can()` (catalog #2 — scope confusion)
- **Scenario:** MCP Slice 1 introduces management capabilities (`schema:write`,
  `users:write`, `tokens:write`, `settings:write`) in the same `resource:action`
  namespace as collection scopes. A token minted `*:write` ("write all my
  content") would then satisfy `can('users','write')`, `can('tokens','write')`,
  etc. once Slices 4–6 add tools that consume them — escalating a content-write
  token into user creation / token minting / settings changes (site takeover).
  Rated Medium not High only because no tool consumes those caps *yet*; it
  becomes High the moment Slice 4 lands, which is why it was fixed in the
  foundation slice rather than deferred.
- **Control added:** the content wildcard `*:{action}` is now scoped to
  collections only — `can()` denies it for the fixed management set and requires
  an exact grant or `admin` for those. `admin` remains the sole cross-cutting
  super-grant. ADR 0009's capability section updated to state this precisely.
- **Evidence:** MCP Slice 1 PR · guarding test
  `tests/Unit/TokenPrincipalTest.php::test_the_content_wildcard_never_reaches_a_management_capability`
- **Recurrence:** 1st sighting of scope confusion (catalog #2) — watch for a 2nd.

<!--
Template for a confirmed finding:

### YYYY-MM-DD · <short title> — <Severity>
- **Status:** fixed
- **Surface:** `src/...` (catalog #N — <class>)
- **Scenario:** <the input and the bad outcome an attacker would achieve>
- **Control added:** <the smallest control that closed it>
- **Evidence:** <commit/PR link> · guarding test `tests/...::<name>`
- **Recurrence:** 1st sighting | 2nd → promoted to threat-catalog #N

Template for an accepted risk:

### YYYY-MM-DD · <short title> — <Severity> — ACCEPTED RISK
- **Status:** accepted-risk
- **Surface:** `src/...` (catalog #N)
- **Why accepted:** <rationale — why not fixed now>
- **Owner:** <who accepted> · **Revisit by:** YYYY-MM-DD
- **ADR:** docs/adr/NNNN-...md · **Reproduction:** <link/steps>
-->
