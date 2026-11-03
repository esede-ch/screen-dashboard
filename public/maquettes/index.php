<?php
// Standalone visual prototypes. No registry, session or box API access.
$variant = max(1, min(3, (int) ($_GET['v'] ?? 1)));
$names = [1 => 'Grille institutionnelle', 2 => 'Vue compacte', 3 => 'Mur d’écrans'];
$boxes = [
 ['Rez', 'Philips', 'Allumée', 'Accueil · Rez-de-chaussée'],
 ['1er', 'LG', 'Allumée', 'Formation · Premier étage'],
 ['2e', 'Samsung', 'En veille', 'Formation · Deuxième étage'],
 ['3e', 'Philips', 'Allumée', 'Formation · Troisième étage'],
 ['4e', 'LG', 'Indisponible', 'Administration · Quatrième étage'],
];
function icon(string $name): string {
 $paths = ['refresh'=>'M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-1l2 3M4 15l2 3a7 7 0 0 0 12-1', 'plus'=>'M12 5v14M5 12h14', 'arrow'=>'M5 12h14M13 6l6 6-6 6', 'screen'=>'M3 4h18v13H3zM8 21h8M12 17v4', 'settings'=>'M4 7h16M4 17h16M8 4v6M16 14v6'];
 return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="'.($paths[$name] ?? $paths['screen']).'"/></svg>';
}
function preview(int $i): void { ?>
 <div class="screen-preview <?= $i === 4 ? 'unavailable' : '' ?>">
 <?php if ($i === 4): ?>
   <span class="missing-icon"><?= icon('screen') ?></span><strong>Capture indisponible</strong><span>Dernière connexion à 08:42</span>
 <?php else: ?>
   <div class="screen-top"><b>esede</b><span>Lundi 2 novembre 2026</span><strong>09:41</strong></div>
   <div class="screen-body"><p>La journée à l’esede</p><strong><?= $i === 0 ? 'Bienvenue' : 'Au programme' ?></strong>
   <div class="schedule-row"><span>08:30<br><small>10:00</small></span><b>Accompagnement éducatif<small>Groupe A · Salle <?= 101+$i*100 ?></small></b><em>En cours</em></div>
   <div class="schedule-row"><span>10:15<br><small>11:45</small></span><b>Pratiques professionnelles<small>Groupe B · Salle <?= 102+$i*100 ?></small></b></div>
   <div class="schedule-row"><span>13:00<br><small>14:30</small></span><b>Atelier collaboratif<small>Groupe C · Salle <?= 103+$i*100 ?></small></b></div></div>
   <div class="screen-bottom">École supérieure en éducation de l’enfance<span>Informations & horaires</span></div>
 <?php endif ?></div>
<?php } ?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Maquette <?= $variant ?> — Gestion des écrans</title><link rel="stylesheet" href="style.css"><script src="demo.js" defer></script></head>
<body class="variant-<?= $variant ?>">
<a class="skip" href="#main">Aller aux écrans</a>
<nav class="prototype-nav" aria-label="Choix de la maquette"><span>MAQUETTES <span class="demo-label">/ Données de démonstration</span></span><div><?php foreach($names as $v=>$name): ?><a href="?v=<?= $v ?>" <?= $v===$variant?'aria-current="page"':'' ?>><span>0<?= $v ?></span> <?= $name ?></a><?php endforeach ?></div><a class="return" href="/">Dashboard actuel ↗</a></nav>
<div class="app-shell">
<header class="brand-header"><div class="brand">esede<span>école supérieure en<br>éducation de l’enfance</span></div><span class="brand-section">Affichage numérique</span><div class="side-nav"><span class="selected"><?= icon('screen') ?> Gestion des écrans</span><p>Des écrans connectés.<br>Une vision d’ensemble.</p></div></header>
<main id="main">
 <div class="heading"><div><p class="eyebrow">AFFICHAGE NUMÉRIQUE</p><h1>Gestion des<br class="title-break"> écrans d’affichage<span class="title-dot">.</span></h1><div class="title-rule"></div></div><button class="primary add" data-action="add"><?= icon('plus') ?> Ajouter une box</button></div>
 <div class="toolbar"><div class="view-title"><?= icon('screen') ?><span><?= ['','Vue d’ensemble','Tous les emplacements','Aperçu des affichages'][$variant] ?></span></div><div class="toolbar-right"><label><input type="checkbox" checked id="auto"> Actualisation automatique</label><button class="subtle" data-action="refresh"><?= icon('refresh') ?><span>Actualiser</span></button></div></div>
 <section class="boxes" aria-label="Écrans d’affichage">
 <?php foreach($boxes as $i=>[$floor,$brand,$state,$location]): ?>
 <article class="box <?= $state==='En veille'?'sleeping':'' ?> <?= $i===4?'offline':'' ?>" data-name="Écran <?= $floor ?>">
   <header class="box-heading"><span class="floor-number">0<?= $i+1 ?></span><div><h2>Écran <?= $floor ?></h2><p><?= $location ?></p></div><button class="icon-button" data-action="settings" aria-label="Réglages Écran <?= $floor ?>"><?= icon('settings') ?></button></header>
   <button class="preview-button" data-action="preview" aria-label="Agrandir la capture Écran <?= $floor ?>"><?php preview($i) ?><span class="expand">Agrandir ↗</span><?php if($i===2):?><span class="last-capture">Dernière capture avant la veille</span><?php endif ?></button>
   <div class="box-info"><div class="tv-line"><span class="info-label">État annoncé par la TV</span><strong class="tv-state"><i></i><?= $state ?></strong></div><div class="details"><span><?= $brand ?> <span class="separator">·</span> <?= $i===4?'Non joignable':'Joignable' ?></span><span class="checked"><?= $i===4?'Vérification échouée':'Vérifié à 09:41' ?></span></div><div class="hours"><span>Horaires</span><strong>07:00 – 18:00 <small>lun. – ven.</small></strong></div></div>
   <footer class="box-actions"><button class="cec" data-action="cec"><?= icon('refresh') ?><span>Actualiser l’état CEC</span></button><button class="mode" data-action="settings">Automatique <?= icon('arrow') ?></button></footer>
 </article><?php endforeach ?>
 </section>
 <footer class="page-footer"><span>esede · Affichage numérique</span><span>Maquette <?= $variant ?> — <?= $names[$variant] ?></span></footer>
</main></div>
<dialog id="dialog" aria-labelledby="dialog-title"><div class="dialog-head"><h2 id="dialog-title">Réglages</h2><button class="icon-button" data-close aria-label="Fermer">×</button></div><div id="dialog-content"></div></dialog>
<p class="toast" id="toast" role="status" hidden></p>
</body></html>
