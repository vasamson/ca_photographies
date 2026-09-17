<?php
declare(strict_types=1);

namespace App;

/** Accès typé à la configuration issue du .env. */
final class Config
{
    public static function isLocal(): bool { return Env::get('APP_ENV', 'local') !== 'production'; }
    public static function appUrl(): string { return rtrim((string) Env::get('APP_URL', ''), '/'); }
    public static function appKey(): string
    {
        $k = (string) Env::get('APP_KEY', '');
        if ($k === '' || $k === 'changez-moi') {
            if (!self::isLocal()) throw new \RuntimeException('APP_KEY doit être définie en production.');
            $k = 'dev-key-non-securisee';
        }
        return $k;
    }

    /** Résout un chemin relatif à la racine du projet (les chemins absolus sont renvoyés tels quels). */
    public static function path(string $p): string
    {
        return str_starts_with($p, '/') ? $p : BASE_PATH . '/' . ltrim($p, './');
    }

    public static function photosRoot(): string { return rtrim(self::path((string) Env::get('PHOTOS_ROOT', 'storage/media/photos')), '/'); }
    public static function photosUrl(): string { return rtrim((string) Env::get('PHOTOS_URL', '/photos'), '/'); }
    public static function legacyRoot(): string { return rtrim(self::path((string) Env::get('LEGACY_ROOT', 'storage/media/legacy')), '/'); }
    public static function legacyUrl(): string { return rtrim((string) Env::get('LEGACY_URL', ''), '/'); }
    public static function uploadTmp(): string { return rtrim(self::path((string) Env::get('UPLOAD_TMP', 'storage/uploads')), '/'); }
    public static function chunkSize(): int { return max(262144, Env::int('UPLOAD_CHUNK_SIZE', 4 * 1024 * 1024)); }
    public static function trashDir(): string { return trim((string) Env::get('TRASH_DIR', '_corbeille'), '/'); }

    public static function imageDriver(): string { return (string) Env::get('IMAGE_DRIVER', 'auto'); }
    /** @return int[] */
    public static function imageSizes(): array
    {
        $s = array_filter(array_map('intval', explode(',', (string) Env::get('IMAGE_SIZES', '800,1600,2400'))));
        sort($s);
        return array_values($s);
    }
    public static function thumbWidth(): int { return Env::int('IMAGE_THUMB_WIDTH', 640); }
    public static function imageQuality(): int { return min(100, max(40, Env::int('IMAGE_QUALITY', 82))); }
    public static function imageFormat(): string { return Env::get('IMAGE_FORMAT', 'webp') === 'jpeg' ? 'jpeg' : 'webp'; }

    public static function wpApiUrl(): string { return rtrim((string) Env::get('WP_API_URL', 'https://caphotographies.fr/wp-json/wp/v2'), '/'); }
    public static function backupDir(): string { return rtrim(self::path((string) Env::get('BACKUP_DIR', 'storage/backups')), '/'); }
    public static function backupKeep(): int { return Env::int('BACKUP_KEEP', 14); }
}
