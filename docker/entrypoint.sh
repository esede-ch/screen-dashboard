#!/bin/sh
set -eu
umask 077
mkdir -p "$APACHE_RUN_DIR" "$APACHE_LOCK_DIR" "$DASHBOARD_DATA_DIR/sessions"
chmod 700 "$DASHBOARD_DATA_DIR" "$DASHBOARD_DATA_DIR/sessions"
php /var/www/dashboard/docker/check-config.php
if [ "$#" -eq 1 ] && [ "$1" = apache2-foreground ]; then
    exec /usr/bin/supervisord -c /etc/supervisor/dashboard.conf
fi
exec docker-php-entrypoint "$@"
