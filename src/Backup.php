<?php

declare(strict_types=1);

namespace CerealImages;

final class Backup
{
    private const DIRECTORY = 'cereal-images-backup';

    private const SHORTPIXEL_DIRECTORY = 'ShortpixelBackups';

    public static function path(string $file): ?string
    {
        $relative = self::relative($file);

        return $relative === null ? null : self::uploadsDir().'/'.self::DIRECTORY.'/'.$relative;
    }

    /**
     * ShortPixel reproduit le chemin d'URL des uploads sous son dossier : `ShortpixelBackups/app/uploads/2026/06/photo.jpg` sur Bedrock.
     */
    public static function shortPixelPath(string $file): ?string
    {
        $relative = self::relative($file);
        $urlPath = trim((string) parse_url(wp_upload_dir(null, false)['baseurl'], PHP_URL_PATH), '/');

        return $relative === null ? null : self::shortPixelRoot()."/{$urlPath}/{$relative}";
    }

    public static function shortPixelRoot(): string
    {
        return self::uploadsDir().'/'.self::SHORTPIXEL_DIRECTORY;
    }

    /**
     * Une sauvegarde existante n'est jamais écrasée : c'est elle qui fait foi.
     *
     * @return 'existing'|'shortpixel'|'created'|'error'
     */
    public static function ensure(string $file): string
    {
        $backup = self::path($file);

        if ($backup === null) {
            return 'error';
        }

        if (is_file($backup)) {
            return 'existing';
        }

        if (! wp_mkdir_p(dirname($backup))) {
            return 'error';
        }

        // Déplacée et non copiée : le dossier ShortPixel ne doit pas rester seul garant des originaux, et le quota est limité.
        $shortPixel = self::shortPixelPath($file);

        if ($shortPixel && is_file($shortPixel) && self::sameDimensions($file, $shortPixel) && rename($shortPixel, $backup)) {
            return 'shortpixel';
        }

        return self::copy($file, $backup) ? 'created' : 'error';
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
            @unlink($tmp);

            return false;
        }

        return rename($tmp, $to);
    }

    private static function relative(string $file): ?string
    {
        $prefix = self::uploadsDir().'/';

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : null;
    }

    private static function uploadsDir(): string
    {
        return untrailingslashit(wp_upload_dir(null, false)['basedir']);
    }

    private static function sameDimensions(string $a, string $b): bool
    {
        $sizeA = @getimagesize($a);
        $sizeB = @getimagesize($b);

        return $sizeA && $sizeB && $sizeA[0] === $sizeB[0] && $sizeA[1] === $sizeB[1];
    }
}
