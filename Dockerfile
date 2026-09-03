# syntax=docker/dockerfile:1

FROM php:8.4-apache-bookworm AS php-base

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libsqlite3-dev \
        libxml2-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        pcntl \
        pdo_mysql \
        pdo_sqlite \
        zip \
    && a2enmod rewrite headers expires \
    && sed -ri -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf \
    && printf '%s\n' \
        'upload_max_filesize=30M' \
        'post_max_size=35M' \
        'memory_limit=256M' \
        'max_execution_time=600' \
        'opcache.enable=1' \
        'opcache.validate_timestamps=0' \
        > /usr/local/etc/php/conf.d/cobeb.ini \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
# Estes pacotes sao importados por resources/js/echo.js, mas ainda nao constam
# no package.json do projeto. Mantemos a imagem reproduzivel com versoes fixas.
RUN npm install --no-save --package-lock=false --no-audit --no-fund \
    laravel-echo@2.3.0 \
    pusher-js@8.4.0
COPY resources ./resources
COPY public ./public
COPY postcss.config.js tailwind.config.js vite.config.js ./
RUN npm exec -- vite build

FROM php-base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

FROM php-base AS runtime

COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

RUN mkdir -p \
        bootstrap/cache \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && if [ ! -e public/storage ]; then ln -s ../storage/app/public public/storage; fi \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data bootstrap/cache storage \
    && chmod -R ug+rwX bootstrap/cache storage

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD php -r '$s=@fsockopen("127.0.0.1",80,$e,$m,3); if(!$s) exit(1); fclose($s);'

CMD ["apache2-foreground"]
