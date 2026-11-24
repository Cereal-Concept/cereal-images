<?php

declare(strict_types=1);

namespace CerealImages;

final class Backup
{
    public const DIRECTORY = 'cereal-images-backup';

    public const SHORTPIXEL_DIRECTORY = 'ShortpixelBackups';

    public static function path(string $file): ?string
    {
        $relative = Uploads::relative($file);

        return $relative === null ? null : Uploads::dir() . '/' . self::DIRECTORY . '/' . $relative;
    }

    /**
     * ShortPixel reproduit le chemin d'URL des uploads sous son dossier : `ShortpixelBackups/app/uploads/2026/06/photo.jpg` sur Bedrock.
     */
    public static function shortPixelPath(string $file): ?string
    {
        $relative = Uploads::relative($file);
        $urlPath = trim((string) parse_url(Uploads::url(), PHP_URL_PATH), '/');

        return $relative === null ? null : self::shortPixelRoot() . "/{$urlPath}/{$relative}";
    }

    public static function shortPixelRoot(): string
    {
        return Uploads::dir() . '/' . self::SHORTPIXEL_DIRECTORY;
    }

    /**
     * Une sauvegarde existante n'est jamais écrasée : c'est elle qui fait foi.
     * Sans `$copy`, seule la sauvegarde ShortPixel est reprise (déplacement, sans coût disque) : inutile de copier
     * un fichier qui ne sera pas modifié.
     *
     * @return 'existing'|'shortpixel'|'created'|'skipped'|'error'
     */
    public static function ensure(string $file, bool $copy = true): string
    {
        $backup = self::path($file);

        if ($backup === null) {
            return 'error';
        }

        if (is_file($backup)) {
            return 'existing';
        }

        if (self::adoptShortPixel($file)) {
            return 'shortpixel';
        }

        if (! $copy) {
            return 'skipped';
        }

        return wp_mkdir_p(dirname($backup)) && self::copy($file, $backup) ? 'created' : 'error';
    }

    /**
     * Déplace la sauvegarde ShortPixel du fichier dans notre dossier, si elle existe et a les mêmes dimensions.
     * Déplacée et non copiée : le dossier ShortPixel ne doit pas rester seul garant des originaux, et le quota est limité.
     */
    public static function adoptShortPixel(string $file): bool
    {
        return self::moveShortPixel($file, self::sameDimensions(...));
    }

    /**
     * Pour l'original non réduit, largeur et hauteur peuvent être inversées : ShortPixel applique l'orientation EXIF
     * des photos de téléphone en compressant. Jamais pour un fichier converti : GD ignore l'EXIF, le sidecar serait tourné.
     */
    public static function adoptShortPixelOriginal(string $file): bool
    {
        return self::moveShortPixel($file, self::sameOrRotatedDimensions(...));
    }

    /** @param callable(string, string): bool $matches */
    private static function moveShortPixel(string $file, callable $matches): bool
    {
        $backup = self::path($file);
        $shortPixel = self::shortPixelPath($file);

        if ($backup === null || $shortPixel === null || is_file($backup) || ! is_file($shortPixel)) {
            return false;
        }

        return $matches($file, $shortPixel) && wp_mkdir_p(dirname($backup)) && rename($shortPixel, $backup);
    }

    public static function restore(string $file): bool
    {
        $backup = self::path($file);

        return $backup !== null && is_file($backup) && self::copy($backup, $file);
    }

    /** Vrai si le fichier n'a pas été modifié depuis sa sauvegarde, donc pas encore optimisé (par nous ou par ShortPixel). */
    public static function isPristine(string $file): bool
    {
        $backup = self::path($file);

        if ($backup === null || ! is_file($backup)) {
            return false;
        }

        clearstatcache(true, $file);

        return filesize($file) === filesize($backup) && md5_file($file) === md5_file($backup);
    }

    public static function copy(string $from, string $to): bool
    {
        $tmp = "{$to}.tmp";

        if (! copy($from, $tmp)) {
            Files::remove($tmp);

            return false;
        }

        return rename($tmp, $to);
    }

    private static function sameDimensions(string $a, string $b): bool
    {
        $sizeA = wp_getimagesize($a);
        $sizeB = wp_getimagesize($b);

        return $sizeA && $sizeB && $sizeA[0] === $sizeB[0] && $sizeA[1] === $sizeB[1];
    }

    private static function sameOrRotatedDimensions(string $a, string $b): bool
    {
        $sizeA = wp_getimagesize($a);
        $sizeB = wp_getimagesize($b);

        return self::sameDimensions($a, $b) || $sizeA && $sizeB && $sizeA[0] === $sizeB[1] && $sizeA[1] === $sizeB[0];
    }
}
