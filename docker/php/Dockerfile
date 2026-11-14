FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-dev libzip-dev libpng-dev libjpeg-turbo-dev libwebp-dev \
        libavif-dev freetype-dev oniguruma-dev bash git unzip \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-avif --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl zip gd mbstring opcache pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-kulmis.ini

WORKDIR /var/www/html
