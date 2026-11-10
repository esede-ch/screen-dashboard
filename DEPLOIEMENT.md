# Déploiement Docker Compose et GitHub

Le dashboard fonctionne avec **Docker Engine et Docker Compose v2, sans Swarm**. Le conteneur `screen-dashboard` fournit Apache et PHP 8.5 avec LDAP, en HTTP sur son port interne **8080**. **Aucun port n’est publié sur le serveur.** Le HTTPS est assuré par ton reverse proxy existant, connecté au réseau `nginx` ou `www` ; aucun proxy ni certificat HTTPS n’est ajouté à cette stack.

## 1. Publier le projet sur GitHub

L’archive `dashboard-ecrans-github.zip` contient un dossier `dashboard-ecrans` prêt à devenir la racine d’un dépôt. Il faut publier le contenu de ce dossier, afin que `compose.yaml`, `Dockerfile` et `.github/` soient à la racine du dépôt.

Les données réelles, les clés API, les sessions, les captures de contrôle, `.env` et les fichiers privés de `config/` sont exclus de l’archive. Les maquettes de `public/maquettes/` restent locales : elles sont exclues de Git, de l’archive et de l’image Docker. Les logos et la photo utilisés par la page de connexion sont inclus dans `public/assets/`.

Créer un dépôt GitHub vide, puis, dans le dossier extrait :

```sh
git init
git branch -M main
git add .
git commit -m "Dashboard esede avec LDAP et Docker Compose"
git remote add origin https://github.com/TON-COMPTE/TON-DEPOT.git
git push -u origin main
```

Remplacer l’adresse du dépôt par la tienne. Le dépôt n’a pas été créé ni publié automatiquement.

## 2. Installer sur le serveur Docker

Prérequis : Docker Engine, le plugin **Compose v2**, Git, et une connexion du serveur Docker à l’annuaire `192.168.1.7:389` ainsi qu’aux box. Le dashboard ne nécessite aucune base SQL.

```sh
git clone https://github.com/TON-COMPTE/TON-DEPOT.git dashboard-ecrans
cd dashboard-ecrans
cp .env.example .env
```

Modifier `.env` avant le premier lancement.

### Utilisation derrière ton reverse proxy HTTPS

```dotenv
DASHBOARD_PUBLIC_URL=https://ecrans.esede.ch
```

Configurer le reverse proxy :

| Réglage | Valeur |
| --- | --- |
| Adresse amont | `http://screen-dashboard:8080` |
| En-tête `Host` transmis | Le nom public, par exemple `ecrans.exemple.ch` |
| Délai d’attente | Au moins 120 secondes pour les commandes CEC |
| Cache du proxy | Désactivé pour les pages PHP et l’API |
| TLS et certificat public | Gérés par ton reverse proxy |

Le conteneur reçoit toujours du HTTP. Le réglage `DASHBOARD_PUBLIC_URL=https://…` indique l’adresse utilisée par le navigateur, permet les cookies `Secure` et le contrôle correct de l’origine des requêtes. Les en-têtes `X-Forwarded-*` ne remplacent pas cette configuration. Le proxy doit conserver le `Host` public, et le site doit être servi à la racine du domaine, pas sous un préfixe de chemin.

Le reverse proxy doit partager l’un des réseaux Docker externes avec le dashboard. `DASHBOARD_PORT` et `DASHBOARD_BIND_IP` ne sont plus utilisés. Les autres conteneurs de ces réseaux peuvent aussi joindre le port interne 8080 ; l’authentification LDAP reste requise.

Une seule adresse publique est autorisée à la fois. `.env` reste à côté de `compose.yaml`, où Compose le lit automatiquement. Avec Portainer, renseigner ces mêmes variables dans l’environnement de la stack.

### Démarrage

Le service `dashboard` est connecté aux deux réseaux Docker externes `www` et `nginx`. Ils doivent exister sur le même serveur Docker. Compose les réutilise sans les créer ni les supprimer. Pour les créer uniquement s’ils sont absents :

