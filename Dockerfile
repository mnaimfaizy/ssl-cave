FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libzip-dev \
        unzip \
    && docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Document root is the app root (cPanel subdomain folder model), not public/
ENV APACHE_DOCUMENT_ROOT=/var/www/html

WORKDIR /var/www/html

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/sslcave-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/sslcave-entrypoint \
    && chmod +x /usr/local/bin/sslcave-entrypoint

COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html/storage \
    && chmod -R ug+rwX /var/www/html/storage

EXPOSE 80

ENTRYPOINT ["sslcave-entrypoint"]
CMD ["apache2-foreground"]
