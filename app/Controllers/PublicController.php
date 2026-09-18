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
        $res = Albums::listPublic(1, 10);
        $albums = array_map([Albums::class, 'toPublic'], $res['albums']);
        Response::html(View::page('home', [
            'page' => 'home', 'title' => null,
            'albums' => $albums, 'total' => $res['total'],
            'initial' => ['albums' => $albums, 'total' => $res['total']],
        ]), 200, ['Cache-Control: no-cache, must-revalidate']);
    }

    public static function albums(Request $req): void
    {
        $res = Albums::listPublic(1, self::perPage());
        $albums = array_map([Albums::class, 'toPublic'], $res['albums']);
        Response::html(View::page('albums', [
            'page' => 'albums', 'title' => 'Tous les albums',
            'albums' => $albums, 'total' => $res['total'], 'pages' => $res['pages'],
            'facets' => Albums::facets(),
            'initial' => ['albums' => $albums, 'total' => $res['total'], 'pages' => $res['pages'], 'page' => 1],
        ]), 200, ['Cache-Control: no-cache, must-revalidate']);
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
        ]), 200, ['Cache-Control: no-cache, must-revalidate']);
    }

    public static function about(Request $req): void
    {
        $latest = Albums::listPublic(1, 1)['albums'][0] ?? null;
        Response::html(View::page('about', [
            'page' => 'about', 'title' => Settings::get('about_title'),
            'aboutCover' => $latest && $latest['cover'] ? Photos::toPublic($latest['cover']) : null,
            'aboutCaption' => $latest ? $latest['title'] . ' · ' . Util::dateFr($latest['event_date']) : '',
        ]), 200, ['Cache-Control: no-cache, must-revalidate']);
    }

    // ─── Contact ─────────────────────────────────────────────────────────
    public static function contact(Request $req): void
    {
        \App\Auth::start();
        Response::html(View::page('contact', ['page' => 'contact', 'title' => 'Contact', 'token' => \App\Auth::csrf(), 'sent' => null, 'error' => null, 'old' => []]), 200, ['Cache-Control: no-store']);
    }

    public static function contactSend(Request $req): void
    {
        \App\Auth::start();
        $in = array_map(fn($v) => is_string($v) ? trim($v) : '', $_POST);
        $render = fn(?string $error, ?string $sent = null) => Response::html(View::page('contact', ['page' => 'contact', 'title' => 'Contact', 'token' => \App\Auth::csrf(), 'sent' => $sent, 'error' => $error, 'old' => $in]), $error ? 422 : 200, ['Cache-Control: no-store']);

        // Anti-spam : jeton de session, champ piège, délai minimal, limite par IP
        if (!hash_equals(\App\Auth::csrf(), $in['_token'] ?? '')) { $render('Formulaire expiré, réessayez.'); return; }
        if (($in['website'] ?? '') !== '' || time() - (int) ($in['_t'] ?? 0) < 3) { $render('Envoi refusé. Réessayez dans un instant.'); return; }
        $ip = $req->ip();
        \App\Db::exec('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 3600)]);
        if ((int) \App\Db::value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?', ['contact:' . $ip, date('Y-m-d H:i:s', time() - 3600)]) >= 5) { $render('Trop de messages envoyés. Réessayez dans une heure.'); return; }

        $name = mb_substr($in['name'] ?? '', 0, 120); $email = mb_substr($in['email'] ?? '', 0, 190);
        $subject = mb_substr($in['subject'] ?? '', 0, 150) ?: 'Message depuis caphotographies.fr'; $message = mb_substr($in['message'] ?? '', 0, 5000);
        if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $render('Merci de renseigner votre nom, un e-mail valide et un message.'); return; }
        if (preg_match('/[\r\n]/', $name . $email . $subject)) { $render('Caractères non autorisés.'); return; }

        $to = Settings::get('contact_email') ?: 'c.aphotographies@gmail.com';
        $host = parse_url(Config::appUrl(), PHP_URL_HOST) ?: 'caphotographies.fr';
        $body = "Nom : $name\nE-mail : $email\nSujet : $subject\n\n$message\n\n—\nEnvoyé depuis https://$host/contact · IP $ip · " . date('d/m/Y H:i');
        $headers = ['From: ' . Settings::get('site_name') . " <noreply@$host>", "Reply-To: $name <$email>", 'Content-Type: text/plain; charset=UTF-8', 'X-Mailer: caphotographies-contact'];
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode("[Contact] $subject") . '?=', $body, implode("\r\n", $headers), "-f noreply@$host");
        if (!$ok) { error_log('Contact : envoi mail() échoué'); { $render('Envoi impossible pour le moment. Écrivez directement à ' . $to . '.'); return; } }
        \App\Db::insert('login_attempts', ['ip' => 'contact:' . $ip, 'attempted_at' => \App\Db::now()]);
        $render(null, $name);
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
        $xml .= "<url><loc>$base/</loc></url><url><loc>$base/albums</loc></url><url><loc>$base/a-propos</loc></url><url><loc>$base/contact</loc></url>";
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
