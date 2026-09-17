<?php
declare(strict_types=1);

namespace App;

/**
 * Accès au stockage des photos sur le disque d'EX2.
 * Deux racines : `photos` (nouvelle structure propre) et `legacy` (wp-content/uploads, lecture seule).
 * Tous les chemins en base sont RELATIFS à leur racine, jamais absolus.
 */
final class Storage
{
    public static function root(string $storage): string
    {
        return $storage === 'legacy' ? Config::legacyRoot() : Config::photosRoot();
    }

    public static function url(string $storage, string $relPath): string
    {
        $base = $storage === 'legacy' ? Config::legacyUrl() : Config::photosUrl();
        return $base . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($relPath, '/'))));
    }

    public static function abs(string $storage, string $relPath): string
    {
        $rel = self::clean($relPath);
        return self::root($storage) . '/' . $rel;
    }

    /** Refuse toute traversée de répertoire. */
    public static function clean(string $rel): string
    {
        $rel = str_replace('\\', '/', $rel);
        if (str_contains($rel, "\0") || preg_match('#(^|/)\.\.(/|$)#', $rel)) throw new \InvalidArgumentException('Chemin invalide');
        return ltrim($rel, '/');
    }

    public static function exists(string $storage, string $relPath): bool
    {
        try { return is_file(self::abs($storage, $relPath)); } catch (\Throwable) { return false; }
    }

    public static function ensureDir(string $abs): void
    {
        if (!is_dir($abs) && !mkdir($abs, 0755, true) && !is_dir($abs)) {
            throw new \RuntimeException("Impossible de créer le dossier $abs");
        }
    }

    /** Dossier d'un album pour les nouveaux uploads : photos/AAAA/slug */
    public static function albumDir(array $album): string
    {
        $year = $album['event_date'] ? substr($album['event_date'], 0, 4) : date('Y');
        return $year . '/' . $album['slug'];
    }

    /** Renvoie un chemin relatif libre dans $dir pour $filename (suffixe -2, -3… si collision). */
    public static function uniquePath(string $dir, string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $rel = "$dir/$filename"; $i = 1;
        while (self::exists('photos', $rel) || Db::value('SELECT id FROM photos WHERE storage = ? AND storage_path = ?', ['photos', $rel])) {
            $rel = "$dir/$base-" . (++$i) . ".$ext";
        }
        return $rel;
    }

    /**
     * Déplace un fichier de la racine `photos` vers la corbeille (jamais de suppression directe).
     * Les fichiers `legacy` (WordPress) ne sont JAMAIS touchés : règle absolue du projet.
     */
    public static function trash(string $storage, string $relPath): ?string
    {
        if ($storage !== 'photos') return null;
        $abs = self::abs('photos', $relPath);
        if (!is_file($abs)) return null;
        $dest = Config::trashDir() . '/' . date('Y-m-d') . '/' . self::clean($relPath);
        $destAbs = self::abs('photos', $dest);
        self::ensureDir(dirname($destAbs));
        if (!rename($abs, $destAbs)) throw new \RuntimeException("Impossible de déplacer $relPath vers la corbeille");
        return $dest;
    }

    /** Restaure un fichier depuis la corbeille. */
    public static function restore(string $trashRel, string $relPath): bool
    {
        $from = self::abs('photos', $trashRel);
        if (!is_file($from)) return false;
        $to = self::abs('photos', $relPath);
        self::ensureDir(dirname($to));
        return rename($from, $to);
    }

    public static function freeSpace(): ?int
    {
        $f = @disk_free_space(Config::photosRoot());
        return $f === false ? null : (int) $f;
    }
}
