<?php
/**
 * Import des albums WordPress via l'API REST publique (aucune écriture côté WordPress,
 * aucun fichier copié : les photos sont RÉFÉRENCÉES en place dans wp-content/uploads).
 *
 * Idempotent et reprenable : chaque article est identifié par wp_post_id, chaque photo par wp_media_id.
 * Relancer le script met à jour ce qui a changé et continue là où il s'était arrêté.
 *
 * Usage :
 *   php bin/import-wordpress.php                 tout importer
 *   php bin/import-wordpress.php --limit=5       n premiers articles (test)
 *   php bin/import-wordpress.php --post=282072   un seul article
 *   php bin/import-wordpress.php --draft         importer en brouillon (non publié)
 *   php bin/import-wordpress.php --force         re-parser même les albums déjà importés
 */
require __DIR__ . '/_cli.php';
use App\{Albums, Config, Db, Photos};

$api = Config::wpApiUrl();
$legacyUrl = Config::legacyUrl();
if ($legacyUrl === '') { err('LEGACY_URL manquant dans .env (ex. https://caphotographies.fr/wp-content/uploads).'); exit(1); }
$limit = (int) (opt('limit') ?? 0);
$onlyPost = (int) (opt('post') ?? 0);
$asDraft = opt('draft') !== null;
$force = opt('force') !== null;
$publishFlag = $asDraft ? 0 : 1;

// Correspondance tailles WordPress → variantes du nouveau système
const SIZE_MAP = ['thumb' => ['medium_large', 'medium', 'large'], 'w800' => ['medium_large', 'large'], 'w1600' => ['1536x1536', 'kubio-fullhd', 'large'], 'w2400' => ['2048x2048', '1536x1536']];

