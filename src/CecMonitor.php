<?php
declare(strict_types=1);

/** One page-triggered polling cycle; shares locks with manual commands. */
final class CecMonitor {
    public static function tick(array $boxes, ?int $now = null): array {
        $now ??= time();
        $locks = []; $jobs = []; $selected = []; $results = [];
        try {
            foreach ($boxes as $box) {
                $id = $box['id'];
                $lock = fopen(dashboard_dir() . '/command-' . $id . '.lock', 'c');
                if (!$lock) continue;
                if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); continue; }
                $path = dashboard_dir() . '/cec-auto-' . $id . '.json';
                $previous = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
                $identity = hash('sha256', $box['address'] . "\0" . $box['key']);
                if (($previous['identity'] ?? '') === $identity && ($previous['attempted_at'] ?? 0) > $now - 60) {
                    flock($lock, LOCK_UN); fclose($lock); continue;
                }
                $locks[$id] = $lock; $selected[$id] = $box;
                // Record attempts as well as successes to avoid hammering an offline box.
                self::record($path, ['identity' => $identity, 'attempted_at' => $now, 'status' => 'pending']);
                $jobs[$id] = [$box, '/admin/?action=state'];
            }
            $states = BoxClient::batch($jobs, 2500);
            $legacyJobs = []; $refreshJobs = []; $legacy = [];
            foreach ($states as $id => $response) {
                if ($response['code'] === 404 && !$response['errno']) {
                    $legacyJobs[$id] = [$selected[$id], '/?action=status'];
                    continue;
                }
                try {
                    $state = BoxClient::parse($response);
                    if (!isset($state['cec'], $state['capture'])) throw new RuntimeException('Format d’API non reconnu.');
                    $refreshJobs[$id] = [$selected[$id], '/admin/?action=cec-refresh', 'POST', []];
                } catch (RuntimeException $error) { $results[$id] = 'error'; }
            }
            foreach (BoxClient::batch($legacyJobs, 2500) as $id => $response) {
                try {
                    $state = BoxClient::parse($response);
                    if (!is_array($state['config'] ?? null)) throw new RuntimeException('Format d’API non reconnu.');
                    $legacy[$id] = true;
                    $refreshJobs[$id] = [$selected[$id], '/?action=cec_refresh'];
                } catch (RuntimeException $error) { $results[$id] = 'error'; }
            }
            // Separate boxes run in parallel; a slow TV does not delay starting the others.
            foreach (BoxClient::batch($refreshJobs, 55000) as $id => $response) {
                try {
                    $reply = BoxClient::parse($response);
                    if (isset($legacy[$id])) BoxClient::recordLegacyCec($selected[$id], $reply);
                    $results[$id] = ($reply['refresh_busy'] ?? false) ? 'busy' : 'ok';
                } catch (RuntimeException $error) { $results[$id] = 'error'; }
            }
            foreach ($selected as $id => $box) self::record(dashboard_dir() . '/cec-auto-' . $id . '.json', [
                'identity' => hash('sha256', $box['address'] . "\0" . $box['key']),
                'attempted_at' => $now, 'finished_at' => time(), 'status' => $results[$id] ?? 'error',
            ]);
            SharedCache::invalidate(array_keys($selected));
            return $results;
        } finally {
            foreach ($locks as $lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }
    private static function record(string $path, array $value): void {
        $temp = tempnam(dashboard_dir(), '.cec-auto-');
        if ($temp === false) throw new RuntimeException('Impossible d’enregistrer le suivi CEC.');
        try {
            chmod($temp, 0600);
            if (file_put_contents($temp, json_encode($value, JSON_THROW_ON_ERROR)) === false || !rename($temp, $path))
                throw new RuntimeException('Impossible d’enregistrer le suivi CEC.');
        } finally { if (is_file($temp)) unlink($temp); }
    }
}
