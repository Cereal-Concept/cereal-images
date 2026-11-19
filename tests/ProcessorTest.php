<?php

declare(strict_types=1);

namespace CerealImages\Tests;

use CerealImages\Encoders\GdEncoder;
use CerealImages\Optimizers\JpegoptimOptimizer;
use CerealImages\Optimizers\OptipngOptimizer;
use CerealImages\Processor;
use CerealImages\Tests\Doubles\FixedSizeEncoder;
use CerealImages\Tests\Doubles\GrowingOptimizer;
use PHPUnit\Framework\Attributes\DataProvider;

final class ProcessorTest extends TestCase
{
    private function processor(): Processor
    {
        return new Processor([new GdEncoder('webp', 80)], [new JpegoptimOptimizer(true), new OptipngOptimizer()]);
    }

    public function testNewImageIsBackedUpOptimizedAndConverted(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $size = $this->jpeg('2026/06/photo-150x150.jpg', 150, 150);
        $this->attachment(1, $file, [$size]);

        $report = $this->processor()->processAttachment(1);

        self::assertCount(2, $report);
        self::assertSame('created', $report[0]['backup']);
        self::assertSame('ok', $report[0]['optimization']['status']);
        self::assertLessThan($report[0]['optimization']['before'], $report[0]['optimization']['after']);
        self::assertSame('ok', $report[0]['sidecars'][0]['status']);
        self::assertFileExists("{$file}.webp");
        self::assertFileExists("{$size}.webp");
        self::assertSame([], $this->temporaryFiles());

        $meta = Wp::$meta[1][Processor::META] ?? null;
        self::assertIsArray($meta);
        self::assertSame(\CerealImages\VERSION, $meta['version']);
    }

    public function testShortPixelImageIsNotOptimizedAgain(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg', quality: 50);
        $this->jpeg('ShortpixelBackups/app/uploads/2026/06/photo.jpg', quality: 95);
        $this->attachment(1, $file);
        $before = md5_file($file);

        $report = $this->processor()->processAttachment(1);

        self::assertSame('shortpixel', $report[0]['backup']);
        self::assertSame('already', $report[0]['optimization']['status']);
        self::assertSame($before, md5_file($file));
    }

    public function testSecondRunTouchesNothing(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $this->attachment(1, $file);
        $this->processor()->processAttachment(1);
        $optimized = md5_file($file);

        $report = $this->processor()->processAttachment(1);

        self::assertSame('existing', $report[0]['backup']);
        self::assertSame('already', $report[0]['optimization']['status']);
        self::assertSame($optimized, md5_file($file));
    }

    public function testWithoutOptimizerFileIsLeftAsIs(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $this->attachment(1, $file);
        $before = md5_file($file);

        $report = (new Processor([new GdEncoder('webp', 80)], []))->processAttachment(1);

        self::assertSame('unavailable', $report[0]['optimization']['status']);
        self::assertSame($before, md5_file($file));
    }

    public function testHeavierOptimizationIsDiscarded(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $this->attachment(1, $file);
        $before = md5_file($file);

        $report = (new Processor([], [new GrowingOptimizer()]))->processAttachment(1);

        self::assertSame('larger', $report[0]['optimization']['status']);
        self::assertSame($before, md5_file($file));
        self::assertSame([], $this->temporaryFiles());
    }

    public function testHeavierSidecarIsRemoved(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $this->attachment(1, $file);
        file_put_contents("{$file}.avif", 'ancien sidecar');

        $report = (new Processor([new FixedSizeEncoder('avif', (int) filesize($file))], []))->processAttachment(1);

        self::assertSame('larger', $report[0]['sidecars'][0]['status']);
        self::assertFileDoesNotExist("{$file}.avif");
        self::assertSame([], $this->temporaryFiles());
    }

    public function testFailedEncodingLeavesNoFile(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $this->attachment(1, $file);

        $report = (new Processor([new FixedSizeEncoder('avif', 10, succeeds: false)], []))->processAttachment(1);

        self::assertSame('error', $report[0]['sidecars'][0]['status']);
        self::assertFileDoesNotExist("{$file}.avif");
        self::assertSame([], $this->temporaryFiles());
    }

    public function testSidecarIsEncodedFromBackupButComparedToServedFile(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg', quality: 30);
        $this->jpeg('ShortpixelBackups/app/uploads/2026/06/photo.jpg', quality: 95);
        $this->attachment(1, $file);

        $report = (new Processor([new FixedSizeEncoder('webp', (int) filesize($file) - 1)], []))->processAttachment(1);

        self::assertSame((int) filesize($file), $report[0]['sidecars'][0]['served']);
        self::assertSame('ok', $report[0]['sidecars'][0]['status']);
    }

