#!/usr/bin/env bash
#
# End-to-end smoke test: install NimbusCMS from nothing, serve it, and drive a
# real browserless session through collection and entry CRUD.
#
# Proves the things unit and integration tests cannot: that the installer works
# on an empty database, that the shipped entry point boots, and that a fresh
# install is immediately usable. Runs in CI and locally.
#
# Usage: tests/smoke.sh            (expects DB_* env vars, or a .env)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-nimbus_smoke}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"
PORT="${SMOKE_PORT:-8099}"
EMAIL="smoke@nimbus.test"
PASSWORD="smoke-test-passphrase"

JAR="$(mktemp)"
ENVFILE="$ROOT/.env"
SERVER_PID=""

cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null || true
    rm -f "$JAR"
    [ -f "$ENVFILE.smoke-backup" ] && mv "$ENVFILE.smoke-backup" "$ENVFILE" || rm -f "$ENVFILE"
}
trap cleanup EXIT

say()  { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
pass() { printf '  \033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '  \033[31m✗\033[0m %s\n' "$1"; exit 1; }

# ---------------------------------------------------------------- fresh install

say "Preparing a fresh database"
[ -f "$ENVFILE" ] && cp "$ENVFILE" "$ENVFILE.smoke-backup"
# Via PHP rather than the mysql client, so the only dependencies are php + curl.
H="$DB_HOST" P="$DB_PORT" U="$DB_USER" W="$DB_PASS" N="$DB_NAME" php -r '
    $pdo = new PDO(sprintf("mysql:host=%s;port=%d", getenv("H"), (int) getenv("P")), getenv("U"), getenv("W"),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db = getenv("N");
    $pdo->exec("DROP DATABASE IF EXISTS `$db`");
    $pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");
'
pass "database $DB_NAME recreated"

cat > "$ENVFILE" <<EOF
APP_NAME="NimbusCMS Smoke"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://127.0.0.1:$PORT
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
TRUSTED_PROXIES=
EOF

# Real environment variables deliberately win over .env (see Support\Env), and a
# dev container sets APP_ENV/DB_* of its own. Export ours so the smoke test is
# never quietly redirected at the development database or into dev mode.
export APP_ENV=production APP_DEBUG=false
export DB_HOST DB_PORT DB_NAME DB_USER DB_PASS

say "Installing (production mode — credentials must be supplied)"
if php bin/nimbus install --email="$EMAIL" --password=password 2>/dev/null; then
    fail "installer accepted a weak password"
fi
pass "weak passwords are refused"

php bin/nimbus install --email="$EMAIL" --password="$PASSWORD" --name="Smoke Admin"
pass "installed"

# ------------------------------------------------------------------- serve it

say "Booting the shipped entry point"
php -S "127.0.0.1:$PORT" -t public public/index.php >/dev/null 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 40); do
    curl -fsS "http://127.0.0.1:$PORT/admin/login" >/dev/null 2>&1 && break
    sleep 0.25
done
curl -fsS "http://127.0.0.1:$PORT/admin/login" >/dev/null || fail "server never came up"
pass "serving on :$PORT"

get()  { curl -sSL -b "$JAR" -c "$JAR" "http://127.0.0.1:$PORT$1"; }   # -L so we can assert on the page, not the 302
post() { curl -sS  -b "$JAR" -c "$JAR" -X POST "http://127.0.0.1:$PORT$1" "${@:2}"; }
postr() { curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "http://127.0.0.1:$PORT$1" "${@:2}"; }
token() { get "$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }

# Assertions take an already-captured body: piping into `grep -q` would close
# the pipe early, curl would die of EPIPE, and pipefail would report a failure
# on a successful match.
has()    { printf '%s' "$2" | grep -qF -- "$1"; }
expect() { has "$1" "$2" || fail "$3"; }
reject() { has "$1" "$2" && fail "$3" || true; }

# ------------------------------------------------------------------- the flow

say "Signing in"
expect 'Sign in' "$(get /admin/collections)" "anonymous access was not gated"
pass "anonymous admin request redirects to the login page"

expect '302' "$(postr /admin/login -d "_token=$(token /admin/login)" -d "email=$EMAIL" -d "password=$PASSWORD")" "login failed"
expect 'Dashboard' "$(get /admin)" "dashboard not reachable after login"
pass "signed in"

say "Creating a collection"
expect 'msg=created' "$(postr /admin/collections \
    -d "_token=$(token /admin/collections/new)" \
    -d "name=Smoke Posts" -d "handle=smoke_posts" -d "kind=collection" -d "icon=x" \
    -d "fields[0][label]=Body" -d "fields[0][handle]=body" -d "fields[0][type]=textarea" \
    -d "fields[1][label]=Qty"  -d "fields[1][handle]=qty"  -d "fields[1][type]=number")" \
    "collection was not created"
pass "collection created with two fields"

say "Rejecting a duplicate handle"
DUP="$(post /admin/collections -d "_token=$(token /admin/collections/new)" -d "name=Impostor" -d "handle=smoke_posts")"
expect 'already taken'      "$DUP" "duplicate handle was not reported"
expect 'value="Impostor"'   "$DUP" "duplicate handle lost the submission"
pass "duplicate handle reported without losing the submission"

say "Creating an entry"
expect 'msg=created' "$(postr /admin/collections/smoke_posts/entries \
    -d "_token=$(token /admin/collections/smoke_posts/entries/new)" \
    -d "title=Hello Smoke" -d "status=published" -d "f[body]=body text" -d "f[qty]=42")" \
    "entry was not created"
expect 'Hello Smoke' "$(get /admin/collections/smoke_posts/entries)" "entry missing from the list"
pass "entry created and listed"

say "Rejecting an invalid number"
BAD="$(post /admin/collections/smoke_posts/entries \
    -d "_token=$(token /admin/collections/smoke_posts/entries/new)" \
    -d "title=Bad Number" -d "status=draft" -d "f[qty]=not-a-number")"
expect 'valid number' "$BAD" "invalid number was accepted"
reject 'Bad Number' "$(get /admin/collections/smoke_posts/entries)" "invalid entry was stored"
pass "invalid number rejected and nothing stored"

say "Updating the entry"
ID="$(get /admin/collections/smoke_posts/entries | grep -o '/entries/[0-9]*/edit' | head -1 | grep -o '[0-9]*')"
[ -n "$ID" ] || fail "could not find the entry id"

expect 'msg=updated' "$(postr "/admin/collections/smoke_posts/entries/$ID" \
    -d "_token=$(token "/admin/collections/smoke_posts/entries/$ID/edit")" \
    -d "title=Hello Smoke Edited" -d "status=published" -d "f[body]=edited" -d "f[qty]=7")" \
    "entry was not updated"
expect 'Hello Smoke Edited' "$(get /admin/collections/smoke_posts/entries)" "update not visible"
pass "entry updated"

say "Deleting the entry"
expect 'msg=deleted' "$(postr "/admin/collections/smoke_posts/entries/$ID/delete" \
    -d "_token=$(token /admin/collections/smoke_posts/entries)")" "entry was not deleted"
reject 'Hello Smoke Edited' "$(get /admin/collections/smoke_posts/entries)" "entry survived deletion"
pass "entry deleted"

say "Signing out"
postr /admin/logout -d "_token=$(token /admin/collections)" >/dev/null
expect 'Sign in' "$(get /admin/collections)" "still authenticated after logout"
pass "signed out and re-gated"

printf '\n\033[32m✓ smoke test passed — installed from empty and completed collection + entry CRUD\033[0m\n'
