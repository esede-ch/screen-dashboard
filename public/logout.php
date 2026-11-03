<?php
declare(strict_types=1);
define('DASHBOARD_AUTH_PAGE', true);
require dirname(__DIR__) . '/src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); http_response_code(405); exit; }
if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) { http_response_code(403); exit('Demande de déconnexion refusée.'); }
session_start();
$_SESSION = [];
session_destroy();
$p = session_get_cookie_params();
setcookie(session_name(), '', ['expires'=>time()-3600, 'path'=>$p['path'], 'secure'=>$p['secure'], 'httponly'=>true, 'samesite'=>'Strict']);
header('Location: /login.php', true, 303);
