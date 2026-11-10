<?php
declare(strict_types=1);

require_once __DIR__ . '/OrangeBar.php';

/** Shared by dashboard requests and the background capture monitor. */
final class SharedCache {
    private const TTL = 15;
    private static function identity(array $box): string {
        return hash('sha256', $box['address'] . "\0" . $box['key']);
    }
    private static function path(string $kind, string $id): string {
        return dashboard_dir() . '/shared-' . $kind . '-' . $id . '.json';
    }
    private static function lock(string $name) {
        $lock = fopen(dashboard_dir() . '/shared-' . $name . '.lock', 'c');
        if (!$lock) throw new RuntimeException('Impossible d’ouvrir le cache partagé.');
        if (!flock($lock, LOCK_EX)) { fclose($lock); throw new RuntimeException('Le cache partagé est occupé.'); }
        return $lock;
    }
    private static function read(string $kind, array $box, int $maxAge = self::TTL): ?array {
        $path = self::path($kind, $box['id']);
        if (!is_file($path)) return null;
        $entry = json_decode((string)file_get_contents($path), true);
        if (!is_array($entry) || ($entry['identity'] ?? '') !== self::identity($box)
            || !is_int($entry['fetched_at'] ?? null) || !is_array($entry['value'] ?? null)
            || $entry['fetched_at'] > time() || time() - $entry['fetched_at'] >= $maxAge) return null;
        return $entry;
    }
    private static function write(string $kind, array $box, array $value): array {
        $entry = ['identity' => self::identity($box), 'fetched_at' => time(), 'value' => $value];
        $temp = tempnam(dashboard_dir(), '.shared-');
        if ($temp === false) throw new RuntimeException('Impossible d’enregistrer le cache partagé.');
        try {
            chmod($temp, 0600);
            if (file_put_contents($temp, json_encode($entry, JSON_THROW_ON_ERROR)) === false
                || !rename($temp, self::path($kind, $box['id']))) throw new RuntimeException('Impossible d’enregistrer le cache partagé.');
        } finally { if (is_file($temp)) unlink($temp); }
        return $entry;
    }
    public static function states(array $boxes): array {
        // Lock + second read coalesces simultaneous misses across PHP processes.
        // Fetch missing boxes in one parallel batch instead of serial requests.
        $lock = self::lock('states');
        try {
            $entries = []; $missing = []; $byId = [];
            foreach ($boxes as $box) {
                $byId[$box['id']] = $box;
                $entry = self::read('state', $box);
                if ($entry) $entries[$box['id']] = $entry;
                else $missing[] = $box;
            }
            if ($missing) foreach (BoxClient::states($missing) as $row) {
                // Only the sanitized observation is cached, not names, order or credentials.
                $value = array_intersect_key($row, array_flip(['online','state','error','error_kind','checked_at']));
                $entries[$row['id']] = self::write('state', $byId[$row['id']], $value);
                if (empty($value['online']) || empty($value['state']['capture']['available'])) {
                    self::forgetImage($row['id']);
                }
            }
            $rows = [];
            foreach ($boxes as $box) {
                $entry = $entries[$box['id']]; $value = $entry['value'];
                $elapsed = max(0, time() - $entry['fetched_at']);
                if (isset($value['state']['tv_age_seconds'])) $value['state']['tv_age_seconds'] += $elapsed;
                if (isset($value['state']['capture']['age_seconds'])) $value['state']['capture']['age_seconds'] += $elapsed;
                $rows[] = array_merge(Store::safe($box), $value);
            }
            return $rows;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public static function image(array $box): array {
        $lock = self::lock('image-' . $box['id']);
        try {
            $entry = self::read('image', $box);
            if (!$entry || !array_key_exists('bar_present', $entry['value'])) {
                $value = ['bar_present' => null];
                try {
                    $state = self::read('state', $box);
                    $capture = $state['value']['state']['capture'] ?? [];
                    $response = BoxClient::screenshot($box);
                    if (!$response['errno'] && !$response['oversized'] && $response['code'] === 200
                        && str_starts_with($response['body'], "\xff\xd8") && str_ends_with($response['body'], "\xff\xd9")) {
                        $value = [
                            'jpeg' => base64_encode($response['body']),
                            'bar_present' => OrangeBar::present($response['body']),
                            'captured_at' => isset($capture['age_seconds']) && !empty($capture['available'])
                                ? $state['fetched_at'] - $capture['age_seconds'] : null,
                        ];
                    }
                } catch (RuntimeException $error) { /* Cache failures without remote bodies or keys. */ }
                $entry = self::write('image', $box, $value);
            }
            $jpeg = isset($entry['value']['jpeg']) ? base64_decode($entry['value']['jpeg'], true) : false;
            if ($jpeg === false) throw new RuntimeException('Capture indisponible.', 502);
            $capturedAt = $entry['value']['captured_at'];
            $age = $capturedAt === null ? null : max(0, time() - $capturedAt);
            return [
                'jpeg' => $jpeg,
                'bar' => OrangeBar::status($entry['value']['bar_present'], $age),
                'fresh_for' => $age === null ? null : max(0, OrangeBar::FRESH_SECONDS + 1 - $age),
            ];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    private static function forgetImage(string $id): void {
        $lock = self::lock('image-' . $id);
        try {
            $path = self::path('image', $id);
            if (is_file($path)) unlink($path);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public static function invalidate(array $ids, bool $images = false): void {
        if (!$ids) return;
        // Using the reader's lock prevents an older in-flight observation from
        // repopulating the cache after a command has invalidated it.
        $lock = self::lock('states');
        try { foreach ($ids as $id) { $path = self::path('state', $id); if (is_file($path)) unlink($path); } }
        finally { flock($lock, LOCK_UN); fclose($lock); }
        if ($images) foreach ($ids as $id) self::forgetImage($id);
    }
}
