FROM php:8.5-apache-trixie AS production

RUN apt-get update \
    && apt-get install -y --no-install-recommends libldap-dev libjpeg62-turbo-dev libpng-dev \
    && docker-php-ext-configure ldap --with-libdir="lib/$(dpkg-architecture -qDEB_HOST_MULTIARCH)" \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" ldap gd \
    && apt-get purge -y libldap-dev libjpeg62-turbo-dev libpng-dev \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'Listen 8080\n' > /etc/apache2/ports.conf

WORKDIR /var/www/dashboard
COPY src/ ./src/
COPY public/ ./public/
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/dashboard.ini
COPY docker/entrypoint.sh /usr/local/bin/dashboard-entrypoint
COPY docker/healthcheck.php docker/check-config.php ./docker/

RUN mkdir -p /var/lib/dashboard/sessions /config \
    && chown -R www-data:www-data /var/lib/dashboard \
    && chmod 700 /var/lib/dashboard /var/lib/dashboard/sessions \
    && chmod 755 /usr/local/bin/dashboard-entrypoint \
    && php -r 'foreach (["curl","gd","ldap","mbstring","openssl"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR,"Extension manquante: $ext\n"); exit(1); } }' \
    && apache2ctl configtest

ENV DASHBOARD_DATA_DIR=/var/lib/dashboard \
    APACHE_RUN_DIR=/tmp/apache2 \
    APACHE_LOCK_DIR=/tmp/apache2/lock \
    APACHE_PID_FILE=/tmp/apache2/apache2.pid \
    TZ=Europe/Zurich
USER www-data
EXPOSE 8080
ENTRYPOINT ["dashboard-entrypoint"]
CMD ["apache2-foreground"]
HEALTHCHECK --interval=30s --timeout=10s --start-period=15s --retries=3 \
    CMD ["php", "/var/www/dashboard/docker/healthcheck.php"]

# Isolated CI image; test tooling is not included in the deployed image.
FROM production AS test
USER root
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends python3 slapd ldap-utils openssl \
    && rm -rf /var/lib/apt/lists/*
COPY tests/ ./tests/
USER www-data

FROM production AS runtime
