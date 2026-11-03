<?php
declare(strict_types=1);

/** A new URL whenever the deployed file changes, including timestamp-preserving copies. */
function dashboard_asset(string $name): string {
    if (!in_array($name, ['app.js', 'app.css', 'auth.css', 'login.css'], true)) {
        throw new InvalidArgumentException('Fichier statique inconnu.');
    }
    $hash = hash_file('sha256', dirname(__DIR__) . '/public/' . $name);
    if ($hash === false) throw new RuntimeException('Fichier statique indisponible.');
    return '/' . $name . '?v=' . substr($hash, 0, 16);
}
