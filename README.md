# Tableau de bord des écrans

Un tableau de bord avec **authentification LDAP**, réservé aux membres du groupe **screen**, pour visualiser et commander plusieurs box équipées de l’API screenTV. Le serveur utilise **PHP 8.5**.

## Déployer sur un serveur Docker

Le projet fournit `compose.yaml`, un `Dockerfile` PHP 8.5/Apache, deux volumes persistants et un workflow GitHub Actions. Les dossiers sont `/home/esede-admin/Docker-volumes/screen/data` et `/home/esede-admin/Docker-volumes/screen/config`. **Docker Compose sans Swarm**, sans port publié sur le serveur ; ton reverse proxy rejoint `http://screen-dashboard:8080` via le réseau `nginx` ou `www` et sert le HTTPS.

Consulter le **[guide Docker et GitHub](DEPLOIEMENT.md)** pour la publication, la configuration, le lancement, l’import des box et les sauvegardes.

```sh
cp .env.example .env
# Renseigner dans .env l’URL publique du serveur ou du reverse proxy.
docker network inspect www >/dev/null 2>&1 || docker network create www
docker network inspect nginx >/dev/null 2>&1 || docker network create nginx
sudo install -d -o 33 -g 33 -m 700 /home/esede-admin/Docker-volumes/screen/data
sudo install -d -o root -g 33 -m 750 /home/esede-admin/Docker-volumes/screen/config
docker compose up -d --build --wait --wait-timeout 120
```

Les instructions ci-dessous décrivent les fonctions communes et les essais locaux sur Mac. Dans Docker, la configuration LDAP vient de Compose et le dossier de données est `/var/lib/dashboard` dans le volume persistant.

## Démarrer sur ton Mac

