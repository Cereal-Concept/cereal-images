<?php

declare(strict_types=1);

namespace CerealImages;

final class Uploads
{
    public static function dir(): string
    {
        return untrailingslashit(wp_upload_dir(null, false)['basedir']);
    }

    public static function url(): string
    {
        return untrailingslashit(wp_upload_dir(null, false)['baseurl']);
    }

    /** Chemin relatif au dossier des uploads, ou null si le fichier est ailleurs. */
    public static function relative(string $file): ?string
    {
        $prefix = self::dir() . '/';

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : null;
    }
}
