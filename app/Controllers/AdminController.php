<?php
declare(strict_types=1);

namespace App\Controllers;

use App\{Albums, Auth, Config, Db, HttpException, Images, Photos, Request, Response, Settings, Storage, Uploads, Util, View};

/** Espace administrateur : page /admin (application JS) + API /api/admin/*. */
final class AdminController
{
    // ─── Page ────────────────────────────────────────────────────────────
    public static function page(Request $req): void
    {
        Auth::start();
        $user = Auth::user();
        Response::html(View::page('admin', [
            'page' => 'admin', 'title' => 'Administration', 'noindex' => true,
            'boot' => ['csrf' => Auth::csrf(), 'user' => $user ? ['id' => $user['id'], 'username' => $user['username']] : null, 'chunk_size' => Config::chunkSize()],
        ]), 200, ['Cache-Control: no-store']);
    }

    // ─── Session ─────────────────────────────────────────────────────────
    public static function login(Request $req): void
    {
        Auth::start();
        if (!hash_equals(Auth::csrf(), $req->header('X-CSRF-Token') ?? '')) throw new HttpException(419, 'Jeton de sécurité invalide, recharge la page.');
        $u = Auth::attempt((string) $req->input('username', ''), (string) $req->input('password', ''), $req->ip());
        Response::json(['user' => ['id' => $u['id'], 'username' => $u['username']], 'csrf' => Auth::csrf()]);
    }

    public static function logout(Request $req): void { Auth::require($req); Auth::logout(); Response::json(['ok' => true]); }

    public static function me(Request $req): void
    {
        $u = Auth::require($req);
        Response::json(['user' => ['id' => $u['id'], 'username' => $u['username']], 'csrf' => Auth::csrf()]);
    }

