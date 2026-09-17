<?php
declare(strict_types=1);

namespace App;

final class Photos
{
    /** Photos d'un album, page par page (jamais tout l'album d'un coup pour les gros albums). */
    public static function page(int $albumId, int $page, int $per): array
    {
        $per = max(1, min(500, $per)); $page = max(1, $page);
        $rows = Db::all('SELECT * FROM photos WHERE album_id = ? AND deleted_at IS NULL ORDER BY sort_order, id LIMIT ? OFFSET ?', [$albumId, $per, ($page - 1) * $per]);
        return self::hydrate($rows);
    }

    public static function count(int $albumId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM photos WHERE album_id = ? AND deleted_at IS NULL', [$albumId]);
    }

    public static function find(int $id, bool $withDeleted = false): ?array
    {
        $p = Db::one('SELECT * FROM photos WHERE id = ?' . ($withDeleted ? '' : ' AND deleted_at IS NULL'), [$id]);
        return $p ? self::hydrate([$p])[0] : null;
    }

    /** Attache les variantes en une seule requête (pas une requête par photo). */
    public static function hydrate(array $rows): array
    {
        if (!$rows) return [];
        $ids = array_column($rows, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $vars = Db::all("SELECT * FROM photo_variants WHERE photo_id IN ($in)", $ids);
        $byPhoto = [];
        foreach ($vars as $v) $byPhoto[$v['photo_id']][$v['variant']] = $v;
        foreach ($rows as &$r) $r['variants'] = $byPhoto[$r['id']] ?? [];
        return $rows;
    }

    /** Représentation publique : URLs des tailles, jamais de chemin disque. */
    public static function toPublic(array $p): array
    {
        $sizes = [];
        foreach ($p['variants'] as $name => $v) {
            if ($name === 'thumb') continue;
            $sizes[] = ['w' => (int) $v['width'], 'h' => (int) $v['height'], 'url' => Storage::url($p['storage'], $v['storage_path'])];
        }
        usort($sizes, fn($a, $b) => $a['w'] <=> $b['w']);
        $original = Storage::url($p['storage'], $p['storage_path']);
        $thumb = isset($p['variants']['thumb']) ? Storage::url($p['storage'], $p['variants']['thumb']['storage_path']) : ($sizes[0]['url'] ?? $original);
        return [
            'id' => (int) $p['id'],
            'name' => $p['original_filename'] ?: $p['filename'],
            'w' => (int) ($p['width'] ?: 3), 'h' => (int) ($p['height'] ?: 2),
            'thumb' => $thumb,
            'sizes' => $sizes,
            'original' => $original,
        ];
    }

    /** Représentation admin : la publique + métadonnées techniques. */
    public static function toAdmin(array $p): array
    {
        return self::toPublic($p) + [
            'album_id' => (int) $p['album_id'],
            'storage' => $p['storage'], 'storage_path' => $p['storage_path'],
            'filename' => $p['filename'], 'mime_type' => $p['mime_type'],
            'filesize' => (int) ($p['filesize'] ?? 0), 'sort_order' => (int) $p['sort_order'],
            'variants_status' => $p['variants_status'], 'created_at' => $p['created_at'], 'deleted_at' => $p['deleted_at'] ?? null,
        ];
    }

    public static function nextSortOrder(int $albumId): int
    {
        return (int) Db::value('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM photos WHERE album_id = ?', [$albumId]);
    }

    /** Réordonne : les ids fournis prennent les positions 0..n-1, les autres suivent dans leur ordre actuel. */
    public static function reorder(int $albumId, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        Db::transaction(function () use ($albumId, $ids) {
            $all = array_map('intval', array_column(Db::all('SELECT id FROM photos WHERE album_id = ? AND deleted_at IS NULL ORDER BY sort_order, id', [$albumId]), 'id'));
            $set = array_flip($ids);
            $ordered = array_merge(array_values(array_filter($ids, fn($i) => in_array($i, $all, true))), array_values(array_filter($all, fn($i) => !isset($set[$i]))));
            $st = Db::pdo()->prepare('UPDATE photos SET sort_order = ? WHERE id = ? AND album_id = ?');
            foreach ($ordered as $pos => $id) $st->execute([$pos, $id, $albumId]);
        });
    }

    /**
     * Suppression douce : la ligne est conservée (deleted_at), les fichiers `photos` partent en corbeille.
     * Les fichiers `legacy` (WordPress) ne sont jamais déplacés ni supprimés.
     */
    public static function trash(array $p): void
    {
        Db::transaction(function () use ($p) {
            if ($p['storage'] === 'photos') {
                Storage::trash('photos', $p['storage_path']);
                foreach ($p['variants'] as $v) Storage::trash('photos', $v['storage_path']);
            }
            Db::update('photos', ['deleted_at' => Db::now()], 'id = :id', ['id' => $p['id']]);
            Db::exec('UPDATE albums SET cover_photo_id = NULL WHERE cover_photo_id = ?', [$p['id']]);
            Albums::refreshCount((int) $p['album_id']);
        });
    }

    public static function restore(array $p): void
    {
        Db::transaction(function () use ($p) {
            if ($p['storage'] === 'photos') {
                $day = substr((string) $p['deleted_at'], 0, 10);
                $trash = Config::trashDir() . "/$day/";
                Storage::restore($trash . $p['storage_path'], $p['storage_path']);
                foreach ($p['variants'] as $v) Storage::restore($trash . $v['storage_path'], $v['storage_path']);
            }
            Db::update('photos', ['deleted_at' => null], 'id = :id', ['id' => $p['id']]);
            Albums::refreshCount((int) $p['album_id']);
        });
    }

    /** Génère (ou regénère) les variantes d'une photo `photos` à partir de son original. */
    public static function generateVariants(array $p): void
    {
        if ($p['storage'] !== 'photos') return;
        $abs = Storage::abs('photos', $p['storage_path']);
        $dir = dirname($p['storage_path']);
        $base = pathinfo($p['filename'], PATHINFO_FILENAME);
        $destRel = preg_replace('#/original$#', '', $dir) . '/web';
        try {
            $out = Images::generate($abs, Storage::abs('photos', $destRel), $base);
            Db::transaction(function () use ($p, $out, $destRel) {
                Db::exec('DELETE FROM photo_variants WHERE photo_id = ?', [$p['id']]);
                foreach ($out as $variant => $v) {
                    Db::insert('photo_variants', ['photo_id' => $p['id'], 'variant' => $variant, 'storage_path' => "$destRel/{$v['path']}", 'width' => $v['width'], 'height' => $v['height'], 'filesize' => $v['filesize']]);
                }
                Db::update('photos', ['variants_status' => 'ok'], 'id = :id', ['id' => $p['id']]);
            });
        } catch (\Throwable $e) {
            Db::update('photos', ['variants_status' => 'failed'], 'id = :id', ['id' => $p['id']]);
            error_log("Variantes échouées pour la photo {$p['id']} : " . $e->getMessage());
            throw $e;
        }
    }
}
