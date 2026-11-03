<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/WebContext.php';
try {
    if (WebContext::publicOrigin() === null) throw new RuntimeException('DASHBOARD_PUBLIC_URL est requis.');
    Auth::config();
    if (!is_writable(dashboard_dir())) throw new RuntimeException('Volume de données non accessible en écriture.');
    foreach (['curl','ldap','mbstring','openssl'] as $extension) if (!extension_loaded($extension)) throw new RuntimeException('Extension PHP manquante.');
} catch (Throwable $e) {
    fwrite(STDERR, 'Configuration du dashboard invalide : vérifier URL publique, LDAP, fichiers de configuration et droits du volume.' . PHP_EOL);
    exit(1);
}
echo 'Configuration du dashboard valide.' . PHP_EOL;
