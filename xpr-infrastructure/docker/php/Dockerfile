# PHP 8.3 (version imposée par le CLAUDE.md — l'hôte peut avoir plus récent,
# le conteneur fait foi pour la parité dev/prod).
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        icu-dev libzip-dev libpng-dev freetype-dev libjpeg-turbo-dev \
        postgresql-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql intl bcmath zip gd pcntl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'memory_limit=512M'; \
    } > /usr/local/etc/php/conf.d/xpr.ini

WORKDIR /var/www/xpr-backend
