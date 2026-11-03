#!/bin/sh
# CI only: against the stack just started on localhost, with a disposable volume.
set -eu
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/)" = 303
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/login.php)" = 200
test "$(curl -s -o /dev/null -w '%{http_code}' 'http://localhost:8090/api.php?action=status')" = 401
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/assets/esede-logo.png)" = 200
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/data/boxes.json)" = 404
test "$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: refused.example' http://localhost:8090/login.php)" = 403
test "$(docker compose exec -T dashboard id -u)" = 33
docker compose exec -T dashboard php -r 'file_put_contents("/var/lib/dashboard/ci-persistence", "ok");'
docker compose up -d --force-recreate --wait --wait-timeout 120
docker compose exec -T dashboard php -r 'if (file_get_contents("/var/lib/dashboard/ci-persistence") !== "ok") exit(1); unlink("/var/lib/dashboard/ci-persistence");'
