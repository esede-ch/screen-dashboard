<?php
declare(strict_types=1);

final class Store {
    public function __construct(private readonly string $dir) {}
    public static function address(string $input): string {
        $input = trim($input);
        if (strlen($input) > 300 || preg_match('/[\x00-\x20\x7f\\\\]/', $input)) throw new InvalidArgumentException('Une adresse IP ou DNS valide, sans espace, est requise.');
        if (!str_contains($input, '://')) $input = 'http://' . $input;
        $p = parse_url($input);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])
            || isset($p['user'], $p['pass']) || isset($p['user']) || isset($p['query']) || isset($p['fragment'])
            || !in_array($p['path'] ?? '', ['', '/'], true)) throw new InvalidArgumentException('Adresse IP ou nom DNS uniquement, avec un port éventuel.');
        $host = strtolower($p['host']);
        $plain = trim($host, '[]');
        if (!filter_var($plain, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) throw new InvalidArgumentException('Le nom DNS ou l’adresse IP n’est pas valide.');
        if (str_contains($plain, ':') && $host[0] !== '[') $host = '[' . $plain . ']';
        $port = $p['port'] ?? 8088;
        if ($port < 1 || $port > 65535) throw new InvalidArgumentException('Le port doit être compris entre 1 et 65535.');
        return strtolower($p['scheme']) . '://' . $host . ':' . $port;
    }
    private function read(): array {
        $path = $this->dir . '/boxes.json';
        if (!file_exists($path)) return [];
        $raw = file_get_contents($path);
        try { $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new RuntimeException('Le fichier des box est illisible. Il a été conservé.'); }
        if (!is_array($data) || !array_is_list($data)) throw new RuntimeException('Le fichier des box est invalide.');
        foreach ($data as $row) if (!is_array($row) || !isset($row['id'], $row['name'], $row['address'], $row['key'], $row['revision'])) throw new RuntimeException('Une entrée du fichier des box est invalide.');
        return $data;
    }
    public function all(): array { return $this->read(); }
    public function find(string $id): array {
        foreach ($this->read() as $row) if ($row['id'] === $id) return $row;
        throw new OutOfBoundsException('Cette box a été retirée du tableau de bord.', 404);
    }
    public static function safe(array $row): array {
        return array_intersect_key($row, array_flip(['id','name','address','revision','created_at','updated_at']));
    }
    private function mutate(callable $fn): mixed {
        $old = umask(0077);
        $lock = fopen($this->dir . '/boxes.lock', 'c');
        umask($old);
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Le stockage est occupé. Réessayer.');
        $tmp = null;
        try {
            $rows = $this->read();
            $result = $fn($rows);
            $tmp = tempnam($this->dir, '.boxes-');
            if (!$tmp || !chmod($tmp, 0600)) throw new RuntimeException('Impossible de protéger le fichier des box.');
            $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($tmp, $json . "\n") !== strlen($json) + 1 || !rename($tmp, $this->dir . '/boxes.json')) throw new RuntimeException('Impossible d’enregistrer les box.');
            return $result;
        } finally {
            if ($tmp && file_exists($tmp)) unlink($tmp);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
    public function save(array $input): array {
        return $this->mutate(function (&$rows) use ($input) {
            foreach (['name','address','key'] as $field) if (isset($input[$field]) && !is_string($input[$field])) throw new InvalidArgumentException('Un champ est invalide.');
            $name = trim($input['name'] ?? '');
            $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : preg_match_all('/./us', $name);
            if ($name === '' || $nameLength === false || $nameLength > 120 || preg_match('/[\x00-\x1f\x7f]/', $name)) throw new InvalidArgumentException('Un nom est requis pour cette box (120 caractères maximum).');
            $address = self::address($input['address'] ?? '');
            $id = $input['id'] ?? '';
            $index = null; $existing = null;
            foreach ($rows as $i => $row) {
                if ($row['id'] === $id) { $index = $i; $existing = $row; }
                elseif ($row['address'] === $address) throw new InvalidArgumentException('Cette adresse est déjà dans le tableau de bord.');
            }
            if ($id !== '' && !$existing) throw new OutOfBoundsException('Cette box n’existe plus.', 404);
            if ($existing && ($input['revision'] ?? '') !== $existing['revision']) throw new RuntimeException('Cette box a été modifiée dans un autre onglet. Actualiser la liste.', 409);
            $key = trim($input['key'] ?? '');
            if ($key === '' && $existing) $key = $existing['key'];
            if (!preg_match('/^[a-zA-Z0-9]{64}$/D', $key)) throw new InvalidArgumentException('La clé API doit contenir exactement 64 lettres ou chiffres.');
            if (!$existing && count($rows) >= 50) throw new InvalidArgumentException('Ce tableau de bord de test accepte jusqu’à 50 box.');
            $row = ['id' => $existing['id'] ?? bin2hex(random_bytes(8)), 'name' => $name, 'address' => $address, 'key' => $key,
                'revision' => bin2hex(random_bytes(12)), 'created_at' => $existing['created_at'] ?? time(), 'updated_at' => time()];
            if ($index === null) $rows[] = $row; else $rows[$index] = $row;
            return self::safe($row);
        });
    }
    public function reorder(array $order, array $expected): array {
        if (!array_is_list($order) || !array_is_list($expected)
            || count($order) > 50 || count($expected) > 50) throw new InvalidArgumentException('Ordre des box invalide.');
        foreach (array_merge($order, $expected) as $id) if (!is_string($id)) throw new InvalidArgumentException('Identifiant de box invalide.');
        return $this->mutate(function (&$rows) use ($order, $expected) {
            $current = array_column($rows, 'id');
            if ($expected !== $current) throw new RuntimeException('La liste ou son ordre a changé. Actualiser le tableau de bord avant de réessayer.', 409);
            $sorted = $order; $all = $current; sort($sorted); sort($all);
            if ($sorted !== $all) throw new InvalidArgumentException('Toutes les box doivent apparaître une seule fois.');
            $byId = array_column($rows, null, 'id');
            $rows = array_map(static fn($id) => $byId[$id], $order);
            return array_map([self::class, 'safe'], $rows);
        });
    }
    public function remove(string $id, string $revision): void {
        $this->mutate(function (&$rows) use ($id, $revision) {
            foreach ($rows as $i => $row) if ($row['id'] === $id) {
                if ($row['revision'] !== $revision) throw new RuntimeException('Cette box a changé. Actualiser la liste avant de la retirer.', 409);
                array_splice($rows, $i, 1); return;
            }
            throw new OutOfBoundsException('Cette box n’existe plus.', 404);
        });
    }
}
