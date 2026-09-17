<?php
declare(strict_types=1);

namespace App;

final class Albums
{
    private const COLS = 'a.*';

    /** Liste publique paginée (albums publiés), avec recherche plein texte simple et filtre catégorie/année. */
    public static function listPublic(int $page, int $per, string $q = '', string $category = '', string $year = ''): array
    {
        $where = ['a.published = 1', 'a.deleted_at IS NULL'];
        $params = [];
        if ($q !== '') {
            foreach (preg_split('/\s+/', trim($q)) as $word) {
                $where[] = '(a.title LIKE ? OR a.location LIKE ? OR a.description LIKE ?)';
                array_push($params, "%$word%", "%$word%", "%$word%");
            }
        }
        if ($category !== '') { $where[] = 'a.category = ?'; $params[] = $category; }
        if ($year !== '') { $where[] = 'a.event_date LIKE ?'; $params[] = "$year-%"; }
        return self::paginate(implode(' AND ', $where), $params, $page, $per);
    }

    /** Liste admin : tout, brouillons compris. */
    public static function listAdmin(int $page, int $per, string $q = '', string $status = ''): array
    {
        $where = ['a.deleted_at IS NULL']; $params = [];
        if ($q !== '') { $where[] = '(a.title LIKE ? OR a.slug LIKE ? OR a.location LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
        if ($status === 'published') $where[] = 'a.published = 1';
        if ($status === 'draft') $where[] = 'a.published = 0';
        if ($status === 'trash') { $where = ['a.deleted_at IS NOT NULL']; $params = []; }
        return self::paginate(implode(' AND ', $where), $params, $page, $per, 'a.updated_at DESC');
    }

    private static function paginate(string $where, array $params, int $page, int $per, string $order = 'a.event_date DESC, a.id DESC'): array
    {
        $page = max(1, $page); $per = max(1, min(100, $per));
        $total = (int) Db::value("SELECT COUNT(*) FROM albums a WHERE $where", $params);
        $rows = Db::all("SELECT " . self::COLS . " FROM albums a WHERE $where ORDER BY $order LIMIT ? OFFSET ?", [...$params, $per, ($page - 1) * $per]);
        self::attachCovers($rows);
        return ['albums' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per)), 'per' => $per];
    }

    /** Couverture de chaque album en une requête (cover_photo_id, sinon première photo). */
    public static function attachCovers(array &$albums): void
    {
        if (!$albums) return;
        $ids = array_column($albums, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        // cover explicite
        $covers = Db::all("SELECT p.* FROM photos p JOIN albums a ON a.cover_photo_id = p.id WHERE a.id IN ($in) AND p.deleted_at IS NULL", $ids);
        $byAlbum = array_column($covers, null, 'album_id');
        // sinon première photo (sous-requête par album : bornée par la taille de la page, pas par le nombre de photos)
        $missing = array_values(array_filter($ids, fn($id) => !isset($byAlbum[$id])));
        if ($missing) {
            $in2 = implode(',', array_fill(0, count($missing), '?'));
            $first = Db::all("SELECT p.* FROM photos p WHERE p.id IN (SELECT MIN(id) FROM photos WHERE album_id IN ($in2) AND deleted_at IS NULL GROUP BY album_id)", $missing);
            foreach ($first as $p) $byAlbum[$p['album_id']] = $p;
        }
        $hydrated = Photos::hydrate(array_values($byAlbum));
        $byAlbum = array_column($hydrated, null, 'album_id');
        foreach ($albums as &$a) $a['cover'] = $byAlbum[$a['id']] ?? null;
    }

    public static function findBySlug(string $slug, bool $publishedOnly = true): ?array
    {
        $a = Db::one('SELECT * FROM albums WHERE slug = ? AND deleted_at IS NULL' . ($publishedOnly ? ' AND published = 1' : ''), [$slug]);
        if ($a) { $arr = [$a]; self::attachCovers($arr); $a = $arr[0]; }
        return $a;
    }

    public static function find(int $id, bool $withDeleted = false): ?array
    {
        $a = Db::one('SELECT * FROM albums WHERE id = ?' . ($withDeleted ? '' : ' AND deleted_at IS NULL'), [$id]);
        if ($a) { $arr = [$a]; self::attachCovers($arr); $a = $arr[0]; }
        return $a;
    }

    public static function findByWpId(int $wpId): ?array
    {
        return Db::one('SELECT * FROM albums WHERE wp_post_id = ?', [$wpId]);
    }

    /** Album publié précédent (plus ancien) → « album suivant » sur la page. */
    public static function neighbor(array $a): ?array
    {
        $n = Db::one('SELECT * FROM albums WHERE published = 1 AND deleted_at IS NULL AND id <> ? AND (event_date < ? OR (event_date = ? AND id < ?)) ORDER BY event_date DESC, id DESC LIMIT 1', [$a['id'], $a['event_date'], $a['event_date'], $a['id']])
            ?? Db::one('SELECT * FROM albums WHERE published = 1 AND deleted_at IS NULL AND id <> ? ORDER BY event_date DESC, id DESC LIMIT 1', [$a['id']]);
        if ($n) { $arr = [$n]; self::attachCovers($arr); $n = $arr[0]; }
        return $n;
    }

    public static function uniqueSlug(string $base, ?int $exceptId = null): string
    {
        $slug = Util::slugify($base); $try = $slug; $i = 1;
        while (Db::value('SELECT id FROM albums WHERE slug = ?' . ($exceptId ? ' AND id <> ' . (int) $exceptId : ''), [$try])) $try = $slug . '-' . (++$i);
        return $try;
    }

    /** Validation + normalisation des champs éditables. */
    public static function sanitize(array $in, ?array $existing = null): array
    {
        $d = [];
        if (array_key_exists('title', $in)) {
            $d['title'] = trim((string) $in['title']);
            if ($d['title'] === '') throw new HttpException(422, 'Le titre est obligatoire.');
        }
        if (array_key_exists('slug', $in) && trim((string) $in['slug']) !== '') $d['slug'] = self::uniqueSlug((string) $in['slug'], $existing['id'] ?? null);
        if (array_key_exists('description', $in)) $d['description'] = trim((string) $in['description']) ?: null;
        if (array_key_exists('location', $in)) $d['location'] = trim((string) $in['location']) ?: null;
        if (array_key_exists('category', $in)) $d['category'] = mb_substr(trim((string) $in['category']), 0, 64) ?: null;
        if (array_key_exists('event_date', $in)) {
            $v = trim((string) $in['event_date']);
            if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) throw new HttpException(422, 'Date invalide (AAAA-MM-JJ).');
            $d['event_date'] = $v ?: null;
        }
        if (array_key_exists('published', $in)) $d['published'] = $in['published'] ? 1 : 0;
        if (array_key_exists('cover_photo_id', $in)) {
            $cid = (int) $in['cover_photo_id'];
            if ($cid && $existing && !Db::value('SELECT id FROM photos WHERE id = ? AND album_id = ? AND deleted_at IS NULL', [$cid, $existing['id']])) throw new HttpException(422, 'Cette photo n\'appartient pas à l\'album.');
            $d['cover_photo_id'] = $cid ?: null;
        }
        return $d;
    }

    public static function create(array $in): array
    {
        $d = self::sanitize($in);
        if (empty($d['title'])) throw new HttpException(422, 'Le titre est obligatoire.');
        $d['slug'] = $d['slug'] ?? self::uniqueSlug($d['title']);
        $d['event_date'] = $d['event_date'] ?? date('Y-m-d');
        $d['published'] = $d['published'] ?? 0;
        $d['created_at'] = $d['updated_at'] = Db::now();
        unset($d['cover_photo_id']);
        return self::find(Db::insert('albums', $d));
    }

    public static function update(array $a, array $in): array
    {
        $d = self::sanitize($in, $a);
        $d['updated_at'] = Db::now();
        Db::update('albums', $d, 'id = :id', ['id' => $a['id']]);
        return self::find((int) $a['id']);
    }

    public static function refreshCount(int $albumId): void
    {
        Db::exec('UPDATE albums SET photo_count = (SELECT COUNT(*) FROM photos WHERE album_id = ? AND deleted_at IS NULL) WHERE id = ?', [$albumId, $albumId]);
    }

    /** Suppression douce de l'album et de ses photos (fichiers `photos` → corbeille, `legacy` intouchés). */
    public static function trash(array $a): void
    {
        $photos = Photos::hydrate(Db::all('SELECT * FROM photos WHERE album_id = ? AND deleted_at IS NULL', [$a['id']]));
        foreach ($photos as $p) Photos::trash($p);
        Db::update('albums', ['deleted_at' => Db::now(), 'published' => 0, 'updated_at' => Db::now()], 'id = :id', ['id' => $a['id']]);
    }

    public static function restore(array $a): void
    {
        $photos = Photos::hydrate(Db::all('SELECT * FROM photos WHERE album_id = ? AND deleted_at IS NOT NULL AND deleted_at >= ?', [$a['id'], $a['deleted_at']]));
        foreach ($photos as $p) Photos::restore($p);
        Db::update('albums', ['deleted_at' => null, 'updated_at' => Db::now()], 'id = :id', ['id' => $a['id']]);
        self::refreshCount((int) $a['id']);
    }

    public static function toPublic(array $a): array
    {
        return [
            'id' => (int) $a['id'], 'slug' => $a['slug'], 'title' => $a['title'],
            'date' => $a['event_date'], 'date_fr' => Util::dateFr($a['event_date']),
            'location' => $a['location'], 'category' => $a['category'],
            'description' => $a['description'], 'count' => (int) $a['photo_count'],
            'cover' => $a['cover'] ? Photos::toPublic($a['cover']) : null,
            'url' => '/albums/' . $a['slug'],
        ];
    }

    public static function toAdmin(array $a): array
    {
        return self::toPublic($a) + [
            'published' => (bool) $a['published'], 'cover_photo_id' => $a['cover_photo_id'] ? (int) $a['cover_photo_id'] : null,
            'wp_post_id' => $a['wp_post_id'] ? (int) $a['wp_post_id'] : null, 'legacy_url' => $a['legacy_url'],
            'created_at' => $a['created_at'], 'updated_at' => $a['updated_at'], 'deleted_at' => $a['deleted_at'],
        ];
    }

    /** Années et catégories disponibles (pour les filtres). */
    public static function facets(): array
    {
        $years = array_map(fn($r) => $r['y'], Db::all('SELECT DISTINCT SUBSTR(event_date, 1, 4) AS y FROM albums WHERE published = 1 AND deleted_at IS NULL AND event_date IS NOT NULL ORDER BY y DESC'));
        $cats = array_map(fn($r) => $r['category'], Db::all('SELECT DISTINCT category FROM albums WHERE published = 1 AND deleted_at IS NULL AND category IS NOT NULL AND category <> \'\' ORDER BY category'));
        return ['years' => $years, 'categories' => $cats];
    }
}
