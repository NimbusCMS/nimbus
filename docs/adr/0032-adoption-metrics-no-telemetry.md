# ADR 0032 — Adoption is measured by public download counters, not telemetry

**Status:** accepted · **Date:** 2026-09-08

## Context

We want to know roughly how many people run NimbusCMS. The obvious mechanism —
a "phone home" ping from each install — is in direct tension with what the project
sells: the site makes **no third-party requests**, tracking is an **opt-in plugin**
(Analytics), and the capability model is deny-by-default. A telemetry path baked
into core, even off by default, would sit against that promise and would be the
first thing a skeptical reader points at.

Three options were weighed:

1. **Public download counters only** — count what registries and GitHub already
   publish: Packagist installs, container image pulls, GitHub stars and clone
   traffic. Zero privacy impact, no request from any install, nothing in the CMS.
2. **Opt-in anonymous version ping in the Analytics plugin** — off by default, a
   random install id + version, sent only if the operator turns it on, kept out of
   core. More accurate active-install data; still a request from installs that opt
   in, and needs a collection endpoint.
3. **Opt-in ping in core** — the same, but in core. Rejected: it puts a phone-home
   path in the one place that must stay request-free.

## Decision

**Adoption is measured only through public download counters (option 1). Core never
phones home, and ships no telemetry.**

- The signals are the ones the outside world already exposes: **Packagist** install
  counts, **container image pulls**, and **GitHub** stars / clone traffic. These
  become meaningful once the release-and-packaging track publishes to those
  registries (ROADMAP § Release & packaging); until then GitHub metrics are the only
  live signal, and that is fine.
- No code runs in the CMS for this. Collecting the counters is repo tooling
  (a scheduled job reading public APIs), never anything the CMS or a site does.
- If active-install telemetry is ever genuinely wanted, it is a **strictly opt-in,
  off-by-default capability of the Analytics plugin** (option 2) — never core. That
  is a separate, future decision; this ADR does not adopt it.

## Consequences

- The adoption number is a lower bound (downloads, not running installs), and lags
  publishing. We accept that as the honest, on-brand trade: the alternative measures
  more precisely by breaking the promise that sells the CMS.
- "How do you know how many people use it?" has a clean answer that reinforces the
  positioning: *we count public downloads; we don't track you.* This belongs in the
  site's transparency copy.
- Core keeps its "no third-party requests" property intact, with no exception to
  explain.
