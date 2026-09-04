# 28. Install & distribution for the 0.1.0 beta

- **Status:** Accepted
- **Date:** 2026-09-04
- **Supersedes:** —
- **Related:** [ADR 0010](0010-deployment.md) (deployment platform),
  [docs/COMPATIBILITY.md](../COMPATIBILITY.md) (versioning),
  [CHANGELOG.md](../../CHANGELOG.md)

## Context

The 0.1.0 **beta** is the first release intended for other people to run. Two
questions had to be settled before tagging it: *how does someone get and run
Nimbus*, and *how does a plugin or a downstream app depend on it*. A competitor's
differentiator is one-minute shared-hosting install (upload files, open an
installer). Nimbus made the opposite architectural bet — a modern, layered,
Composer/Docker PHP app — so "easiest possible install" cannot mean "no build
step on cheap shared hosting" without abandoning that bet. The goal for 0.1.0 is
therefore an install that is **honest, reproducible, and genuinely simple for the
beta's audience (developers), not magical.**

## Decision

For 0.1.0 Nimbus is distributed two ways, both real today:

1. **Clone-and-run (primary).** `git clone … && cd nimbus && docker compose up`
   brings up PHP + MySQL, runs migrations, and serves the site; the operator
   creates the first admin on first run. This is what the docs and the marketing
   site show, and what the deploy platform (ADR 0010) uses. It is the supported
   path for standing up a site.
2. **Composer package (for consumers).** The core and the official plugins are
   published on **Packagist** under the `nimbuscms/*` vendor, so a plugin, a
   theme, or a downstream application can `composer require nimbuscms/nimbus` and
   depend on the **versioned public API** ([COMPATIBILITY.md](../COMPATIBILITY.md),
   SemVer against the plugin surface). Until a package is on Packagist it is
   consumed via a VCS repository entry (as the demo/Foodmart images do today).

Versioning: **SemVer against the public plugin API**, `0.x` may break that API
between minors (documented in the CHANGELOG's pre-1.0 note). 0.1.0 is a beta —
`0.x` already signals pre-1.0; the README frames it as beta in words, not a
`-beta` pre-release tag, so `composer require` resolves it as a normal stable-ish
0.x.

### Deferred (not in 0.1.0)

- **A turnkey one-command / web installer** — an interactive installer, or a
  single `curl | sh` bootstrap, or an agent that provisions a host and deploys a
  site from a sentence (the "dead-easy install" idea). Real, but a larger effort
  whose shape should be driven by beta feedback, not guessed now. Revisit when
  there is demand from someone who is *not* comfortable with clone-and-run.
- **Shared-hosting / no-Docker install** — deliberately out of scope; it fights
  the architecture (ADR 0010). Not planned.

## Consequences

- The install story is documented and true: no step in it is aspirational.
- Publishing to Packagist is an **account action** on the maintainer's Packagist
  profile (submit the repo, or enable the GitHub webhook) — outside what the build
  performs; the packages' `composer.json` are valid and taggable so submission is
  the only remaining step.
- Downstream code pins the public API by SemVer; a `0.x` minor may break it, with
  a CHANGELOG note — the honest contract for a beta.
- The turnkey installer remains a recorded, deferred opportunity, so its absence
  is a decision, not an omission.
