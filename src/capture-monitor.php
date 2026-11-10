<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/bootstrap.php';
umask(0077);

$dir = dashboard_dir();
$report = $dir . '/capture-monitor.json';
$lockFile = $dir . '/capture-monitor.lock';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "La surveillance des captures est déjà active ou indisponible.\n");
    exit(1);
}
$store = new Store($dir);
$once = ($argv[1] ?? null) === '--once';
do {
    $results = [];
    $boxes = $store->all();
    foreach (array_chunk($boxes, 2) as $pair) {
        $states = SharedCache::states($pair);
        foreach ($pair as $index => $box) {
            $status = 'unavailable';
            if (!empty($states[$index]['online']) && !empty($states[$index]['state']['capture']['available'])) {
                try {
                    $status = SharedCache::image($box)['bar'];
                } catch (RuntimeException $e) {
                    if ($e->getCode() !== 502) throw $e;
                }
            }
            $results[] = ['id' => $box['id'], 'checked_at' => time(), 'status' => $status];
            touch($lockFile);
        }
    }
    $json = json_encode(['checked_at' => time(), 'screens' => $results], JSON_THROW_ON_ERROR);
    $tmp = tempnam($dir, '.capture-monitor-');
    if ($tmp === false) throw new RuntimeException('Impossible de préparer le bilan des captures.');
    try {
        if (file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, $report)) {
            throw new RuntimeException('Impossible de conserver le bilan des captures.');
        }
    } finally {
        if (is_file($tmp)) unlink($tmp);
    }
    touch($lockFile);
    if (!$once) sleep(15);
} while (!$once);
