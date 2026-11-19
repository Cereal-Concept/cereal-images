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

    public function testUnreadableSourceFails(): void
    {
        $file = "{$this->uploads}/2026/06/broken.jpg";
        file_put_contents($file, 'pas une image');

        self::assertFalse((new GdEncoder('webp', 80))->encode($file, "{$file}.webp"));
    }
}
