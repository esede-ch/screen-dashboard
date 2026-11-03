<?php
declare(strict_types=1);

final class BoxClient {
    private static function handle(array $box, string $route, string $method, ?array $body, int $timeout, object $result): CurlHandle {
        $ch = curl_init($box['address'] . $route);
        $result->body = ''; $result->oversized = false; $result->errno = 0;
        $result->limit = ($route === '/admin/?action=screenshot' || str_starts_with($route, '/screenshot.php?')) ? 4 * 1024 * 1024 : 262144;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 1500, CURLOPT_TIMEOUT_MS => $timeout,
            CURLOPT_PROXY => '', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $box['key'], 'Accept: application/json, image/jpeg', 'Content-Type: application/json'],
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($result): int {
                if (strlen($result->body) + strlen($chunk) > $result->limit) { $result->oversized = true; return 0; }
                $result->body .= $chunk; return strlen($chunk);
            }]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        return $ch;
    }
    private static function result(CurlHandle $ch, object $result): array {
        return ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'type' => curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
            'errno' => $result->errno ?: curl_errno($ch), 'body' => $result->body, 'oversized' => $result->oversized];
    }
    public static function request(array $box, string $route, string $method = 'GET', ?array $body = null, int $timeout = 4000): array {
        $result = new stdClass();
        $ch = self::handle($box, $route, $method, $body, $timeout, $result);
        curl_exec($ch);
        return self::result($ch, $result);
    }
    public static function parse(array $response): array {
        if ($response['oversized']) throw new RuntimeException('La box a renvoyé une réponse trop volumineuse.', 502);
        if ($response['errno']) throw new RuntimeException('La box ne répond pas. Vérifier son adresse et sa connexion.', 504);
        if (in_array($response['code'], [401, 403])) throw new RuntimeException('La clé API est refusée. Mettre à jour la clé dans les informations de la box.', 401);
        if ($response['code'] === 409) throw new RuntimeException('Les réglages ont changé sur la box. Les actualiser avant d’enregistrer.', 409);
        if ($response['code'] < 200 || $response['code'] >= 300) throw new RuntimeException('La box a renvoyé une erreur (HTTP ' . $response['code'] . ').', 502);
        $data = json_decode($response['body'], true, 32);
        if (!is_array($data) || ($data['ok'] ?? false) !== true) throw new RuntimeException('La box a renvoyé une réponse inattendue. Vérifier son API.', 502);
        return $data;
    }
    private static function short(mixed $value, int $max = 120): ?string {
        return is_string($value) ? (function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max)) : null;
    }
    public static function state(array $data): array {
        $cec = is_array($data['cec'] ?? null) ? $data['cec'] : [];
        $config = is_array($cec['config'] ?? null) ? $cec['config'] : [];
        $tv = is_array($cec['television'] ?? null) ? $cec['television'] : [];
        $capture = is_array($data['capture'] ?? null) ? $data['capture'] : [];
        $settings = [];
        foreach (['MODE','ON_TIME','OFF_TIME','WEEKEND_ENABLED','HDMI_LOCK_ENABLED'] as $key) $settings[$key] = self::short($config[$key] ?? null, 20);
        return ['hostname' => self::short($data['hostname'] ?? null), 'boot_id' => self::short($data['boot_id'] ?? null),
            'php_version' => self::short($data['runtime']['php_version'] ?? null, 30),
            'tv_status' => in_array($cec['tv_status'] ?? '', ['on','off','to_on','to_off'], true) ? $cec['tv_status'] : 'unknown',
            'tv_checked_at' => self::short($cec['checked_at'] ?? null),
            'tv_age_seconds' => is_numeric($cec['age_seconds'] ?? null) ? max(0, (int)$cec['age_seconds']) : null,
            'brand' => self::short($tv['brand'] ?? null), 'tv_name' => self::short($tv['name'] ?? null),
            'config' => $settings, 'revision' => self::short($cec['revision'] ?? null), 'timezone' => self::short($cec['timezone'] ?? null),
            'capture' => ['available' => ($capture['available'] ?? false) === true, 'updated_at' => self::short($capture['updated_at'] ?? null),
                'age_seconds' => is_numeric($capture['age_seconds'] ?? null) ? max(0, (int)$capture['age_seconds']) : null]];
    }
    public static function batch(array $jobs, int $timeout = 4500): array {
        $multi = curl_multi_init(); $requests = []; $results = [];
        curl_multi_setopt($multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, 50);
        foreach ($jobs as $id => $job) {
            [$box, $route] = $job;
            $result = new stdClass();
            $ch = self::handle($box, $route, $job[2] ?? 'GET', $job[3] ?? null, $timeout, $result);
            $requests[] = [$id, $ch, $result]; $results[spl_object_id($ch)] = $result; curl_multi_add_handle($multi, $ch);
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running && $status === CURLM_OK) { if (curl_multi_select($multi, .2) === -1) usleep(10000); }
        } while ($running && $status === CURLM_OK);
        // Multi-transfer errors are reported by info_read, not reliably by curl_errno.
        while ($message = curl_multi_info_read($multi)) {
            $results[spl_object_id($message['handle'])]->errno = $message['result'];
        }
        if ($status !== CURLM_OK) foreach ($results as $result) $result->errno = CURLE_FAILED_INIT;
        $responses = [];
        foreach ($requests as [$id, $ch, $result]) {
            $responses[$id] = self::result($ch, $result);
            curl_multi_remove_handle($multi, $ch);
        }
        return $responses;
    }
    private static function deviceId(array $data): string {
        $id = $data['hostname'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9._-]{1,128}$/D', $id)) throw new RuntimeException('Le nom de capture communiqué par la box est invalide.', 502);
        return $id;
    }
    private static function legacyRoute(array $box, string $file, string $device): string {
        // These older endpoints require a query token. It stays on the server.
        // Never use image_url returned by the box: it may point elsewhere or expose the key.
        return '/' . $file . '?' . http_build_query(['device_id' => $device, 'token' => $box['key']], '', '&', PHP_QUERY_RFC3986);
    }
    private static function legacyRevision(array $config): string {
        ksort($config);
        return hash('sha256', json_encode($config, JSON_THROW_ON_ERROR));
    }
    private static function cecCachePath(array $box): string {
        return dashboard_dir() . '/cec-' . hash('sha256', $box['address'] . "\0" . $box['key']) . '.json';
    }
    private static function legacyState(array $box, array $data, array $info): array {
        $state = self::state([
    'hostname' => self::deviceId($data),
    'cec' => [
        'config' => $data['config'] ?? [],
        'television' => $data['television'] ?? null,
    ],
]);
        $state['api_generation'] = 'legacy';
        $state['revision'] = self::legacyRevision($data['config']);
        // Legacy tv_status is the last sent command, not a response from the TV.
        // Only a successful explicit cec_refresh is an observed TV state.
        $path = self::cecCachePath($box);
        $observation = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        if (is_array($observation) && is_int($observation['checked'] ?? null)
            && in_array($observation['status'] ?? '', ['on','off','to_on','to_off','unknown'], true)) {
            $state['tv_status'] = $observation['status'];
            $state['tv_checked_at'] = gmdate('c', $observation['checked']);
            $state['tv_age_seconds'] = max(0, time() - $observation['checked']);
        }
        $metadata = $info['code'] === 200 && !$info['errno'] && !$info['oversized'] ? json_decode($info['body'], true) : null;
        $timestamp = is_array($metadata) ? ($metadata['timestamp'] ?? null) : null;
        $date = is_string($timestamp) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $timestamp, new DateTimeZone('UTC')) : false;
        if ($date && $date->format('Y-m-d H:i:s') !== $timestamp) $date = false;
        $boxTime = is_string($data['time'] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $data['time'], new DateTimeZone('UTC')) : false;
        $state['capture'] = ['available' => $info['code'] !== 404,
            'updated_at' => $date ? str_replace(' ', 'T', $timestamp) : null,
            'age_seconds' => $date && $boxTime ? max(0, $boxTime->getTimestamp() - $date->getTimestamp()) : null];
        return $state;
    }
    public static function commandContext(array $box): array {
        $response = self::request($box, '/admin/?action=state');
        $legacy = $response['code'] === 404 && !$response['errno'];
        $data = self::parse($legacy ? self::request($box, '/?action=status') : $response);
        if ($legacy && !is_array($data['config'] ?? null)) throw new RuntimeException('Format de configuration inconnu.', 502);
        return ['legacy' => $legacy, 'config' => $legacy ? $data['config'] : ($data['cec']['config'] ?? []),
            'revision' => $legacy ? self::legacyRevision($data['config']) : ($data['cec']['revision'] ?? '')];
    }
    public static function legacySettings(array $box, array $context, array $data): array {
        if (!hash_equals($context['revision'], $data['revision'])) throw new RuntimeException('Les réglages ont changé sur la box. Les actualiser avant d’enregistrer.', 409);
        if ($data['ON_TIME'] > $data['OFF_TIME']) throw new InvalidArgumentException('Cette API nécessite une heure d’extinction après l’heure d’allumage.');
        // Partial config update preserves CEC_DEVICE and CEC_PHYS_ADDR on the box.
        $settings = array_intersect_key($data, array_flip(['MODE','ON_TIME','OFF_TIME','WEEKEND_ENABLED']));
        $saved = self::parse(self::request($box, '/?action=config', 'POST', $settings));
        foreach ($settings as $key => $value) if (($saved['config'][$key] ?? null) !== $value)
            throw new RuntimeException('La box n’a pas confirmé l’enregistrement des réglages. Vérifier son état avant de réessayer.', 502);
        // tv_auto evaluates the saved MODE, including force_on and force_off.
        try {
            $reply = self::parse(self::request($box, '/?action=tv_auto', 'POST', [], 55000));
            $applied = ($reply['result']['return_code'] ?? null) === 0;
        } catch (RuntimeException $error) { $applied = false; }
        return ['ok' => true, 'saved' => true, 'applied' => $applied,
            'message' => $applied ? 'Réglages enregistrés et commande envoyée.' : 'Réglages enregistrés, mais l’application immédiate n’a pas été confirmée. Vérifier l’état CEC avant de réessayer.'];
    }
    public static function legacyCommand(array $box, string $command): array {
        if ($command === 'restart-display') throw new InvalidArgumentException('Cette API ne propose pas de redémarrage de l’affichage.');
        $refresh = $command === 'cec-refresh';
        $reply = self::parse(self::request($box, '/?action=' . ($refresh ? 'cec_refresh' : 'reboot'), $refresh ? 'GET' : 'POST', $refresh ? null : [], 55000));
        if (($reply['result']['return_code'] ?? null) !== 0) throw new RuntimeException('La box n’a pas confirmé la commande. Vérifier son état avant de réessayer.', 502);
        if ($refresh) return self::recordLegacyCec($box, $reply);
        return ['ok' => true, 'accepted' => true, 'message' => 'Redémarrage demandé. Cette API ne permet pas de confirmer un nouveau démarrage.'];
    }
    public static function recordLegacyCec(array $box, array $reply): array {
            if (($reply['result']['return_code'] ?? null) !== 0) throw new RuntimeException('La box n’a pas confirmé la lecture CEC.', 502);
            $status = $reply['tv_status'] ?? null;
            if (!in_array($status, ['on','off','to_on','to_off','unknown'], true)) throw new RuntimeException('La réponse CEC de la box n’est pas reconnue.', 502);
            $path = self::cecCachePath($box);
            $temp = tempnam(dashboard_dir(), 'cec-tmp-');
            if ($temp === false) throw new RuntimeException('Impossible de conserver la réponse CEC.');
            try {
                chmod($temp, 0600);
                if (file_put_contents($temp, json_encode(['status' => $status, 'checked' => time()], JSON_THROW_ON_ERROR)) === false || !rename($temp, $path))
                    throw new RuntimeException('Impossible de conserver la réponse CEC.');
            } finally { if (is_file($temp)) unlink($temp); }
            return ['ok' => true, 'message' => $status === 'unknown' ? 'Vérification CEC terminée : état non communiqué par la TV.' : 'État CEC actualisé.'];
    }
    public static function screenshot(array $box): array {
        $response = self::request($box, '/admin/?action=screenshot', timeout: 4500);
        if ($response['code'] !== 404 || $response['errno']) return $response;
        $data = self::parse(self::request($box, '/?action=status', timeout: 2500));
        return self::request($box, self::legacyRoute($box, 'screenshot.php', self::deviceId($data)), timeout: 4500);
    }
    public static function states(array $boxes): array {
        $jobs = []; $byId = [];
        foreach ($boxes as $box) { $byId[$box['id']] = $box; $jobs[$box['id']] = [$box, '/admin/?action=state']; }
        $responses = self::batch($jobs);
        $legacyJobs = [];
        foreach ($responses as $id => $response) if ($response['code'] === 404 && !$response['errno']) $legacyJobs[$id] = [$byId[$id], '/?action=status'];
        $legacyResponses = self::batch($legacyJobs, 2500);
        $legacyData = []; $infoJobs = [];
        foreach ($legacyResponses as $id => $response) {
            try {
                $data = self::parse($response); $device = self::deviceId($data);
                if (!is_array($data['config'] ?? null)) throw new RuntimeException('Format de configuration inconnu.', 502);
                $legacyData[$id] = $data;
                $infoJobs[$id] = [$byId[$id], self::legacyRoute($byId[$id], 'screenshot-info.php', $device)];
            } catch (RuntimeException $error) { $legacyData[$id] = $error; }
        }
        $infoResponses = self::batch($infoJobs, 2500);
        $rows = [];
        foreach ($boxes as $box) {
            $row = Store::safe($box); $id = $box['id'];
            try {
                if (isset($legacyData[$id])) {
                    if ($legacyData[$id] instanceof RuntimeException) throw $legacyData[$id];
                    $row['state'] = self::legacyState($box, $legacyData[$id], $infoResponses[$id]);
                } else {
                    $data = self::parse($responses[$id]);
                    if (!isset($data['cec'], $data['capture'])) throw new RuntimeException('Format d’administration non reconnu.', 502);
                    $row['state'] = self::state($data);
                }
                $row['online'] = true;
            } catch (RuntimeException $error) {
                $row['online'] = false; $row['error'] = $error->getMessage();
                $row['error_kind'] = $error->getCode() === 401 ? 'key' : ($error->getCode() === 504 ? 'offline' : 'api');
            }
            $row['checked_at'] = time(); $rows[] = $row;
        }
        return $rows;
    }
}
