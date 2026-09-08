# Multi-stage: `dev` and `prod` share the same base (PHP/Apache setup) so there's only one
# place to maintain that. `dev` bind-mounts the app code in at runtime (see docker-compose.yaml)
# and expects `composer install` to be run manually afterwards. `prod` bakes the app and its
# production-only dependencies into the image, so it runs standalone with no bind mount — this
# is what gets published to ghcr.io.

FROM php:8.4-apache AS base

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN DEBIAN_FRONTEND=noninteractive apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get install -y unzip && \
    DEBIAN_FRONTEND=noninteractive apt-get clean

RUN docker-php-ext-install pdo_mysql

RUN a2enmod rewrite
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

FROM base AS dev

FROM base AS builder
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

FROM base AS prod
COPY --from=builder /var/www/html /var/www/html
RUN mkdir -p var/cache var/log && chown -R www-data:www-data var
ENV APP_ENV=prod
EXPOSE 80
