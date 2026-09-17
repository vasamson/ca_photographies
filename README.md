# CA Photographies — site & administration

Refonte sur mesure de [caphotographies.fr](https://caphotographies.fr) : site public + espace d'administration,
sans WordPress, avec les photos conservées sur l'hébergement **EX2**.

- **Backend** : PHP 8.1+ sans framework ni Composer (tourne tel quel sur cPanel/LiteSpeed), MySQL en production, SQLite en local.
- **Frontend** : HTML rendu côté serveur + JS léger (GSAP pour les animations), design « chambre noire ».
- **Photos** : sur le disque d'EX2, jamais dans GitHub. Les 510 albums WordPress sont **référencés en place** (`wp-content/uploads`), les nouveaux uploads vont dans `photos/AAAA/album/`.
- **Aucun identifiant EX2 dans le code** : le backend tourne *sur* EX2 et lit/écrit les fichiers directement. Il n'y a pas de FTP/SFTP à configurer, donc rien à protéger côté navigateur.

| Document | Contenu |
|---|---|
| [docs/DEPLOIEMENT.md](docs/DEPLOIEMENT.md) | Installation sur EX2 (sous-domaine de test, puis bascule du domaine) |
| [docs/MIGRATION.md](docs/MIGRATION.md) | Import des albums WordPress, vérification, redirections, retrait de WordPress |
| [docs/SAUVEGARDE.md](docs/SAUVEGARDE.md) | Sauvegardes base + photos, corbeille, restauration |

---

## Installation locale

Prérequis : PHP ≥ 8.1 avec `pdo_sqlite`, `gd` (ou `imagick`), `mbstring`. Sur macOS : `brew install php`.

```bash
cp .env.example .env
php -r "echo bin2hex(random_bytes(32));"     # → coller dans APP_KEY
php bin/migrate.php                          # crée la base SQLite (storage/database.sqlite)
php bin/create-admin.php                     # crée l'administratrice
php -S 127.0.0.1:8080 -t public public/index.php
```

- Site : http://127.0.0.1:8080 — Admin : http://127.0.0.1:8080/admin
- Pour avoir des albums réels en local sans copier une seule photo :
  `php bin/import-wordpress.php --limit=5` (les images sont lues depuis caphotographies.fr).

## Configuration (`.env`)

Le fichier `.env` **n'est jamais commité** (voir `.gitignore`). Toutes les variables sont documentées dans [`.env.example`](.env.example). Les principales :

| Variable | Rôle |
|---|---|
| `APP_ENV` | `local` (erreurs affichées, photos servies par PHP) ou `production` |
| `APP_URL`, `APP_KEY` | URL publique ; clé secrète des sessions |
| `DATABASE_URL` | `sqlite:storage/database.sqlite` ou `mysql://user:pass@localhost:3306/base` |
| `PHOTOS_ROOT` / `PHOTOS_URL` | Dossier disque et URL publique des **nouveaux** uploads |
| `LEGACY_ROOT` / `LEGACY_URL` | Dossier disque et URL de `wp-content/uploads` (lecture seule) |
| `UPLOAD_TMP`, `UPLOAD_CHUNK_SIZE` | Morceaux d'upload temporaires (hors DocumentRoot) |
| `IMAGE_SIZES`, `IMAGE_THUMB_WIDTH`, `IMAGE_FORMAT`, `IMAGE_QUALITY` | Versions web générées (800/1600/2400 px + miniature 640 px, WebP) |

## Structure

```
public/                 DocumentRoot — seul dossier exposé au web
  index.php             Routeur unique (pages, API, redirections WordPress)
  .htaccess             Réécriture, cache, en-têtes, limites PHP
  assets/css, assets/js Style « chambre noire », site.js (public), admin.js (administration)
app/                    Code PHP (jamais accessible par URL)
  Controllers/          PublicController (pages + API publique), AdminController (API admin)
  views/                Gabarits : layout, home, album, about, admin, error
  Albums, Photos, Uploads, Images, Storage, Auth, Settings, Db, Router…
database/               schema.mysql.sql, schema.sqlite.sql
bin/                    Scripts CLI (voir ci-dessous)
docs/                   Déploiement, migration, sauvegarde
storage/                Local uniquement : base SQLite, chunks, photos de dev, sauvegardes, logs
archive/                Ancien prototype statique (non versionné)
```

## Scripts

| Commande | Rôle |
|---|---|
| `php bin/migrate.php` | Crée / met à jour le schéma (idempotent) |
| `php bin/create-admin.php` | Crée un admin ou réinitialise son mot de passe |
| `php bin/import-wordpress.php [--limit=N] [--post=ID] [--draft] [--force]` | Importe les albums WordPress (référence les fichiers, ne copie rien) |
| `php bin/verify-photos.php [--http] [--variants] [--album=slug]` | Vérifie qu'aucun fichier référencé ne manque |
| `php bin/generate-variants.php [--limit=N] [--all]` | Génère les versions web manquantes (cron conseillé) |
| `php bin/backup.php` | Dump base + export JSON des albums, avec rotation |
| `php bin/trash.php list \| restore \| purge \| purge-uploads` | Corbeille et uploads abandonnés |

## Fonctionnement

### Site public
- `/` accueil (liste paginée, recherche, filtres année/catégorie), `/albums/<slug>`, `/a-propos`, `/sitemap.xml`.
- La page album est rendue côté serveur avec le premier lot de photos (120 par défaut) ; la suite se charge au défilement via `GET /api/albums/<slug>?page=N`. Jamais tout un album de 700 photos d'un coup.
- Chaque photo expose `thumb` (grille) et `sizes[]` (800/1600/2400… ou les tailles WordPress 768/1536/2048) : la lightbox choisit automatiquement la plus petite taille ≥ largeur d'écran × densité. L'original n'est envoyé que sur clic « Original ».
- Anciennes URLs WordPress : `/<slug>/` et `/?p=<id>` → **301** vers `/albums/<slug>`.

### Administration (`/admin`)
- Session PHP (cookie HttpOnly/SameSite, `secure` en prod), mot de passe Argon2id, jeton CSRF sur toute écriture, 8 tentatives / 15 min par IP.
- Albums : créer, modifier (titre, slug, date, lieu, catégorie, description, publié/brouillon), couverture, réordonner par glisser-déposer, supprimer (confirmation par le slug), corbeille et restauration.
- **Upload** : glisser-déposer de centaines de fichiers ; chaque fichier est découpé en morceaux de 4 Mo (`UPLOAD_CHUNK_SIZE`), envoyés 3 fichiers en parallèle, 3 tentatives par morceau, barre de progression (photos, octets, débit, temps restant). Un upload interrompu **reprend là où il s'est arrêté** : en re-sélectionnant les mêmes fichiers, seuls les morceaux manquants sont renvoyés (le serveur liste les uploads en attente dans l'album).
- À la fin de chaque fichier : original rangé dans `photos/AAAA/<slug>/original/`, versions web générées dans `photos/AAAA/<slug>/web/` (Imagick si présent, sinon GD), ligne créée en base. Si la génération échoue, la photo est marquée et `bin/generate-variants.php` la reprend.

