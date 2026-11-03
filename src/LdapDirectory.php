<?php
declare(strict_types=1);

interface DashboardDirectory {
    public function authenticate(string $username, string $password): ?array;
    public function authorized(string $dn, string $username): bool;
}

final class LdapDirectory implements DashboardDirectory {
    public function __construct(private array $config) {}

    private function connect(): LDAP\Connection {
        if (!extension_loaded('ldap')) throw new RuntimeException('Extension LDAP absente.');
        $c = ldap_connect($this->config['uri']);
        if (!$c) throw new RuntimeException('Connexion LDAP impossible.');
        try {
            ldap_set_option($c, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($c, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($c, LDAP_OPT_NETWORK_TIMEOUT, 5);
            ldap_set_option($c, LDAP_OPT_TIMEOUT, 5);
            ldap_set_option($c, LDAP_OPT_X_TLS_REQUIRE_CERT, $this->config['tls_verify'] ? LDAP_OPT_X_TLS_DEMAND : LDAP_OPT_X_TLS_NEVER);
            if ($this->config['ca_file'] !== '') ldap_set_option($c, LDAP_OPT_X_TLS_CACERTFILE, $this->config['ca_file']);
            // ldap:// always upgrades before any credential or directory query is sent.
            if (str_starts_with($this->config['uri'], 'ldap://') && !@ldap_start_tls($c)) throw new RuntimeException('Connexion LDAP chiffrée impossible.');
            return $c;
        } catch (Throwable $e) { ldap_unbind($c); throw $e; }
    }

    private function reader(): LDAP\Connection {
        $c = $this->connect();
        $ok = $this->config['bind_dn'] === '' ? @ldap_bind($c) : @ldap_bind($c, $this->config['bind_dn'], $this->config['bind_password']);
        if (!$ok) { ldap_unbind($c); throw new RuntimeException('Lecture LDAP impossible.'); }
        return $c;
    }

    private function entries(LDAP\Connection $c, LDAP\Result|false $result): array {
        if (!$result) throw new RuntimeException('Lecture LDAP impossible.');
        // Never accept a partial result (time limit, referral, size limit…).
        if (!ldap_parse_result($c, $result, $code) || $code !== 0) throw new RuntimeException('Réponse LDAP incomplète.');
        $rows = ldap_get_entries($c, $result);
        if (!is_array($rows)) throw new RuntimeException('Réponse LDAP invalide.');
        return $rows;
    }

    public function authenticate(string $username, string $password): ?array {
        if ($username === '' || strlen($username) > 128 || $password === '' || strlen($password) > 4096 || str_contains($password, "\0") || preg_match('/[\x00-\x1f\x7f]/', $username)) return null;
        $c = $this->reader();
        try {
            $attr = $this->config['login_attribute'];
            $filter = '(&' . $this->config['user_filter'] . '(' . $attr . '=' . ldap_escape($username, '', LDAP_ESCAPE_FILTER) . '))';
            $rows = $this->entries($c, @ldap_search($c, $this->config['base_dn'], $filter, [$attr], 0, 2, 5));
            if ($rows['count'] !== 1 || empty($rows[0]['dn']) || empty($rows[0][$attr][0])) return null;
            $dn = $rows[0]['dn'];
            $canonical = (string)$rows[0][$attr][0];
            $user = $this->connect();
            try {
                if (!@ldap_bind($user, $dn, $password)) {
                    if (ldap_errno($user) !== 49) throw new RuntimeException('Authentification LDAP indisponible.');
                    return null;
                }
            } finally { ldap_unbind($user); }
            if (!$this->member($c, $dn, $canonical)) return null;
            return ['dn' => $dn, 'username' => $canonical];
        } finally { ldap_unbind($c); }
    }

    private function member(LDAP\Connection $c, string $dn, string $username): bool {
        $d = ldap_escape($dn, '', LDAP_ESCAPE_FILTER);
        $u = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
        // Direct membership, including Synology/OpenLDAP posixGroup (memberUid).
        $filter = '(|(member=' . $d . ')(uniqueMember=' . $d . ')(memberUid=' . $u . '))';
        $rows = $this->entries($c, @ldap_read($c, $this->config['group_dn'], $filter, ['1.1'], 0, 1, 5));
        return $rows['count'] === 1;
    }

    public function authorized(string $dn, string $username): bool {
        $c = $this->reader();
        try {
            $filter = '(&' . $this->config['user_filter'] . '(' . $this->config['login_attribute'] . '=' . ldap_escape($username, '', LDAP_ESCAPE_FILTER) . '))';
            $rows = $this->entries($c, @ldap_read($c, $dn, $filter, ['1.1'], 0, 1, 5));
            return $rows['count'] === 1 && $this->member($c, $dn, $username);
        } finally { ldap_unbind($c); }
    }

    public function checkConfiguration(): void {
        $c = $this->reader();
        try {
            $rows = $this->entries($c, @ldap_read($c, $this->config['group_dn'], '(objectClass=*)', ['1.1'], 0, 1, 5));
            if ($rows['count'] !== 1) throw new RuntimeException('Groupe autorisé introuvable.');
        } finally { ldap_unbind($c); }
    }
}
