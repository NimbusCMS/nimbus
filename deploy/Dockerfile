# NimbusCMS production image — FrankenPHP (Caddy + PHP in one process), classic
# mode (NOT worker mode: public/index.php's define(NB_START) + static Config/Env
# state are request-scoped and would bleed/fatal across worker requests).
#
# One container per site; the edge Caddy terminates TLS and reverse-proxies to
# this container's internal :8080. Runs as a non-root user on a >1024 port so
# `cap_drop: [ALL]` holds with no setcap.
FROM dunglas/frankenphp:1-php8.3

# Extensions: the same three the app needs, plus OPcache (not on by default).
RUN install-php-extensions pdo_mysql gd opcache

# Composer (build-time only; not needed in the running container).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# FrankenPHP ships with cap_net_bind_service on its binary so it can bind :80 as
# non-root. We bind :8080 (>1024) and don't need it — and a file-capability
# binary cannot be exec'd under `no-new-privileges` + `cap_drop: ALL`. Strip it
# so the full hardening set holds and the process starts as an ordinary binary.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcap2-bin \
    && setcap -r /usr/local/bin/frankenphp \
    && rm -rf /var/lib/apt/lists/*

# Production PHP config (display_errors off, OPcache on, bounded memory).
COPY deploy/php/prod.ini /usr/local/etc/php/conf.d/zz-nimbus-prod.ini

WORKDIR /app

# Bake the app + production dependencies only (no dev tooling in the runtime).
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction \
    && rm -f /usr/bin/composer   # build-time only; nothing in the runtime should install packages
COPY . .

# The per-site FrankenPHP server config + the DB-wait entrypoint.
COPY deploy/site.Caddyfile /etc/frankenphp/Caddyfile
COPY deploy/docker-entrypoint.sh /usr/local/bin/nimbus-entrypoint
RUN chmod +x /usr/local/bin/nimbus-entrypoint \
    # Writable runtime dirs, owned by the non-root runtime user; the rest is read-only.
    && mkdir -p storage public/uploads /data /config \
    && chown -R www-data:www-data storage public/uploads /data /config

USER www-data
EXPOSE 8080

ENTRYPOINT ["nimbus-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
