<?php
declare(strict_types=1);
require_once __DIR__ . '/LdapDirectory.php';

final class Auth {
    public const RECHECK_SECONDS = 60;
    public const SESSION_SECONDS = 28800;

    public static function config(): array {
        if (getenv('DASHBOARD_LDAP_URI') !== false) {
            $verify = getenv('DASHBOARD_LDAP_TLS_VERIFY');
            if ($verify !== false && !in_array($verify, ['true','false'], true)) throw new RuntimeException('DASHBOARD_LDAP_TLS_VERIFY doit valoir true ou false.');
            $c = [
                'uri'=>(string)getenv('DASHBOARD_LDAP_URI'),
                'base_dn'=>(string)getenv('DASHBOARD_LDAP_BASE_DN'),
                'group_dn'=>(string)getenv('DASHBOARD_LDAP_GROUP_DN'),
                'login_attribute'=>getenv('DASHBOARD_LDAP_LOGIN_ATTRIBUTE') ?: 'uid',
                'user_filter'=>getenv('DASHBOARD_LDAP_USER_FILTER') ?: '(objectClass=person)',
                'tls_verify'=>$verify !== 'false',
                'ca_file'=>getenv('DASHBOARD_LDAP_CA_FILE') ?: '',
                'bind_dn'=>getenv('DASHBOARD_LDAP_BIND_DN') ?: '',
                'bind_password'=>'',
            ];
            $secret = getenv('DASHBOARD_LDAP_BIND_PASSWORD_FILE');
            if ($secret !== false && $secret !== '') {
                if (!is_readable($secret)) throw new RuntimeException('Fichier du compte de lecture LDAP inaccessible.');
                $c['bind_password'] = rtrim(file_get_contents($secret), "\r\n");
            }
        } else {
            $path = dashboard_dir() . '/ldap.json';
            if (!is_file($path)) throw new RuntimeException('Configuration LDAP absente.');
            $raw = file_get_contents($path);
            $c = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        }
        if (!is_array($c)) throw new RuntimeException('Configuration LDAP invalide.');
        $c += ['bind_dn'=>'', 'bind_password'=>'', 'login_attribute'=>'uid', 'user_filter'=>'(objectClass=person)', 'tls_verify'=>true, 'ca_file'=>''];
        foreach (['uri','base_dn','group_dn','bind_dn','bind_password','login_attribute','user_filter','ca_file'] as $key) {
            if (!is_string($c[$key] ?? null)) throw new RuntimeException('Configuration LDAP invalide.');
        }
        $url = parse_url($c['uri']);
        if (!$url || !in_array($url['scheme'] ?? '', ['ldap','ldaps'], true) || empty($url['host']) || isset($url['user']) || isset($url['query']) || isset($url['fragment']) || !in_array($url['path'] ?? '', ['', '/'], true)
            || trim($c['base_dn']) === '' || trim($c['group_dn']) === '' || !is_bool($c['tls_verify'])
            || !preg_match('/^[a-z][a-z0-9-]*$/D', $c['login_attribute'])
            || !str_starts_with($c['user_filter'], '(') || !str_ends_with($c['user_filter'], ')')
            || ($c['bind_dn'] !== '' && $c['bind_password'] === '')) throw new RuntimeException('Configuration LDAP invalide.');
        return $c;
    }

    public static function fingerprint(array $config): string { return hash('sha256', json_encode($config, JSON_THROW_ON_ERROR)); }

    public static function identity(array $user, array $config, int $now): array {
        return ['dn'=>$user['dn'], 'username'=>$user['username'], 'issued_at'=>$now, 'checked_at'=>$now, 'config'=>self::fingerprint($config)];
    }

    public static function validate(array &$session, array $config, DashboardDirectory $directory, int $now): bool {
        $auth = $session['auth'] ?? null;
        if (!is_array($auth) || !is_string($auth['dn'] ?? null) || !is_string($auth['username'] ?? null)
            || !is_int($auth['issued_at'] ?? null) || !is_int($auth['checked_at'] ?? null)
            || $auth['issued_at'] > $now || $auth['checked_at'] > $now || $now - $auth['issued_at'] >= self::SESSION_SECONDS
            || !hash_equals(self::fingerprint($config), (string)($auth['config'] ?? ''))) {
            unset($session['auth']); return false;
        }
        if ($now - $auth['checked_at'] >= self::RECHECK_SECONDS) {
            // Unset first: directory failures must revoke, rather than preserve, access.
            unset($session['auth']);
            if (!$directory->authorized($auth['dn'], $auth['username'])) return false;
            $auth['checked_at'] = $now;
            $session['auth'] = $auth;
        }
        return true;
    }

    public static function requireAccess(): void {
        session_start();
        try {
            $c = self::config();
            $valid = self::validate($_SESSION, $c, new LdapDirectory($c), time());
        } catch (Throwable $e) { unset($_SESSION['auth']); $valid = false; }
        session_write_close();
        if ($valid) return;
        if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'api.php') json_response(['error'=>'Connexion requise ou accès au groupe refusé.', 'code'=>'auth_required'], 401);
        header('Location: /login.php', true, 303); exit;
    }

    // Shared across PHP workers and browser sessions. Reserve before a bind so
    // concurrent attempts cannot all slip through the same remaining quota.
    public static function reserveAttempt(string $username, string $ip, ?int $now = null): bool {
        $now ??= time();
        $file = dashboard_dir() . '/login-attempts.json';
        $handle = fopen($file, 'c+');
        if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Limitation des connexions indisponible.');
        try {
            chmod($file, 0600);
            $raw = stream_get_contents($handle);
            $data = $raw === '' ? [] : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new RuntimeException('Limitation des connexions invalide.');
            foreach ($data as $key => $times) {
                $data[$key] = array_values(array_filter($times, fn($t) => is_int($t) && $t > $now - 900));
                if (!$data[$key]) unset($data[$key]);
            }
            $keys = [hash('sha256','user:'.mb_strtolower($username)) => 5, hash('sha256','ip:'.$ip) => 30];
            foreach ($keys as $key=>$limit) if (count($data[$key] ?? []) >= $limit) return false;
            foreach ($keys as $key=>$limit) $data[$key][]=$now;
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            rewind($handle); ftruncate($handle, 0);
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) throw new RuntimeException('Limitation des connexions indisponible.');
            return true;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
