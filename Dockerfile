FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip \
    && docker-php-ext-install pdo_mysql mysqli \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html
COPY docker/php.ini /usr/local/etc/php/conf.d/dibonke.ini
WORKDIR /var/www/html

# Include the application in the image so the container is portable and
# does not depend on a source-code bind mount from the host computer.
COPY --chown=root:root . /var/www/html

# Apache/PHP may write uploaded files, but application source stays read-only.
RUN mkdir -p /var/www/html/uploads/products \
    && chown -R www-data:www-data /var/www/html/uploads \
    && find /var/www/html/uploads -type d -exec chmod 755 {} \; \
    && find /var/www/html/uploads -type f -exec chmod 644 {} \;
