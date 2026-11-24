<?php

declare(strict_types=1);

namespace CerealImages\Tests;

use CerealImages\Admin\Rest;
use CerealImages\Encoders\GdEncoder;
use CerealImages\Optimizers\JpegoptimOptimizer;
use CerealImages\Processor;
use CerealImages\Tests\Doubles\FixedSizeEncoder;

final class RestTest extends TestCase
{
    public function testProcessesOneAttachmentAndReportsErrors(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $this->attachment(1, $file);

        $ok = Rest::processAttachment(new Processor([new GdEncoder('webp', 80)], [new JpegoptimOptimizer(true)]), 1);
        $failed = Rest::processAttachment(new Processor([new FixedSizeEncoder('avif', 10, succeeds: false)], []), 1);

        self::assertSame(['id' => 1, 'files' => 1, 'errors' => []], $ok);
        self::assertFileExists("{$file}.webp");
        self::assertCount(1, $failed['errors']);
        self::assertStringContainsString('#1 photo.jpg', $failed['errors'][0]);
    }

    public function testRestoresOneAttachment(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $this->attachment(1, $file);
        $processor = new Processor([new GdEncoder('webp', 80)], [new JpegoptimOptimizer(true)]);
        $processor->processAttachment(1);

        self::assertSame(['id' => 1, 'files' => 1, 'errors' => []], Rest::restoreAttachment($processor, 1));
        self::assertFileDoesNotExist("{$file}.webp");
        self::assertFileEquals($this->backupPath('2026/06/photo.jpg'), $file);
    }

    public function testUnknownAttachmentIsEmpty(): void
    {
        self::assertSame(
            ['id' => 99, 'files' => 0, 'errors' => []],
            Rest::processAttachment(new Processor([], []), 99),
        );
    }

    public function testRegistersRoutesOnRestInit(): void
    {
        self::assertArrayHasKey('rest_api_init', Wp::$hooks);
    }
}
