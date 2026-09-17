<?php
declare(strict_types=1);

namespace App;

final class Util
{
    public static function slugify(string $s, int $max = 80): string
    {
        // Accents → ASCII (intl si dispo, sinon iconv, sinon table de secours)
        if (class_exists('\Normalizer')) { $s = \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s; $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s; }
        elseif (function_exists('iconv')) { $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s; }
        else { $s = strtr($s, ['à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','œ'=>'oe','æ'=>'ae','À'=>'a','É'=>'e','È'=>'e','Ç'=>'c']); }
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        $s = trim($s, '-');
        return substr($s, 0, $max) ?: 'album';
    }

    /** Nom de fichier sûr : ASCII, sans chemin, extension conservée en minuscules. */
    public static function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = self::slugify(pathinfo($name, PATHINFO_FILENAME), 120);
        $ext = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? ($ext === 'jpeg' ? 'jpg' : $ext) : 'jpg';
        return $base . '.' . $ext;
    }

    public static function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    public static function dateFr(?string $iso): string
    {
        if (!$iso) return '';
        $t = strtotime($iso);
        if (!$t) return $iso;
        $months = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
        return (int) date('j', $t) . ' ' . $months[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
    }

    public static function humanSize(int|float $bytes): string
    {
        $u = ['o', 'Ko', 'Mo', 'Go', 'To']; $i = 0;
        while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
        return ($i ? number_format($bytes, 1, ',', ' ') : (string) $bytes) . ' ' . $u[$i];
    }

    public static function excerpt(?string $s, int $len = 160): string
    {
        $s = trim(preg_replace('/\s+/', ' ', strip_tags((string) $s)) ?? '');
        return mb_strlen($s) > $len ? rtrim(mb_substr($s, 0, $len - 1)) . '…' : $s;
    }
}
