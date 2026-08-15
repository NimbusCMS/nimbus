---
name: nimbus-security-review
description: >-
  Repository-specific adversarial security review for NimbusCMS. Invoke before
  merging any security-relevant change (auth, sessions, API tokens/scopes,
  permissions/authorization, SQL, templates/output, file upload, redirects,
  the plugin boundary, HTTP/proxy/header handling) and when planning a feature
  that touches them. Runs a three-lens review — the Attacker (offense, exploit
  chains), the Defender (white-hat, controls and severity), and the QA Engineer
  (turns every confirmed finding into a regression test) — assigns severity,
  and enforces the merge bar. Companion to nimbus-review-loop; both are governed
  by docs/CHARTER.md. Use when the task mentions tokens, auth, permissions,
  security, exploit, injection, XSS, CSRF, IDOR, upload, "is this safe", or a PR
  that touches any surface above.
---

# NimbusCMS security review

A discipline, not a rubber stamp — the adversarial companion to
[`nimbus-review-loop`](../nimbus-review-loop/SKILL.md). That loop asks *"is this
a good change for the platform?"* through three builders' hats. This loop asks a
different, hostile question: **"how does an attacker turn this against a real
site, and what stops them?"**

PHP CMSes are not felled by exotic bugs; they are felled by the same recurring
loophole classes — broken object-level authorization, injection, over-posting,
unsafe upload, traversal, missing CSRF, token mishandling — shipped a little at a
time until one composes into a breach. Nimbus is now growing exactly the surfaces
where this happens (API tokens, scopes, per-collection permissions). **Security
is a first-class citizen from here on**: this review runs before those changes
merge, not after an incident.

Governed by [`docs/CHARTER.md`](../../../docs/CHARTER.md); where this skill and
the charter disagree, the charter wins and this skill is corrected.

## When to run it

Run it on any **security-relevant** change — a PR (or a plan for one) that
touches, directly or transitively:

- **AuthN / sessions** — login, password handling, session lifecycle, cookies, 2FA
- **AuthZ** — API token **scopes/abilities**, per-collection roles, ownership
  checks, any "can this actor do this to this object?" decision
- **The API** — `/api/v1`, tokens, serialization, any new endpoint (read *or* write)
- **Untrusted input at a boundary** — request parsing, SQL, template output,
  redirects, file upload/serving, header/host/proxy handling, deserialization
- **The plugin boundary** — anything a plugin can reach, register, or persist
- **Secrets** — key/token generation, storage, comparison, logging

Skip it for changes that cannot affect those surfaces (a docs typo, a chart
colour, a comment). Use judgement — the point is to catch the PR that *quietly*
touches authorization, not to ceremony every diff. When unsure, run it: a false
alarm costs minutes; a missed authz bug costs a site.

## Ground yourself first (never review from memory)

Read the **actual diff and the code around it** before forming a view:

- the PR diff — every changed line, and the callers/callees it touches;
- the [threat catalog](references/threat-catalog.md) — the Nimbus-specific
  loophole classes and the exact surfaces each applies to;
- the relevant `src/` — how the control is *actually* enforced (route guard vs.
  object-level check; escape-by-default vs. escape-by-memory; bound query vs.
  string-built SQL);
- prior [ADRs](../../../docs/adr) and the
  [security ledger](references/security-ledger.md) — decided controls and past
  findings (do not re-litigate a merged decision; do check the fix still holds).

A control that "exists somewhere" is not a control. Trace the specific path the
attacker's input takes and name where it is stopped — or that it isn't.

## The three lenses — run each independently, then reconcile

Full question sets are in the [attack playbook](references/attack-playbook.md).
Run them in this order; do not let one collapse into another.

### 🔴 The Attacker (offense)

You control every byte of input and you want out. Think in **exploit chains and
abuse cases**, not a checklist. For each new/changed surface: what is the worst
thing a hostile client, a low-privilege user, a scoped token, or a malicious
plugin can make it do? Reach for the classics *against this specific code* —
IDOR across collections, over-posting a privileged field, scope confusion, SQLi
via an unbound fragment, stored XSS through an un-escaped value, traversal
through a path parameter, open redirect, SSRF, upload-to-RCE. **Produce concrete
attack scenarios** — ideally the request/payload that would work if the hole is
real. Assume nothing is validated until you've found where it is.

### ⚪ The Defender (white-hat)

Take each attack scenario and map it to the **control that should stop it**, and
check that control is real and in the right place:

- Is authorization enforced at the **object** level (this actor, this object) or
  only at the **route/door**? (route-only guards are the classic IDOR gap)
- Is a token **scope enforced at the query**, or merely checked at entry and then
  forgotten?
- Is output **escaped by default** by the template layer, or by the author
  remembering to?
- Is the SQL **parameter-bound**, including `ORDER BY`/`LIMIT`/identifiers that
  can't be bound and must be allow-listed?

