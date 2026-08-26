# Deploying NimbusCMS

A small, self-hostable recipe for running **one or many** Nimbus sites on a
single box, behind a single reverse proxy, without paying per site. It is the
platform decided in [ADR 0010](adr/0010-deployment.md): one edge
**Caddy** + one **MySQL 8** + **one container per site**, with Cloudflare as the
edge (DNS/TLS/CDN/WAF) in front.

Everything here lives under [`deploy/`](../deploy). Nothing in `deploy/` contains
a secret — only `*.example` templates. Your real `.env` files and TLS keys are
git-ignored and never baked into the image.

```
Internet ──▶ Cloudflare (edge, WAF) ──▶ [ box ]
                                         Caddy :443  ── terminates TLS, routes by Host
                                           ├─▶ site-a container  (FrankenPHP :8080, internal)
                                           ├─▶ site-b container  (FrankenPHP :8080, internal)
                                           └─▶ …
                                         MySQL 8  (internal only — never published)
```

## The image

`deploy/Dockerfile` builds one production image used by **every** site. It is
[FrankenPHP](https://frankenphp.dev/) in **classic mode** — a real concurrent
web server + PHP in one process, so a site is one self-contained container with
no php-fpm/Caddy split and no shared docroot volume.

It is hardened at build time: code + `composer install --no-dev` baked in (no
source mount, no dev tooling, Composer binary removed), runs as the non-root
`www-data` user on `:8080`, OPcache on with `validate_timestamps=0`,
`display_errors` off. Combined with the compose hardening below
(`cap_drop: ALL`, `no-new-privileges`, per-service memory limits, no Docker
socket), a compromised site process has almost nothing to stand on.

> **Do not enable FrankenPHP worker mode.** `public/index.php` defines
> `NB_START` and Nimbus keeps request-scoped state in `Config`/`Env` singletons;
> a long-lived worker would leak or fatal across requests. Classic mode
> (one PHP process per request, as configured in `deploy/site.Caddyfile`) is the
> supported runtime. Concurrency comes from the process pool, not from workers.

## Prove it locally first

Before touching a box, bring the whole stack up on your machine — two real sites
served through the edge Caddy:

```bash
cd deploy/local-proof
docker compose up -d --build
# migrate + create an admin for each site (production install requires real creds)
docker compose exec site-a php bin/nimbus migrate
docker compose exec site-a php bin/nimbus install --email=you@example.com --password='a-strong-password'
docker compose exec site-b php bin/nimbus migrate
# visit via the edge (Host routing):
curl -H 'Host: site-a.localhost' http://127.0.0.1:8090/
```

`deploy/local-proof/` is HTTP-only and self-contained (throwaway credentials
inlined — no `.env` to create). It exists to validate the image + multi-site
routing + the hardening; it is **not** a production config. Tear down with
`docker compose down -v`.

## Production deploy

The box is provisioned separately (see ADR 0010 / Slice 2). This section is the
per-box recipe; `deploy/docker-compose.prod.yml` is the reference stack.

### 1. Secrets

Copy the templates and fill in strong, generated values. Keep them `0600` and
never commit them (they are git-ignored):

```bash
cd deploy
cp .env.example .env && chmod 600 .env
cp sites/site-a.env.example sites/site-a.env && chmod 600 sites/site-a.env
```

- `deploy/.env` — the MySQL **root** password (used only to create per-site DBs)
  + each site's DB name/user/password + `CLOUDFLARE_RANGES`. Root credentials
  live here and **only** here — never in a site's env.
- `deploy/sites/<site>.env` — that one site's `DB_*`, `APP_URL`, and
  `TRUSTED_PROXIES`. A site container gets its own DB user's password, nothing
  else.

### 2. Per-site database (least privilege)

`deploy/mysql/init/01-sites.sh` runs on first MySQL boot and creates, per site,
a database plus a user **granted only on that schema** (`GRANT ALL ON
<db>.* `) — never `*.*`. A site container can never read another site's data
even if it is fully compromised. Add sites by setting `SITE_C_DB/USER/PASS` etc.
in `deploy/.env` (the script handles A–D out of the box; extend the loop for
more).

MySQL publishes **no host port** — it is reachable only on the internal Docker
network.

### 3. TLS + origin lock-down

Cloudflare proxies every site (orange cloud), so use a **Cloudflare Origin
Certificate** with SSL mode **Full (strict)**:

1. In Cloudflare → SSL/TLS → Origin Server, create a certificate for your
   hostnames.
2. Save the cert/key to `deploy/certs/<site>.pem` / `.key` (git-ignored, mounted
   read-only into Caddy — never baked into the image).
3. `deploy/Caddyfile` references them per site (`tls /certs/<site>.pem …`).

**Origin lock-down (defense in depth).** The real boundary is the **Hetzner
Cloud Firewall**: allow inbound `443`/`80` **only** from Cloudflare's published
ranges, deny the rest, so nobody can reach the origin directly and bypass the
WAF. As a second layer, `deploy/Caddyfile` also returns `403` to any peer
outside `CLOUDFLARE_RANGES` (the `cloudflare_only` snippet). Set
`CLOUDFLARE_RANGES` in `deploy/.env` from
<https://www.cloudflare.com/ips/> and **refresh it when Cloudflare updates the
list** (rare, but it does change) — update both the firewall and this variable.

### 4. Trusted proxies (so the real visitor IP survives)

Two layers must agree, or the rate limiter and audit log will see Cloudflare's IP
instead of the visitor's — or, worse, trust a forged header:

- **Caddy** (`servers.trusted_proxies` = `CLOUDFLARE_RANGES`) trusts only
  Cloudflare as an upstream, so it strips any `X-Forwarded-For` a client forges
  and sets it from the true peer.
- **Each site** (`TRUSTED_PROXIES` in its env) lists the **edge Caddy's exact
  pinned IP (`172.31.7.254`) ∪ Cloudflare ranges** — *not* the `/24` subnet, and
  never the whole Docker bridge (`172.16.0.0/12`) or `0.0.0.0/0`. Nimbus then
  walks `X-Forwarded-For` right-to-left and takes the first hop it does not
  trust: the real visitor.

The compose pins Caddy to `172.31.7.254` on a fixed subnet so this list is exact.
Trusting only the edge's single IP (not the whole subnet) matters on a shared
box: every site sits on the same network, so if a site trusted the `/24` a
*compromised* neighbour could open a direct connection and forge a client IP
against this site's rate limiter and audit log. For stronger isolation you can
also put each site on its own bridge network shared only with `caddy` and `db`.

### 5. Bring it up + first-run

```bash
cd deploy
docker compose -f docker-compose.prod.yml up -d --build
# per new site, once:
docker compose -f docker-compose.prod.yml exec site-a php bin/nimbus migrate
docker compose -f docker-compose.prod.yml exec site-a php bin/nimbus install \
  --email=admin@yoursite.com --password='<strong>'
```

Migrations are an explicit release step, never run automatically at container
start (the entrypoint only waits for the DB to be reachable). Outside
`APP_ENV=local`, `install` **refuses** to seed a default/weak admin — it fails
closed unless you pass real `--email`/`--password`, so a site never ships with a
guessable admin. Seed content (optional) with the MCP seed runner or the API.

### 6. Adding a site

1. Add `SITE_x_DB/USER/PASS` to `deploy/.env`; create the DB/user (re-run the
   init on a fresh DB, or apply the same `CREATE DATABASE/USER/GRANT` by hand).
2. `cp sites/site-a.env.example sites/<site>.env`, edit, `chmod 600`.
3. Add a `site-x` service in `docker-compose.prod.yml` (copy the `site-a` block,
   point `env_file` at the new env; mount a per-site `config/`+`themes/`
   read-only if it has a custom theme).
4. Add its block to `deploy/Caddyfile` — **including `import cloudflare_only` and
   the `tls` line**, exactly like the shipped `site-a` block, or the new site
   loses the origin-lock 403 and tries public ACME instead of the origin cert:

   ```
   site-x.example.com {
       import cloudflare_only
       tls /certs/site-x.pem /certs/site-x.key
       reverse_proxy site-x:8080
   }
   ```
5. `docker compose -f docker-compose.prod.yml up -d`, then `migrate` + `install`.

### Per-site theme / config

Sites share one image. A site that needs its own theme or `config/` mounts them
read-only over the baked defaults (`- ./sites/<site>/themes:/app/themes:ro`), as
the prod compose shows. For heavier per-site divergence, build a small image
`FROM nimbus:local` that `COPY`s the theme in — the base image is the contract.

## Memory budget

Measured on the local proof (idle): MySQL ~450 MB, Caddy ~11 MB, each site
~30 MB. The compose sets limits (site 256 MB, Caddy 128 MB, MySQL 768 MB) so one
site can't starve the others. A 4 GB box comfortably runs the platform baseline
(~0.5 GB) plus a dozen low-traffic sites; raise `innodb_buffer_pool_size` in
`deploy/mysql/my.cnf` if the DB is the bottleneck.

## Backups

Back up **off-box** (Hetzner Storage Box / an object store) so backups don't fill
the disk: a periodic `mysqldump` per database + the `site_*_uploads` volumes.
Not automated here — wire it to your host's scheduler.

## Running a public demo site

To run a shared, public "try it" instance, set `NIMBUS_DEMO=true` in that site's
env: the admin shows a persistent banner and the account-destructive
self-service actions are refused server-side — **change-password** and
**API-token minting** (admin *and* MCP) — and **PDF uploads** are dropped. This
stops one anonymous visitor from locking others out or abusing the shared box
between resets; it is not a substitute for the reset.

A demo that also showcases the official plugins uses
[`deploy/demo/Dockerfile`](../deploy/demo/Dockerfile) — the production image plus
`seo`, `markdown`, `api-advanced`, and `analytics` (installed from their repos;
enabled-by-default once present). Give the demo its **own MySQL** (so a visitor
can't starve the marketing DB), and reset it on a timer from a pre-baked,
root-owned golden dump the container can't reach — stop the app, restore only the
demo DB, wipe its uploads + storage volumes, start — plus a short-interval
size-watchdog that resets early if the demo balloons, hard-capping its disk
footprint. Front it with a Cloudflare rate-limit rule on `/admin/login` + `/api/`.

## Box hardening (Slice 2)

Provisioning + host hardening is a separate step: Docker install, deploy the
stack, the Hetzner Cloud Firewall above, an admin/SSH plane on **Tailscale** with
public port 22 closed, `userns-remap` for the daemon, DNS + the Cloudflare origin
cert. Those touch live credentials and are done on the box, not in this repo.
