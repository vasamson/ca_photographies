<?php
declare(strict_types=1);

namespace App;

final class Settings
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        'site_name'    => 'CA Photographies',
        'tagline'      => 'Photographie de cyclisme',
        'about_title'  => 'À propos',
        'about_text'   => "CA Photographies couvre les courses cyclistes de l'Ouest : chaque épreuve, développée en album, à feuilleter en plein écran.",
        'contact_email'=> '',
        'instagram'    => '',
        'facebook'     => '',
        'home_intro'   => 'Chaque course, développée en album. À feuilleter en plein écran.',
        'per_page'     => '24',
        'photos_per_page' => '120',
    ];

    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;
        $rows = Db::all('SELECT `key`, `value` FROM settings');
        $db = array_column($rows, 'value', 'key');
        return self::$cache = array_merge(self::DEFAULTS, $db);
    }

    public static function get(string $k): string { return (string) (self::all()[$k] ?? ''); }

    public static function set(array $values): void
    {
        foreach ($values as $k => $v) {
            if (!array_key_exists($k, self::DEFAULTS)) continue;
            $v = trim((string) $v);
            if (Db::driver() === 'sqlite') Db::exec('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`', [$k, $v]);
            else Db::exec('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$k, $v]);
        }
        self::$cache = null;
    }
}
