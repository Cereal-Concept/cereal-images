<?php

declare(strict_types=1);

namespace CerealImages;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Suppression des versions AVIF/WebP. Une pièce jointe WebP peut s'appeler `photo.jpg.webp`, comme un sidecar :
 * un fichier qui appartient à une pièce jointe n'est jamais supprimé.
 */
final class Sidecars
{
    private const IMAGE = '/\.(jpe?g|png)$/i';

    private const SIDECAR = '/\.(jpe?g|png)\.(avif|webp)$/i';

    /** Supprime les sidecars d'une image des uploads ; renvoie le nombre de fichiers supprimés. */
    public static function remove(string $file): int
    {
        if (! preg_match(self::IMAGE, $file) || Uploads::relative($file) === null) {
            return 0;
        }

        return count(array_filter(Processor::FORMATS, static fn (string $format) => self::delete("{$file}.{$format}")));
    }

    /** Parcourt tous les uploads, hors dossiers de sauvegarde, y compris les sidecars orphelins. */
    public static function removeAll(): int
    {
        $root = Uploads::dir();

        if (! is_dir($root)) {
            return 0;
        }

        $directories = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            static fn (SplFileInfo $item) => ! $item->isDir()
            || ! in_array($item->getFilename(), [Backup::DIRECTORY, Backup::SHORTPIXEL_DIRECTORY], true),
        );

        $removed = 0;

        foreach (new RecursiveIteratorIterator($directories) as $item) {
            if (! $item instanceof SplFileInfo || ! preg_match(self::SIDECAR, $item->getFilename())) {
                continue;
            }

            $removed += (int) self::delete($item->getPathname());
        }

        return $removed;
    }

    private static function delete(string $sidecar): bool
    {
        return is_file($sidecar) && ! Attachment::isAttachmentFile($sidecar) && unlink($sidecar);
    }
}
