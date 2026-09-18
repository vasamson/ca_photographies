<?php
declare(strict_types=1);

namespace App;

/**
 * Import des albums WordPress via l'API REST publique : aucune écriture côté WordPress,
 * aucun fichier copié, les photos sont RÉFÉRENCÉES en place (storage = legacy).
 *
 * Idempotent et reprenable : article identifié par wp_post_id, photo par chemin.
 * Utilisable en CLI (bin/import-wordpress.php) ou par tranches avec un budget de temps.
 */
final class WpImport
{
    /** Tailles WordPress → variantes du nouveau système, par ordre de préférence. */
    private const SIZE_MAP = ['thumb' => ['medium_large', 'medium', 'large'], 'w800' => ['medium_large', 'large'], 'w1600' => ['1536x1536', 'kubio-fullhd', 'large'], 'w2400' => ['2048x2048', '1536x1536']];
    private const FIELDS = '_fields=id,slug,link,date,modified,title,excerpt,content,featured_media,categories';

    private string $api; private string $legacyUrl; private array $catNames = [];
    private ?WpDb $db = null;
    public array $stats = ['seen' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'photos' => 0, 'missing_sizes' => 0, 'errors' => 0];
    /** @var callable */ private $log;

    public function __construct(?callable $log = null)
    {
        $this->api = Config::wpApiUrl();
        $this->legacyUrl = Config::legacyUrl();
        if ($this->legacyUrl === '') throw new \RuntimeException('LEGACY_URL manquant dans .env');
        $this->log = $log ?? fn(string $s) => null;
        if ((string) Env::get('WP_DATABASE', '') !== '') $this->db = new WpDb();
    }

    public function source(): string { return $this->db ? 'db' : 'api'; }

    /**
     * @param array{limit?:int, post?:int, draft?:bool, force?:bool, time?:int} $o
     *   limit : nombre max d'albums réellement traités (les « déjà à jour » ne comptent pas)
     *   time  : budget en secondes ; l'import s'arrête proprement entre deux albums
     * @return bool true si tout a été parcouru, false s'il reste des articles (relancer)
     */
    public function run(array $o = []): bool
    {
        $limit = (int) ($o['limit'] ?? 0); $only = (int) ($o['post'] ?? 0); $budget = (int) ($o['time'] ?? 0); $start = time();
        $publish = !empty($o['draft']) ? 0 : 1; $force = !empty($o['force']);
        Db::migrate();
        $cats = $this->db ? $this->db->categories() : $this->get("{$this->api}/categories?per_page=100&_fields=id,name")['data'];
        foreach ($cats as $c) $this->catNames[$c['id']] = self::text($c['name']);

        $page = 1; $processed = 0; $per = 20;
        while (true) {
            if ($this->db) { $posts = $this->db->posts($per, ($page - 1) * $per, $only); $totalPages = PHP_INT_MAX; }
            else {
                $url = $only ? "{$this->api}/posts/$only?" . self::FIELDS : "{$this->api}/posts?" . self::FIELDS . "&per_page=$per&page=$page&orderby=date&order=desc";
                $res = $this->get($url);
                $posts = $only ? [$res['data']] : $res['data'];
                $totalPages = (int) ($this->header($res['headers'], 'X-WP-TotalPages') ?? 1);
            }
            if (!$posts || empty($posts[0]['id'])) return true;

            foreach ($posts as $post) {
                if ($limit && $processed >= $limit) return false;
                if ($budget && time() - $start > $budget) return false;
                $this->stats['seen']++;
                $existing = Albums::findByWpId((int) $post['id']);
                if ($existing && !$force && $existing['updated_at'] >= str_replace('T', ' ', $post['modified'] ?? '')) { $this->stats['skipped']++; continue; }
                try { $this->importPost($post, $existing, $publish); $processed++; }
                catch (\Throwable $e) { $this->stats['errors']++; ($this->log)("  ! #{$post['id']} : " . $e->getMessage()); }
            }
            if ($only || $page >= $totalPages) return true;
            $page++;
        }
    }

