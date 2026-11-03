#!/bin/sh
set -eu
umask 077
mkdir -p "$APACHE_RUN_DIR" "$APACHE_LOCK_DIR" "$DASHBOARD_DATA_DIR/sessions"
chmod 700 "$DASHBOARD_DATA_DIR" "$DASHBOARD_DATA_DIR/sessions"
php /var/www/dashboard/docker/check-config.php
exec docker-php-entrypoint "$@"
