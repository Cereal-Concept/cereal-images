<?php

declare(strict_types=1);

namespace CerealImages\Tests;

use CerealImages\Encoders\GdEncoder;

final class GdEncoderTest extends TestCase
{
    public function testAvifEncodingWhenCodecIsAvailable(): void
    {
        $encoder = new GdEncoder('avif', 50);

        if (! $encoder->isAvailable()) {
            self::markTestSkipped('GD sans codec AVIF dans cet environnement.');
        }

        $file = $this->jpeg('2026/06/photo.jpg');

        self::assertTrue($encoder->encode($file, "{$file}.avif"));
        self::assertSame('image/avif', mime_content_type("{$file}.avif"));
    }

    public function testFlatPngGetsLosslessWebp(): void
    {
        $file = "{$this->uploads}/2026/06/picto.png";
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 50, 50, 200, 250, (int) imagecolorallocate($image, 200, 30, 60));
        imagefilledellipse($image, 300, 150, 120, 120, (int) imagecolorallocate($image, 20, 90, 200));
        imagepng($image, $file);

        self::assertTrue((new GdEncoder('webp', 80))->encode($file, "{$file}.webp"));

        // Chunk VP8L : WebP sans perte (VP8 : avec perte).
        self::assertSame('VP8L', substr((string) file_get_contents("{$file}.webp"), 12, 4));
        self::assertSame([], $this->temporaryFiles());
    }

    public function testJpegGetsLossyWebp(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');

        self::assertTrue((new GdEncoder('webp', 80))->encode($file, "{$file}.webp"));
        self::assertSame('VP8 ', substr((string) file_get_contents("{$file}.webp"), 12, 4));
    }

    public function testUnreadableSourceFails(): void
    {
        $file = "{$this->uploads}/2026/06/broken.jpg";
        file_put_contents($file, 'pas une image');

        self::assertFalse((new GdEncoder('webp', 80))->encode($file, "{$file}.webp"));
    }
}