Then **prioritize honestly** and reject the attacker's theatrical-but-unreachable
findings. This lens is the reconciler: it prevents both paranoia and complacency,
and it assigns the **severity** (below).

### 🟢 The QA Engineer (permanence)

Security fixes rot. A refactor months later silently re-opens a hole unless a
test stands guard. Your job is **not** to test the feature — it is to make every
confirmed finding **permanent**:

- write the **regression test that fails on the vulnerable code and passes after
  the fix** — the finding isn't closed until this exists;
- build/extend the **authorization matrix** (actor × scope × object × action) so
  a future scope or role is forced to declare its answers;
- own the **tooling gate**: `composer audit` clean, PHPStan level 6 clean, and
  any security-relevant test suite green in CI.

A finding with no failing test is a claim; a finding with one is a fixed bug that
stays fixed.

## Severity

The Defender assigns exactly one, by realistic impact **and** reachability:

- **Critical** — unauthenticated or trivially-authenticated compromise: auth
  bypass, RCE, SQLi returning/altering arbitrary data, reading/altering any
  site's content across a tenant/scope boundary.
- **High** — meaningful boundary break needing some position: IDOR/privilege
  escalation for a low-privilege user or a scoped token, stored XSS in the admin,
  CSRF on a state-changing route, token leakage, traversal reading arbitrary
  files.
- **Medium** — real weakness, limited blast radius or needs an unlikely
  precondition: reflected XSS behind auth, info leak via errors, missing rate
  limit on a sensitive endpoint, weak-but-not-broken crypto choice.
- **Low** — defense-in-depth gap with no direct exploit: a missing hardening
  header, an overly verbose log, a timing signal with negligible advantage.

When reachability is genuinely uncertain, rate it one level **down** but record
the assumption that would raise it — never inflate to look thorough.

## Merge bar

**Block High and above; an accepted risk needs an ADR.**

- **Critical / High** — **block the merge.** It must be fixed, *or* the risk
  explicitly accepted in a short ADR (see
  [risk-acceptance-template.md](references/risk-acceptance-template.md)) —
  rationale, the accepting owner, and a revisit date. No silent acceptance.
- **Medium** — fix in the PR, or land it with a tracked follow-up recorded in the
  ledger. Don't let mediums accrete unwatched — three composed mediums are a high.
- **Low** — record in the ledger; fix opportunistically.

"Security-green to merge" = **no open Critical/High without an accepted-risk
ADR, and every confirmed finding has a regression test or a tracked ticket.**

## Evidence discipline (no theater)

Same bar as the platform loop: **a finding is real only with a plausible exploit
path or a failing test.** State the concrete input, the code path it reaches, and
the outcome. "This could theoretically be unsafe" is not a finding — either trace
it to an exploit or drop it. Do not pad reviews with generic OWASP items that
don't apply to the changed code. No CVE cosplay.

## Proportionality (Nimbus stays lightweight)

The Platform Drift Guard applies to defenses too. Recommend the **smallest
control that closes the hole**, in the right layer — an object-level check, a
bound parameter, an allow-list, escape-by-default. Do **not** prescribe a WAF, a
heavyweight security framework, a bespoke crypto scheme, or a second auth stack
without demonstrated need. Prefer a small, audited library over DIY for
HTML-sanitize / MIME-sniff (the charter allows this — "zero deps" is a goal, not
an absolute). A control a solo maintainer can't hold in their head will rot.

## Output format

Report in this order:

- 🔴 **Attacker findings** — each: *surface · scenario/payload · what it yields*
- ⚪ **Defender assessment** — for each finding: *control that should stop it ·
  is it present/correct/well-placed · **severity***
- 🟢 **QA / permanence** — the regression test each confirmed finding needs; the
  authorization-matrix rows added; the tooling gate status
- **Verdict** — security-green to merge? If not, the exact blockers (Critical/High
  to fix or ADR-accept) and the required tests
- **What this hardens / what it leaves open** — and any Medium follow-ups filed

Be willing to say **block**. A security review that only reassures is not a
security review.

## Close the loop after it merges (self-learning)

Same discipline as the platform loop — learn only from **explicit, reviewable
artifacts** (merged code, passing regression tests, `composer audit`/PHPStan,
maintainer-approved risk ADRs):

1. Append every confirmed finding to the
   [security ledger](references/security-ledger.md) with its severity, the
   commit that fixed it, and the regression test that guards it.
2. When a finding class appears **twice**, promote it into the
   [threat catalog](references/threat-catalog.md) as a standing check with the
   Nimbus surface it bit — so the next review starts from it.
3. Record accepted risks (with their ADR link and revisit date) and honor the
   revisit.
4. Never treat an unproven worry as a standing rule; never weaken a control
   silently — a control change is a proposal for maintainer approval, like a
   principle change in the platform loop.

The ledger is append-only: supersede entries, don't delete them.
