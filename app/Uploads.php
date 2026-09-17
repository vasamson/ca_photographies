<?php
declare(strict_types=1);

namespace App;

/**
 * Upload par chunks, reprenable.
 *   init     → crée (ou retrouve) une session d'upload pour (album, empreinte du fichier)
 *   chunk    → écrit un morceau dans UPLOAD_TMP/<id>/<index>.part
 *   complete → assemble, vérifie l'image, range l'original, génère les versions web, crée la photo
 */
final class Uploads
{
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_SIZE = 200 * 1024 * 1024; // 200 Mo par fichier

    public static function init(array $album, int $userId, array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        $size = (int) ($in['size'] ?? 0);
        $type = (string) ($in['type'] ?? '');
        $mtime = (string) ($in['last_modified'] ?? '');
        if ($name === '' || $size <= 0) throw new HttpException(422, 'Nom ou taille de fichier manquant.');
        if ($size > self::MAX_SIZE) throw new HttpException(413, 'Fichier trop volumineux (max 200 Mo).');
        if (!in_array($type, self::ALLOWED, true) && !preg_match('/\.(jpe?g|png|webp)$/i', $name)) throw new HttpException(415, 'Seuls les JPEG, PNG et WebP sont acceptés.');

        $fp = sha1("{$album['id']}|$name|$size|$mtime");
        $chunk = Config::chunkSize();
        $total = (int) ceil($size / $chunk);
        $u = Db::one('SELECT * FROM uploads WHERE album_id = ? AND fingerprint = ?', [$album['id'], $fp]);

        if ($u && $u['status'] === 'done') {
            // Déjà importé : on renvoie l'info pour que le client saute le fichier
            return ['upload_id' => $u['id'], 'status' => 'done', 'chunk_size' => $chunk, 'chunks_total' => $total, 'received' => []];
        }
        if ($u && $u['status'] === 'failed') {
            self::cleanup($u['id']);
            Db::exec('DELETE FROM uploads WHERE id = ?', [$u['id']]);
            $u = null;
        }
        if (!$u) {
            $id = bin2hex(random_bytes(16));
            Db::insert('uploads', [
                'id' => $id, 'album_id' => $album['id'], 'user_id' => $userId, 'fingerprint' => $fp, 'filename' => $name,
                'size' => $size, 'mime_type' => $type ?: null, 'chunk_size' => $chunk, 'chunks_total' => $total,
                'chunks_done' => '[]', 'status' => 'pending', 'created_at' => Db::now(), 'updated_at' => Db::now(),
            ]);
            $u = Db::one('SELECT * FROM uploads WHERE id = ?', [$id]);
        }
        // Chunks réellement présents sur le disque (source de vérité pour la reprise)
        $received = self::receivedChunks($u);
        return ['upload_id' => $u['id'], 'status' => 'pending', 'chunk_size' => (int) $u['chunk_size'], 'chunks_total' => (int) $u['chunks_total'], 'received' => $received];
    }

    private static function dir(string $id): string { return Config::uploadTmp() . '/' . preg_replace('/[^a-f0-9]/', '', $id); }

    private static function receivedChunks(array $u): array
    {
        $dir = self::dir($u['id']);
        if (!is_dir($dir)) return [];
        $out = [];
        for ($i = 0; $i < (int) $u['chunks_total']; $i++) {
            $f = "$dir/$i.part";
            if (!is_file($f)) continue;
            $expected = $i === (int) $u['chunks_total'] - 1 ? (int) $u['size'] - $i * (int) $u['chunk_size'] : (int) $u['chunk_size'];
            if (filesize($f) === $expected) $out[] = $i;
        }
        return $out;
    }

    public static function find(string $id, ?int $albumId = null): array
    {
        $u = Db::one('SELECT * FROM uploads WHERE id = ?', [$id]);
        if (!$u || ($albumId !== null && (int) $u['album_id'] !== $albumId)) throw new HttpException(404, 'Upload introuvable.');
        return $u;
    }

    /** Écrit le chunk $index depuis php://input. */
    public static function chunk(array $u, int $index): array
    {
        if ($u['status'] !== 'pending') throw new HttpException(409, 'Cet upload est déjà terminé.');
        if ($index < 0 || $index >= (int) $u['chunks_total']) throw new HttpException(422, 'Index de chunk invalide.');
        $dir = self::dir($u['id']);
        Storage::ensureDir($dir);
        $expected = $index === (int) $u['chunks_total'] - 1 ? (int) $u['size'] - $index * (int) $u['chunk_size'] : (int) $u['chunk_size'];

        $in = fopen('php://input', 'rb'); $out = fopen("$dir/$index.part.tmp", 'wb');
        $written = stream_copy_to_stream($in, $out, $expected + 1);
        fclose($in); fclose($out);
        if ($written !== $expected) { @unlink("$dir/$index.part.tmp"); throw new HttpException(422, "Chunk $index incomplet ($written / $expected octets)."); }
        rename("$dir/$index.part.tmp", "$dir/$index.part");

        $received = self::receivedChunks($u);
        Db::update('uploads', ['chunks_done' => json_encode($received), 'updated_at' => Db::now()], 'id = :id', ['id' => $u['id']]);
        return ['received' => count($received), 'total' => (int) $u['chunks_total']];
    }