function wpGet(string $url, int $tries = 4): array
{
    for ($t = 1; $t <= $tries; $t++) {
        $ctx = stream_context_create(['http' => ['timeout' => 60, 'header' => "User-Agent: ca-import/1.0\r\nAccept: application/json\r\n", 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) $status = (int) $m[1];
        if ($body !== false && $status === 200) {
            $data = json_decode($body, true);
            if (is_array($data)) return ['data' => $data, 'headers' => $http_response_header];
        }
        if ($status === 400 || $status === 404) return ['data' => [], 'headers' => $http_response_header ?? []];
        out("   … tentative $t échouée (HTTP $status), nouvel essai");
        sleep($t * 2);
    }
    throw new RuntimeException("Échec définitif sur $url");
}
function header_value(array $headers, string $name): ?string
{
    foreach ($headers as $h) if (stripos($h, "$name:") === 0) return trim(substr($h, strlen($name) + 1));
    return null;
}
function relPath(string $url, string $legacyUrl): ?string
{
    if (!str_starts_with($url, $legacyUrl . '/')) return null;
    return rawurldecode(substr($url, strlen($legacyUrl) + 1));
}
function decodeHtml(string $s): string { return html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

/** Parse la galerie [gallery] / Elementor : liste ordonnée de [media_id, original_url]. */
function parseGallery(string $html): array
{
    $items = []; $seen = [];
    if (!preg_match_all('#<a\b[^>]*>#i', $html, $anchors)) return [];
    foreach ($anchors[0] as $a) {
        if (!preg_match('#href=["\']([^"\']+\.(?:jpe?g|png|webp))["\']#i', $a, $h)) continue;
        $url = html_entity_decode($h[1]);
        if (isset($seen[$url])) continue;
        $id = null;
        if (preg_match('#data-e-action-hash=["\']([^"\']+)["\']#', $a, $m)) {
            $hash = rawurldecode($m[1]);
            if (preg_match('#settings=([A-Za-z0-9+/=]+)#', $hash, $s)) { $j = json_decode(base64_decode($s[1]), true); $id = (int) ($j['id'] ?? 0) ?: null; }
        }
        $seen[$url] = true;
        $items[] = ['id' => $id, 'url' => $url];
    }
    return $items;
}

Db::migrate();
$fields = '_fields=id,slug,link,date,modified,title,excerpt,content,featured_media,categories';
$page = 1; $done = 0; $created = 0; $updated = 0; $photosTotal = 0; $missingSizes = 0;
$catNames = [];
foreach (wpGet("$api/categories?per_page=100&_fields=id,name")['data'] as $c) $catNames[$c['id']] = decodeHtml($c['name']);

out("Import depuis $api");
while (true) {
    $url = $onlyPost ? "$api/posts/$onlyPost?$fields" : "$api/posts?$fields&per_page=20&page=$page&orderby=date&order=desc";
    $res = wpGet($url);
    $posts = $onlyPost ? [$res['data']] : $res['data'];
    if (!$posts || empty($posts[0]['id'])) break;
    $totalPages = (int) (header_value($res['headers'], 'X-WP-TotalPages') ?? 1);

    foreach ($posts as $post) {
        if ($limit && $done >= $limit) break 2;
        $done++;
        $existing = Albums::findByWpId((int) $post['id']);
        $title = trim(decodeHtml($post['title']['rendered'] ?? '')) ?: $post['slug'];
        out(sprintf('[%d] #%d %s', $done, $post['id'], $title));

        if ($existing && !$force && $existing['updated_at'] >= str_replace('T', ' ', $post['modified'] ?? '')) {
            out('     déjà à jour, ignoré (--force pour re-parser)');
            continue;
        }

        $items = parseGallery($post['content']['rendered'] ?? '');
        $ids = array_values(array_filter(array_map(fn($i) => $i['id'], $items)));
        if (!empty($post['featured_media'])) $ids[] = (int) $post['featured_media'];
        // Métadonnées média par lots de 100 (tailles WordPress déjà générées)
        $media = [];
        foreach (array_chunk(array_unique($ids), 100) as $batch) {
            foreach (wpGet("$api/media?include=" . implode(',', $batch) . '&per_page=100&_fields=id,source_url,mime_type,media_details')['data'] as $m) $media[$m['id']] = $m;
        }

        $category = null;
        foreach ($post['categories'] ?? [] as $cid) { $n = $catNames[$cid] ?? null; if ($n && strtolower($n) !== 'blog' && strtolower($n) !== 'non classé') { $category = $n; break; } }

        $albumData = [
            'title' => $title, 'description' => trim(decodeHtml(preg_replace('/\s*\[…\]\s*$/u', '', $post['excerpt']['rendered'] ?? ''))) ?: null,
            'event_date' => substr($post['date'], 0, 10), 'category' => $category,
            'wp_post_id' => (int) $post['id'], 'legacy_url' => $post['link'] ?? null, 'updated_at' => Db::now(),
        ];
        Db::transaction(function () use ($existing, $albumData, $post, $publishFlag, $items, $media, $legacyUrl, &$created, &$updated, &$photosTotal, &$missingSizes) {
            if ($existing) {
                Db::update('albums', $albumData, 'id = :id', ['id' => $existing['id']]);
                $albumId = (int) $existing['id']; $updated++;
            } else {
                $albumData['slug'] = Albums::uniqueSlug($post['slug']);
                $albumData['published'] = $publishFlag;
                $albumData['created_at'] = Db::now();
                $albumId = Db::insert('albums', $albumData); $created++;
            }

            $known = array_column(Db::all('SELECT id, wp_media_id, storage_path FROM photos WHERE album_id = ?', [$albumId]), null, 'storage_path');
            $order = 0; $featuredPhotoId = null;
            foreach ($items as $it) {
                $m = $it['id'] ? ($media[$it['id']] ?? null) : null;
                $originalUrl = $m['source_url'] ?? $it['url'];
                $rel = relPath($originalUrl, $legacyUrl);
                if (!$rel) { out("     ! URL hors de LEGACY_URL ignorée : $originalUrl"); continue; }
                $d = $m['media_details'] ?? [];
                $row = [
                    'album_id' => $albumId, 'filename' => basename($rel), 'storage' => 'legacy', 'storage_path' => $rel,
                    'original_filename' => basename($rel), 'mime_type' => $m['mime_type'] ?? 'image/jpeg',
                    'width' => $d['width'] ?? null, 'height' => $d['height'] ?? null, 'filesize' => $d['filesize'] ?? null,
                    'sort_order' => $order++, 'wp_media_id' => $it['id'], 'variants_status' => 'ok',
                ];
                if (isset($known[$rel])) {
                    $photoId = (int) $known[$rel]['id'];
                    Db::update('photos', $row + ['deleted_at' => null], 'id = :id', ['id' => $photoId]);
                } else {
                    $row['created_at'] = Db::now();
                    $photoId = Db::insert('photos', $row);
                }
                if ($it['id'] && (int) $it['id'] === (int) ($post['featured_media'] ?? 0)) $featuredPhotoId = $photoId;
                $photosTotal++;

                // Variantes = tailles WordPress existantes (aucune génération)
                Db::exec('DELETE FROM photo_variants WHERE photo_id = ?', [$photoId]);
                $sizes = $d['sizes'] ?? [];
                $has = false;
                foreach (SIZE_MAP as $variant => $candidates) {
                    foreach ($candidates as $c) {
                        if (empty($sizes[$c]['source_url'])) continue;
                        $vrel = relPath($sizes[$c]['source_url'], $legacyUrl);
                        if (!$vrel) continue;
                        Db::insert('photo_variants', ['photo_id' => $photoId, 'variant' => $variant, 'storage_path' => $vrel, 'width' => (int) $sizes[$c]['width'], 'height' => (int) $sizes[$c]['height'], 'filesize' => $sizes[$c]['filesize'] ?? null]);
                        $has = true; break;
                    }
                }
                if (!$has) $missingSizes++;
            }
            // Couverture : image mise en avant si elle fait partie de la galerie, sinon (par correspondance de fichier) sinon première photo
            if (!$featuredPhotoId && !empty($post['featured_media']) && isset($media[$post['featured_media']])) {
                $frel = relPath($media[$post['featured_media']]['source_url'], $legacyUrl);
                if ($frel) $featuredPhotoId = (int) Db::value('SELECT id FROM photos WHERE album_id = ? AND storage_path = ?', [$albumId, $frel]) ?: null;
            }
            $cover = $featuredPhotoId ?: (int) Db::value('SELECT id FROM photos WHERE album_id = ? AND deleted_at IS NULL ORDER BY sort_order LIMIT 1', [$albumId]) ?: null;
            Db::update('albums', ['cover_photo_id' => $cover], 'id = :id', ['id' => $albumId]);
            Albums::refreshCount($albumId);
        });
        out('     ' . count($items) . ' photos');
    }
    if ($onlyPost || $page >= $totalPages) break;
    $page++;
}
out('');
out("Terminé : $done article(s) parcourus, $created album(s) créés, $updated mis à jour, $photosTotal photo(s) référencées" . ($missingSizes ? ", $missingSizes sans tailles intermédiaires (original utilisé)" : '') . '.');
out('Étape suivante : php bin/verify-photos.php   (vérifie que chaque fichier référencé existe)');