    public function testTransparentPngKeepsAlphaInWebp(): void
    {
        $file = $this->png('2026/06/logo.png');
        $this->attachment(1, $file);

        $report = (new Processor([new GdEncoder('webp', 80)], []))->processAttachment(1);

        self::assertSame('ok', $report[0]['sidecars'][0]['status']);
        $webp = imagecreatefromwebp("{$file}.webp");
        self::assertNotFalse($webp);
        self::assertSame(127, (imagecolorat($webp, 0, 0) >> 24) & 0x7F);
    }

    public function testOriginalImageShortPixelBackupIsAdoptedOnly(): void
    {
        $scaled = $this->jpeg('2026/06/photo-scaled.jpg', quality: 50);
        $original = $this->jpeg('2026/06/photo.jpg', 800, 600, 50);
        $shortPixelOriginal = $this->jpeg('ShortpixelBackups/app/uploads/2026/06/photo.jpg', 800, 600, 95);
        $this->attachment(1, $scaled, original: $original);
        $before = md5_file($original);

        (new Processor([new GdEncoder('webp', 80)], []))->processAttachment(1);

        self::assertFileDoesNotExist($shortPixelOriginal);
        self::assertFileExists($this->backupPath('2026/06/photo.jpg'));
        self::assertSame($before, md5_file($original), "L'original non réduit n'est pas optimisé.");
        self::assertFileDoesNotExist("{$original}.webp");
    }

    public function testRotatedOriginalImageShortPixelBackupIsAdopted(): void
    {
        $scaled = $this->jpeg('2026/06/photo-scaled.jpg', 300, 400);
        $original = $this->jpeg('2026/06/photo.jpg', 600, 800, 50);
        $shortPixelOriginal = $this->jpeg('ShortpixelBackups/app/uploads/2026/06/photo.jpg', 800, 600, 95);
        $this->attachment(1, $scaled, original: $original);

        (new Processor([], []))->processAttachment(1);

        self::assertFileDoesNotExist($shortPixelOriginal);
        self::assertFileExists($this->backupPath('2026/06/photo.jpg'));
    }

    public function testRestoreBringsBackOriginalsAndCleansUp(): void
    {
        $scaled = $this->jpeg('2026/06/photo-scaled.jpg');
        $original = $this->jpeg('2026/06/photo.jpg', 800, 600, 50);
        $this->jpeg('ShortpixelBackups/app/uploads/2026/06/photo.jpg', 800, 600, 95);
        $this->attachment(1, $scaled, original: $original);
        $processor = $this->processor();
        $processor->processAttachment(1);

        $report = $processor->restoreAttachment(1);

        self::assertSame(['restored', 'restored'], array_column($report, 'status'));
        self::assertFileEquals($this->backupPath('2026/06/photo-scaled.jpg'), $scaled);
        self::assertFileEquals($this->backupPath('2026/06/photo.jpg'), $original);
        self::assertFileDoesNotExist("{$scaled}.webp");
        self::assertArrayNotHasKey(Processor::META, Wp::$meta[1] ?? []);
    }

    /** @return list<array{int}> */
    public static function encodedSizes(): array
    {
        return [[10], [10_000_000]];
    }

    #[DataProvider('encodedSizes')]
    public function testAttachmentNamedLikeSidecarIsNeverTouched(int $encodedSize): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $webp = "{$file}.webp";
        file_put_contents($webp, 'pièce jointe WebP');
        $this->attachment(1, $file);
        $this->attachment(2, $webp);
        $processor = new Processor([new FixedSizeEncoder('webp', $encodedSize)], []);

        $report = $processor->processAttachment(1);

        self::assertSame('collision', $report[0]['sidecars'][0]['status']);
        self::assertSame('pièce jointe WebP', file_get_contents($webp));
        self::assertSame([], $this->temporaryFiles());

        $processor->restoreAttachment(1);
        self::assertFileExists($webp);
    }

    public function testSummaryCountsServedFileWhenSidecarIsRejected(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $this->attachment(1, $file);
        $size = (int) filesize($file);

        $report = (new Processor([
            new FixedSizeEncoder('avif', $size + 1),
            new FixedSizeEncoder('webp', 100),
        ], []))->processAttachment(1);
        $summary = Processor::summary($report);

        self::assertSame($size, $summary['before']);
        self::assertSame($size, $summary['after']['avif']);
        self::assertSame(100, $summary['after']['webp']);

        $merged = Processor::mergeSummaries($summary, $summary);
        self::assertSame(2 * $size, $merged['before']);
        self::assertSame(200, $merged['after']['webp']);
    }
}
