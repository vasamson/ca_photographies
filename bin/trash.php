<?php
/**
 * Corbeille : liste, restauration, purge définitive.
 * Usage :
 *   php bin/trash.php list
 *   php bin/trash.php restore --photo=123 | --album=45
 *   php bin/trash.php purge --days=30 [--yes]     supprime DÉFINITIVEMENT fichiers + lignes plus vieux que N jours
 *   php bin/trash.php purge-uploads [--days=7]    nettoie les uploads interrompus
 */
require __DIR__ . '/_cli.php';
use App\{Albums, Config, Db, Photos, Storage, Uploads};

$cmd = $argv[1] ?? 'list';
if ($cmd === 'list') {
    foreach (Db::all('SELECT id, title, slug, deleted_at FROM albums WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC') as $a) out(sprintf('album  #%-6d %s  (%s)  supprimé le %s', $a['id'], $a['title'], $a['slug'], $a['deleted_at']));
    foreach (Db::all('SELECT p.id, p.filename, p.storage, p.deleted_at, a.slug FROM photos p JOIN albums a ON a.id = p.album_id WHERE p.deleted_at IS NOT NULL AND a.deleted_at IS NULL ORDER BY p.deleted_at DESC LIMIT 200') as $p) out(sprintf('photo  #%-6d %s  album %s  [%s]  supprimée le %s', $p['id'], $p['filename'], $p['slug'], $p['storage'], $p['deleted_at']));
    exit;
}
if ($cmd === 'restore') {
    if ($id = (int) opt('photo')) { $p = Photos::find($id, true); if (!$p || !$p['deleted_at']) { err('Photo introuvable dans la corbeille'); exit(1); } Photos::restore($p); out("Photo #$id restaurée."); }
    if ($id = (int) opt('album')) { $a = Albums::find($id, true); if (!$a || !$a['deleted_at']) { err('Album introuvable dans la corbeille'); exit(1); } Albums::restore($a); out("Album #$id restauré."); }
    exit;
}
if ($cmd === 'purge-uploads') { out(Uploads::purgeStale((int) (opt('days') ?? 7)) . ' upload(s) interrompu(s) nettoyé(s).'); exit; }
if ($cmd === 'purge') {
    $days = (int) (opt('days') ?? 30);
    $before = date('Y-m-d H:i:s', time() - $days * 86400);
    $photos = Photos::hydrate(Db::all('SELECT * FROM photos WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$before]));
    $albums = Db::all('SELECT * FROM albums WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$before]);
    out(count($photos) . ' photo(s) et ' . count($albums) . ' album(s) supprimés depuis plus de ' . $days . ' jours.');
    if (!$photos && !$albums) exit;
    if (opt('yes') === null && strtolower(ask('Suppression DÉFINITIVE des fichiers de la corbeille (les fichiers WordPress ne sont jamais touchés). Continuer ? [oui/non] ')) !== 'oui') { out('Annulé.'); exit; }
    foreach ($photos as $p) {
        if ($p['storage'] === 'photos') {
            $day = substr($p['deleted_at'], 0, 10);
            foreach (array_merge([$p['storage_path']], array_column($p['variants'], 'storage_path')) as $rel) { $abs = Storage::abs('photos', Config::trashDir() . "/$day/$rel"); if (is_file($abs)) unlink($abs); }
        }
        Db::exec('DELETE FROM photos WHERE id = ?', [$p['id']]);
    }
    foreach ($albums as $a) Db::exec('DELETE FROM albums WHERE id = ?', [$a['id']]);
    out('Purge terminée.');
    exit;
}
err("Commande inconnue : $cmd"); exit(1);
