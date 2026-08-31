# Demo image = the production image + the four official plugins. Kept separate
# so the marketing image stays plugin-free; the demo enables all four (plugins
# are enabled-by-default once installed — config/plugins.php is the opt-out).
FROM nimbus:local

USER root
# git/unzip for Composer VCS installs; composer itself (removed again after).
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN cd /app \
    && composer config minimum-stability dev \
    && composer config prefer-stable true \
    # no-api → clone via git instead of the GitHub API (public repos, no token,
    # no API rate limit).
    && composer config repositories.seo '{"type":"vcs","url":"https://github.com/NimbusCMS/plugin-seo","no-api":true}' \
    && composer config repositories.markdown '{"type":"vcs","url":"https://github.com/NimbusCMS/plugin-markdown","no-api":true}' \
    && composer config repositories.apiadv '{"type":"vcs","url":"https://github.com/NimbusCMS/plugin-api-advanced","no-api":true}' \
    && composer config repositories.analytics '{"type":"vcs","url":"https://github.com/NimbusCMS/plugin-analytics","no-api":true}' \
    && composer config repositories.inventory '{"type":"vcs","url":"https://github.com/NimbusCMS/plugin-inventory","no-api":true}' \
    && composer config repositories.commerce '{"type":"vcs","url":"https://github.com/NimbusCMS/plugin-commerce","no-api":true}' \
    && composer config repositories.storefront '{"type":"vcs","url":"https://github.com/NimbusCMS/plugin-storefront","no-api":true}' \
    # COMPOSER_ROOT_VERSION so the plugins' `nimbuscms/nimbus: dev-main` requirement
    # resolves against this baked root (the image has no .git to infer it from).
    # Commerce and Storefront both pull Inventory transitively (its ports).
    && COMPOSER_ROOT_VERSION=dev-main composer require --no-scripts --no-interaction --update-no-dev --optimize-autoloader \
       nimbuscms/seo nimbuscms/markdown nimbuscms/api-advanced nimbuscms/analytics nimbuscms/inventory nimbuscms/commerce nimbuscms/storefront \
    && rm -f /usr/bin/composer \
    && chown -R www-data:www-data /app/vendor /app/composer.json /app/composer.lock

# The headless example — a static page served same-origin at /example.html, read
# by docs/HEADLESS.md. External JS/CSS (not inline) so it loads under the strict
# nonce-only CSP via 'self'. Demo-only; the marketing image stays without it.
COPY example.html example.css example.js /app/public/

USER www-data
