FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql curl \
    && rm -rf /var/lib/apt/lists/* \
    && sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf

COPY deploy/apache-security.conf /etc/apache2/conf-enabled/geonet-security.conf
COPY deploy/php-production.ini /usr/local/etc/php/conf.d/geonet-production.ini
COPY *.php /var/www/html/
COPY assets/ /var/www/html/assets/
COPY config/ /var/www/html/config/
COPY includes/ /var/www/html/includes/
COPY api/ /var/www/html/api/
COPY style.css script.js /var/www/html/

EXPOSE 10000
CMD ["apache2-foreground"]
