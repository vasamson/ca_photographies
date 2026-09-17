# Migration des albums WordPress

Chiffres constatés (API REST, 17/09/2026) : **510 articles** (= albums), galerie `[gallery]` WordPress avec lightbox
Elementor, originaux à 2560 px maximum, tailles intermédiaires déjà générées par WordPress
(150, 300, 768, 1024, 1536, 2048 px). Un album peut dépasser 700 photos.

## Principe : référencer, ne pas copier

`bin/import-wordpress.php` lit l'API REST publique (`/wp-json/wp/v2/posts`, `/media`) et crée en base :
- un album par article (`wp_post_id`, slug WordPress conservé → même adresse `/albums/<slug>`, `legacy_url` mémorisée) ;
- une photo par image de la galerie, `storage = legacy`, chemin relatif à `wp-content/uploads` ;
- les tailles WordPress comme variantes : `thumb` ← 768 px, `w800` ← 768, `w1600` ← 1536, `w2400` ← 2048.

Aucun fichier n'est copié, déplacé ni modifié. Rien n'est écrit dans WordPress. Le script est **idempotent** : relancé, il
met à jour les albums modifiés (date `modified`) et saute les autres ; `--force` re-parse tout.

## Procédure (sur EX2, Terminal cPanel ou cron)

```bash
cd ~/ca_photographies
php bin/backup.php                                  # 0. état initial
php bin/import-wordpress.php --limit=5              # 1. test sur 5 albums, vérifier sur le sous-domaine
php bin/import-wordpress.php                        # 2. tout (≈ 3 000 requêtes API, 20 à 40 min)
php bin/verify-photos.php                           # 3. chaque original existe sur le disque
php bin/verify-photos.php --variants                # 4. idem pour les tailles (plus long, optionnel)
php bin/backup.php                                  # 5. état après import
```

Sortie attendue de l'étape 3 : `✓ Aucun fichier manquant (N vérifiés)`. Sinon la liste des chemins manquants
s'affiche : comparer avec le Gestionnaire de fichiers cPanel avant toute décision.

Comparer aussi les totaux : le nombre d'albums importés doit être 510 (`Tableau de bord` de l'admin ou
`SELECT COUNT(*) FROM albums`), et pour quelques albums pris au hasard le nombre de photos doit correspondre à
la galerie WordPress.

## Ce qui est repris / pas repris

| WordPress | Nouveau site |
|---|---|
| Titre, slug, date de publication, extrait | titre, slug, `event_date`, description |
| Image mise en avant | couverture (si elle fait partie de la galerie, sinon la première photo) |
| Catégorie (autre que « Blog ») | catégorie |
| Ordre des images de la galerie | `sort_order` |
| Lieu | ⚠ inexistant dans WordPress : à renseigner dans l'admin si souhaité |
| Commentaires, tags | non repris |

## Anciennes URLs

| Ancienne adresse | Nouvelle |
|---|---|
| `https://caphotographies.fr/<slug>/` | 301 → `/albums/<slug>` |
| `https://caphotographies.fr/?p=<id>` | 301 → `/albums/<slug>` |
| `/category/…`, `/tag/…`, `/page/N`, `/feed`, `/wp-json`, `/wp-admin` | 301 → `/` |
| `/wp-content/uploads/…` (liens directs vers des images) | inchangés : les fichiers sont toujours servis |

Le slug WordPress est conservé tel quel à l'import ; si un slug est changé plus tard dans l'admin, la `legacy_url`
mémorisée continue de rediriger.

## Phase 2 (optionnelle, plus tard) — rapatrier les photos dans `photos/`

Une fois le nouveau site en production, on peut, album par album, copier les originaux de `wp-content/uploads`
vers `photos/AAAA/<slug>/original/`, regénérer les versions web (`bin/generate-variants.php --album=<slug>`) et
passer les lignes en `storage = photos`. Ce n'est pas nécessaire au fonctionnement : c'est du rangement.
À faire seulement avec une sauvegarde à jour, et sans supprimer les fichiers source tant que
`verify-photos.php --variants` n'est pas au vert.

## Retrait de WordPress (phase 4)

Conditions : site en production depuis plusieurs semaines, aucune photo manquante, sauvegarde complète
**testée** (base + `wp-content/uploads` + `photos`) stockée hors EX2.

Ce qui peut être retiré : `~/wordpress_archive` (les fichiers PHP/thèmes/plugins déplacés à la bascule) et la base
MySQL de WordPress (après export `.sql`).
Ce qui ne doit **jamais** être retiré : `public_html/wp-content/uploads` (les 280 000 photos référencées) et `public_html/photos`.

Avant toute suppression : `php bin/verify-photos.php --variants` au vert le jour même.
