# Accepting a security risk (instead of fixing before merge)

The merge bar blocks **Critical/High** findings. If one is *not* going to be fixed
in the PR, the risk must be **explicitly accepted** — never silently merged. An
accepted risk is a decision on the record, with an owner and an expiry, so it can
be revisited rather than forgotten.

Acceptance is rare and deliberate. Prefer fixing. Accept only when the fix is
genuinely out of scope/blocked, the exposure is understood and bounded, and a
responsible owner signs off.

## Steps

1. **Write an ADR** in `docs/adr/NNNN-<slug>.md` (next number in sequence),
   using the format below — it mirrors the existing ADRs (Status / Date /
   Context / Decision / Consequences), specialized for a risk acceptance.
2. **Get maintainer sign-off** — acceptance is the maintainer's call, not the
   reviewer's. Record who accepted.
3. **Record it** in [`security-ledger.md`](security-ledger.md) as
   `accepted-risk`, linking the ADR and the revisit date.
4. **Capture a reproduction** so the revisit starts from something concrete.
5. **Honor the revisit date** — an expired acceptance is re-opened, not renewed
   by default.

## ADR template

```markdown
# NNNN. Accept risk: <short title>

- **Status:** Accepted (security risk acceptance)
- **Date:** YYYY-MM-DD
- **Severity:** High | Critical
- **Accepted by:** <maintainer>
- **Revisit by:** YYYY-MM-DD
- **Related:** <PR link>, [threat catalog #N](../../.claude/skills/nimbus-security-review/references/threat-catalog.md)

## Context

<The finding: the surface, the attacker scenario, and the concrete exposure.
What input yields what bad outcome, and under what preconditions.>

## Decision

We accept this risk for now rather than fixing it in <PR>, because <reason the
fix is out of scope or blocked>. The exposure is bounded by <compensating
factors / preconditions the attacker still needs>.

We will revisit by <date>, or sooner if <trigger — e.g. the write API ships,
scopes widen, the feature leaves experimental>.

## Consequences

- **Residual risk:** <what remains exploitable, and to whom>
- **Compensating controls:** <anything that reduces likelihood/impact meanwhile>
- **Reproduction:** <link or steps, so the revisit is concrete>
- **Exit:** <what "fixed" will look like when we revisit>
```
