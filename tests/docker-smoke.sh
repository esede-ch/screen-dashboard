#!/bin/sh
# CI only: against the stack just started on localhost, with a disposable volume.
set -eu

check_http() {
    expected=$1
    path=$2
    shift 2
    if actual=$(curl --silent --show-error --noproxy '*' --connect-timeout 5 --max-time 15 \
        --output /dev/null --write-out '%{http_code}' "$@" "http://127.0.0.1:8090$path"); then
        if [ "$actual" = "$expected" ]; then
            printf 'OK HTTP %s : %s\n' "$actual" "$path"
        else
            printf 'ERREUR %s : HTTP attendu %s, reçu %s\n' "$path" "$expected" "$actual" >&2
            exit 1
        fi
    else
        printf 'ERREUR réseau : impossible de joindre %s\n' "$path" >&2
        exit 1
    fi
}

check_http 303 / -H 'Host: localhost:8090'
check_http 200 /login.php -H 'Host: localhost:8090'
check_http 401 '/api.php?action=status' -H 'Host: localhost:8090'
check_http 200 /assets/esede-logo.png -H 'Host: localhost:8090'
check_http 404 /data/boxes.json -H 'Host: localhost:8090'
check_http 403 /login.php -H 'Host: refused.example'
actual_uid=$(docker compose exec -T dashboard id -u)
if [ "$actual_uid" != 33 ]; then
    printf 'ERREUR utilisateur : UID attendu 33, reçu %s\n' "$actual_uid" >&2
    exit 1
fi
printf 'OK utilisateur non privilégié : UID 33\n'
docker compose exec -T dashboard php -r '
$path="/var/lib/dashboard/ci-persistence";
if (file_put_contents($path, "ok") !== 2) { fwrite(STDERR,"ERREUR écriture du volume persistant\n"); exit(1); }
echo "OK écriture du volume persistant\n";'
docker compose up -d --force-recreate --wait --wait-timeout 120
docker compose exec -T dashboard php -r '
$path="/var/lib/dashboard/ci-persistence";
if (file_get_contents($path) !== "ok") { fwrite(STDERR,"ERREUR données perdues après recréation\n"); exit(1); }
unlink($path); echo "OK persistance après recréation\n";'
printf 'Tous les contrôles Docker ont réussi.\n'
