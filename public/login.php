<?php
declare(strict_types=1);
define('DASHBOARD_AUTH_PAGE', true);
require dirname(__DIR__) . '/src/bootstrap.php';
$error = '';
$username = '';
$ready = true;
try { $config = Auth::config(); if (!extension_loaded('ldap')) throw new RuntimeException(); }
catch (Throwable $e) { $ready = false; $error = 'La connexion LDAP n’est pas encore configurée. Contacter l’administration.'; http_response_code(503); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $token = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
    if (!hash_equals($csrf, $token)) { $error = 'La page a expiré. Réessayer la connexion.'; http_response_code(403); }
    elseif ($ready) {
        try {
            if ($username === '' || strlen($username) > 128 || $password === '' || strlen($password) > 4096) {
                $error = 'Identifiant et mot de passe requis.'; http_response_code(400);
            } elseif (!Auth::reserveAttempt($username, (string)($_SERVER['REMOTE_ADDR'] ?? ''))) {
                $error = 'Trop de tentatives. Réessayer dans 15 minutes.'; http_response_code(429); header('Retry-After: 900');
            } else {
                $user = (new LdapDirectory($config))->authenticate($username, $password);
                if ($user === null) { $error = 'Identifiants incorrects ou accès non autorisé.'; http_response_code(401); }
                else {
                    session_start(); session_regenerate_id(true);
                    $_SESSION = ['auth'=>Auth::identity($user, $config, time()), 'csrf'=>bin2hex(random_bytes(32))];
                    session_write_close();
                    header('Location: /', true, 303); exit;
                }
            }
        } catch (Throwable $e) { $error = 'L’annuaire est momentanément indisponible. Réessayer plus tard.'; http_response_code(503); }
    }
    unset($password, $_POST['password']);
}
function login_escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Connexion — Gestion des écrans d’affichage</title>
  <link rel="stylesheet" href="/login.css">
</head>
<body>
  <main class="login-layout">
    <section class="photo-panel" aria-label="Bâtiment de l’esede">
      <img src="/assets/esede-photo.jpg" alt="Bâtiment de l’esede entouré de feuillages d’automne" class="building" fetchpriority="high">
      <div class="photo-shade" aria-hidden="true"></div>
      <p class="photo-caption">Gestion des écrans d’affichage.</p>
    </section>
    <section class="connection-panel" aria-labelledby="login-title">
      <header class="brand-header">
        <a href="https://www.esede.ch/" aria-label="Site de l’esede"><img src="/assets/esede-logo.png" alt="esede — école supérieure en éducation de l’enfance" width="700" height="292"></a>
      </header>
      <div class="connection-content">
        <h1 id="login-title">Connexion</h1>
        <p class="intro">Connexion avec un compte esede.</p>
        <?php if ($error !== ''): ?>
          <p id="login-error" class="login-error" role="alert"><?= login_escape($error) ?></p>
        <?php endif ?>
        <form class="login-form" method="post" action="/login.php" aria-labelledby="login-title" <?= $error !== ''?'aria-describedby="login-error"':'' ?>>
          <input type="hidden" name="csrf" value="<?= login_escape($csrf) ?>">
          <div class="field">
            <label for="username">Nom d’utilisateur</label>
            <input id="username" name="username" type="text" placeholder="Identifiant esede" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="128" value="<?= login_escape($username) ?>" required <?= !$ready?'disabled':'' ?>>
          </div>
          <div class="field">
            <label for="password">Mot de passe</label>
            <input id="password" name="password" type="password" placeholder="Mot de passe esede" autocomplete="current-password" maxlength="4096" required <?= !$ready?'disabled':'' ?>>
          </div>
          <button type="submit" <?= !$ready?'disabled':'' ?>>Se connecter</button>
        </form>
      </div>
      <footer class="connection-footer">
        <p>esede · École supérieure en éducation de l’enfance</p>
      </footer>
    </section>
  </main>
</body>
</html>
