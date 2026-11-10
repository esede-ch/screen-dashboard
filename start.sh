#!/bin/sh
set -eu
cd "$(dirname "$0")"
PHP_BIN=${PHP_BIN:-php}
"$PHP_BIN" -r 'if (PHP_VERSION_ID < 80500 || !extension_loaded("curl") || !extension_loaded("ldap") || !function_exists("imagecreatefromjpeg")) {fwrite(STDERR,"PHP 8.5 avec cURL, LDAP et GD avec JPEG est nécessaire.\n");exit(1);}'
umask 077
mkdir -p data
chmod 700 data
export PHP_CLI_SERVER_WORKERS=4
printf 'Tableau de bord : http://127.0.0.1:8090\nArrêt : Ctrl+C\n'
"$PHP_BIN" src/capture-monitor.php &
monitor_pid=$!
trap 'kill "$monitor_pid" 2>/dev/null || true' EXIT
trap 'exit 0' HUP INT TERM
"$PHP_BIN" -d display_errors=0 -d log_errors=1 -d error_reporting=E_ALL -S 127.0.0.1:8090 -t public
