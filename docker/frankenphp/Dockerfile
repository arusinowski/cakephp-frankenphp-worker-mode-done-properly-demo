# FrankenPHP image for the classic and worker mode services. Same image for both, so benchmarks compare the
# execution model only.
FROM dunglas/frankenphp:1.13.5-php8.4 AS server

RUN install-php-extensions pdo_mysql intl \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY php.ini "$PHP_INI_DIR/conf.d/zz-demo.ini"
COPY Caddyfile.classic /etc/frankenphp/Caddyfile.classic
COPY Caddyfile.worker /etc/frankenphp/Caddyfile.worker

# The same PHP build plus the tools to install, test, soak and benchmark the app: Composer and ApacheBench.
FROM server AS cli

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends apache2-utils git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_sqlite zip
