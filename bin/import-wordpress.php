<?php
/**
 * Import des albums WordPress (voir app/WpImport.php). Les photos sont référencées en place, jamais copiées.
 *
 * Usage :
 *   php bin/import-wordpress.php                 tout importer
 *   php bin/import-wordpress.php --limit=5       n albums (test)
 *   php bin/import-wordpress.php --post=282072   un seul article
 *   php bin/import-wordpress.php --draft         importer en brouillon
 *   php bin/import-wordpress.php --force         re-parser même les albums déjà à jour
 *   php bin/import-wordpress.php --time=240      s'arrêter proprement après N secondes (à relancer)
 */
require __DIR__ . '/_cli.php';
$imp = new App\WpImport('out');
$done = $imp->run(['limit' => (int) (opt('limit') ?? 0), 'post' => (int) (opt('post') ?? 0), 'draft' => opt('draft') !== null, 'force' => opt('force') !== null, 'time' => (int) (opt('time') ?? 0)]);
$s = $imp->stats;
out('');
out("{$s['seen']} article(s) parcourus · {$s['created']} créés · {$s['updated']} mis à jour · {$s['skipped']} déjà à jour · {$s['photos']} photos référencées" . ($s['missing_sizes'] ? " · {$s['missing_sizes']} sans tailles intermédiaires" : '') . ($s['errors'] ? " · {$s['errors']} ERREUR(S)" : ''));
out($done ? 'Import complet. Étape suivante : php bin/verify-photos.php' : 'Arrêt sur limite/budget : relancer pour continuer.');
exit($s['errors'] ? 1 : 0);