    public static function password(Request $req): void
    {
        $u = Auth::require($req);
        $row = Db::one('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
        if (!password_verify((string) $req->input('current', ''), $row['password_hash'])) throw new HttpException(422, 'Mot de passe actuel incorrect.');
        $new = (string) $req->input('new', '');
        Auth::validatePassword($new);
        Db::update('users', ['password_hash' => Auth::hash($new)], 'id = :id', ['id' => $u['id']]);
        Response::json(['ok' => true]);
    }

    // ─── Tableau de bord ─────────────────────────────────────────────────
    public static function stats(Request $req): void
    {
        Auth::require($req);
        Response::json([
            'albums' => (int) Db::value('SELECT COUNT(*) FROM albums WHERE deleted_at IS NULL'),
            'published' => (int) Db::value('SELECT COUNT(*) FROM albums WHERE deleted_at IS NULL AND published = 1'),
            'drafts' => (int) Db::value('SELECT COUNT(*) FROM albums WHERE deleted_at IS NULL AND published = 0'),
            'photos' => (int) Db::value('SELECT COUNT(*) FROM photos WHERE deleted_at IS NULL'),
            'photos_legacy' => (int) Db::value("SELECT COUNT(*) FROM photos WHERE deleted_at IS NULL AND storage = 'legacy'"),
            'trash' => (int) Db::value('SELECT COUNT(*) FROM photos WHERE deleted_at IS NOT NULL'),
            'variants_pending' => (int) Db::value("SELECT COUNT(*) FROM photos WHERE deleted_at IS NULL AND variants_status <> 'ok'"),
            'bytes' => (int) Db::value("SELECT COALESCE(SUM(filesize), 0) FROM photos WHERE deleted_at IS NULL AND storage = 'photos'"),
            'free_space' => Storage::freeSpace(),
            'uploads_pending' => (int) Db::value("SELECT COUNT(*) FROM uploads WHERE status = 'pending'"),
            'image_driver' => Images::driver(), 'webp' => Images::supportsWebp(),
            'recent' => array_map([Albums::class, 'toAdmin'], Albums::listAdmin(1, 8)['albums']),
        ]);
    }

    // ─── Albums ──────────────────────────────────────────────────────────
    public static function albums(Request $req): void
    {
        Auth::require($req);
        $res = Albums::listAdmin($req->int('page', 1), $req->int('per', 40), trim((string) $req->query('q', '')), (string) $req->query('status', ''));
        Response::json(['albums' => array_map([Albums::class, 'toAdmin'], $res['albums']), 'total' => $res['total'], 'page' => $res['page'], 'pages' => $res['pages']]);
    }

    private static function album(int $id, bool $withDeleted = false): array
    {
        $a = Albums::find($id, $withDeleted);
        if (!$a) throw new HttpException(404, 'Album introuvable');
        return $a;
    }

    public static function albumCreate(Request $req): void
    {
        Auth::require($req);
        Response::json(['album' => Albums::toAdmin(Albums::create($req->json()))], 201);
    }

    public static function albumShow(Request $req, string $id): void
    {
        Auth::require($req);
        $a = self::album((int) $id, true);
        Response::json(['album' => Albums::toAdmin($a), 'uploads_pending' => Uploads::pending((int) $a['id'])]);
    }

    public static function albumUpdate(Request $req, string $id): void
    {
        Auth::require($req);
        Response::json(['album' => Albums::toAdmin(Albums::update(self::album((int) $id), $req->json()))]);
    }

    /** Suppression : le client doit renvoyer le slug en confirmation. */
    public static function albumDelete(Request $req, string $id): void
    {
        Auth::require($req);
        $a = self::album((int) $id);
        if ((string) $req->input('confirm', '') !== $a['slug']) throw new HttpException(422, 'Confirmation incorrecte : recopie exactement l\'identifiant de l\'album.');
        Albums::trash($a);
        Response::json(['ok' => true]);
    }

    public static function albumRestore(Request $req, string $id): void
    {
        Auth::require($req);
        $a = self::album((int) $id, true);
        if (!$a['deleted_at']) throw new HttpException(409, 'Cet album n\'est pas dans la corbeille.');
        Albums::restore($a);
        Response::json(['album' => Albums::toAdmin(Albums::find((int) $a['id']))]);
    }

    public static function albumPhotos(Request $req, string $id): void
    {
        Auth::require($req);
        $a = self::album((int) $id, true);
        $per = max(20, min(500, $req->int('per', 200))); $page = $req->int('page', 1);
        if ($req->query('trash') === '1') {
            $rows = Photos::hydrate(Db::all('SELECT * FROM photos WHERE album_id = ? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT 500', [$a['id']]));
            Response::json(['photos' => array_map([Photos::class, 'toAdmin'], $rows), 'total' => count($rows), 'page' => 1, 'pages' => 1]);
        }
        $total = Photos::count((int) $a['id']);
        Response::json(['photos' => array_map([Photos::class, 'toAdmin'], Photos::page((int) $a['id'], $page, $per)), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per)), 'per' => $per]);
    }

    public static function albumReorder(Request $req, string $id): void
    {
        Auth::require($req);
        $a = self::album((int) $id);
        $ids = $req->input('ids');
        if (!is_array($ids)) throw new HttpException(422, 'Liste d\'identifiants attendue.');
        Photos::reorder((int) $a['id'], $ids);
        Db::update('albums', ['updated_at' => Db::now()], 'id = :id', ['id' => $a['id']]);
        Response::json(['ok' => true]);
    }

    public static function albumCover(Request $req, string $id): void
    {
        Auth::require($req);
        $a = self::album((int) $id);
        Response::json(['album' => Albums::toAdmin(Albums::update($a, ['cover_photo_id' => (int) $req->input('photo_id', 0)]))]);
    }

    // ─── Photos ──────────────────────────────────────────────────────────
    public static function photoDelete(Request $req, string $id): void
    {
        Auth::require($req);
        $p = Photos::find((int) $id);
        if (!$p) throw new HttpException(404, 'Photo introuvable');
        Photos::trash($p);
        Response::json(['ok' => true, 'note' => $p['storage'] === 'legacy' ? 'Photo retirée de l\'album. Le fichier WordPress d\'origine n\'a pas été touché.' : 'Photo déplacée dans la corbeille.']);
    }

    public static function photoRestore(Request $req, string $id): void
    {
        Auth::require($req);
        $p = Photos::find((int) $id, true);
        if (!$p || !$p['deleted_at']) throw new HttpException(404, 'Photo introuvable dans la corbeille');
        Photos::restore($p);
        Response::json(['photo' => Photos::toAdmin(Photos::find((int) $p['id']))]);
    }

    public static function photoRegenerate(Request $req, string $id): void
    {
        Auth::require($req);
        $p = Photos::find((int) $id);
        if (!$p) throw new HttpException(404, 'Photo introuvable');
        if ($p['storage'] !== 'photos') throw new HttpException(409, 'Les photos WordPress utilisent les tailles déjà générées par WordPress.');
        Photos::generateVariants($p);
        Response::json(['photo' => Photos::toAdmin(Photos::find((int) $p['id']))]);
    }

    // ─── Upload par chunks ───────────────────────────────────────────────
    public static function uploadInit(Request $req, string $id): void
    {
        $u = Auth::require($req);
        $a = self::album((int) $id);
        Response::json(Uploads::init($a, (int) $u['id'], $req->json()));
    }

    public static function uploadChunk(Request $req, string $id, string $uploadId, string $index): void
    {
        Auth::require($req);
        $u = Uploads::find($uploadId, (int) $id);
        Response::json(Uploads::chunk($u, (int) $index));
    }

    public static function uploadComplete(Request $req, string $id, string $uploadId): void
    {
        Auth::require($req);
        $a = self::album((int) $id);
        $u = Uploads::find($uploadId, (int) $a['id']);
        ignore_user_abort(true);
        set_time_limit(300);
        Response::json(['photo' => Uploads::complete($u, $a)], 201);
    }

    public static function uploadCancel(Request $req, string $id, string $uploadId): void
    {
        Auth::require($req);
        $u = Uploads::find($uploadId, (int) $id);
        Uploads::cleanup($u['id']);
        Db::exec('DELETE FROM uploads WHERE id = ? AND status = ?', [$u['id'], 'pending']);
        Response::json(['ok' => true]);
    }

    // ─── Réglages ────────────────────────────────────────────────────────
    public static function settingsGet(Request $req): void { Auth::require($req); Response::json(['settings' => Settings::all()]); }

    public static function settingsSet(Request $req): void
    {
        Auth::require($req);
        Settings::set($req->json());
        Response::json(['settings' => Settings::all()]);
    }
}
