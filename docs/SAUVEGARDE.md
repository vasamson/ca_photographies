# Sauvegardes et restauration

Trois choses à sauvegarder, à trois rythmes :

| Quoi | Où | Rythme | Outil |
|---|---|---|---|
| Base de données (albums, photos, ordre, réglages, comptes) | `storage/backups/<date>/db.sql` + `albums.json` | chaque nuit (cron) | `php bin/backup.php` |
| Nouveaux originaux | `public_html/photos/` | après chaque gros upload, et hebdo | cPanel Backup ou rsync/FTP vers un disque local |
| Photos héritées | `public_html/wp-content/uploads/` | une fois (elles ne changent plus), puis mensuel | idem |

`bin/backup.php` conserve les `BACKUP_KEEP` (14) dernières sauvegardes. Le fichier `albums.json` est un export lisible
et indépendant de MySQL : il contient chaque album avec la liste ordonnée de ses photos et leurs chemins.

## Copie hors EX2 (indispensable)

Une sauvegarde qui reste sur le même serveur ne protège pas d'une panne disque ni d'une erreur d'hébergement.
- cPanel → **Backup** → *Download a Full Account Backup* (base + fichiers) : à conserver sur un disque externe.
- Ou un client FTP (FileZilla, Transmit) : dossier distant `public_html/photos` et `public_html/wp-content/uploads`
  → synchronisation vers un disque local. Le volume est de plusieurs centaines de Go : prévoir la première copie sur une nuit.
- `storage/backups/` : télécharger le dossier de la nuit chaque semaine (quelques Mo).

## Corbeille : suppressions réversibles

- Supprimer une photo ou un album dans l'admin ne détruit rien : les fichiers vont dans
  `public_html/photos/_corbeille/AAAA-MM-JJ/…` et la ligne reste en base avec `deleted_at`.
- Restauration : admin → album → « voir la corbeille » → ↶, ou `php bin/trash.php restore --photo=ID` / `--album=ID`.
- Les photos héritées de WordPress ne sont jamais déplacées : les retirer d'un album est une simple mise à jour en base.
- Purge définitive, volontaire uniquement : `php bin/trash.php purge --days=30` (demande confirmation).

## Restauration complète

1. Réinstaller l'application (docs/DEPLOIEMENT.md, étapes 1 à 5) et son `.env`.
2. Base : `mysql -u <user> -p <base> < db.sql` (cPanel → phpMyAdmin → Importer fonctionne aussi).
   Sans dump SQL : `php bin/migrate.php` puis réimporter `albums.json` à la main ou relancer `import-wordpress.php`
   pour les albums hérités.
3. Fichiers : remettre `photos/` et `wp-content/uploads/` au même chemin qu'avant (`PHOTOS_ROOT`, `LEGACY_ROOT`).
4. `php bin/verify-photos.php --variants` doit être au vert.

## Test de restauration

Une sauvegarde non testée n'est pas une sauvegarde. Une fois par trimestre : restaurer `db.sql` dans une base MySQL
vide en local (`DATABASE_URL=mysql://…`) et ouvrir le site en local avec `LEGACY_URL=https://caphotographies.fr/wp-content/uploads`.
