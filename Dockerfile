# ─────────────  PHP‑8.2‑FPM + Apache on a clean Debian base  ─────────────
FROM php:8.2-apache

# Enable the required extensions and PostgreSQL drivers
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq-dev; \
    docker-php-ext-install pdo_pgsql pgsql; \
    a2enmod rewrite headers

# Point Apache’s document root to htdocs inside the bind‑mount
ENV APACHE_DOCUMENT_ROOT=/var/www/dolibarr/htdocs

# Rewrite the default virtual host so it uses the new DOCUMENT_ROOT
RUN sed -ri 's!/var/www/html!/var/www/dolibarr/htdocs!g' \
        /etc/apache2/sites-available/000-default.conf \
        /etc/apache2/apache2.conf

# (Optional) set the default PHP timezone
RUN echo "date.timezone=Europe/Kyiv" > /usr/local/etc/php/conf.d/timezone.ini

EXPOSE 80
CMD ["apache2-foreground"]
