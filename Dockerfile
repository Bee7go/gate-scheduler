FROM php:8.4-fpm-bookworm

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install --yes --no-install-recommends \
        libpq-dev \
        libzip-dev \
        nginx \
        unzip \
    && docker-php-ext-install \
        opcache \
        pdo_pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/start-web.sh /usr/local/bin/start-web

RUN composer dump-autoload \
    --no-dev \
    --classmap-authoritative \
    --no-interaction \
    && chown -R www-data:www-data bootstrap/cache storage \
    && chmod +x /usr/local/bin/start-web

EXPOSE 8080

CMD ["/usr/local/bin/start-web"]
