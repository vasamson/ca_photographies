<?php
/**
 * Génère les versions web manquantes ou échouées (photos `photos` avec variants_status <> 'ok').
 * À lancer à la main ou via une tâche cron cPanel (ex. toutes les 10 min) :
 *   php /home/<compte>/ca_photographies/bin/generate-variants.php --limit=50
 *
 * Usage : php bin/generate-variants.php [--limit=N] [--all] [--album=slug]
 *   --all   regénère TOUTES les versions web des photos `photos` (ex. après changement de IMAGE_SIZES)
 */
require __DIR__ . '/_cli.php';
use App\{Db, Images, Photos};

$limit = (int) (opt('limit') ?? 0); $all = opt('all') !== null; $slug = opt('album');
$lock = fopen(sys_get_temp_dir() . '/ca-variants.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) { out('Une autre génération est en cours.'); exit(0); }

$where = "p.storage = 'photos' AND p.deleted_at IS NULL" . ($all ? '' : " AND p.variants_status <> 'ok'") . ($slug ? ' AND a.slug = ' . Db::pdo()->quote($slug) : '');
$rows = Db::all("SELECT p.* FROM photos p JOIN albums a ON a.id = p.album_id WHERE $where ORDER BY p.id" . ($limit ? " LIMIT $limit" : ''));
out(count($rows) . ' photo(s) à traiter · moteur ' . Images::driver() . (Images::supportsWebp() ? ' · WebP' : ' · JPEG'));
$ok = 0; $ko = 0;
foreach (Photos::hydrate($rows) as $p) {
    try { Photos::generateVariants($p); $ok++; out("  ✓ #{$p['id']} {$p['filename']}"); }
    catch (\Throwable $e) { $ko++; err("  ✗ #{$p['id']} {$p['filename']} : " . $e->getMessage()); }
}
out("Terminé : $ok générée(s), $ko échec(s).");