### Sécurité des fichiers
- Suppression = déplacement vers `photos/_corbeille/AAAA-MM-JJ/…` + `deleted_at` en base ; restaurable depuis l'admin ou `bin/trash.php`. Purge définitive uniquement par `bin/trash.php purge` avec confirmation.
- **Les fichiers WordPress (`storage = legacy`) ne sont jamais déplacés ni supprimés**, quelle que soit l'action dans l'admin : retirer une photo héritée la retire seulement de l'album.
- Chemins en base toujours relatifs ; toute traversée (`..`) est rejetée.

## Base de données

`albums` (titre, slug, description, event_date, location, category, cover_photo_id, published, photo_count, wp_post_id, legacy_url, deleted_at…),
`photos` (album_id, filename, storage `photos|legacy`, storage_path, original_filename, mime_type, width, height, filesize, sort_order, wp_media_id, variants_status, deleted_at),
`photo_variants` (photo_id, variant `thumb|w800|w1600|w2400`, storage_path, width, height),
`users` (username, password_hash, role), `settings`, `uploads` (sessions d'upload reprenables), `login_attempts`.

## Déploiement, migration, sauvegardes

Voir les trois documents du dossier [`docs/`](docs/). Résumé de la stratégie :

1. **Phase 1** — nouveau site sur un sous-domaine de test, base MySQL dédiée, import des 510 albums *par référence*. WordPress intact.
2. **Phase 2** — vérification (`verify-photos.php`), tests, sauvegarde complète.
3. **Phase 3** — bascule du domaine sur le nouveau site ; `wp-content/uploads` reste en place et continue d'être servi.
4. **Phase 4** — après validation et sauvegarde complète, retrait des fichiers WordPress (jamais du dossier `uploads`).