```sh
docker network inspect www >/dev/null 2>&1 || docker network create www
docker network inspect nginx >/dev/null 2>&1 || docker network create nginx
```

Un reverse proxy connecté à l’un de ces réseaux peut joindre le service sur `http://screen-dashboard:8080` ; il doit transmettre le `Host` public configuré. Le port hôte `8090` n’est pas publié.

Créer d’abord les deux dossiers sur le serveur Docker. Le pilote `local` avec `o: bind` nécessite qu’ils existent ; le dossier de données doit être accessible à l’utilisateur du conteneur (UID/GID 33).

```sh
sudo install -d -o 33 -g 33 -m 700 /home/esede-admin/Docker-volumes/screen/data
sudo install -d -o root -g 33 -m 750 /home/esede-admin/Docker-volumes/screen/config
```

Pour une installation existante avec l’ancien volume `dashboard_data`, effectuer la migration décrite en section 4 **avant de recréer le conteneur**.

```sh
docker compose config --quiet
docker compose up -d --build --wait --wait-timeout 120
docker compose ps
```

Ouvrir l’adresse définie dans `DASHBOARD_PUBLIC_URL` et utiliser un compte LDAP membre de `screen`.

La première construction télécharge PHP 8.5 et compile l’extension LDAP. Le conteneur est lancé avec l’utilisateur `www-data` (UID/GID 33), sans privilèges, avec un système de fichiers en lecture seule et un volume de données accessible en écriture. Il redémarre après un redémarrage du serveur, sauf s’il a été arrêté volontairement.

## 3. LDAP

Les valeurs de `.env.example` reprennent l’annuaire esede et le groupe autorisé :

```dotenv
LDAP_URI=ldap://192.168.1.7:389
LDAP_BASE_DN=dc=ldap,dc=esede,dc=lan
LDAP_GROUP_DN=cn=screen,cn=groups,dc=ldap,dc=esede,dc=lan
LDAP_LOGIN_ATTRIBUTE=uid
LDAP_USER_FILTER=(objectClass=person)
LDAP_TLS_VERIFY=false
```

StartTLS est obligatoire même si l’URI commence par `ldap://`. Le réglage `false` conserve l’exception autorisée pour le certificat autosigné du serveur Synology : le transport est chiffré, mais le certificat et le nom du serveur ne sont pas validés. Avec un certificat valide, passer à `true` ; placer le fichier d’autorité interne dans `/home/esede-admin/Docker-volumes/screen/config/ldap-ca.pem`, avec `LDAP_CA_FILE=/config/ldap-ca.pem`.

La lecture anonyme suffit pour l’annuaire actuel. Si un compte de lecture devient nécessaire, renseigner `LDAP_BIND_DN` et placer son mot de passe dans `/home/esede-admin/Docker-volumes/screen/config/ldap-bind-password.txt`, puis définir :

```dotenv
LDAP_BIND_PASSWORD_FILE=/config/ldap-bind-password.txt
```

Le fichier doit être lisible par l’UID 33 dans le conteneur ; par exemple, sur le serveur Linux, propriétaire `root:33` et droits `0640`. Ne pas publier ce fichier. Ce dossier de configuration est hors du dépôt Git et monté en lecture seule dans le conteneur. `.env` reste dans le dossier du projet, ignoré par Git ; Compose le lit avant de lancer le conteneur.

