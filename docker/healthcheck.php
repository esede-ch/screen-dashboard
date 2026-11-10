<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/WebContext.php';
try {
    $heartbeat = @filemtime(getenv('DASHBOARD_DATA_DIR') . '/capture-monitor.lock');
    if ($heartbeat === false || time() - $heartbeat >= 90) exit(1);
    $origin = WebContext::publicOrigin();
    if ($origin === null) exit(1);
    $c = curl_init('http://127.0.0.1:8080/login.php');
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5,
        CURLOPT_HTTPHEADER=>['Host: '.substr($origin, strpos($origin,'://')+3)], CURLOPT_PROXY=>'']);
    $body = curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
    exit($code === 200 && is_string($body) && str_contains($body, 'name="csrf"') ? 0 : 1);
} catch (Throwable $e) { exit(1); }
