# 10. Deploying Nimbus — a multi-site platform on Hetzner behind Cloudflare

- **Status:** Accepted (architecture + host decided; the reverse-proxy choice and
  each demo's exact subdomain are settled in the deploy slices). An independent
  track — it does **not** depend on the MCP slices.
- **Date:** 2026-08-17
- **Related:** [ADR 0006](0006-non-human-authentication.md) (tokens/CORS — the
  headless-frontend option), the proxy-trust handling (PR #5), `src/Support/Env.php`
  + `src/Support/Config.php` (env-based config), `bin/nimbus` (`migrate`/`install`),
  the shipped dev `Dockerfile` + `docker-compose.yml`.
- **Drives:** hosting *several* sites on Nimbus — the portfolio, the NimbusCMS
  marketing/docs site, and the Foodmart and Restaurant demos — from **one
  dedicated box**, for one bill, so the "MCP-native CMS" story can be *shown* on a
  live, self-hosted platform.

## Context

Long-term, multiple sites run on Nimbus: **`danmat.dev`** (the portfolio — Nimbus
replaces the temporary GitHub Pages site at the apex), the **NimbusCMS
marketing + docs/install-guide site**, a **Foodmart demo**, and a **Restaurant
demo** (the platform-validation apps). The constraint is explicit: *don't pay per
site.*

The app is already deployment-friendly: configuration is entirely environment
variables (`DB_*`, `APP_URL`, `APP_ENV`, `APP_NAME`), the `.env` loader lets real
process env win (12-factor), sessions set `httponly` / `SameSite` / `secure`
cookies, the router honours trusted proxies, and `bin/nimbus migrate` / `install`
bootstrap a fresh database. What's missing is a **production packaging + wiring
story**; the shipped `docker-compose.yml` is for local development only.

**A dedicated Hetzner box — separate from Aegis.** Co-location on the existing
*aegis* box (Hetzner CX23, `49.13.135.236`) was considered — it's idle most of the
time — but **rejected on security grounds** (see below). Instead the Nimbus
platform gets its **own small Hetzner box** (a CX23/CAX11-class 4 GB at ~€5/mo, or
a CAX21 ARM 8 GB at ~€7/mo for comfort with four sites + MySQL). This still meets
*don't pay per site*: **one box hosts all the Nimbus sites**, a single ~€5–7/mo
bill for the whole platform — just not the *same* box as the trading agent.

**Why not co-locate (checked against `DanMat/Aegis`, its `deploy/` + ADR 0011):**
Aegis is a trading agent run by **systemd timers**; its Caddy binds
`127.0.0.1:8080` and is published **privately over Tailscale** — its box is
**public-facing to no one**, and its ADR is explicit that signals stay non-public.
There was *no technical blocker* to co-locating (no port conflict — Aegis is on
loopback; Docker coexists with systemd), but adding a **public** web platform to
that box would put an internet attack surface next to Aegis's trading + LLM keys,
breaking its private-only posture. The risk is low *today* (Aegis is in an
experiment phase on **sandbox/paper keys**), but a separate box is the clean
long-term boundary and costs little — so we take it now rather than migrate later.

**The Cloudflare reality:** Cloudflare Workers/Pages cannot execute PHP, so Nimbus
is never *hosted on* Cloudflare. Cloudflare is the edge — DNS, TLS, CDN, WAF — in
front of the Hetzner origin, per domain.

## Decision

### One box, many containers — pay for the host, not the site

The platform is a single Docker Compose stack on its dedicated box:

- **Caddy** reverse proxy owns `:80`/`:443` — automatic TLS and hostname routing.
  Adding a site is a few readable lines and does not disturb the running sites.
  (Traefik with Docker labels is the label-driven alternative; Caddy is chosen
  for a solo maintainer's legibility.)
- **One MySQL 8** container on a persistent volume, with a **separate database +
  user per site** — data isolation without per-site database servers.
- **One Nimbus container per site**, all the *same production image*, differing
  only by an env file (`DB_NAME`, `APP_URL`, theme, plugins). Same binary, N sites.
- A dedicated Docker **network** for the web stack; **only Caddy publishes ports**;
  each Nimbus container gets a **memory limit** so no one site can starve the
  others.

Multi-*tenancy in core* (one instance resolving sites by hostname with per-tenant
isolation) is **explicitly rejected** for now: it is a large, security-sensitive
feature (a tenant-isolation bug leaks across sites), and container-per-site
already delivers "many sites, one bill" with hard isolation and zero core code.
It stays a future option only if container density ever becomes the bottleneck —
which four low-traffic sites will not hit.

### A dedicated box keeps Aegis isolated (decided)

The public platform runs on its **own box**, so a compromise of a public Nimbus
site cannot reach the Aegis trading host at all — the strongest isolation, and the
reason we don't co-locate. Standard container hardening still applies as
defence-in-depth for the public surface: Nimbus containers run **non-root**,
`cap_drop: [ALL]` + minimal adds, **no Docker socket** mounted, read-only rootfs
where feasible, host-wide Docker **userns-remap**, and only Caddy publishes ports.

### The production image is separate from the dev stack

A lean production target — not the dev compose: PHP 8.2+ with **OPcache on**,
production `php.ini`, `composer install --no-dev --optimize-autoloader`, no
Adminer; a single web server per container (`php-fpm` behind Caddy, or Caddy in
the image) serving `public/`; config strictly from environment — **no secrets in
the image**; `uploads/` on a per-site **named volume** (local-disk media would be
lost on an ephemeral container filesystem; object-storage media is a later
option). Built on the box from a tagged release initially; a published image
(GHCR) is a later convenience.

### Adding a site is declarative

1. a `sites/<name>.env` (its `DB_NAME` / `APP_URL` / theme); 2. a service block
reusing the shared image + a named uploads volume; 3. a Caddy hostname route (or a
Traefik label); 4. create the database + user; 5. a Cloudflare DNS record for the
domain; 6. `docker compose up -d <name>` → `bin/nimbus migrate` → env-driven
`install`.

### Cloudflare + trust wiring

Each domain is proxied (orange-cloud) to the new box's IP; **TLS end-to-end**
(Full/strict) via Caddy. Nimbus's trusted-proxy list must contain **the internal
proxy and Cloudflare's IP ranges** — nothing wider — so the rate limiter and audit
log read the real client IP from `X-Forwarded-For`. The edge caches only static
theme assets; the app owns dynamic responses (its page cache, the API's no-cache).
Optionally, **Cloudflare Access** gates `/admin` on public demos.

### Production hardening (release gate)

`APP_ENV=production`; `APP_URL` on `https://`; secure cookies require HTTPS;
display_errors off (generic 500s already implemented); a strong admin via
`bin/nimbus install` with **env-supplied** credentials (never the dev default);
Adminer absent; per-site rate-limit + CORS for the real origin; `composer audit`
clean; migrations an explicit release step; **backups off-box** (a nightly
`mysqldump` + `uploads/` to Hetzner Storage Box or Cloudflare R2, so they don't
consume the local disk).

## Consequences

**Enables**
- All four sites live on **one dedicated ~€5–7/mo box** — one bill for the whole
  platform, no per-site cost, and the Aegis trading host stays private + untouched.
- A repeatable, declarative deploy that closes ledger finding **F2** (no supported
  way to run Nimbus outside the dev root): one production image + per-site env +
  a migrate/install step.
- The differentiator made real: a public, self-hosted, MCP-native CMS to demo.

**Costs / makes harder**
- A second Hetzner box (~€5–7/mo) rather than reusing the idle aegis box — the
  deliberate price of keeping the public platform off the trading host.
- The box's RAM is the ceiling — fine for low-traffic PHP with OPcache + memory
  limits (an 8 GB CAX21 gives comfortable room for four sites + MySQL); watch it,
  add swap, and a resize is a one-click bump if needed.
- Local-disk media forces a per-site volume now and motivates object-storage media
  later.
- Public admins are exposed attack surface — hence the hardening gate and optional
  Cloudflare Access.

**Out of scope (later, on need):** in-core multi-tenancy; object-storage media; a
managed database; a published GHCR image; HA / a second platform box; Shape B (a
Cloudflare Pages headless frontend consuming the API — the CORS + scoped tokens
exist for it).

## Slices (a small, independent deploy vertical)

1. **Production image + platform compose** — the prod Docker target, a
   `docker-compose.prod.yml` (Caddy + MySQL + the first site), per-site `.env`
   convention, and a `docs/DEPLOY.md`.
2. **Provision the box + first site live (the portfolio)** — spin up the dedicated
   Hetzner box, the platform stack (isolated network + memory limits), MySQL volume
   + off-box backup, `migrate` + env `install`, then smoke the public site + API +
   MCP over HTTPS at `danmat.dev`, Cloudflare in front (apex CNAME-flattened),
   replacing GitHub Pages.
3. **Add the remaining sites** — nimbuscms.dev (marketing + docs), then the
   Foodmart and Restaurant demos, each a container + DB + Caddy route + DNS record.
4. **Hardening pass + go-live** — the checklist above, optional Access on demo
   admins, confirm backups restore.

Slices 2–4 get a `nimbus-security-review` pass (a public admin and an
internet-facing token surface are security-relevant).
