FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install -j2 pdo_mysql curl mbstring \
    && a2dismod mpm_event mpm_worker \
    && a2enmod mpm_prefork rewrite headers setenvif \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY *.php .htaccess ./
COPY movie_groups.sql content_v2.sql ads.sql /var/www/html/
COPY assets/ ./assets/
COPY mysql.sql /opt/gpanel/mysql.sql
COPY deploy/ /opt/gpanel/deploy/
COPY deploy/apache.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/gpanel.ini
RUN sed -i 's/\r$//' /opt/gpanel/deploy/start.sh \
    && chmod +x /opt/gpanel/deploy/start.sh \
    && mkdir -p /var/lib/gpanel/state \
    && chown -R www-data:www-data /var/lib/gpanel

ENV PORT=8080 PANEL_STATE_DIR=/var/lib/gpanel/state
EXPOSE 8080
CMD ["/opt/gpanel/deploy/start.sh"]
