<?php

declare(strict_types=1);

namespace CerealImages\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase as BaseTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

abstract class TestCase extends BaseTestCase
{
    protected string $uploads;

    protected function setUp(): void
    {
        $this->uploads = sys_get_temp_dir() . '/cereal-images-tests-' . bin2hex(random_bytes(4));
        mkdir($this->uploads . '/2026/06', 0o777, true);
        Wp::reset($this->uploads);
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->uploads, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->uploads);
    }

    /** Photo synthétique assez détaillée pour que la compression ait un effet mesurable. */
    protected function jpeg(string $relative, int $width = 400, int $height = 300, int $quality = 92): string
    {
        $path = "{$this->uploads}/{$relative}";
        wp_mkdir_p(dirname($path));
        $image = imagecreatetruecolor($width, $height);

        for ($x = 0; $x < $width; $x += 2) {
            for ($y = 0; $y < $height; $y += 2) {
                $color = imagecolorallocate($image, ($x * 7) % 256, ($y * 3) % 256, ($x + $y) % 256);
                imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, (int) $color);
            }
        }

        imagejpeg($image, $path, $quality);

        return $path;
    }

    /** PNG transparent : fond totalement transparent, zone opaque détaillée au centre. */
    protected function png(string $relative): string
    {
        $path = "{$this->uploads}/{$relative}";
        $image = imagecreatetruecolor(400, 300);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 255, 0, 0, 127));

        // Bruit à graine fixe : un motif régulier se compresse mieux en PNG qu'en WebP, une photo non.
        mt_srand(42);

        for ($x = 50; $x < 350; $x++) {
            for ($y = 50; $y < 250; $y++) {
                $color = imagecolorallocatealpha($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), 0);
                imagesetpixel($image, $x, $y, (int) $color);
            }
        }

        imagepng($image, $path);

        return $path;
    }

    /** @param list<string> $sizes */
    protected function attachment(int $id, string $file, array $sizes = [], ?string $original = null): void
    {
        Wp::$attachments[$id] = ['file' => $file, 'sizes' => array_map('basename', $sizes)];

        if ($original !== null) {
            Wp::$attachments[$id]['original'] = $original;
        }
    }

    protected function shortPixelPath(string $relative): string
    {
        return "{$this->uploads}/ShortpixelBackups/app/uploads/{$relative}";
    }

    protected function backupPath(string $relative): string
    {
        return "{$this->uploads}/cereal-images-backup/{$relative}";
    }

    /** @return list<string> */
    protected function temporaryFiles(): array
    {
        $found = [];

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->uploads, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (! $file instanceof SplFileInfo || ! preg_match('/\.tmp$|cereal-tmp/', $file->getFilename())) {
                continue;
            }

            $found[] = $file->getPathname();
        }

        return $found;
    }
}
