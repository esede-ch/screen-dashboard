<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
try {
    if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_DASHBOARD_TOKEN'] ?? ''))) json_response(['error' => 'Actualiser le tableau de bord pour continuer.'], 403);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin && $origin !== $dashboardOrigin) json_response(['error' => 'Origine de la demande refusée.'], 403);
    $store = new Store(dashboard_dir());
    $action = $_GET['action'] ?? 'list';
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET' && $action === 'list') json_response(['boxes' => array_map([Store::class, 'safe'], $store->all())]);
    if ($method === 'GET' && $action === 'status') json_response(['boxes' => SharedCache::states($store->all()), 'checked_at' => time()]);
    if ($method === 'GET' && $action === 'image') {
        $box = $store->find((string)($_GET['id'] ?? ''));
        $image = SharedCache::image($box);
        $jpeg = $image['jpeg'];
        header('X-Orange-Bar: ' . $image['bar']);
        header('Content-Type: image/jpeg'); header('Content-Length: ' . strlen($jpeg));
        echo $jpeg; exit;
    }
    if ($method !== 'POST') json_response(['error' => 'Action inconnue.'], 404);
    $body = json_body();
    if ($action === 'cec-auto') {
        require dirname(__DIR__) . '/src/CecMonitor.php';
        json_response(['ok' => true, 'results' => CecMonitor::tick($store->all())]);
    }
    if ($action === 'save') json_response(['box' => $store->save($body)]);
    if ($action === 'reorder') {
        if (!is_array($body['order'] ?? null) || !is_array($body['expected_order'] ?? null)) throw new InvalidArgumentException('Ordre des box invalide.');
        json_response(['boxes' => $store->reorder($body['order'], $body['expected_order'])]);
    }
    if ($action === 'remove') {
        if (($body['confirm'] ?? false) !== true) throw new InvalidArgumentException('Confirmation requise pour retirer cette box.');
        $store->remove((string)($body['id'] ?? ''), (string)($body['revision'] ?? ''));
        SharedCache::invalidate([$body['id']], true);
        json_response(['ok' => true]);
    }
    if ($action !== 'command') json_response(['error' => 'Action inconnue.'], 404);
    $box = $store->find((string)($body['id'] ?? ''));
    if (($body['box_revision'] ?? '') !== $box['revision']) throw new RuntimeException('Les informations de cette box ont changé. Actualiser le tableau de bord.', 409);
    $command = $body['command'] ?? '';
    if (!in_array($command, ['mode','settings','cec-refresh','restart-display','restart-box'], true)) throw new InvalidArgumentException('Commande inconnue.');
    if (in_array($command, ['restart-display','restart-box'], true) && ($body['confirm'] ?? false) !== true) throw new InvalidArgumentException('Confirmation requise pour redémarrer.');
    $lock = fopen(dashboard_dir() . '/command-' . $box['id'] . '.lock', 'c');
    if (!$lock) throw new RuntimeException('Le serveur ne peut pas ouvrir le verrou de commande. Vérifier les droits du dossier data.');
    if (!flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
        fclose($lock);
        if ($wouldBlock) throw new RuntimeException('Une commande est déjà en cours pour cette box.', 423);
        throw new RuntimeException('Le serveur ne peut pas verrouiller la commande. Vérifier les droits du dossier data.');
    }
    try {
        $context = BoxClient::commandContext($box);
        if ($command === 'mode' || $command === 'settings') {
            if ($command === 'mode') {
                $settings = $context['config'];
                $settings['MODE'] = $body['mode'] ?? '';
                $settings['revision'] = $context['revision'];
            } else { $settings = $body['settings'] ?? []; }
            if (!is_array($settings)) throw new InvalidArgumentException('Réglages invalides.');
            $data = array_intersect_key($settings, array_flip(['MODE','ON_TIME','OFF_TIME','WEEKEND_ENABLED','HDMI_LOCK_ENABLED','revision']));
            if (!in_array($data['MODE'] ?? '', ['auto','force_on','force_off'], true)
                || !in_array($data['WEEKEND_ENABLED'] ?? '', ['yes','no'], true)
                || !in_array($data['HDMI_LOCK_ENABLED'] ?? 'yes', ['yes','no'], true)
                || !is_string($data['revision'] ?? null)) throw new InvalidArgumentException('Réglages invalides.');
            foreach (['ON_TIME','OFF_TIME'] as $field) if (!is_string($data[$field] ?? null) || !preg_match('/^([01][0-9]|2[0-3])[0-5][0-9]$/D', $data[$field])) throw new InvalidArgumentException('Deux horaires valides sont requis.');
            if ($data['ON_TIME'] === $data['OFF_TIME']) throw new InvalidArgumentException('Les heures d’allumage et d’extinction doivent être différentes.');
            if ($context['legacy']) {
                $result = BoxClient::legacySettings($box, $context, $data);
            } else {
                $reply = BoxClient::parse(BoxClient::request($box, '/admin/?action=cec-config', 'POST', $data, 60000));
                if (($reply['saved'] ?? false) !== true) throw new RuntimeException('La box n’a pas confirmé l’enregistrement des réglages. Vérifier son état avant de réessayer.', 502);
                $result = ['ok' => true, 'saved' => true, 'applied' => ($reply['applied'] ?? false) === true,
                    'message' => ($reply['applied'] ?? false) ? 'Réglages enregistrés et commande envoyée.' : 'Réglages enregistrés, mais la commande n’a pas pu être envoyée. Vérifier son état.'];
            }
        } elseif ($context['legacy']) {
            $result = BoxClient::legacyCommand($box, $command);
        } else {
            $reply = BoxClient::parse(BoxClient::request($box, '/admin/?action=' . $command, 'POST', $command === 'restart-box' ? ['confirm' => true] : [], 60000));
            if ($command !== 'cec-refresh' && ($reply['accepted'] ?? false) !== true) throw new RuntimeException('La box n’a pas confirmé la demande de redémarrage. Vérifier son état.', 502);
            $result = ['ok' => true, 'accepted' => ($reply['accepted'] ?? false) === true,
                'boot_id' => $reply['boot_id'] ?? null,
                'message' => $command === 'cec-refresh' ? (($reply['refresh_busy'] ?? false) ? 'La communication avec la TV est occupée. Réessayer dans un instant.' : 'État actualisé.') : ($command === 'restart-display' ? 'Redémarrage de l’affichage demandé.' : 'Redémarrage demandé. Le retour de la box sera vérifié.')];
        }
    } finally {
        try { SharedCache::invalidate([$box['id']], in_array($command, ['restart-box','restart-display'], true)); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    json_response($result);
} catch (InvalidArgumentException $error) { json_response(['error' => $error->getMessage()], 400); }
catch (OutOfBoundsException $error) { json_response(['error' => $error->getMessage()], 404); }
catch (RuntimeException $error) { json_response(['error' => $error->getMessage()], in_array($error->getCode(), [401,409,423,502,504], true) ? $error->getCode() : 500); }
catch (Throwable $error) { error_log('Dashboard request failed: ' . get_class($error)); json_response(['error' => 'La demande n’a pas pu être traitée. Réessayer.'], 500); }
