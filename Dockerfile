FROM php:8.2-apache

# NOTE: curl, mbstring, openssl, json and opcache are already compiled and
# enabled in the official php:8.2-apache image. We deliberately do NOT run
# docker-php-ext-install for them: rebuilding bundled extensions is a frequent
# cause of immediate build failures. The application's required extensions
# (curl, openssl, json, mbstring) are therefore available without a build step.

RUN a2enmod headers

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php-production.ini /usr/local/etc/php/conf.d/99-scorp.ini

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .

RUN mkdir -p var/rate var/audit \
 && chown -R www-data:www-data var \
 && chmod -R 775 var

EXPOSE 80