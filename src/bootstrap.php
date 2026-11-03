<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80500 || !extension_loaded('curl')) {
    http_response_code(500);
    exit('PHP 8.5 et son extension cURL sont nécessaires.');
}
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/WebContext.php';
    try { $dashboardOrigin = WebContext::origin($_SERVER); }
    catch (Throwable $e) { http_response_code($e->getCode() === 403 ? 403 : 500); exit('Adresse du tableau de bord non autorisée ou mal configurée.'); }
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    session_name('screens_dashboard');
    session_start(['cookie_httponly' => true, 'cookie_secure' => str_starts_with($dashboardOrigin, 'https://'), 'cookie_samesite' => 'Strict', 'cookie_path'=>'/', 'use_strict_mode' => true, 'use_only_cookies'=>true]);
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $csrf = $_SESSION['csrf'];
    session_write_close(); // Remote commands must not block other requests in this session.
}
require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/BoxClient.php';
require_once __DIR__ . '/SharedCache.php';

function dashboard_dir(): string {
    $path = getenv('DASHBOARD_DATA_DIR') ?: dirname(__DIR__) . '/data';
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('Impossible de créer le stockage local.');
    chmod($path, 0700);
    return $path;
}
function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    exit;
}
function json_body(): array {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) throw new InvalidArgumentException('La demande est trop volumineuse.');
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if (strlen($raw) > 16384) throw new InvalidArgumentException('La demande est trop volumineuse.');
    try { $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new InvalidArgumentException('La demande est invalide.'); }
    if (!is_array($body) || array_is_list($body) && $body !== []) throw new InvalidArgumentException('La demande est invalide.');
    return $body;
}

require_once __DIR__ . '/Auth.php';
if (PHP_SAPI !== 'cli' && !defined('DASHBOARD_AUTH_PAGE')) Auth::requireAccess();
