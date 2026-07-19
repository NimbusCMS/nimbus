# NimbusCMS dev/prod image: PHP 8.3 CLI + PDO MySQL + GD (image thumbnails) + Composer.
FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
EXPOSE 8080

# The built-in server uses public/index.php as the router (serves static files,
# routes everything else through the app).
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
