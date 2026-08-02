#!/usr/bin/env bash
#
# Cross-repository package-boundary test.
#
# Proves that Nimbus and the official Markdown plugin work as independently
# packaged software: a real Composer resolution installs the plugin into a
# Nimbus project, the loader discovers it from the generated installed.json,
# and the whole Markdown lifecycle runs through the real HTTP boundary —
# including the safety behaviour when the plugin is disabled.
#
# Nothing here mocks Composer discovery. The only thing synthesised is the
# database connection (from env) and the admin credentials.
#
# Layout it builds:
#   $WORK/app     a copy of this Nimbus checkout (the root project)
#   $WORK/plugin  the plugin-markdown checkout (a path repository)
# then `composer require nimbuscms/markdown` inside $WORK/app, exactly as a
# real operator installs a plugin into their site.
#
# Usage: tests/Integration/package-boundary.sh
#   env: DB_HOST DB_PORT DB_NAME DB_USER DB_PASS   (a reachable MySQL)
#        PLUGIN_REPO   git URL or local path of plugin-markdown
#        PLUGIN_REF    branch/tag to test against (default: main)
set -euo pipefail

CORE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-nimbus_pkg}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"
PORT="${PKG_PORT:-8097}"
EMAIL="pkg@nimbus.test"
PASSWORD="package-boundary-passphrase"
PLUGIN_REPO="${PLUGIN_REPO:-https://github.com/NimbusCMS/plugin-markdown}"
PLUGIN_REF="${PLUGIN_REF:-main}"

WORK="$(mktemp -d)"
APP="$WORK/app"
PLUGIN="$WORK/plugin"
JAR="$(mktemp)"
SERVER_PID=""

cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null || true
    rm -rf "$WORK" "$JAR"
}
trap cleanup EXIT

