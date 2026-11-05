FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    libcurl4-openssl-dev \
    libonig-dev \
    libssl-dev \
 && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install curl mbstring openssl opcache

RUN a2enmod headers

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php-production.ini /usr/local/etc/php/conf.d/99-scorp.ini

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .

RUN mkdir -p var/rate var/audit \
 && chown -R www-data:www-data var \
 && chmod -R 775 var

EXPOSE 80