<?php
require dirname(__DIR__) . '/src/bootstrap.php';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="dashboard-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
  <title>Écrans — Tableau de bord</title>
  <link rel="stylesheet" href="app.css">
  <link rel="stylesheet" href="auth.css">
  <script src="app.js" defer></script>
</head>
<body>
  <main>
    <header class="page-heading"><h1>Gestion des écrans d’affichage</h1><div class="account-controls"><span class="account-name"><?= htmlspecialchars($_SESSION['auth']['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><button type="submit">Se déconnecter</button></form></div></header>
    <div class="toolbar">
      <div class="refresh-controls"><label class="check"><input id="auto-refresh" type="checkbox" checked> Actualisation 15 s</label><button id="refresh" class="icon-button" aria-label="Actualiser les écrans" title="Actualiser"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-1l2 3M4 15l2 3a7 7 0 0 0 12-1"/></svg></button></div>
      <button id="add-box" class="primary"><span aria-hidden="true">＋</span> Ajouter une box</button>
    </div>
    <p id="global-error" class="notice error" role="alert" hidden></p>
    <div id="loading" class="loading-grid" aria-label="Chargement"><div class="skeleton"></div><div class="skeleton"></div></div>
    <section id="screens" class="screens" aria-label="Liste des écrans"></section>
    <section id="empty" class="empty" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8m-4-4v4"/></svg><h2>Aucun écran enregistré</h2><p>Ajouter une box avec son adresse et sa clé API pour consulter l’affichage et commander la TV.</p><button id="first-box" class="primary">Ajouter une box</button></section>
    <p id="last-refresh" class="last-refresh"></p>
  </main>
  <p id="drag-help" class="sr-only">Glisser vers une autre box pour changer l’ordre. Au clavier : flèches pour déplacer, Échap pour annuler le glissement.</p>
  <div id="drag-preview" class="drag-preview" hidden></div>
  <div id="announcement" class="sr-only" role="status" aria-live="polite"></div>

  <dialog id="box-dialog" aria-labelledby="box-title">
    <form id="box-form">
      <div class="dialog-header"><h2 id="box-title">Ajouter une box</h2><button type="button" class="icon-button close" aria-label="Fermer">×</button></div>
      <div class="dialog-body">
        <label for="box-name">Nom de l’écran</label><input id="box-name" name="name" required maxlength="120" placeholder="Accueil, salle de réunion…" autocomplete="off">
        <label for="box-address">Adresse IP ou DNS</label><input id="box-address" name="address" required maxlength="300" placeholder="192.168.17.20 ou ecran-accueil.local" autocomplete="off" spellcheck="false" aria-describedby="address-help"><p id="address-help" class="field-help">Port 8088 par défaut. Autre port possible : box.local:8089.</p>
        <label for="box-key">Clé API</label><input id="box-key" name="key" type="password" minlength="64" maxlength="64" required autocomplete="off" spellcheck="false" aria-describedby="key-help"><p id="key-help" class="field-help">La clé reste enregistrée sur cet ordinateur.</p>
        <p id="box-error" class="notice error" role="alert" hidden></p>
      </div>
      <div class="dialog-footer"><button type="button" class="close">Annuler</button><button id="box-submit" class="primary" type="submit">Ajouter la box</button></div>
    </form>
  </dialog>

  <dialog id="settings-dialog" aria-labelledby="settings-title">
    <form id="settings-form">
      <div class="dialog-header"><div><h2 id="settings-title">Horaires de la TV</h2><p id="settings-box-name" class="muted"></p></div><button type="button" class="icon-button close" aria-label="Fermer">×</button></div>
      <div class="dialog-body">
        <label for="settings-mode">Mode</label><select id="settings-mode"><option value="auto">Automatique selon les horaires</option><option value="force_on">Toujours allumée</option><option value="force_off">Toujours en veille</option></select>
        <div class="time-fields"><div><label for="settings-on">Allumage</label><input id="settings-on" type="time" required></div><div><label for="settings-off">Extinction</label><input id="settings-off" type="time" required></div></div>
        <label class="check"><input id="settings-weekend" type="checkbox">Appliquer aussi le week-end</label>
        <p id="settings-error" class="notice error" role="alert" hidden></p>
      </div>
      <div class="dialog-footer"><button type="button" class="close">Annuler</button><button type="submit" id="settings-submit" class="primary">Enregistrer et appliquer</button></div>
    </form>
  </dialog>

  <dialog id="confirm-dialog" aria-labelledby="confirm-title">
    <div class="dialog-header"><h2 id="confirm-title">Confirmer</h2><button type="button" class="icon-button close" aria-label="Fermer">×</button></div>
    <div class="dialog-body"><p id="confirm-description"></p><p id="confirm-error" class="notice error" role="alert" hidden></p></div>
    <div class="dialog-footer"><button class="close">Annuler</button><button id="confirm-action" class="danger-button">Confirmer</button></div>
  </dialog>

  <dialog id="preview-dialog" class="preview-dialog" aria-labelledby="preview-title">
    <div class="dialog-header"><div><h2 id="preview-title">Aperçu</h2><p id="preview-description" class="muted"></p></div><button class="icon-button close" aria-label="Fermer l’aperçu">×</button></div>
    <img id="large-preview" alt="Capture de l’écran">
  </dialog>

  <template id="screen-template">
    <article class="screen-card">
      <div class="card-header"><button type="button" class="drag-handle" aria-label="Déplacer la box" aria-describedby="drag-help" title="Glisser pour déplacer"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5h.01M15 5h.01M9 12h.01M15 12h.01M9 19h.01M15 19h.01"/></svg></button><div class="card-title"><h2></h2></div><details class="more"><summary aria-label="Actions de la box" title="Actions"><span aria-hidden="true">•••</span></summary><div class="menu"><button data-action="settings">Horaires de la TV</button><button data-action="edit">Modifier la box</button><div class="menu-divider"></div><button data-action="move-before">Déplacer avant</button><button data-action="move-after">Déplacer après</button><div class="menu-divider"></div><button data-action="restart-display">Redémarrer l’affichage</button><button data-action="restart-box">Redémarrer la box</button><div class="menu-divider"></div><button data-action="remove" class="danger-text">Retirer du tableau de bord</button></div></details></div>
      <button class="preview" data-action="preview"><img alt="" hidden><span class="preview-placeholder">En attente d’une capture</span><span class="preview-open">Agrandir</span></button>
      <div class="card-body">
        <div class="status-row"><span class="connection">Vérification…</span><span class="capture-time">—</span></div>
        <div class="tv-row"><span class="tv-brand">Télévision</span><button class="text-button" data-action="settings">Horaires <span aria-hidden="true">↗</span></button></div>
        <dl class="tv-observation"><dt>État annoncé par la TV</dt><dd class="tv-status">État non communiqué</dd></dl>
        <p class="tv-checked">Aucune réponse CEC disponible</p>
        <button class="cec-refresh" data-action="cec-refresh"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-1l2 3M4 15l2 3a7 7 0 0 0 12-1"/></svg>Actualiser l’état CEC</button>
        <p class="schedule">Lecture des réglages…</p>
        <div class="mode-controls" role="group" aria-label="Mode de la télévision"><button data-mode="auto">Automatique</button><button data-mode="force_on">Allumée</button><button data-mode="force_off">En veille</button></div>
        <p class="box-message" role="status" hidden></p>
      </div>
    </article>
  </template>
</body>
</html>
