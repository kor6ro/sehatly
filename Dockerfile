# syntax=docker/dockerfile:1
#
# API-only image for Render's free web service.
#
# The SPA is deployed separately to Cloudflare Pages. This container serves
# /api/v1 and /up only; / returns the deliberate 503 from routes/web.php
# because frontend/dist is not in the image.
#
FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        mbstring \
        opcache \
        pdo_mysql \
        zip \
    && a2enmod rewrite headers \
    && printf '\n<IfModule mpm_prefork_module>\n    StartServers 1\n    MinSpareServers 1\n    MaxSpareServers 2\n    MaxRequestWorkers 6\n    ServerLimit 6\n    MaxConnectionsPerChild 300\n</IfModule>\n' \
        >> /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependency layer first, so application edits do not reinstall Composer.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

COPY . .

# package:discover runs here. The build has no deployment secrets, so it runs
# as the one environment the delivery guard permits (local) with a throwaway
# key; runtime configuration comes from Render.
RUN APP_ENV=local \
    APP_DEBUG=false \
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    OTP_DRIVER=log \
    PUSH_DRIVER=log \
    composer dump-autoload --optimize --no-dev \
    && mkdir -p \
        storage/app/public \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && rm -f .env

COPY deploy/php.ini /usr/local/etc/php/conf.d/99-sehatly.ini
COPY deploy/apache-vhost.conf /etc/apache2/sehatly-vhost.conf
COPY deploy/entrypoint.sh /usr/local/bin/sehatly-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/sehatly-entrypoint \
    && chmod +x /usr/local/bin/sehatly-entrypoint

EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/sehatly-entrypoint"]
