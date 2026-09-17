<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Connexion PDO unique (MySQL en production sur EX2, SQLite en local).
 * DATABASE_URL : mysql://user:pass@host:port/base  |  sqlite:chemin/vers/fichier.sqlite
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static string $driver = 'sqlite';

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $url = (string) Env::get('DATABASE_URL', 'sqlite:storage/database.sqlite');
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];

        if (str_starts_with($url, 'sqlite:')) {
            self::$driver = 'sqlite';
            $file = Config::path(substr($url, 7));
            if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
            self::$pdo = new PDO('sqlite:' . $file, null, null, $opts);
            self::$pdo->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
            return self::$pdo;
        }

        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'mysql') throw new \RuntimeException('DATABASE_URL invalide.');
        self::$driver = 'mysql';
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $p['host'] ?? 'localhost', $p['port'] ?? 3306, ltrim($p['path'] ?? '', '/'));
        self::$pdo = new PDO($dsn, urldecode($p['user'] ?? ''), urldecode($p['pass'] ?? ''), $opts);
        self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode = 'STRICT_ALL_TABLES'");
        return self::$pdo;
    }

    public static function driver(): string { self::pdo(); return self::$driver; }

    /** @return array<int, array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(',', $cols), implode(',', array_map(fn($c) => ':' . $c, $cols)));
        self::exec($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(', ', array_map(fn($c) => "$c = :set_$c", array_keys($data)));
        $bound = [];
        foreach ($data as $k => $v) $bound["set_$k"] = $v;
        return self::exec("UPDATE $table SET $set WHERE $where", $bound + $params);
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try { $r = $fn(); $pdo->commit(); return $r; }
        catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    public static function now(): string { return date('Y-m-d H:i:s'); }

    /** Applique le schéma correspondant au pilote (idempotent : CREATE TABLE IF NOT EXISTS). */
    public static function migrate(): void
    {
        $file = BASE_PATH . '/database/schema.' . self::driver() . '.sql';
        foreach (array_filter(array_map('trim', explode(';', file_get_contents($file)))) as $stmt) {
            try { self::pdo()->exec($stmt); }
            catch (\PDOException $e) {
                // ALTER TABLE … ADD CONSTRAINT n'est pas idempotent sous MySQL : on ignore « déjà existant »
                if (str_starts_with(strtoupper($stmt), 'ALTER') && preg_match('/1826|1061|1022|Duplicate/i', $e->getMessage())) continue;
                throw $e;
            }
        }
    }
}
