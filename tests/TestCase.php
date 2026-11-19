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

    public const SRGB = [
        [0.4361, 0.2225, 0.0139],
        [0.3851, 0.7169, 0.0971],
        [0.1431, 0.0606, 0.7141],
    ];

    public const DISPLAY_P3 = [[0.5151, 0.2412, -0.0011], [0.2920, 0.6922, 0.0419], [0.1571, 0.0666, 0.7841]];

    /**
     * Profil ICC minimal : en-tête, puis les trois balises de primaires.
     *
     * @param list<list<float>> $primaries
     */
    protected static function iccProfile(array $primaries): string
    {
        // Taille, CMM, version, classe « écran », espaces RGB et XYZ, date, signature, puis illuminant D50 en 68.
        $header = pack('N3', 228, 0, 0x0210_0000) . 'mntrRGB XYZ ' . str_repeat("\0", 12) . 'acsp';
        $header = str_pad($header, 68, "\0") . pack('N3', 0xF6D6, 0x1_0000, 0xD32D);
        $header = str_pad($header, 128, "\0");
        $table = pack('N', 3);
        $data = '';

        foreach (['rXYZ', 'gXYZ', 'bXYZ'] as $index => $tag) {
            $table .= $tag . pack('N2', 168 + (20 * $index), 20);
            $data .= 'XYZ ' . "\0\0\0\0";

            foreach ($primaries[$index] as $value) {
                $data .= pack('N', (int) round($value * 65_536) & 0xFFFF_FFFF);
            }
        }

        return $header . $table . $data;
    }

    /** Ajoute un profil ICC (segment APP2 pour un JPEG, bloc iCCP pour un PNG) à un fichier existant. */
    protected static function embedProfile(string $path, string $profile): void
    {
        $content = (string) file_get_contents($path);

        if (str_starts_with($content, "\xFF\xD8")) {
            $segment = "ICC_PROFILE\0\x01\x01" . $profile;
            file_put_contents(
                $path,
                "\xFF\xD8\xFF\xE2" . pack('n', strlen($segment) + 2) . $segment . substr($content, 2),
            );

            return;
        }

        $chunk = "P3\0\0" . gzcompress($profile);
        $iccp = pack('N', strlen($chunk)) . 'iCCP' . $chunk . pack('N', crc32('iCCP' . $chunk));
        // Après la signature (8 octets) et le bloc IHDR (25 octets).
        file_put_contents($path, substr($content, 0, 33) . $iccp . substr($content, 33));
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
