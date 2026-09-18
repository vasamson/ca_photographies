<?php
declare(strict_types=1);

namespace App\Controllers;

use App\{Albums, Config, HttpException, Photos, Request, Response, Settings, Util, View};

/** Pages publiques (rendu serveur) + API publique en lecture. */
final class PublicController
{
    private static function perPage(): int { return max(6, min(60, (int) Settings::get('per_page') ?: 24)); }
    private static function photosPerPage(): int { return max(24, min(300, (int) Settings::get('photos_per_page') ?: 120)); }

    // ─── Pages ───────────────────────────────────────────────────────────
    public static function home(Request $req): void
    {
        $res = Albums::listPublic(1, self::perPage());
        $albums = array_map([Albums::class, 'toPublic'], $res['albums']);
        Response::html(View::page('home', [
            'page' => 'home', 'title' => null,
            'albums' => $albums, 'total' => $res['total'], 'pages' => $res['pages'],
            'facets' => Albums::facets(),
            'initial' => ['albums' => $albums, 'total' => $res['total'], 'pages' => $res['pages'], 'page' => 1],
        ]), 200, ['Cache-Control: public, max-age=120']);
    }

    public static function album(Request $req, string $slug): void
    {
        $a = Albums::findBySlug($slug);
        if (!$a) throw new HttpException(404, 'Album introuvable');
        $per = self::photosPerPage();
        $photos = array_map([Photos::class, 'toPublic'], Photos::page((int) $a['id'], 1, $per));
        $album = Albums::toPublic($a);
        $next = Albums::neighbor($a);
        Response::html(View::page('album', [
            'page' => 'album', 'title' => $a['title'],
            'description' => $a['description'] ?: ($a['title'] . ($a['location'] ? ' — ' . $a['location'] : '') . ' · ' . $a['photo_count'] . ' photos'),
            'ogImage' => $album['cover'] ? ($album['cover']['sizes'] ? end($album['cover']['sizes'])['url'] : $album['cover']['thumb']) : null,
            'album' => $album, 'photos' => $photos, 'next' => $next ? Albums::toPublic($next) : null,
            'initial' => ['album' => $album, 'photos' => $photos, 'per' => $per, 'pages' => max(1, (int) ceil($a['photo_count'] / $per)), 'next' => $next ? Albums::toPublic($next) : null],
        ]), 200, ['Cache-Control: public, max-age=120']);
    }

    public static function about(Request $req): void
    {
        $latest = Albums::listPublic(1, 1)['albums'][0] ?? null;
        Response::html(View::page('about', [
            'page' => 'about', 'title' => Settings::get('about_title'),
            'aboutCover' => $latest && $latest['cover'] ? Photos::toPublic($latest['cover']) : null,
            'aboutCaption' => $latest ? $latest['title'] . ' · ' . Util::dateFr($latest['event_date']) : '',
        ]), 200, ['Cache-Control: public, max-age=600']);
    }

    // ─── API publique ────────────────────────────────────────────────────
    public static function apiAlbums(Request $req): void
    {
        $res = Albums::listPublic($req->int('page', 1), self::perPage(), trim((string) $req->query('q', '')), trim((string) $req->query('category', '')), trim((string) $req->query('year', '')));
        Response::json(['albums' => array_map([Albums::class, 'toPublic'], $res['albums']), 'total' => $res['total'], 'page' => $res['page'], 'pages' => $res['pages']]);
    }

    public static function apiAlbum(Request $req, string $slug): void
    {
        $a = Albums::findBySlug($slug);
        if (!$a) throw new HttpException(404, 'Album introuvable');
        $per = self::photosPerPage(); $page = $req->int('page', 1);
        Response::json([
            'album' => Albums::toPublic($a),
            'photos' => array_map([Photos::class, 'toPublic'], Photos::page((int) $a['id'], $page, $per)),
            'page' => $page, 'per' => $per, 'pages' => max(1, (int) ceil($a['photo_count'] / $per)), 'total' => (int) $a['photo_count'],
        ]);
    }

    // ─── SEO ─────────────────────────────────────────────────────────────
    public static function sitemap(Request $req): void
    {
        $base = Config::appUrl();
        $rows = \App\Db::all('SELECT slug, updated_at FROM albums WHERE published = 1 AND deleted_at IS NULL ORDER BY event_date DESC');
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        $xml .= "<url><loc>$base/</loc></url><url><loc>$base/a-propos</loc></url>";
        foreach ($rows as $r) $xml .= '<url><loc>' . $base . '/albums/' . rawurlencode($r['slug']) . '</loc><lastmod>' . date('Y-m-d', strtotime($r['updated_at'])) . '</lastmod></url>';
        $xml .= '</urlset>';
        header('Content-Type: application/xml; charset=utf-8');
        echo $xml; exit;
    }

    public static function robots(Request $req): void
    {
        header('Content-Type: text/plain');
        echo "User-agent: *\nDisallow: /admin\nDisallow: /api/\nSitemap: " . Config::appUrl() . "/sitemap.xml\n"; exit;
    }

    /**
     * Anciennes URLs WordPress → 301.
     *   /<slug>/            (permalien article)   → /albums/<slug>
     *   /?p=<id>            (lien court)          → /albums/<slug>
     *   /category/…, /tag/…, /page/N, /feed…      → /
     */
    public static function legacy(Request $req, string $slug): void
    {
        $slug = trim($slug, '/');
        if (preg_match('#^(category|tag|author|page|feed|wp-json|wp-admin|wp-login\.php|comments)#', $slug)) Response::redirect('/', 301);
        $a = Albums::findBySlug($slug) ?? \App\Db::one('SELECT * FROM albums WHERE legacy_url LIKE ? AND published = 1 AND deleted_at IS NULL', ['%/' . $slug . '/']);
        if ($a) Response::redirect('/albums/' . $a['slug'], 301);
        throw new HttpException(404, 'Page introuvable');
    }

    public static function legacyShortlink(Request $req): void
    {
        $id = $req->int('p');
        $a = $id ? Albums::findByWpId($id) : null;
        if ($a && $a['published'] && !$a['deleted_at']) Response::redirect('/albums/' . $a['slug'], 301);
        self::home($req);
    }
}
