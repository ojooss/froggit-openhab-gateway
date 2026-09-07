FROM php:8.4-apache

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN DEBIAN_FRONTEND=noninteractive apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get install -y unzip && \
    DEBIAN_FRONTEND=noninteractive apt-get clean

RUN docker-php-ext-install pdo_mysql

RUN a2enmod rewrite
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
