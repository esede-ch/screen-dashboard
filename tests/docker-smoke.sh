#!/bin/sh
# CI only: HTTP inside the container, with disposable host directories.
# No published host port is required by the reverse-proxy deployment.
set -eu

check_http() {
    expected=$1
    path=$2
    host=$3
    if actual=$(docker compose exec -T dashboard php -r '
$c = curl_init("http://127.0.0.1:8080".$argv[1]);
curl_setopt_array($c, [
    CURLOPT_HTTPHEADER => ["Host: ".$argv[2]],
    CURLOPT_PROXY => "",
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_WRITEFUNCTION => static function ($handle, $body) { return strlen($body); },
]);
if (curl_exec($c) === false) {
    fwrite(STDERR, "Erreur HTTP interne : ".curl_error($c)."\n");
    exit(1);
}
echo curl_getinfo($c, CURLINFO_HTTP_CODE);
' -- "$path" "$host"); then
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

check_http 303 / localhost:8090
check_http 200 /login.php localhost:8090
check_http 401 '/api.php?action=status' localhost:8090
check_http 200 /assets/esede-logo.png localhost:8090
check_http 404 /data/boxes.json localhost:8090
check_http 403 /login.php refused.example
actual_uid=$(docker compose exec -T dashboard id -u)
if [ "$actual_uid" != 33 ]; then
    printf 'ERREUR utilisateur : UID attendu 33, reçu %s\n' "$actual_uid" >&2
    exit 1
fi
printf 'OK utilisateur non privilégié : UID 33\n'
docker compose exec -T dashboard php -r '
if (trim(file_get_contents("/config/ci-volume-check")) !== "config-volume-ok") {
    fwrite(STDERR,"ERREUR montage du dossier de configuration hôte\n"); exit(1);
}
echo "OK configuration lue depuis le dossier hôte\n";'
docker compose exec -T dashboard php -r '
$path="/var/lib/dashboard/ci-persistence";
if (file_put_contents($path, "ok") !== 2) { fwrite(STDERR,"ERREUR écriture du volume persistant\n"); exit(1); }
echo "OK écriture du volume persistant\n";'
if ! sudo test -f /home/esede-admin/Docker-volumes/screen/data/ci-persistence; then
    printf 'ERREUR : données absentes du dossier persistant hôte\n' >&2
    exit 1
fi
printf 'OK données présentes dans le dossier hôte\n'
docker compose up -d --force-recreate --wait --wait-timeout 120
docker compose exec -T dashboard php -r '
$path="/var/lib/dashboard/ci-persistence";
if (file_get_contents($path) !== "ok") { fwrite(STDERR,"ERREUR données perdues après recréation\n"); exit(1); }
unlink($path); echo "OK persistance après recréation\n";'

# The monitoring process belongs to the web container and recovers independently.
docker compose exec -T dashboard php <<'PHP'
<?php
function monitorPid(): ?int {
    foreach (glob('/proc/[0-9]*/cmdline') as $file) {
        $args = explode("\0", (string)@file_get_contents($file));
        if (basename($args[0]) === 'php' && ($args[1] ?? '') === '/var/www/dashboard/src/capture-monitor.php') {
            return (int)basename(dirname($file));
        }
    }
    return null;
}
$old = monitorPid();
if (!$old || !posix_kill($old, 15)) exit(1);
for ($i = 0; $i < 60; ++$i) {
    usleep(250000);
    $new = monitorPid();
    if ($new && $new !== $old && is_file('/var/lib/dashboard/capture-monitor.json')) {
        echo "OK surveillance relancée dans le conteneur web\n";
        exit(0);
    }
}
exit(1);
PHP
check_http 200 /login.php localhost:8090
printf 'Tous les contrôles Docker ont réussi.\n'