    /** Assemble et crée la photo. Renvoie la photo (format admin). */
    public static function complete(array $u, array $album): array
    {
        if ($u['status'] === 'done') {
            $p = Db::one('SELECT * FROM photos WHERE album_id = ? AND original_filename = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$album['id'], $u['filename']]);
            if ($p) return Photos::toAdmin(Photos::hydrate([$p])[0]);
        }
        $received = self::receivedChunks($u);
        if (count($received) !== (int) $u['chunks_total']) {
            throw new HttpException(409, 'Chunks manquants : ' . (int) $u['chunks_total'] - count($received) . ' restant(s).', ['received' => $received]);
        }
        $dir = self::dir($u['id']);
        $tmp = "$dir/assembled";
        $out = fopen($tmp, 'wb');
        for ($i = 0; $i < (int) $u['chunks_total']; $i++) { $in = fopen("$dir/$i.part", 'rb'); stream_copy_to_stream($in, $out); fclose($in); }
        fclose($out);
        if (filesize($tmp) !== (int) $u['size']) { @unlink($tmp); throw new HttpException(422, 'Taille du fichier assemblé incorrecte.'); }

        try {
            $info = Images::probe($tmp);
            $safe = Util::safeFilename($u['filename']);
            $albumDir = Storage::albumDir($album);
            $rel = Storage::uniquePath("$albumDir/original", $safe);
            $abs = Storage::abs('photos', $rel);
            Storage::ensureDir(dirname($abs));
            if (!rename($tmp, $abs)) throw new \RuntimeException('Impossible de ranger l\'original.');
            @chmod($abs, 0644);

            $photoId = Db::insert('photos', [
                'album_id' => $album['id'], 'filename' => basename($rel), 'storage' => 'photos', 'storage_path' => $rel,
                'original_filename' => $u['filename'], 'mime_type' => $info['mime'], 'width' => $info['width'], 'height' => $info['height'],
                'filesize' => (int) $u['size'], 'sort_order' => Photos::nextSortOrder((int) $album['id']), 'variants_status' => 'pending', 'created_at' => Db::now(),
            ]);
            $photo = Photos::find($photoId);
            try { Photos::generateVariants($photo); }
            catch (\Throwable) { /* statut 'failed' : bin/generate-variants.php reprendra */ }

            Albums::refreshCount((int) $album['id']);
            if (empty($album['cover_photo_id'])) Db::update('albums', ['cover_photo_id' => $photoId], 'id = :id', ['id' => $album['id']]);
            Db::update('uploads', ['status' => 'done', 'updated_at' => Db::now()], 'id = :id', ['id' => $u['id']]);
            self::cleanup($u['id']);
            return Photos::toAdmin(Photos::find($photoId));
        } catch (\Throwable $e) {
            Db::update('uploads', ['status' => 'failed', 'error' => $e->getMessage(), 'updated_at' => Db::now()], 'id = :id', ['id' => $u['id']]);
            self::cleanup($u['id']);
            throw $e instanceof HttpException ? $e : new HttpException(500, 'Import impossible : ' . $e->getMessage());
        }
    }

    public static function cleanup(string $id): void
    {
        $dir = self::dir($id);
        if (!is_dir($dir)) return;
        foreach (glob("$dir/*") ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }

    /** Uploads en attente pour un album (pour proposer la reprise). */
    public static function pending(int $albumId): array
    {
        $rows = Db::all('SELECT id, filename, size, chunks_total, updated_at FROM uploads WHERE album_id = ? AND status = ? ORDER BY updated_at DESC', [$albumId, 'pending']);
        foreach ($rows as &$r) { $r['received'] = count(self::receivedChunks(Db::one('SELECT * FROM uploads WHERE id = ?', [$r['id']]))); }
        return $rows;
    }

    /** Purge les uploads abandonnés depuis plus de N jours. */
    public static function purgeStale(int $days = 7): int
    {
        $rows = Db::all('SELECT id FROM uploads WHERE status = ? AND updated_at < ?', ['pending', date('Y-m-d H:i:s', time() - $days * 86400)]);
        foreach ($rows as $r) { self::cleanup($r['id']); Db::exec('DELETE FROM uploads WHERE id = ?', [$r['id']]); }
        return count($rows);
    }
}