    private function importPost(array $post, ?array $existing, int $publish): void
    {
        $title = trim(self::text($post['title']['rendered'] ?? '')) ?: $post['slug'];
        $items = self::parseGallery($post['content']['rendered'] ?? '');
        $ids = array_values(array_filter(array_map(fn($i) => $i['id'], $items)));
        if (!empty($post['featured_media'])) $ids[] = (int) $post['featured_media'];
        $media = [];
        foreach (array_chunk(array_unique($ids), 100) as $batch) {
            $rows = $this->db ? $this->db->media($batch) : $this->get("{$this->api}/media?include=" . implode(',', $batch) . '&per_page=100&_fields=id,source_url,mime_type,media_details')['data'];
            foreach ($rows as $m) $media[$m['id']] = $m;
        }
        $category = null;
        foreach ($post['categories'] ?? [] as $cid) { $n = $this->catNames[$cid] ?? null; if ($n && !in_array(strtolower($n), ['blog', 'non classé', 'uncategorized'], true)) { $category = $n; break; } }

        $data = [
            'title' => $title, 'description' => trim(self::text(preg_replace('/\s*\[…\]\s*$/u', '', $post['excerpt']['rendered'] ?? ''))) ?: null,
            'event_date' => substr($post['date'], 0, 10), 'category' => $category,
            'wp_post_id' => (int) $post['id'], 'legacy_url' => $post['link'] ?? null, 'updated_at' => Db::now(),
        ];
        $legacyUrl = $this->legacyUrl; $stats = &$this->stats; $log = $this->log;

        Db::transaction(function () use ($existing, $data, $post, $publish, $items, $media, $legacyUrl, &$stats, $log) {
            if ($existing) { Db::update('albums', $data, 'id = :id', ['id' => $existing['id']]); $albumId = (int) $existing['id']; $stats['updated']++; }
            else { $data['slug'] = Albums::uniqueSlug($post['slug']); $data['published'] = $publish; $data['created_at'] = Db::now(); $albumId = Db::insert('albums', $data); $stats['created']++; }

            $known = array_column(Db::all('SELECT id, storage_path FROM photos WHERE album_id = ?', [$albumId]), 'id', 'storage_path');
            $order = 0; $featuredPhotoId = null;
            foreach ($items as $it) {
                $m = $it['id'] ? ($media[$it['id']] ?? null) : null;
                $rel = self::relPath($m['source_url'] ?? $it['url'], $legacyUrl);
                if (!$rel) { $log("  ! URL hors LEGACY_URL ignorée : " . ($m['source_url'] ?? $it['url'])); continue; }
                $d = $m['media_details'] ?? [];
                $row = [
                    'album_id' => $albumId, 'filename' => basename($rel), 'storage' => 'legacy', 'storage_path' => $rel,
                    'original_filename' => basename($rel), 'mime_type' => $m['mime_type'] ?? 'image/jpeg',
                    'width' => $d['width'] ?? null, 'height' => $d['height'] ?? null, 'filesize' => $d['filesize'] ?? null,
                    'sort_order' => $order++, 'wp_media_id' => $it['id'], 'variants_status' => 'ok',
                ];
                if (isset($known[$rel])) { $photoId = (int) $known[$rel]; Db::update('photos', $row + ['deleted_at' => null], 'id = :id', ['id' => $photoId]); }
                else { $row['created_at'] = Db::now(); $photoId = Db::insert('photos', $row); }
                if ($it['id'] && (int) $it['id'] === (int) ($post['featured_media'] ?? 0)) $featuredPhotoId = $photoId;
                $stats['photos']++;

                Db::exec('DELETE FROM photo_variants WHERE photo_id = ?', [$photoId]);
                $sizes = $d['sizes'] ?? []; $has = false;
                foreach (self::SIZE_MAP as $variant => $candidates) {
                    foreach ($candidates as $c) {
                        if (empty($sizes[$c]['source_url'])) continue;
                        $vrel = self::relPath($sizes[$c]['source_url'], $legacyUrl);
                        if (!$vrel) continue;
                        Db::insert('photo_variants', ['photo_id' => $photoId, 'variant' => $variant, 'storage_path' => $vrel, 'width' => (int) $sizes[$c]['width'], 'height' => (int) $sizes[$c]['height'], 'filesize' => $sizes[$c]['filesize'] ?? null]);
                        $has = true; break;
                    }
                }
                if (!$has) $stats['missing_sizes']++;
            }
            if (!$featuredPhotoId && !empty($post['featured_media']) && isset($media[$post['featured_media']])) {
                $frel = self::relPath($media[$post['featured_media']]['source_url'], $legacyUrl);
                if ($frel) $featuredPhotoId = (int) Db::value('SELECT id FROM photos WHERE album_id = ? AND storage_path = ?', [$albumId, $frel]) ?: null;
            }
            $cover = $featuredPhotoId ?: (int) Db::value('SELECT id FROM photos WHERE album_id = ? AND deleted_at IS NULL ORDER BY sort_order LIMIT 1', [$albumId]) ?: null;
            Db::update('albums', ['cover_photo_id' => $cover], 'id = :id', ['id' => $albumId]);
            Albums::refreshCount($albumId);
        });
        ($this->log)(sprintf('#%d %s — %d photos', $post['id'], $title, count($items)));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────
    private function get(string $url, int $tries = 4): array
    {
        $lastStatus = 0; $lastErr = '';
        for ($t = 1; $t <= $tries; $t++) {
            [$status, $body, $headers, $err] = $this->fetch($url);
            if ($body !== null && $status === 200) { $data = json_decode($body, true); if (is_array($data)) return ['data' => $data, 'headers' => $headers]; }
            if ($status === 400 || $status === 404) return ['data' => [], 'headers' => $headers];
            $lastStatus = $status; $lastErr = $err . ' · ' . preg_replace('/\s+/', ' ', strip_tags(substr((string) $body, 0, 300)));
            sleep($t * 2);
        }
        throw new \RuntimeException("Échec définitif sur $url (HTTP $lastStatus $lastErr)");
    }

    /** cURL si disponible (fiable sur cPanel), sinon flux HTTP. @return array{int, ?string, string[], string} */
    private function fetch(string $url): array
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';
        if (function_exists('curl_init')) {
            $ch = curl_init($url); $hdrs = [];
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_USERAGENT => $ua, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_ENCODING => '',
                CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$hdrs) { $hdrs[] = trim($h); return strlen($h); }]);
            $body = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
            return [$status, $body === false ? null : $body, $hdrs, $err];
        }
        $ctx = stream_context_create(['http' => ['timeout' => 90, 'header' => "User-Agent: $ua\r\nAccept: application/json\r\n", 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx); $status = 0;
        foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) $status = (int) $m[1];
        return [$status, $body === false ? null : $body, $http_response_header ?? [], $body === false ? (error_get_last()['message'] ?? '') : ''];
    }

    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $h) if (stripos($h, "$name:") === 0) return trim(substr($h, strlen($name) + 1));
        return null;
    }
    /** Chemin relatif à LEGACY_URL, sans tenir compte de http/https. */
    private static function relPath(string $url, string $legacyUrl): ?string
    {
        $u = preg_replace('#^https?://#i', '', $url); $base = preg_replace('#^https?://#i', '', $legacyUrl) . '/';
        return str_starts_with($u, $base) ? rawurldecode(substr($u, strlen($base))) : null;
    }
    private static function text(string $s): string { return html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

    /** Galerie [gallery] / Elementor : liste ordonnée de [id média, URL originale]. */
    public static function parseGallery(string $html): array
    {
        $items = []; $seen = [];
        // Code court WordPress brut (contenu lu directement en base) : [gallery ids="12,34,56" …]
        if (preg_match_all('#\[gallery\b[^\]]*\bids=["\']([\d,\s]+)["\']#i', $html, $g)) {
            foreach ($g[1] as $list) foreach (preg_split('/\s*,\s*/', trim($list)) as $id) {
                $id = (int) $id; if ($id && !isset($seen["id:$id"])) { $seen["id:$id"] = true; $items[] = ['id' => $id, 'url' => '']; }
            }
        }
        if (!preg_match_all('#<a\b[^>]*>#i', $html, $anchors)) return $items;
        foreach ($anchors[0] as $a) {
            if (!preg_match('#href=["\']([^"\']+\.(?:jpe?g|png|webp))["\']#i', $a, $h)) continue;
            $url = html_entity_decode($h[1]);
            if (isset($seen[$url])) continue;
            $id = null;
            if (preg_match('#data-e-action-hash=["\']([^"\']+)["\']#', $a, $m) && preg_match('#settings=([A-Za-z0-9+/=]+)#', rawurldecode($m[1]), $s)) {
                $j = json_decode(base64_decode($s[1]), true); $id = (int) ($j['id'] ?? 0) ?: null;
            }
            if ($id && isset($seen["id:$id"])) continue;
            $seen[$url] = true; if ($id) $seen["id:$id"] = true;
            $items[] = ['id' => $id, 'url' => $url];
        }
        return $items;
    }
}
