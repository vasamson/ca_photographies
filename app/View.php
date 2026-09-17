<?php
declare(strict_types=1);

namespace App;

/** Rendu des gabarits PHP de app/views. */
final class View
{
    public static function render(string $name, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        $e = fn(?string $s) => Util::e($s);
        ob_start();
        try { require APP_PATH . "/views/$name.php"; }
        catch (\Throwable $t) { ob_end_clean(); throw $t; }
        return (string) ob_get_clean();
    }

    /** Page complète = layout + contenu. */
    public static function page(string $name, array $data = []): string
    {
        $data['settings'] = Settings::all();
        $data['content'] = self::render($name, $data);
        return self::render('layout', $data);
    }
}
