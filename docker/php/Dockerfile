FROM php:8.3-fpm-alpine3.22

# Run PHP as your own user id, so files it creates (cache, logs, uploads,
# vendor/) belong to you on the host — no permission errors on Linux/WSL.
ARG UID=1000
ARG GID=1000

RUN apk add --no-cache icu-dev libzip-dev libpng-dev libjpeg-turbo-dev libwebp-dev \
        libavif-dev freetype-dev oniguruma-dev bash git unzip mysql-client shadow \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-avif --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl zip gd mbstring opcache pcntl \
    && groupmod -o -g "${GID}" www-data && usermod -o -u "${UID}" -g "${GID}" www-data \
    && mkdir -p /tmp/composer && chown www-data:www-data /tmp/composer

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-kulmis.ini

ENV COMPOSER_HOME=/tmp/composer
WORKDIR /var/www/html
USER www-data