say()  { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
pass() { printf '  \033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '  \033[31m✗\033[0m %s\n' "$1"; exit 1; }

# ------------------------------------------------------------- assemble project

say "Copying the Nimbus checkout as the root project"
mkdir -p "$APP"
# Everything but the heavy/local bits — Composer rebuilds vendor from scratch.
tar -C "$CORE_ROOT" --exclude=.git --exclude=vendor --exclude=node_modules \
    --exclude='storage/*' --exclude='public/uploads/*' -cf - . | tar -C "$APP" -xf -
pass "root project at \$WORK/app"

say "Fetching the Markdown plugin ($PLUGIN_REF)"
if [ -d "$PLUGIN_REPO/.git" ] || [ -f "$PLUGIN_REPO/composer.json" ]; then
    cp -R "$PLUGIN_REPO" "$PLUGIN"          # local checkout (dev convenience)
else
    git clone --depth 1 --branch "$PLUGIN_REF" "$PLUGIN_REPO" "$PLUGIN" 2>&1 | tail -1
fi
pass "plugin source at \$WORK/plugin"

# --------------------------------------------------------------- real install

say "Installing the plugin through Composer"
cd "$APP"
# The root IS nimbuscms/nimbus, so the plugin's "nimbuscms/nimbus: dev-main"
# requirement resolves against the root package.
export COMPOSER_ROOT_VERSION=dev-main
composer config repositories.markdown path "$PLUGIN" >/dev/null
composer require "nimbuscms/markdown:@dev" --no-interaction --no-progress 2>&1 | tail -3

INSTALLED="$APP/vendor/composer/installed.json"
grep -q '"nimbuscms/markdown"' "$INSTALLED" \
    || fail "the plugin is not in the generated installed.json"
grep -q '"nimbuscms-plugin"' "$INSTALLED" \
    || fail "the plugin is not typed nimbuscms-plugin in installed.json"
pass "nimbuscms/markdown resolved and recorded by Composer"

# ------------------------------------------------------------------- configure

cat > "$APP/.env" <<EOF
APP_NAME="Nimbus Package Test"
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
export APP_ENV=production DB_HOST DB_PORT DB_NAME DB_USER DB_PASS

say "Installing from an empty database"
H="$DB_HOST" P="$DB_PORT" U="$DB_USER" W="$DB_PASS" N="$DB_NAME" php -r '
    $pdo = new PDO(sprintf("mysql:host=%s;port=%d", getenv("H"), (int) getenv("P")), getenv("U"), getenv("W"),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db = getenv("N");
    $pdo->exec("DROP DATABASE IF EXISTS `$db`");
    $pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");
'
php bin/nimbus install --email="$EMAIL" --password="$PASSWORD" >/dev/null
pass "migrated and installed"

# ------------------------------------------------------------------- serve it

# Plugins load at boot (per Application construction). The single-process
# built-in server caches config across requests, so a plugin enable/disable is
# applied by restarting it — deterministic, and closer to production where
# php-fpm workers each re-read config anyway.
start_server() {
    php -S "127.0.0.1:$PORT" -t public public/index.php >/tmp/pkg-server.log 2>&1 &
    SERVER_PID=$!
    for _ in $(seq 1 40); do
        curl -fsS "http://127.0.0.1:$PORT/admin/login" >/dev/null 2>&1 && return
        sleep 0.25
    done
    fail "server never came up"
}
restart_server() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
    start_server
}

say "Booting the app with the plugin installed"
start_server
pass "serving on :$PORT"

# curl helpers (pipefail-safe: capture, then grep a variable)
get()   { curl -sSL -b "$JAR" -c "$JAR" "http://127.0.0.1:$PORT$1"; }
post()  { curl -sS  -b "$JAR" -c "$JAR" -X POST "http://127.0.0.1:$PORT$1" "${@:2}"; }
postr() { curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "http://127.0.0.1:$PORT$1" "${@:2}"; }
token() { get "$1" | grep -o 'name="_token" value="[^"]*"' | head -1 | cut -d'"' -f4; }
has()    { printf '%s' "$2" | grep -qF -- "$1"; }
expect() { has "$1" "$2" || fail "$3"; }
reject() { has "$1" "$2" && fail "$3" || true; }
set_enabled() { printf "<?php\nreturn ['nimbuscms.markdown' => %s];\n" "$1" > "$APP/config/plugins.php"; restart_server; }

# ------------------------------------------------------------- the lifecycle

say "Signing in"
expect '302' "$(postr /admin/login -d "_token=$(token /admin/login)" -d "email=$EMAIL" -d "password=$PASSWORD")" "login failed"
pass "signed in"

say "The plugin is discovered by the Composer-driven loader"
PLUGINS="$(get /admin/plugins)"
expect 'Markdown' "$PLUGINS" "Markdown plugin missing from the admin page"
expect 'nimbuscms/markdown' "$PLUGINS" "package name missing from the admin page"
expect 'Enabled' "$PLUGINS" "plugin not reported as enabled"
NEWFORM="$(get /admin/collections/new)"
expect 'value="markdown"' "$NEWFORM" "the markdown field type is not offered in the field builder"
pass "markdown field type registered and offered"

say "Creating a collection with a Markdown field"
expect 'msg=created' "$(postr /admin/collections \
    -d "_token=$(token /admin/collections/new)" \
    -d "name=Articles" -d "handle=articles" -d "kind=collection" -d "icon=A" \
    -d "fields[0][label]=Body" -d "fields[0][handle]=body" -d "fields[0][type]=markdown")" \
    "collection was not created"
pass "collection created with a markdown field"

say "Creating and editing an entry through the real boundary"
SOURCE='# Hello

Written in **Markdown**, stored as source.'
expect 'msg=created' "$(postr /admin/collections/articles/entries \
    -d "_token=$(token /admin/collections/articles/entries/new)" \
    -d "title=First Post" -d "status=published" --data-urlencode "f[body]=$SOURCE")" \
    "entry was not created"
ID="$(get /admin/collections/articles/entries | grep -o '/entries/[0-9]*/edit' | head -1 | grep -o '[0-9]*')"
[ -n "$ID" ] || fail "could not find the new entry id"

EDIT="$(get "/admin/collections/articles/entries/$ID/edit")"
expect 'Written in **Markdown**' "$EDIT" "stored Markdown source not shown in the editor"
expect 'msg=updated' "$(postr "/admin/collections/articles/entries/$ID" \
    -d "_token=$(token "/admin/collections/articles/entries/$ID/edit")" \
    -d "title=First Post" -d "status=published" --data-urlencode "f[body]=# Edited")" \
    "entry was not updated"
pass "entry created and edited"

say "Disabling the plugin (supported configuration)"
set_enabled 'false'
DIS_PLUGINS="$(get /admin/plugins)"
expect 'Disabled' "$DIS_PLUGINS" "plugin not reported as disabled after config change"

say "Existing source data is byte-identical after disabling"
STORED="$(H="$DB_HOST" P="$DB_PORT" U="$DB_USER" W="$DB_PASS" N="$DB_NAME" I="$ID" php -r '
    $pdo = new PDO(sprintf("mysql:host=%s;port=%d;dbname=%s", getenv("H"), (int) getenv("P"), getenv("N")), getenv("U"), getenv("W"));
    $row = $pdo->query("SELECT data FROM nb_entries WHERE id = " . (int) getenv("I"))->fetch(PDO::FETCH_ASSOC);
    $data = json_decode($row["data"], true);
    echo $data["body"];
')"
[ "$STORED" = "# Edited" ] || fail "stored source changed after disabling (got: $STORED)"
pass "stored Markdown source unchanged"

say "The field degrades read-only and blocks saves while disabled"
DIS_EDIT="$(get "/admin/collections/articles/entries/$ID/edit")"
expect 'unavailable' "$DIS_EDIT" "no missing-provider diagnostic shown"
expect '# Edited' "$DIS_EDIT" "stored source not shown read-only while disabled"
BLOCKED="$(post /admin/collections/articles/entries/$ID \
    -d "_token=$(token "/admin/collections/articles/entries/$ID/edit")" \
    -d "title=Should Not Save" -d "status=published" --data-urlencode "f[body]=clobber")"
expect 'cannot be saved' "$BLOCKED" "save was not blocked while the provider is unavailable"
AFTER="$(get "/admin/collections/articles/entries/$ID/edit")"
reject 'Should Not Save' "$AFTER" "a blocked save still changed the entry"
pass "read-only, save blocked, content intact"

say "Re-enabling the plugin restores editing"
set_enabled 'true'
expect 'Enabled' "$(get /admin/plugins)" "plugin not enabled again after config change"
expect 'msg=updated' "$(postr "/admin/collections/articles/entries/$ID" \
    -d "_token=$(token "/admin/collections/articles/entries/$ID/edit")" \
    -d "title=Editing Resumed" -d "status=published" --data-urlencode "f[body]=# Back")" \
    "editing did not resume after re-enabling"
expect 'Editing Resumed' "$(get /admin/collections/articles/entries)" "re-enabled edit not visible"
pass "editing resumed"

printf '\n\033[32m✓ package boundary proven — Nimbus and nimbuscms/markdown install and run together through real Composer resolution\033[0m\n'
