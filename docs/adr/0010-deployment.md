# 10. Deploying Nimbus — a live instance behind Cloudflare

- **Status:** Proposed (direction captured; an independent track, schedulable
  whenever a live instance is wanted — it does **not** depend on the MCP slices)
- **Date:** 2026-08-17
- **Related:** [ADR 0006](0006-non-human-authentication.md) (tokens/CORS — the
  headless-frontend option), the proxy-trust handling (PR #5), `src/Support/Env.php`
  + `src/Support/Config.php` (env-based config), `bin/nimbus` (`migrate`/`install`),
  the shipped dev `Dockerfile` + `docker-compose.yml`.
- **Drives:** a public, self-hosted NimbusCMS instance for the portfolio — admin,
  public site, headless API and the live MCP endpoint all reachable — so the
  "MCP-native CMS" story can be *shown*, not just described.

## Context

The app is already deployment-friendly: configuration is entirely environment
variables (`DB_*`, `APP_URL`, `APP_ENV`, `APP_NAME`), the `.env` loader lets real
process env win (12-factor), sessions set `httponly` / `SameSite` / `secure`
cookies, the router honours trusted proxies, and `bin/nimbus migrate` / `install`
bootstrap a fresh database. What's missing is a **production packaging and wiring
story** — the shipped `docker-compose.yml` is explicitly for local development
(app on `:8080`, Adminer, a dev MySQL with a dev password), and the README says
*not production-ready*. This ADR decides how a real instance runs.

**The Cloudflare reality:** Cloudflare Workers/Pages cannot execute PHP, so
Nimbus is never *hosted on* Cloudflare. Cloudflare's role is the edge — DNS, TLS,
CDN, WAF — in front of a PHP origin. Two shapes follow.

## Decision

### Shape A (primary) — containerized PHP origin, Cloudflare at the edge

Nimbus runs as a production container on a small host; Cloudflare proxies it at a
subdomain (e.g. `cms.danmat.dev` or `demo.nimbuscms.dev`). This is the fastest
path to a live URL with **everything** reachable — admin, public site, `/api/v1`,
and `POST /api/v1/mcp` — and it reuses the exact pattern already proven on the
Foodmart project (Docker + a reverse proxy + MySQL on the Oracle Cloud free tier).

Shape B (a Cloudflare Pages static/JS frontend consuming the headless API, Nimbus
as a backend elsewhere) stays available for later — the CORS + scoped tokens
exist for it — but it hides the admin and the MCP surface, so it is not the
portfolio showcase.

### The production image is separate from the dev stack

A lean production target — not the dev compose:

- PHP 8.2+ with **OPcache on**, production `php.ini`, no dev Composer deps
  (`composer install --no-dev --optimize-autoloader`), no Adminer;
- a single web server in the image (Caddy or nginx+php-fpm) serving `public/`,
  with Caddy's automatic TLS as a simple origin-cert option;
- config strictly from environment — **no secrets baked into the image**;
- `uploads/` mounted on a **persistent volume** (media is local-disk today; an
  ephemeral container filesystem would lose it — object storage is a later
  option, out of scope here).

### Database — self-hosted MySQL with backups, managed later if needed

A MySQL 8 container on the same host with a persistent volume and a scheduled
logical dump (nightly `mysqldump` to the host / an object store) is enough for a
portfolio instance. A managed MySQL is a drop-in later — the app only needs
`DB_*`. No app change either way.

### Cloudflare wiring

- **DNS** proxied (orange-cloud) to the origin; **TLS** end-to-end (Full/strict)
  with Caddy or a Cloudflare origin certificate.
- **Trust Cloudflare's IPs as proxies** so the app reads the real client IP from
  `X-Forwarded-For` — the rate limiter and audit log key on it, and the existing
  proxy-trust config must list Cloudflare's ranges, nothing wider.
- **Caching**: let the app own dynamic responses (respect its page cache and the
  API's no-cache); cache only static theme assets at the edge.
- Optionally put **Cloudflare Access** in front of `/admin` for a second gate on
  a public demo.

### Production hardening checklist (release gate)

`APP_ENV=production`; `APP_URL` on `https://`; secure cookies require HTTPS;
display_errors off (generic 500s already implemented); a strong admin created via
`bin/nimbus install` with **env-supplied** credentials (never the dev default);
Adminer absent; rate-limit and CORS configured for the real origin; `composer
audit` clean; migrations run as an explicit release step.

## Consequences

**Enables**
- A public, self-hosted, MCP-native CMS to demo — the differentiator made real.
- A repeatable deploy that closes ledger finding **F2** (no supported way to run
  Nimbus outside the dev root): a production image + documented env + a
  migrate/install release step.

**Costs / makes harder**
- Ops surface: a host, TLS, backups, upgrades to own (mitigated by staying on the
  proven Foodmart pattern and a free-tier host).
- Local-disk media forces a persistent volume now and motivates object-storage
  media later.
- A public admin is an exposed attack surface — hence the hardening gate and the
  optional Cloudflare Access.

**Out of scope (later, on need):** object-storage media; a managed database;
multi-node / autoscaling; a published image on a registry (GHCR) if others deploy
Nimbus; Shape B's Pages frontend.

## Slices (a small, independent deploy vertical)

1. **Production image + config** — a prod Docker target (OPcache, `--no-dev`,
   web server, volume for `uploads/`), a documented `.env` for production, a
   `docs/DEPLOY.md`.
2. **Provision + first deploy** — host, MySQL with a persistent volume + backup,
   `migrate` + env-driven `install`, smoke the public site + API + MCP over HTTPS.
3. **Cloudflare wiring** — DNS/TLS, Cloudflare IPs in trusted proxies, edge
   caching for static assets, optional Access on `/admin`.
4. **Hardening pass + go-live** — the checklist above, then point the subdomain
   and announce.

Each slice lands CI-green; slices 2–4 get a `nimbus-security-review` pass (a
public admin and an internet-facing token surface are security-relevant).
