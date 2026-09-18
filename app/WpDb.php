<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Lecture DIRECTE (SELECT uniquement) de la base WordPress, sur le même serveur MySQL.
 * Renvoie exactement les mêmes structures que l'API REST /wp/v2/posts et /wp/v2/media,
 * pour que WpImport fonctionne à l'identique quel que soit la source.
 *
 * .env : WP_DATABASE=caphotog_vmhxm   (l'utilisateur de DATABASE_URL doit avoir SELECT dessus)
 *        WP_TABLE_PREFIX=wp_          (optionnel : détecté automatiquement)
 */
final class WpDb
{
    private PDO $pdo; private string $p; private string $home;

    public function __construct()
    {
        $db = (string) Env::get('WP_DATABASE', '');
        if ($db === '') throw new \RuntimeException('WP_DATABASE manquant dans .env');
        $u = parse_url((string) Env::get('DATABASE_URL', ''));
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $u['host'] ?? 'localhost', $u['port'] ?? 3306, $db);
        $this->pdo = new PDO($dsn, urldecode($u['user'] ?? ''), urldecode($u['pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->p = (string) (Env::get('WP_TABLE_PREFIX') ?: $this->detectPrefix());
        $this->home = rtrim((string) $this->option('home'), '/');
    }

    private function detectPrefix(): string
    {
        foreach ($this->pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            if (preg_match('/^(.*)posts$/', $t, $m) && in_array($m[1] . 'postmeta', $this->tables(), true)) return $m[1];
        }
        throw new \RuntimeException('Tables WordPress introuvables dans ' . Env::get('WP_DATABASE'));
    }
    private function tables(): array { static $t = null; return $t ??= $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); }
    private function option(string $name): ?string
    {
        $st = $this->pdo->prepare("SELECT option_value FROM {$this->p}options WHERE option_name = ?"); $st->execute([$name]);
        return $st->fetchColumn() ?: null;
    }

    public function info(): array
    {
        $n = (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->p}posts WHERE post_type = 'post' AND post_status = 'publish'")->fetchColumn();
        return ['prefix' => $this->p, 'home' => $this->home, 'posts' => $n, 'upload_url' => $this->uploadUrl()];
    }
    private function uploadUrl(): string
    {
        $path = (string) $this->option('upload_path'); $url = (string) $this->option('upload_url_path');
        return rtrim($url ?: $this->home . '/wp-content/uploads', '/');
    }

    /** Articles publiés, du plus récent au plus ancien, au format API. */
    public function posts(int $limit, int $offset, int $only = 0): array
    {
        $sql = "SELECT ID, post_name, post_date, post_modified, post_title, post_excerpt, post_content FROM {$this->p}posts WHERE post_type = 'post' AND post_status = 'publish'"
             . ($only ? ' AND ID = ' . (int) $only : '') . " ORDER BY post_date DESC, ID DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;
        $rows = $this->pdo->query($sql)->fetchAll();
        if (!$rows) return [];
        $ids = array_column($rows, 'ID'); $in = implode(',', array_map('intval', $ids));
        $thumbs = []; foreach ($this->pdo->query("SELECT post_id, meta_value FROM {$this->p}postmeta WHERE meta_key = '_thumbnail_id' AND post_id IN ($in)") as $r) $thumbs[$r['post_id']] = (int) $r['meta_value'];
        $cats = []; foreach ($this->pdo->query("SELECT tr.object_id, tt.term_id FROM {$this->p}term_relationships tr JOIN {$this->p}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'category' AND tr.object_id IN ($in)") as $r) $cats[$r['object_id']][] = (int) $r['term_id'];
        return array_map(fn($r) => [
            'id' => (int) $r['ID'], 'slug' => $r['post_name'], 'link' => $this->home . '/' . $r['post_name'] . '/',
            'date' => str_replace(' ', 'T', $r['post_date']), 'modified' => str_replace(' ', 'T', $r['post_modified']),
            'title' => ['rendered' => $r['post_title']], 'excerpt' => ['rendered' => $r['post_excerpt']], 'content' => ['rendered' => $r['post_content']],
            'featured_media' => $thumbs[$r['ID']] ?? 0, 'categories' => $cats[$r['ID']] ?? [],
        ], $rows);
    }

    public function categories(): array
    {
        return array_map(fn($r) => ['id' => (int) $r['term_id'], 'name' => $r['name']],
            $this->pdo->query("SELECT t.term_id, t.name FROM {$this->p}terms t JOIN {$this->p}term_taxonomy tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'category'")->fetchAll());
    }

    /** Médias par id, au format API (source_url + media_details.sizes). */
    public function media(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids))); if (!$ids) return [];
        $in = implode(',', $ids); $base = $this->uploadUrl(); $out = [];
        $mime = []; foreach ($this->pdo->query("SELECT ID, post_mime_type FROM {$this->p}posts WHERE ID IN ($in)") as $r) $mime[$r['ID']] = $r['post_mime_type'];
        $files = []; $meta = [];
        foreach ($this->pdo->query("SELECT post_id, meta_key, meta_value FROM {$this->p}postmeta WHERE post_id IN ($in) AND meta_key IN ('_wp_attached_file', '_wp_attachment_metadata')") as $r) {
            if ($r['meta_key'] === '_wp_attached_file') $files[$r['post_id']] = $r['meta_value']; else $meta[$r['post_id']] = $r['meta_value'];
        }
        foreach ($ids as $id) {
            $file = $files[$id] ?? null; if (!$file) continue;
            $m = $meta[$id] ? @unserialize($meta[$id], ['allowed_classes' => false]) : [];
            $dir = str_contains($file, '/') ? dirname($file) . '/' : '';
            $sizes = [];
            foreach (($m['sizes'] ?? []) as $name => $s) {
                if (empty($s['file'])) continue;
                $sizes[$name] = ['source_url' => "$base/$dir{$s['file']}", 'width' => (int) ($s['width'] ?? 0), 'height' => (int) ($s['height'] ?? 0), 'filesize' => $s['filesize'] ?? null];
            }
            $out[] = ['id' => $id, 'source_url' => "$base/$file", 'mime_type' => $mime[$id] ?? 'image/jpeg',
                'media_details' => ['width' => $m['width'] ?? null, 'height' => $m['height'] ?? null, 'filesize' => $m['filesize'] ?? null, 'sizes' => $sizes]];
        }
        return $out;
    }
}
