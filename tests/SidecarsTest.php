<?php

declare(strict_types=1);

namespace CerealImages\Tests;

use CerealImages\Sidecars;

final class SidecarsTest extends TestCase
{
    public function testFindsOnlyShortPixelSidecarsNextToAnImage(): void
    {
        $this->jpeg('2026/06/photo.jpg');
        $this->jpeg('2026/06/photo-768x768.jpg', 100, 100);
        $this->png('2026/06/logo.png');
        $dir = "{$this->uploads}/2026/06";

        foreach ([
            'photo.avif',
            'photo-768x768.webp',
            'logo.webp',
            'photo.jpg.avif',
            'seule.webp',
            'piece.webp',
        ] as $name) {
            file_put_contents("{$dir}/{$name}", 'x');
        }

        $this->jpeg('2026/06/piece.jpg');
        $this->attachment(1, "{$dir}/piece.webp");
        wp_mkdir_p($this->backupPath('2026/06'));
        $this->jpeg('cereal-images-backup/2026/06/photo.jpg');
        file_put_contents($this->backupPath('2026/06/photo.webp'), 'x');

        self::assertSame(
            ["{$dir}/logo.webp", "{$dir}/photo-768x768.webp", "{$dir}/photo.avif"],
            Sidecars::shortPixel(),
        );
    }

    public function testDeletesOnlyWhatItWouldList(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $shortPixel = "{$this->uploads}/2026/06/photo.avif";
        file_put_contents($shortPixel, 'x');
        file_put_contents("{$file}.avif", 'x');

        self::assertTrue(Sidecars::deleteShortPixel($shortPixel));
        self::assertFalse(Sidecars::deleteShortPixel("{$file}.avif"));
        self::assertFileDoesNotExist($shortPixel);
        self::assertFileExists("{$file}.avif");
    }
}
