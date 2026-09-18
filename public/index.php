<?php
/**
 * Point d'entrée unique. Le DocumentRoot pointe sur ce dossier `public/` ;
 * tout le reste (app/, .env, storage/) est hors de portée du navigateur.
 */
declare(strict_types=1);

// Serveur de développement (php -S) : laisse-le servir lui-même les fichiers statiques de public/
if (PHP_SAPI === 'cli-server') {
    $static = __DIR__ . rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if ($static !== __DIR__ . '/index.php' && is_file($static)) return false;
}

// L'application vit à côté de public/ (dev, sous-domaine) ou dans ~/ca_photographies quand
// public/ est copié dans public_html à la bascule (voir docs/DEPLOIEMENT.md). CA_APP_DIR force le chemin.
$appDir = getenv('CA_APP_DIR') ?: (is_file(dirname(__DIR__) . '/app/bootstrap.php') ? dirname(__DIR__) : dirname(__DIR__) . '/ca_photographies');
require $appDir . '/app/bootstrap.php';

use App\{Config, HttpException, Request, Response, Router, Storage, Util, View};
use App\Controllers\{AdminController as Admin, PublicController as Pub};

$req = new Request();
$router = new Router();

// ─── Public ──────────────────────────────────────────────────────────────
$router->get('/', fn($r) => isset($_GET['p']) ? Pub::legacyShortlink($r) : Pub::home($r));
$router->get('/albums/{slug}', [Pub::class, 'album']);
$router->get('/a-propos', [Pub::class, 'about']);
$router->get('/sitemap.xml', [Pub::class, 'sitemap']);
$router->get('/robots.txt', [Pub::class, 'robots']);
$router->get('/api/albums', [Pub::class, 'apiAlbums']);
$router->get('/api/albums/{slug}', [Pub::class, 'apiAlbum']);

// ─── Administration ──────────────────────────────────────────────────────
$router->get('/admin', [Admin::class, 'page']);
$router->post('/api/admin/login', [Admin::class, 'login']);
$router->post('/api/admin/logout', [Admin::class, 'logout']);
$router->get('/api/admin/me', [Admin::class, 'me']);
$router->post('/api/admin/password', [Admin::class, 'password']);
$router->get('/api/admin/stats', [Admin::class, 'stats']);
$router->get('/api/admin/settings', [Admin::class, 'settingsGet']);
$router->patch('/api/admin/settings', [Admin::class, 'settingsSet']);
$router->get('/api/admin/albums', [Admin::class, 'albums']);
$router->post('/api/admin/albums', [Admin::class, 'albumCreate']);
$router->get('/api/admin/albums/{id}', [Admin::class, 'albumShow']);
$router->patch('/api/admin/albums/{id}', [Admin::class, 'albumUpdate']);
$router->delete('/api/admin/albums/{id}', [Admin::class, 'albumDelete']);
$router->post('/api/admin/albums/{id}/restore', [Admin::class, 'albumRestore']);
$router->get('/api/admin/albums/{id}/photos', [Admin::class, 'albumPhotos']);
$router->patch('/api/admin/albums/{id}/order', [Admin::class, 'albumReorder']);
$router->patch('/api/admin/albums/{id}/cover', [Admin::class, 'albumCover']);
$router->post('/api/admin/albums/{id}/uploads', [Admin::class, 'uploadInit']);
$router->put('/api/admin/albums/{id}/uploads/{uploadId}/chunks/{index}', [Admin::class, 'uploadChunk']);
$router->post('/api/admin/albums/{id}/uploads/{uploadId}/complete', [Admin::class, 'uploadComplete']);
$router->delete('/api/admin/albums/{id}/uploads/{uploadId}', [Admin::class, 'uploadCancel']);
$router->delete('/api/admin/photos/{id}', [Admin::class, 'photoDelete']);
$router->post('/api/admin/photos/{id}/restore', [Admin::class, 'photoRestore']);
$router->post('/api/admin/photos/{id}/regenerate', [Admin::class, 'photoRegenerate']);

// ─── Anciennes URLs WordPress (/<slug>/) ─────────────────────────────────
$router->get('/{slug}', [Pub::class, 'legacy']);

try {
    // En local, sert les photos depuis storage/ (en production LiteSpeed les sert directement)
    if (Config::isLocal()) {
        foreach (['photos' => Config::photosUrl(), 'legacy' => Config::legacyUrl()] as $storage => $base) {
            if ($base !== '' && str_starts_with($base, '/') && str_starts_with($req->path, $base . '/')) {
                $abs = Storage::abs($storage, substr($req->path, strlen($base) + 1));
                if (is_file($abs)) Response::file($abs);
            }
        }
    }
    $router->dispatch($req);
} catch (HttpException $e) {
    if ($req->wantsJson()) Response::json(['error' => $e->getMessage()] + $e->extra, $e->status);
    Response::html(View::page('error', ['page' => 'error', 'title' => 'Erreur ' . $e->status, 'status' => $e->status, 'message' => $e->getMessage(), 'noindex' => true]), $e->status);
} catch (\Throwable $e) {
    error_log((string) $e);
    $msg = Config::isLocal() ? $e->getMessage() . "\n" . $e->getTraceAsString() : 'Une erreur interne est survenue.';
    if ($req->wantsJson()) Response::json(['error' => $msg], 500);
    Response::html(View::page('error', ['page' => 'error', 'title' => 'Erreur', 'status' => 500, 'message' => $msg, 'noindex' => true]), 500);
}
