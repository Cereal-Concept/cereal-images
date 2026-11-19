<?php

declare(strict_types=1);

namespace CerealImages\Tests;

use CerealImages\Attachment;

final class AttachmentTest extends TestCase
{
    public function testListsMainFileAndExistingSizes(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $size = $this->jpeg('2026/06/photo-150x150.jpg', 150, 150);
        $this->attachment(1, $file, [$size, "{$this->uploads}/2026/06/photo-missing.jpg"]);

        self::assertSame([$file, $size], Attachment::files(1));
    }

    public function testIgnoresOtherFormats(): void
    {
        $this->attachment(1, "{$this->uploads}/2026/06/doc.pdf");

        self::assertSame([], Attachment::files(1));
        self::assertFalse(Attachment::isProcessable(1));
    }

    public function testOriginalImageOnlyWhenDifferentFromAttachedFile(): void
    {
        $scaled = $this->jpeg('2026/06/photo-scaled.jpg');
        $original = $this->jpeg('2026/06/photo.jpg');
        $this->attachment(1, $scaled, original: $original);
        $this->attachment(2, $original);

        self::assertSame($original, Attachment::originalImage(1));
        self::assertNull(Attachment::originalImage(2));
    }
}
