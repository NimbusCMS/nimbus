#!/bin/sh
# Deploy-artifact guards (nimbus-security-review, QA lens). These are static
# assertions over the deploy/ files — the controls that must not rot as the
# per-site pattern is copied. Fast, dependency-free (grep + git). Run from repo
# root: `sh deploy/check.sh`. Add `--image` to also assert the built image's
# hygiene (requires `nimbus:local`). Exits non-zero on the first failure.
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
fail() { echo "FAIL: $1" >&2; exit 1; }
ok()   { echo "  ok: $1"; }

echo "== deploy artifact guards =="

# 1. Every internet-facing Caddy block is origin-locked + TLS'd (DEP-3).
awk '
  /^[^[:space:]#].*\{/ { blk=$0; cf=0; tls=0; rp=0 }
  /import cloudflare_only/ { cf=1 }
  /^[[:space:]]*tls / { tls=1 }
  /reverse_proxy/ { rp=1 }
  /^\}/ { if (rp && (!cf || !tls)) { print "  block missing cloudflare_only/tls: " blk; bad=1 } }
  END { exit bad?1:0 }
' deploy/Caddyfile || fail "a site block in deploy/Caddyfile lacks import cloudflare_only or tls"
ok "every edge site block has cloudflare_only + tls"

# 2. No site env trusts a broad range; it names the pinned edge IP, not the /24 (DEP-1).
if grep -h '^TRUSTED_PROXIES=' deploy/sites/*.env.example | grep -Eq '172\.16\.0\.0/12|0\.0\.0\.0/0|172\.31\.7\.0/24'; then
  fail "a site env TRUSTED_PROXIES uses a broad/subnet range instead of the pinned edge IP"
fi
grep -q 'TRUSTED_PROXIES=172.31.7.254' deploy/sites/site-a.env.example || fail "site env does not pin the edge IP 172.31.7.254"
ok "site envs trust only the pinned edge IP (not /24, /12, or 0.0.0.0/0)"

# 3. Uploads never execute PHP (DEP-2), and classic mode only (no worker block).
grep -q 'respond @uploads_php 403' deploy/site.Caddyfile || fail "site.Caddyfile lost the uploads-no-PHP guard"
if grep -qE '^[[:space:]]*worker[[:space:]{]' deploy/site.Caddyfile; then fail "site.Caddyfile has a worker directive (classic mode only)"; fi
ok "uploads refuse PHP; FrankenPHP classic mode"

# 4. Production defaults: debug off in the image.
grep -q '^display_errors = Off' deploy/php/prod.ini || fail "prod.ini does not disable display_errors"
ok "prod.ini display_errors Off"

# 5. MySQL is never published; root is confined; password off the cmdline (DEP-4).
if grep -A30 '^  db:' deploy/docker-compose.prod.yml | grep -qE '^[[:space:]]*ports:'; then fail "db service publishes a host port"; fi
grep -q 'MYSQL_ROOT_HOST: localhost' deploy/docker-compose.prod.yml || fail "root not confined to localhost"
if grep -q -- '-p${MYSQL_ROOT_PASSWORD}' deploy/docker-compose.prod.yml; then fail "root password on the healthcheck cmdline"; fi
ok "MySQL unpublished, root confined, password off the cmdline"

# 6. Only templates + configs are tracked; real secrets/certs are git-ignored.
extra=$(git ls-files deploy/ | grep -vE '\.example$|Dockerfile|Caddyfile|\.ya?ml$|\.sh$|\.cnf$|\.ini$|\.gitignore$|\.dockerignore$' || true)
[ -z "$extra" ] || fail "unexpected tracked file(s) under deploy/: $extra"
for f in deploy/.env deploy/sites/site-a.env deploy/certs/x.key; do
  git check-ignore -q "$f" || fail "$f is not git-ignored"
done
ok "no secrets tracked; .env / sites/*.env / certs ignored"

# 7. (optional) built-image hygiene.
if [ "${1:-}" = "--image" ]; then
  [ "$(docker run --rm --entrypoint sh nimbus:local -c 'id -u')" = "33" ] || fail "image not running as www-data (uid 33)"
  if docker run --rm --entrypoint sh nimbus:local -c 'command -v composer' >/dev/null 2>&1; then fail "composer present in image"; fi
  if [ -n "$(docker run --rm --entrypoint sh nimbus:local -c 'getcap /usr/local/bin/frankenphp' 2>/dev/null)" ]; then fail "frankenphp still has file capabilities"; fi
  ok "image: non-root, no composer, frankenphp caps stripped"
fi

echo "== all deploy guards passed =="
