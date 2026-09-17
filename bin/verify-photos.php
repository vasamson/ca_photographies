<?php
/**
 * Vérifie qu'aucune photo référencée en base n'est manquante sur le disque.
 * Sur EX2 : test du système de fichiers. Ailleurs (--http) : requête HEAD sur l'URL publique.
 *
 * Usage : php bin/verify-photos.php [--http] [--album=slug] [--variants] [--fix]
 *   --variants  vérifie aussi chaque version web (plus long)
 *   --fix       marque `variants_status = pending` les photos `photos` dont une version manque
 */
require __DIR__ . '/_cli.php';
use App\{Db, Storage};

$http = opt('http') !== null; $withVariants = opt('variants') !== null; $fix = opt('fix') !== null; $slug = opt('album');
$where = 'p.deleted_at IS NULL' . ($slug ? ' AND a.slug = ' . Db::pdo()->quote($slug) : '');
$total = (int) Db::value("SELECT COUNT(*) FROM photos p JOIN albums a ON a.id = p.album_id WHERE $where");
out("Vérification de $total photo(s)" . ($http ? ' via HTTP HEAD' : ' sur le disque') . '…');

function existsHttp(string $url): bool
{
    $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 20, 'ignore_errors' => true]]);
    @file_get_contents($url, false, $ctx);
    foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) return (int) $m[1] < 400;
    return false;
}
$check = fn(string $storage, string $rel) => $http ? existsHttp(Storage::url($storage, $rel)) : Storage::exists($storage, $rel);

$missing = []; $missingVariants = []; $n = 0; $last = 0;
$st = Db::pdo()->prepare("SELECT p.id, p.album_id, p.storage, p.storage_path, a.slug FROM photos p JOIN albums a ON a.id = p.album_id WHERE $where AND p.id > ? ORDER BY p.id LIMIT 500");
while (true) {
    $st->execute([$last]); $rows = $st->fetchAll(); if (!$rows) break;
    foreach ($rows as $r) {
        $last = (int) $r['id']; $n++;
        if (!$check($r['storage'], $r['storage_path'])) $missing[] = $r;
        if ($withVariants) {
            foreach (Db::all('SELECT variant, storage_path FROM photo_variants WHERE photo_id = ?', [$r['id']]) as $v) {
                if (!$check($r['storage'], $v['storage_path'])) { $missingVariants[] = $r + ['variant' => $v['variant']]; if ($fix && $r['storage'] === 'photos') Db::update('photos', ['variants_status' => 'pending'], 'id = :id', ['id' => $r['id']]); }
            }
        }
        if ($n % 500 === 0) out("  $n / $total…");
    }
}
out('');
if (!$missing && !$missingVariants) { out("✓ Aucun fichier manquant ($n vérifiés)."); exit(0); }
foreach ($missing as $r) out(sprintf('MANQUANT  photo #%d  album %s  %s:%s', $r['id'], $r['slug'], $r['storage'], $r['storage_path']));
foreach ($missingVariants as $r) out(sprintf('VARIANTE  photo #%d  album %s  %s', $r['id'], $r['slug'], $r['variant']));
err(count($missing) . ' original(aux) manquant(s), ' . count($missingVariants) . ' version(s) web manquante(s).');
exit(2);
