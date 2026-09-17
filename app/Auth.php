<?php
declare(strict_types=1);

namespace App;

/**
 * Authentification administrateur : sessions PHP, hash Argon2id/bcrypt,
 * jeton CSRF, limitation des tentatives par IP.
 */
final class Auth
{
    private const MAX_ATTEMPTS = 8;      // par IP
    private const WINDOW_MIN   = 15;     // minutes

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $secure = !Config::isLocal();
        session_name('ca_admin');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) (12 * 3600));
        session_start();
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }

    public static function user(): ?array
    {
        self::start();
        if (empty($_SESSION['uid'])) return null;
        static $cache = null;
        return $cache ??= Db::one('SELECT id, username, role, created_at, last_login_at FROM users WHERE id = ?', [$_SESSION['uid']]);
    }

    public static function csrf(): string { self::start(); return $_SESSION['csrf']; }

    /** Exige une session admin valide + jeton CSRF sur les méthodes mutantes. */
    public static function require(Request $req): array
    {
        $u = self::user();
        if (!$u) throw new HttpException(401, 'Connexion requise');
        if (!in_array($req->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $token = $req->header('X-CSRF-Token') ?? '';
            if (!hash_equals(self::csrf(), $token)) throw new HttpException(419, 'Jeton de sécurité invalide, recharge la page.');
        }
        return $u;
    }

    public static function attempt(string $username, string $password, string $ip): array
    {
        self::start();
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_MIN * 60);
        Db::exec('DELETE FROM login_attempts WHERE attempted_at < ?', [$since]);
        $n = (int) Db::value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?', [$ip, $since]);
        if ($n >= self::MAX_ATTEMPTS) throw new HttpException(429, 'Trop de tentatives. Réessaie dans ' . self::WINDOW_MIN . ' minutes.');

        $u = Db::one('SELECT * FROM users WHERE username = ?', [trim($username)]);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            Db::insert('login_attempts', ['ip' => $ip, 'attempted_at' => Db::now()]);
            usleep(300000); // ralentit le brute force
            throw new HttpException(401, 'Identifiant ou mot de passe incorrect.');
        }
        if (password_needs_rehash($u['password_hash'], self::algo())) {
            Db::update('users', ['password_hash' => self::hash($password)], 'id = :id', ['id' => $u['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        Db::update('users', ['last_login_at' => Db::now()], 'id = :id', ['id' => $u['id']]);
        Db::exec('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
        unset($u['password_hash']);
        return $u;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function algo(): string { return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT; }
    public static function hash(string $password): string { return password_hash($password, self::algo()); }

    public static function validatePassword(string $p): void
    {
        if (strlen($p) < 10) throw new HttpException(422, 'Le mot de passe doit faire au moins 10 caractères.');
    }
}
