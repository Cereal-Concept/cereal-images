<?php

declare(strict_types=1);

namespace CerealImages;

final class Files
{
    /** Supprime le fichier s'il existe ; sans effet sinon. */
    public static function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
