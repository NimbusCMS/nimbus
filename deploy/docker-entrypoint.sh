#!/bin/sh
# Wait for MySQL to accept connections before serving (survives a box reboot
# where the DB comes up slower than the app). Bounded; migrations stay an
# explicit release step (see docs/DEPLOY.md), never run here.
set -e

if [ -n "${DB_HOST:-}" ]; then
	i=0
	until php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT")?:"3306"), getenv("DB_USER"), getenv("DB_PASS"));' 2>/dev/null; do
		i=$((i + 1))
		if [ "$i" -ge 60 ]; then
			echo "nimbus: database not reachable after 60s at ${DB_HOST}:${DB_PORT:-3306}" >&2
			exit 1
		fi
		sleep 1
	done
fi

exec "$@"
