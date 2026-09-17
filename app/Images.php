<?php
declare(strict_types=1);

namespace App;

/**
 * Génération des versions web (miniature + largeurs configurées) à partir d'un original.
 * Imagick si disponible (moins de mémoire, meilleure qualité), sinon GD.
 * Chaque taille est produite à partir de la précédente (plus grande) pour limiter la mémoire.
 */
final class Images
{
    public static function driver(): string
    {
        $want = Config::imageDriver();
        if ($want === 'imagick' || ($want === 'auto' && class_exists('Imagick'))) return 'imagick';
        if (!function_exists('imagecreatetruecolor')) throw new \RuntimeException('Ni Imagick ni GD ne sont disponibles.');
        return 'gd';
    }

    public static function supportsWebp(): bool
    {
        return self::driver() === 'imagick'
            ? in_array('WEBP', \Imagick::queryFormats('WEBP'), true)
            : function_exists('imagewebp');
    }

    /** @return array{width:int,height:int,mime:string} */
    public static function probe(string $abs): array
    {
        $i = @getimagesize($abs);
        if (!$i || !in_array($i['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \RuntimeException('Fichier image invalide (JPEG, PNG ou WebP attendu).');
        }
        return ['width' => $i[0], 'height' => $i[1], 'mime' => $i['mime']];
    }

    /**
     * Liste des variantes à produire : ['thumb' => 640, 'w800' => 800, …], triées de la plus grande à la plus petite.
     * @return array<string,int>
     */
    public static function plan(int $originalWidth): array
    {
        $sizes = [];
        foreach (Config::imageSizes() as $w) if ($w < $originalWidth) $sizes["w$w"] = $w;
        $sizes['thumb'] = min(Config::thumbWidth(), $originalWidth);
        arsort($sizes);
        return $sizes;
    }

    /**
     * Génère toutes les variantes de $originalAbs dans $destDirAbs.
     * @return array<string, array{path:string,width:int,height:int,filesize:int}>  clé = variante, path = nom de fichier
     */
    public static function generate(string $originalAbs, string $destDirAbs, string $baseName): array
    {
        $info = self::probe($originalAbs);
        $plan = self::plan($info['width']);
        $ext = Config::imageFormat() === 'webp' && self::supportsWebp() ? 'webp' : 'jpg';
        Storage::ensureDir($destDirAbs);
        self::raiseMemory($info['width'], $info['height']);

        return self::driver() === 'imagick'
            ? self::withImagick($originalAbs, $destDirAbs, $baseName, $plan, $ext)
            : self::withGd($originalAbs, $destDirAbs, $baseName, $plan, $ext, $info['mime']);
    }

    private static function raiseMemory(int $w, int $h): void
    {
        $need = (int) ($w * $h * 4 * 2.5) + 64 * 1024 * 1024; // original + copie + marge
        $cur = self::bytes((string) ini_get('memory_limit'));
        if ($cur > 0 && $cur < $need) @ini_set('memory_limit', (string) $need);
    }

    private static function bytes(string $v): int
    {
        $v = trim($v); if ($v === '' || $v === '-1') return -1;
        $n = (int) $v; $u = strtolower(substr($v, -1));
        return $n * ($u === 'g' ? 1073741824 : ($u === 'm' ? 1048576 : ($u === 'k' ? 1024 : 1)));
    }

    private static function withImagick(string $src, string $dir, string $base, array $plan, string $ext): array
    {
        $im = new \Imagick($src);
        $im->autoOrient();
        $im->setImageColorspace(\Imagick::COLORSPACE_SRGB);
        $im->stripImage();
        $out = [];
        foreach ($plan as $variant => $w) {
            $im->resizeImage($w, 0, \Imagick::FILTER_LANCZOS, 1);
            $im->setImageFormat($ext === 'webp' ? 'webp' : 'jpeg');
            $im->setImageCompressionQuality(Config::imageQuality());
            if ($ext === 'jpg') { $im->setInterlaceScheme(\Imagick::INTERLACE_PLANE); }
            $file = "$base-$variant.$ext";
            $im->writeImage("$dir/$file");
            $out[$variant] = ['path' => $file, 'width' => $im->getImageWidth(), 'height' => $im->getImageHeight(), 'filesize' => (int) filesize("$dir/$file")];
        }
        $im->clear();
        return $out;
    }

    private static function withGd(string $src, string $dir, string $base, array $plan, string $ext, string $mime): array
    {
        $img = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($src),
            'image/png'  => imagecreatefrompng($src),
            'image/webp' => imagecreatefromwebp($src),
        };
        if (!$img) throw new \RuntimeException('Lecture de l\'image impossible (GD).');
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) $img = self::autoOrientGd($img, $src);
        $out = [];
        foreach ($plan as $variant => $w) {
            $h = (int) round(imagesy($img) * $w / imagesx($img));
            $dst = imagecreatetruecolor($w, $h);
            imagealphablending($dst, false); imagesavealpha($dst, true);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img));
            imagedestroy($img); $img = $dst;
            $file = "$base-$variant.$ext";
            if ($ext === 'webp') imagewebp($img, "$dir/$file", Config::imageQuality());
            else { imageinterlace($img, true); imagejpeg($img, "$dir/$file", Config::imageQuality()); }
            $out[$variant] = ['path' => $file, 'width' => $w, 'height' => $h, 'filesize' => (int) filesize("$dir/$file")];
        }
        imagedestroy($img);
        return $out;
    }

    private static function autoOrientGd(\GdImage $img, string $src): \GdImage
    {
        $exif = @exif_read_data($src);
        $o = (int) ($exif['Orientation'] ?? 1);
        if ($o === 3) $img = imagerotate($img, 180, 0);
        elseif ($o === 6) $img = imagerotate($img, -90, 0);
        elseif ($o === 8) $img = imagerotate($img, 90, 0);
        return $img;
    }
}
