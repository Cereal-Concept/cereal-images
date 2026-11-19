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
        return count(array_filter(
            iterator_to_array(self::uploadsFiles(), false),
            static fn (string $file) => preg_match(self::SIDECAR, $file) && self::delete($file),
        ));
    }

    /**
     * Sidecars laissés par ShortPixel, nommés par remplacement d'extension (`photo.avif` à côté de `photo.jpg`) :
     * plus servis une fois la bascule faite.
     *
     * @return list<string>
     */
    public static function shortPixel(): array
    {
        $found = array_values(array_filter(
            iterator_to_array(self::uploadsFiles(), false),
            self::isShortPixelSidecar(...),
        ));
        sort($found);

        return $found;
    }

    public static function deleteShortPixel(string $file): bool
    {
        return self::isShortPixelSidecar($file) && self::delete($file);
    }

    private static function isShortPixelSidecar(string $file): bool
    {
        if (! preg_match('/^(.+)\.(avif|webp)$/i', $file, $match) || preg_match(self::IMAGE, $match[1])) {
            return false;
        }

        foreach (['jpg', 'jpeg', 'png', 'JPG', 'JPEG', 'PNG'] as $extension) {
            if (is_file("{$match[1]}.{$extension}")) {
                return ! Attachment::isAttachmentFile($file);
            }
        }

        return false;
    }

    /** @return \Generator<int, string> */
    private static function uploadsFiles(): \Generator
    {
        $root = Uploads::dir();

        if (! is_dir($root)) {
            return;
        }

        $directories = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            static fn (SplFileInfo $item) => ! $item->isDir()
            || ! in_array($item->getFilename(), [Backup::DIRECTORY, Backup::SHORTPIXEL_DIRECTORY], true),
        );

        foreach (new RecursiveIteratorIterator($directories) as $item) {
            if (! $item instanceof SplFileInfo || ! $item->isFile()) {
                continue;
            }

            yield $item->getPathname();
        }
    }

    private static function delete(string $sidecar): bool
    {
        return is_file($sidecar) && ! Attachment::isAttachmentFile($sidecar) && unlink($sidecar);
    }
}
