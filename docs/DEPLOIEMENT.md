# Déploiement sur EX2 (cPanel / LiteSpeed)

Ce que l'on sait de l'hébergement (vérifié le 17/09/2026) : serveur **LiteSpeed**, panneau **cPanel**, WordPress dans
`~/public_html`, FTP ouvert (port 21), SSH fermé par défaut (à demander au support EX2 si besoin, sinon le
**Terminal** de cPanel et les tâches **cron** suffisent pour lancer les scripts `bin/`).

Principe : l'application vit dans `~/ca_photographies/` (hors DocumentRoot). Seul son dossier `public/` est exposé.

```
/home/<compte>/
├── ca_photographies/          ← ce dépôt (git clone), contient .env
│   ├── public/                ← DocumentRoot du sous-domaine de test
│   ├── app/  bin/  database/  docs/  storage/
├── public_html/               ← WordPress actuel (intact pendant les phases 1-2)
│   ├── wp-content/uploads/    ← photos héritées, référencées en place, JAMAIS déplacées
│   └── photos/                ← nouveaux uploads (créé par l'application)
```

## Phase 1 — sous-domaine de test

### 1. PHP
cPanel → **MultiPHP Manager** : PHP **8.1 ou plus** pour le domaine.
cPanel → **Select PHP Extensions** (ou MultiPHP INI) : activer `pdo_mysql`, `gd` (avec WebP), `exif`, `mbstring`, `intl` (optionnel), `imagick` (optionnel mais recommandé : moins de mémoire pour les 4K).
MultiPHP INI Editor : `memory_limit = 512M`, `max_execution_time = 300`, `upload_max_filesize = 16M`, `post_max_size = 16M` (les uploads passent par morceaux de 4 Mo, inutile d'aller plus haut).

### 2. Code
cPanel → **Git™ Version Control** → *Create* : clone du dépôt GitHub dans `/home/<compte>/ca_photographies`
(ou dépôt privé avec un deploy key). À chaque mise à jour : *Pull or Deploy*.
Sans Git : envoyer le contenu du dépôt en FTP dans ce dossier (sauf `storage/`, `archive/`, `.env`).

### 3. Base de données
cPanel → **MySQL® Databases** : créer une base (`<compte>_ca`) et un utilisateur avec *ALL PRIVILEGES*.

### 4. `.env`
Créer `/home/<compte>/ca_photographies/.env` (Gestionnaire de fichiers → *New File*, puis coller) :

```env
APP_ENV=production
APP_URL=https://nouveau.caphotographies.fr
APP_KEY=<64 caractères hexadécimaux aléatoires>
DATABASE_URL=mysql://<compte>_ca:<motdepasse>@localhost:3306/<compte>_ca

PHOTOS_ROOT=/home/<compte>/public_html/photos
PHOTOS_URL=https://caphotographies.fr/photos
LEGACY_ROOT=/home/<compte>/public_html/wp-content/uploads
LEGACY_URL=https://caphotographies.fr/wp-content/uploads

UPLOAD_TMP=/home/<compte>/ca_photographies/storage/uploads
IMAGE_DRIVER=auto
IMAGE_FORMAT=webp
WP_API_URL=https://caphotographies.fr/wp-json/wp/v2
BACKUP_DIR=/home/<compte>/ca_photographies/storage/backups
```

Le mot de passe MySQL contient un caractère spécial ? L'encoder en URL (`@` → `%40`, `#` → `%23`…).
`PHOTOS_URL` reste sur le domaine principal : LiteSpeed sert les fichiers réels de `public_html/photos` sans passer
par WordPress (sa règle de réécriture ne s'applique qu'aux fichiers inexistants).

### 5. Sous-domaine
cPanel → **Domains** → *Create a New Domain* : `nouveau.caphotographies.fr`, **Document Root** :
`/home/<compte>/ca_photographies/public`. Activer AutoSSL (cPanel → SSL/TLS Status).

### 6. Initialisation (Terminal cPanel, ou tâche cron exécutée une fois)
```bash
cd ~/ca_photographies
php bin/migrate.php
php bin/create-admin.php --username=camille        # mot de passe demandé
php bin/import-wordpress.php --limit=3              # test
php bin/import-wordpress.php                        # les 510 albums (20 à 40 min)
php bin/verify-photos.php                           # doit finir par « Aucun fichier manquant »
```
Pas de Terminal ? Créer une tâche cron « une fois » : cPanel → **Cron Jobs**, commande
`cd /home/<compte>/ca_photographies && php bin/import-wordpress.php >> storage/logs/import.log 2>&1`, puis la supprimer.

### 7. Tâches cron permanentes
```
*/10 * * * *  cd /home/<compte>/ca_photographies && php bin/generate-variants.php --limit=30 >> storage/logs/variants.log 2>&1
30 3 * * *    cd /home/<compte>/ca_photographies && php bin/backup.php >> storage/logs/backup.log 2>&1
0 4 * * 0     cd /home/<compte>/ca_photographies && php bin/trash.php purge-uploads --days=7 >> storage/logs/trash.log 2>&1
```

### 8. Vérifications
- https://nouveau.caphotographies.fr → accueil avec les albums importés.
- https://nouveau.caphotographies.fr/admin → connexion, tableau de bord (« Moteur d'images : imagick/gd · WebP »).
- Créer un album de test, uploader une vingtaine de photos 4K, vérifier `public_html/photos/2026/<slug>/{original,web}/`.
- Robots : le sous-domaine de test ne doit pas être indexé → ajouter temporairement
  `Header set X-Robots-Tag "noindex"` dans `public/.htaccess` (à retirer à la bascule).

## Phase 3 — bascule du domaine principal

Préalables : phase 2 validée (voir MIGRATION.md), **sauvegarde complète faite** (SAUVEGARDE.md), DNS inchangé (le domaine pointe déjà sur EX2).

Le DocumentRoot du domaine principal est fixé à `public_html` par cPanel. La bascule consiste donc à remplacer les
fichiers WordPress de `public_html` par ceux de `public/`, **sans toucher à `wp-content/uploads` ni `photos`**.

1. Mettre WordPress en maintenance (ou simplement prévenir : l'opération prend quelques minutes).
2. Terminal cPanel :
   ```bash
   cd ~
   mkdir -p wordpress_archive
   # déplace tout WordPress SAUF les photos
   cd public_html && for f in * .[!.]*; do case "$f" in wp-content|photos|cgi-bin) ;; *) mv "$f" ~/wordpress_archive/ ;; esac; done; cd ~
   mv public_html/wp-content/{plugins,themes,upgrade,languages,mu-plugins,index.php} wordpress_archive/ 2>/dev/null
   # installe le nouveau front
   cp -R ca_photographies/public/. public_html/
   ```
   `public_html/index.php` retrouve l'application dans `~/ca_photographies` automatiquement.
3. `.env` : `APP_URL=https://caphotographies.fr` ; dans `public/.htaccess` (copié), décommenter la redirection HTTPS.
4. Tester : accueil, un album importé, un ancien lien `https://caphotographies.fr/<slug>/` (→ 301), l'admin, un upload.
5. Supprimer le sous-domaine de test ou le rediriger.
6. Google Search Console : soumettre `https://caphotographies.fr/sitemap.xml`.

Retour arrière (si problème) : `rm -rf public_html/{index.php,.htaccess,assets} && cp -R wordpress_archive/. public_html/`.
Les photos n'ont jamais bougé, WordPress se retrouve exactement comme avant.

## Mises à jour ultérieures
```bash
cd ~/ca_photographies && git pull && php bin/migrate.php
cp -R public/. ~/public_html/          # seulement après la phase 3
```
