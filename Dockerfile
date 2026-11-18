FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip \
    && docker-php-ext-install pdo_mysql mysqli \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html
COPY docker/php.ini /usr/local/etc/php/conf.d/dibonke.ini
WORKDIR /var/www/html
