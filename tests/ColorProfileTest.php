<?php

declare(strict_types=1);

namespace CerealImages\Tests;

use CerealImages\ColorProfile;

final class ColorProfileTest extends TestCase
{
    public function testImagesWithoutProfileAreSafe(): void
    {
        self::assertTrue(ColorProfile::isSafeForGd($this->jpeg('2026/06/photo.jpg')));
        self::assertTrue(ColorProfile::isSafeForGd($this->png('2026/06/logo.png')));
    }

    public function testSrgbProfileIsSafe(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        self::embedProfile($file, self::iccProfile(self::SRGB));

        self::assertTrue(ColorProfile::isSafeForGd($file));
    }

    public function testDisplayP3JpegIsNotSafe(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        self::embedProfile($file, self::iccProfile(self::DISPLAY_P3));

        self::assertNotFalse(imagecreatefromjpeg($file), 'Le JPEG reste lisible.');
        self::assertFalse(ColorProfile::isSafeForGd($file));
    }

    public function testDisplayP3PngIsNotSafe(): void
    {
        $file = $this->png('2026/06/capture.png');
        self::embedProfile($file, self::iccProfile(self::DISPLAY_P3));

        self::assertNotFalse(imagecreatefrompng($file), 'Le PNG reste lisible.');
        self::assertFalse(ColorProfile::isSafeForGd($file));
    }

    public function testTruncatedProfileIsNotSafe(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        self::embedProfile($file, substr(self::iccProfile(self::SRGB), 0, 150));

        self::assertFalse(ColorProfile::isSafeForGd($file));
    }

    public function testUnreadableFileIsSafe(): void
    {
        $file = "{$this->uploads}/2026/06/broken.jpg";
        file_put_contents($file, "\xFF\xD8\xFF");

        self::assertTrue(ColorProfile::isSafeForGd($file));
    }
}
