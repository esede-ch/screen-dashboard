<?php
declare(strict_types=1);

final class WebContext {
    public static function publicOrigin(): ?string {
        $value = getenv('DASHBOARD_PUBLIC_URL');
        if ($value === false || $value === '') return null;
        $url = parse_url($value);
        if (!$url || !in_array($url['scheme'] ?? '', ['http','https'], true) || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || !in_array($url['path'] ?? '', ['', '/'], true) || preg_match('/[\s\x00-\x1f\x7f]/', $value)) {
            throw new RuntimeException('DASHBOARD_PUBLIC_URL doit être une URL HTTP(S) sans chemin.');
        }
        $port = $url['port'] ?? null;
        if ($port === 0) throw new RuntimeException('Port public invalide.');
        $default = $url['scheme'] === 'https' ? 443 : 80;
        return $url['scheme'].'://'.strtolower($url['host']).($port !== null && $port !== $default ? ':'.$port : '');
    }

    public static function origin(array $server): string {
        $public = self::publicOrigin();
        $host = strtolower((string)($server['HTTP_HOST'] ?? ''));
        if ($public !== null) {
            $expected = substr($public, strpos($public, '://') + 3);
            $defaultPort = str_starts_with($public, 'https://') ? ':443' : ':80';
            $withDefaultPort = parse_url($public, PHP_URL_PORT) === null && $host === $expected.$defaultPort;
            if ($host !== $expected && !$withDefaultPort) throw new RuntimeException('Adresse du tableau de bord refusée.', 403);
            return $public;
        }
        if (!preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(?::[0-9]+)?$/D', $host)) {
            throw new RuntimeException('Configurer DASHBOARD_PUBLIC_URL pour un accès depuis le serveur.', 403);
        }
        return (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off' ? 'https://' : 'http://').$host;
    }
}
