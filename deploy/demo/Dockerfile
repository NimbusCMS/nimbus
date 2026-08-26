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
    # COMPOSER_ROOT_VERSION so the plugins' `nimbuscms/nimbus: dev-main` requirement
    # resolves against this baked root (the image has no .git to infer it from).
    && COMPOSER_ROOT_VERSION=dev-main composer require --no-scripts --no-interaction --update-no-dev --optimize-autoloader \
       nimbuscms/seo nimbuscms/markdown nimbuscms/api-advanced nimbuscms/analytics \
    && rm -f /usr/bin/composer \
    && chown -R www-data:www-data /app/vendor /app/composer.json /app/composer.lock

USER www-data
