# Security Policy

NimbusCMS takes security seriously — auth, tokens/scopes, the write API, and the
plugin boundary are treated as first-class surfaces, and every security-relevant
change is reviewed adversarially before it merges.

## Supported versions

NimbusCMS is pre-1.0. Security fixes are made against the latest release and
`main`. A `0.x` line is not maintained once a newer `0.x` ships.

| Version | Supported |
|---------|-----------|
| latest `0.x` / `main` | ✅ |
| older `0.x` | ❌ |

## Reporting a vulnerability

**Please do not open a public issue, discussion, or pull request for a security
vulnerability.** Public disclosure before a fix puts every user at risk.

Report privately via **GitHub's private vulnerability reporting**:

1. Go to the repository's **Security** tab → **Report a vulnerability**
   (Security Advisories), or open
   <https://github.com/NimbusCMS/nimbus/security/advisories/new>.
2. Include: affected version/commit, a description, reproduction steps or a proof
   of concept, and the impact you observed.

We aim to acknowledge a report within a few days, agree on a severity and a fix
timeline, and credit you in the advisory and CHANGELOG unless you prefer to remain
anonymous. Please give us a reasonable window to release a fix before any public
disclosure (coordinated disclosure).

## Scope

In scope: authentication/session handling, API token scopes and roles, the
`/api/v1` and MCP surfaces, request/SQL/template handling, file upload/serving,
redirect/header/proxy handling, and the plugin boundary.

Out of scope / known by design:

- **Plugins are semi-trusted, in-process code** (ADR 0001/0005): a plugin you
  install runs with the app's privileges — it is a *contract*, not a sandbox.
  Only install plugins you trust.
- **Deployment hardening is the operator's** (TLS, `TRUSTED_PROXIES`, serving
  `/uploads/*` with `nosniff`, file permissions) — see `docs/COMPATIBILITY.md`.
- Findings that require an already-compromised host or database.

## What we've already done

The pre-1.0 hardening pass is recorded finding-by-finding under
[`docs/backlog/audit-2026-08/`](docs/backlog/audit-2026-08/), and the standing
review disciplines live in `.claude/skills/` (a platform review and an adversarial
security review run on every meaningful change).
