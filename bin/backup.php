<?php
/**
 * Sauvegarde : dump de la base + export JSON lisible des albums/photos (métadonnées).
 * Les fichiers photos eux-mêmes sont sauvegardés séparément (voir docs/SAUVEGARDE.md : cPanel Backup + copie locale).
 *
 * Usage : php bin/backup.php            → storage/backups/AAAA-MM-JJ_HHMMSS/{db.sql|db.sqlite, albums.json}
 * Conserve les BACKUP_KEEP dernières sauvegardes.
 */
require __DIR__ . '/_cli.php';
use App\{Config, Db, Env};

$dir = Config::backupDir() . '/' . date('Y-m-d_His');
if (!is_dir($dir)) mkdir($dir, 0700, true);

// 1. Base de données
if (Db::driver() === 'sqlite') {
    $src = Config::path(substr((string) Env::get('DATABASE_URL'), 7));
    Db::pdo()->exec("VACUUM INTO " . Db::pdo()->quote("$dir/db.sqlite"));
    out("Base SQLite copiée → $dir/db.sqlite");
} else {
    $p = parse_url((string) Env::get('DATABASE_URL'));
    $cmd = sprintf('mysqldump --single-transaction --quick --no-tablespaces -h %s -P %d -u %s %s > %s', escapeshellarg($p['host'] ?? 'localhost'), $p['port'] ?? 3306, escapeshellarg(urldecode($p['user'] ?? '')), escapeshellarg(ltrim($p['path'] ?? '', '/')), escapeshellarg("$dir/db.sql"));
    putenv('MYSQL_PWD=' . urldecode($p['pass'] ?? '')); // jamais le mot de passe dans la ligne de commande
    system($cmd, $code); putenv('MYSQL_PWD');
    if ($code !== 0) { err('mysqldump a échoué (code ' . $code . '). Export JSON quand même.'); } else out("Dump MySQL → $dir/db.sql");
}

// 2. Export JSON (restaurable indépendamment du SGBD)
$albums = Db::all('SELECT * FROM albums ORDER BY id');
$fh = fopen("$dir/albums.json", 'w');
fwrite($fh, "{\n  \"exported_at\": " . json_encode(date('c')) . ",\n  \"settings\": " . json_encode(\App\Settings::all(), JSON_UNESCAPED_UNICODE) . ",\n  \"albums\": [\n");
foreach ($albums as $i => $a) {
    $a['photos'] = Db::all('SELECT id, filename, storage, storage_path, original_filename, mime_type, width, height, filesize, sort_order, wp_media_id, deleted_at FROM photos WHERE album_id = ? ORDER BY sort_order, id', [$a['id']]);
    fwrite($fh, ($i ? ",\n" : '') . '    ' . json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
fwrite($fh, "\n  ]\n}\n"); fclose($fh);
out(count($albums) . " album(s) exportés → $dir/albums.json");

// 3. Rotation
$all = glob(Config::backupDir() . '/*', GLOB_ONLYDIR) ?: []; sort($all);
foreach (array_slice($all, 0, max(0, count($all) - Config::backupKeep())) as $old) {
    foreach (glob("$old/*") ?: [] as $f) unlink($f);
    rmdir($old); out("Ancienne sauvegarde supprimée : " . basename($old));
}