1. Double-clique sur **`Lancer-le-dashboard.command`** dans ce dossier. Le lancement nécessite PHP 8.5 avec cURL, LDAP et GD (JPEG).
2. Garde la fenêtre du Terminal ouverte.
3. Ouvre **[http://127.0.0.1:8090](http://127.0.0.1:8090)** dans ton navigateur et connecte-toi avec ton identifiant LDAP et ton mot de passe habituel. Le compte doit être membre direct du groupe `screen`.

Tu peux aussi lancer le serveur depuis le Terminal :

```sh
cd "/Users/philippe/Documents/ChatGPT/esede/dashboard-ecrans"
./start.sh
```

Pour arrêter le serveur, appuie sur **Ctrl+C** dans cette fenêtre. Pour le relancer, utilise à nouveau le lanceur. Si le port 8090 est déjà occupé par ce tableau de bord, ouvre simplement son adresse existante.

Le serveur écoute uniquement sur cet ordinateur. Ton Mac doit pouvoir joindre les box, par le réseau local ou par ton VPN. Cette version est prévue pour tes essais locaux ; aucun hébergement externe n’est nécessaire.

## Connexion LDAP et droits d’accès

La configuration active se trouve dans **`data/ldap.json`**, hors du dossier public et ignorée par Git. Le fichier est privé (`0600`). Le modèle `ldap.example.json` documente les paramètres ; sur une nouvelle installation, le copier dans `data/ldap.json` puis adapter les valeurs avant de se connecter. L’absence ou l’invalidité de configuration bloque l’accès ; il n’existe pas de mode anonyme de secours.

Configuration de cette installation :

| Paramètre | Valeur |
| --- | --- |
| Annuaire | `ldap://192.168.1.7:389` avec StartTLS obligatoire |
| Base | `dc=ldap,dc=esede,dc=lan` |
| Groupe autorisé | `cn=screen,cn=groups,dc=ldap,dc=esede,dc=lan` |
| Identifiant | `uid` |
| Recherche des utilisateurs | `(objectClass=person)` |
| Compte de lecture | Aucun : lecture anonyme autorisée par cet annuaire |

La connexion LDAP reste chiffrée. **À ta demande, `tls_verify` vaut `false` sur cette installation pour accepter le certificat Synology autosigné sans nom valide.** Cela désactive la validation de l’identité du serveur LDAP ; cette exception ne concerne pas les connexions aux box. Le modèle livré utilise `true`. Avec un certificat correspondant au nom du serveur, utiliser `tls_verify: true` et, si nécessaire, `ca_file` pour le certificat de l’autorité interne. `ldaps://` est également pris en charge. Aucun mot de passe n’est envoyé en LDAP non chiffré : l’échec de StartTLS bloque la connexion.

Le mot de passe est vérifié par un bind LDAP avec le compte de l’utilisateur. Il n’est conservé ni dans les fichiers ni dans la session. Les champs `bind_dn` et `bind_password` sont facultatifs et servent uniquement si un futur annuaire exige un compte de lecture.

Les membres directs sont reconnus par `memberUid` (Synology/OpenLDAP), `member` ou `uniqueMember`. Les groupes imbriqués et l’appartenance par groupe primaire seul ne sont pas résolus. Tous les membres autorisés ont les mêmes droits de consultation et de commande ; les autres sont refusés même avec un mot de passe valide.

Les pages réelles du dashboard, les états, les images, l’ajout et la suppression des box, le tri et les commandes sont contrôlés côté serveur. Les maquettes graphiques séparées contiennent uniquement des données fictives et ne donnent aucun accès aux box.

L’appartenance au groupe et l’existence du compte sont revérifiées à la première requête après **60 secondes**, par session. Le retrait du groupe prend donc effet au prochain contrôle, sans attendre une nouvelle connexion. Si la vérification LDAP échoue, l’accès est retiré. Aucun contrôle ne tourne sans requête de page. Une session expire au plus tard après **8 heures** ; une modification de la configuration LDAP invalide les sessions existantes.

Le bouton **Se déconnecter** détruit la session. Une session expirée redirige vers la connexion et arrête les actualisations. Les tentatives sont limitées à **5 par identifiant et 30 par adresse sur 15 minutes**, y compris les connexions réussies. Après dépassement, attendre 15 minutes. Les messages de refus ne distinguent pas un mot de passe incorrect d’un compte non autorisé.

## Ajouter, modifier et retirer une box

Clique sur **Ajouter une box**, puis saisis :

| Champ | Exemple |
| --- | --- |
| Nom de l’écran | Accueil |
| Adresse IP ou DNS | `192.168.17.20` ou `ecran-accueil.local` |
| Clé API | La clé de 64 caractères obtenue dans l’administration de la box |

Le port est **8088 par défaut**. Pour un autre port, saisis par exemple `ecran-accueil.local:8089`. Une adresse complète comme `http://192.168.17.20:8088` convient aussi. HTTPS est accepté avec un certificat valide et le port voulu, par exemple `https://box.exemple.ch:443`.

Une même adresse ne peut être ajoutée qu’une fois. Jusqu’à **50 box** peuvent être enregistrées. Tu peux ajouter une box hors ligne et vérifier sa connexion ensuite.

Dans le menu **•••** de chaque écran :

- **Modifier la box** change son nom, son adresse ou sa clé. Laisse la clé vide pour conserver celle déjà enregistrée.
- **Retirer du tableau de bord** supprime l’entrée et la clé stockées sur ton ordinateur, après confirmation. Cela n’éteint pas la TV et ne désinstalle rien sur la box.

Si tu génères une nouvelle clé dans `/admin` sur une box, renseigne ensuite cette nouvelle clé dans **Modifier la box**.

## Changer l’ordre des box

Saisir la **poignée à six points**, à gauche du nom de la box, puis la glisser sur la position souhaitée. Le cadre en pointillés indique la destination. Relâcher pour enregistrer ; Échap ou un dépôt hors des cartes annule le déplacement. La page défile près du bord de l’écran.

Au clavier, placer le focus sur la poignée puis utiliser les flèches. Le menu **•••** propose aussi **Déplacer avant** et **Déplacer après**. L’ordre est enregistré sur le serveur et partagé entre les navigateurs ; il reste conservé après rechargement ou redémarrage du tableau de bord. Les déplacements sont possibles même si une box est hors ligne.

Une box ajoutée est placée à la fin de la liste.

## Voir les écrans

Le serveur interroge automatiquement l’état CEC de chaque TV **toutes les 60 secondes**, via les anciennes et nouvelles API. La page ouverte déclenche cette vérification. Les vérifications CEC s’arrêtent à la fermeture de la page, lorsque l’onglet est masqué ou lorsque l’actualisation est désactivée. Une interrogation déjà envoyée à une box peut se terminer, mais aucun nouveau cycle CEC n’est lancé sans page active. Si plusieurs pages sont ouvertes, la vérification continue depuis les pages actives ; le serveur évite les doublons pendant une même minute. Elle ne change ni le mode de la TV ni les horaires. Les box sont interrogées en parallèle ; une commande déjà en cours reporte la lecture CEC jusqu’à la libération de la box. Une erreur est retentée au cycle suivant, sans présenter l’ancien état comme une nouvelle réponse.

La page vérifie les états et charge les captures automatiquement, avec une pause de **15 secondes entre deux cycles**. Tu peux suspendre cette actualisation ou utiliser le bouton d’actualisation manuelle. Les vérifications reprennent lorsque la page redevient visible, si l’actualisation est activée.

Les états et images utilisent un **cache partagé de 15 secondes** sur le serveur. Dix navigateurs peuvent consulter la même capture sans provoquer dix téléchargements sur la box. Lors d’une expiration, un verrou autorise une seule récupération ; les autres demandes réutilisent son résultat. Les erreurs sont également conservées pendant 15 secondes pour éviter de solliciter une box indisponible en boucle. Le bouton d’actualisation visuelle utilise ce même cache.

Après une commande ou une lecture CEC, l’état concerné est invalidé pour refléter le résultat immédiatement. Un changement d’adresse ou de clé empêche la réutilisation du cache précédent. L’ancienneté des captures et réponses CEC continue d’augmenter pendant leur mise en cache. Le service de surveillance continue de vérifier les captures lorsque toutes les pages sont fermées ou en pause.

Clique sur une image pour l’agrandir. L’aperçu agrandi continue à se mettre à jour. Il s’agit de captures périodiques, pas d’un flux vidéo.

- **Joignable** indique que l’API de la box répond. La TV peut être en veille alors que la box reste joignable.
- **État annoncé par la TV** affiche la réponse CEC : allumée, en veille, allumage en cours, mise en veille en cours ou état non communiqué. La date de vérification est indiquée lorsqu’elle est disponible. Une réponse de plus de deux minutes ou conservée pendant une coupure est marquée « à revérifier ». Cet état est distinct du mode demandé par les boutons de commande.
- **Capture ancienne** ou **actualisation indisponible** distingue la dernière image reçue d’une image récente. En cas de coupure, l’ancienne capture est conservée lorsqu’elle existe.
- **Clé refusée**, **Hors ligne** ou **API à vérifier** indique la cause à contrôler.

La marque et le nom annoncé par la TV sont affichés ensemble, sans répéter la marque lorsque le nom la contient déjà (par exemple `LG SIGNAGE`). Si la box transmet le code fabricant mais pas sa marque, le dashboard reconnaît notamment Philips (`PHL` et le code non standard `PHP`, confirmé pour les TV esede), Samsung (`SAM`) et LG (`GSM`, `LGE`). Un code inconnu ne sert pas à deviner une marque.

Les fichiers JavaScript et CSS portent une version calculée à partir de leur contenu. Après un déploiement, une ouverture ou un rechargement normal de la page récupère automatiquement les fichiers modifiés, sans vider le cache du navigateur. Un onglet déjà ouvert conserve sa version jusqu’au rechargement.

L’actualisation du tableau de bord ne recharge pas le site affiché par la box et ne change pas son mécanisme de récupération après une erreur.

## Commander une TV

Les trois boutons sous chaque écran changent immédiatement son mode :

| Bouton | Effet |
| --- | --- |
| Automatique | Applique les horaires enregistrés |
| Allumée | Force l’allumage jusqu’au prochain changement de mode |
| En veille | Force la veille jusqu’au prochain changement de mode |

Les horaires restent conservés quand tu forces l’allumage ou la veille.

**Horaires** ouvre les réglages : mode, heure d’allumage, heure d’extinction et week-end. Les horaires utilisent le fuseau horaire configuré sur la box. Le réglage HDMI existant est conservé lors de leur enregistrement. Clique sur **Enregistrer et appliquer** pour sauvegarder et déclencher immédiatement la commande CEC. L’heure d’allumage et celle d’extinction doivent être différentes ; une plage passant minuit est possible si l’API de la box la prend en charge.

Le réglage HDMI n’est pas modifiable depuis ce tableau de bord. La commande et la remontée d’état dépendent des fonctions CEC de chaque TV.

Une commande peut prendre plusieurs secondes. Son résultat distingue un enregistrement réussi d’un envoi CEC qui a échoué. Un envoi réussi ne garantit pas, à lui seul, que la TV a effectivement changé d’état : consulte l’état actualisé ou utilise **Actualiser l’état CEC**, directement sous l’état annoncé par la TV. Ce bouton interroge immédiatement la TV, puis actualise la réponse et sa date de vérification. Il est désactivé pendant une commande ou lorsque la box est injoignable.

Si les réglages ont changé depuis l’ouverture du formulaire, l’enregistrement est refusé. Ferme et rouvre les horaires après actualisation. Avec l’ancienne API, cette vérification se fait juste avant l’enregistrement ; l’API ne permet pas de garantir une protection contre une modification simultanée sur la box.

## Redémarrer

Dans **•••** :

- **Redémarrer l’affichage** relance l’affichage graphique de la box.
- **Redémarrer la box** redémarre le système complet.

Ces actions demandent une confirmation. Pour le redémarrage complet, le tableau de bord vérifie le changement d’identifiant de démarrage quand la box répond de nouveau. Si son retour n’est pas confirmé après environ trois minutes, un message te demande de vérifier sa connexion. L’actualisation automatique doit être active, ou tu dois actualiser manuellement.

## Stockage et compatibilité

Les adresses et clés sont conservées dans **`data/boxes.json`**, hors du dossier web `public`. Ce fichier contient les clés en clair avec des permissions réservées à ton compte macOS ; le navigateur ne reçoit pas les clés enregistrées. Les requêtes vers les box passent par le serveur PHP local, ce qui évite les problèmes d’accès entre sites dans le navigateur.

Les fichiers temporaires `data/shared-*.json` contiennent les états filtrés et les images, sans clé API en clair ; ils sont hors du dossier public et réservés au compte qui exécute le serveur.

Conserve le dossier `data` pour garder ta liste. Si tu sauvegardes ce fichier, traite sa copie comme un fichier contenant des mots de passe. Il est exclu de Git.

La version récente de l’API (`/admin/?action=state`) permet les captures, les réglages CEC et les commandes de redémarrage. L’ancienne API est également détectée : le tableau de bord consulte `/?action=status`, `screenshot-info.php` et `screenshot.php` pour afficher la capture et les horaires existants, sans mise à jour de la box. Les modes et horaires utilisent `POST /?action=config`, suivi immédiatement de `POST /?action=tv_auto` pour appliquer le mode enregistré. Les paramètres du périphérique CEC sont conservés. Cette ancienne API ne gère pas les plages horaires passant minuit : elles sont refusées avant enregistrement. Le bouton CEC utilise `GET /?action=cec_refresh` ; sa réponse et sa date sont conservées côté serveur, avec leur ancienneté, sans confondre le dernier ordre envoyé avec une réponse de la TV. Le redémarrage complet utilise `POST /?action=reboot`, après confirmation ; cette API ne fournit pas d’identifiant permettant de confirmer un nouveau démarrage. Seul le redémarrage de l’affichage reste désactivé, car aucune route correspondante ne figure dans cette API. Les anciennes routes de capture imposent une clé dans la requête vers la box ; cette clé et l’adresse de capture restent côté serveur, hors du navigateur. Les API non reconnues sont signalées.

## Vérifications de développement

Sur le Mac, le lanceur `start.sh` démarre le serveur web et le processus de surveillance des captures. Le suivi des requêtes CEC de la page est conservé dans `data/cec-auto-*.json` pour éviter les doublons entre navigateurs. Aucun réglage sur les box n’est nécessaire.

Le dossier `tests` contient des tests isolés avec des box simulées. Ils ne lisent pas la liste réelle et ne commandent aucune vraie TV :

```sh
python3 tests/integration.py
```

Ils vérifient notamment dix sessions concurrentes partageant une seule récupération d’état et d’image, l’expiration et l’invalidation du cache, l’absence de travail sans requête, l’enregistrement, les modifications concurrentes, la protection des clés, les erreurs réseau, les délais, les redirections refusées, les horaires et les confirmations de redémarrage.

### Boutons de redémarrage

Chaque carte propose « Redémarrer l’affichage » et « Redémarrer la box », avec confirmation. Sur une API moderne, le premier bouton relance uniquement l’affichage. Sur une ancienne API sans cette commande, il propose un redémarrage complet : un avertissement explicite est affiché et le bouton de confirmation indique « Redémarrer la box ». Annuler ne transmet aucune commande.

### Surveillance de la barre orange

Chaque carte affiche « Image OK » quand la capture a au plus 60 secondes et contient la barre orange horizontale des horaires. Une seule image sans barre déclenche une alerte. Une capture de 61 secondes ou plus déclenche aussi une alerte, même avec la barre. Les deux motifs sont affichés ensemble lorsqu’ils s’appliquent. Si l’âge est inconnu, la barre reste vérifiée. Sans image lisible, la carte indique « Capture indisponible ».

Dans le conteneur unique `screen-dashboard`, le processus `capture-monitor` vérifie les box enregistrées, même sans navigateur, avec une pause de 15 secondes après chaque tournée. Le serveur conserve le dernier bilan dans `data/capture-monitor.json` (identifiant, date Unix et résultat par écran). Le résultat vaut `ok`, `alarm` (barre absente), `stale`, `stale_missing`, `unverified` ou `unavailable`.