Dans Docker, les paramètres LDAP viennent des variables de Compose. Le fichier local `data/ldap.json` du Mac n’est pas nécessaire et n’est pas inclus. Les contrôles LDAP, l’expiration des sessions et la limite des tentatives restent identiques à ceux décrits dans le [manuel](README.md#connexion-ldap-et-droits-daccès).

## 4. Box et persistance

Les deux volumes Compose utilisent le pilote `local` avec un montage `bind`, vers les dossiers explicites du serveur :

| Volume Compose | Dossier sur le serveur | Dossier dans le conteneur | Accès |
| --- | --- | --- | --- |
| `screen_data` | `/home/esede-admin/Docker-volumes/screen/data` | `/var/lib/dashboard` | Lecture et écriture |
| `screen_config` | `/home/esede-admin/Docker-volumes/screen/config` | `/config` | Lecture seule |

`screen_data` conserve les box, leurs clés API, l’ordre d’affichage, les caches et les sessions. `screen_config` conserve les certificats LDAP et les éventuels fichiers secrets de configuration. Les noms Docker par défaut sont `esede-ecrans_screen_data` et `esede-ecrans_screen_config`. Les fichiers restent dans les dossiers du serveur lors d’une recréation du conteneur. Le dossier `config/` du dépôt n’est plus monté.

### Migrer l’ancien volume `dashboard_data`

Si des box sont déjà enregistrées dans l’ancienne version, conserver le conteneur existant jusqu’à la fin de cette copie. Préparer les dossiers comme en section 2, puis exécuter depuis le dossier du projet, avant le premier `up` avec le nouveau Compose. La destination `data` doit être vide ; si elle contient déjà des fichiers, sauvegarder et choisir explicitement quelle installation conserver.

```sh
docker compose stop dashboard
sudo docker compose cp dashboard:/var/lib/dashboard/. /home/esede-admin/Docker-volumes/screen/data/
sudo chown -R 33:33 /home/esede-admin/Docker-volumes/screen/data
sudo chmod 700 /home/esede-admin/Docker-volumes/screen/data
```

Si l’ancien dossier `./config/` contient des certificats ou secrets, copier aussi son contenu :

```sh
sudo cp -a ./config/. /home/esede-admin/Docker-volumes/screen/config/
sudo chown -R root:33 /home/esede-admin/Docker-volumes/screen/config
sudo find /home/esede-admin/Docker-volumes/screen/config -type d -exec chmod 750 {} \;
sudo find /home/esede-admin/Docker-volumes/screen/config -type f -exec chmod 640 {} \;
```

Recréer ensuite le conteneur avec le nouveau Compose et vérifier la liste des box. L’ancien volume `esede-ecrans_dashboard_data` reste conservé ; il n’est pas supprimé automatiquement par cette migration.

Le premier lancement utilise une liste vide : ajouter les box depuis l’interface. Les clés API réelles ne sont pas embarquées dans le dépôt GitHub.

### Reprendre les box du Mac

Copier **en privé** le fichier local `data/boxes.json` sur le serveur, par exemple dans `backups/boxes.json`, puis importer avec le conteneur arrêté :

```sh
docker compose stop dashboard
docker compose run --rm --no-deps -T --entrypoint php dashboard -r '
$path = "/var/lib/dashboard/boxes.json";
$raw = stream_get_contents(STDIN);
json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
if (is_file($path)) { fwrite(STDERR, "Une liste existe déjà : sauvegarder et choisir explicitement avant de remplacer.\n"); exit(1); }
file_put_contents($path, $raw, LOCK_EX);
chmod($path, 0600);
' < backups/boxes.json
docker compose start dashboard
```

Ce fichier contient les clés API : il reste hors de GitHub. Les sessions du Mac ne sont pas migrées.

## 5. Mise à jour

```sh
git pull --ff-only
docker compose build --pull
docker compose up -d --wait --wait-timeout 120
```

Les données restent dans le volume. Après une modification de `.env`, `docker compose up -d` recrée le service si nécessaire. Le code est intégré à l’image : un simple redémarrage ne suffit pas pour appliquer une modification du code.

## 6. Sauvegarde et restauration

Sauvegarder `.env`, le dossier `/home/esede-admin/Docker-volumes/screen/config`, et la liste des box. Exemple de copie cohérente de la liste, service arrêté pendant la lecture :

```sh
umask 077
mkdir -p backups
docker compose stop dashboard
docker compose run --rm --no-deps -T --entrypoint php dashboard -r '
$path = "/var/lib/dashboard/boxes.json";
if (!is_file($path)) { fwrite(STDERR, "Aucune liste enregistrée.\n"); exit(1); }
readfile($path);
' > backups/boxes.json
docker compose start dashboard
cp .env backups/dashboard.env
```

Pour restaurer sur une nouvelle installation avec un volume vide, utiliser l’import de la section précédente. Ne pas restaurer les caches ni les anciennes sessions : ils sont recréés automatiquement.

`docker compose down` garde les volumes. Avec ces deux montages `local`/`bind`, `docker compose down -v` retire les objets volumes Docker mais ne supprime pas le contenu des dossiers hôtes. Cela ne remplace pas une sauvegarde : l’application peut modifier les données et une suppression directe des dossiers détruit leur contenu. Le workflow utilise des dossiers jetables sur le runner GitHub.

## 7. Diagnostic

```sh
docker compose ps
docker compose logs --tail=100 dashboard
docker compose exec dashboard php -v
docker compose exec dashboard php -m
docker compose exec dashboard php -r 'require "src/bootstrap.php"; (new LdapDirectory(Auth::config()))->checkConfiguration(); echo "LDAP et groupe accessibles\n";'
```

- **Adresse refusée** : `DASHBOARD_PUBLIC_URL` doit correspondre au nom/IP et au port utilisés ; le proxy doit conserver ce `Host`.
- **Erreur 502 du reverse proxy** : vérifier son accès au réseau `nginx` ou `www`, la destination `screen-dashboard:8080` et l’utilisation de HTTP vers le conteneur.
- **Annuaire indisponible** : vérifier la route vers le LDAP, son port, le certificat et les paramètres de lecture.
- **Box hors ligne** : le réseau du conteneur doit atteindre les IP/DNS des box, pas seulement le navigateur.
- **Dossiers absents ou droits du volume** : créer les deux dossiers avec les commandes de la section 2. `data` doit appartenir à `33:33` ; `config` doit être traversable et ses fichiers lisibles par le groupe 33. Le conteneur ne tourne pas en root et ne peut pas réparer un dossier hôte appartenant à root.

Le conteneur `screen-dashboard` exécute Apache et la surveillance des captures sous Supervisor. Son contrôle de santé vérifie la page de connexion et l’activité du processus de surveillance. Pour contrôler le service, utiliser `docker compose ps dashboard`. Son dernier bilan se trouve dans `/home/esede-admin/Docker-volumes/screen/data/capture-monitor.json`.

Pour migrer depuis la version à deux conteneurs, construire l’image avec `docker compose build dashboard`, arrêter l’ancien conteneur de surveillance, puis lancer `docker compose up -d --wait dashboard`. Après vérification du nouveau bilan, retirer l’ancien conteneur de surveillance arrêté. Les volumes de la stack sont réutilisés.

## 8. Vérifications et GitHub Actions

Le workflow `.github/workflows/checks.yml` construit l’image de test, lance les tests sur un annuaire LDAP local fictif et des box simulées, prépare les deux dossiers hôtes avec leurs permissions, puis construit et démarre la stack de production. Il vérifie les routes HTTP, les protections, l’utilisateur non privilégié, la lecture de la configuration hôte, l’écriture des données dans le dossier hôte et leur persistance après recréation. Il ne contacte pas les équipements esede et ne publie aucune image ni ne déploie sur ton serveur.

Les requêtes HTTP du test sont exécutées dans le conteneur via PHP/cURL sur `127.0.0.1:8080`, sans publication de port. L’en-tête `Host: localhost:8090` correspond uniquement à l’URL fictive imposée par le workflow pour vérifier le contrôle d’adresse ; il ne publie aucun port. Le reverse proxy de production n’est pas testé par ce workflow.

Vérifications réalisées lors de la préparation : syntaxe YAML/PHP/shell et tests applicatifs locaux. **Le conteneur n’a pas été construit ni démarré sur le Mac, où le moteur Docker est absent.** Le workflow vérifiera cette partie après publication sur GitHub ; sur le serveur, la commande `docker compose up -d --build --wait` constitue la vérification de démarrage.
