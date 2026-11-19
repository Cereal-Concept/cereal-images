<?php

declare(strict_types=1);

namespace CerealImages\Tests;

use CerealImages\Encoders\GdEncoder;
use CerealImages\Processor;
use CerealImages\Stats;

final class StatsTest extends TestCase
{
    public function testSumsProcessedAttachmentsAndIgnoresInvalidMeta(): void
    {
        $totals = Stats::summarize([
            ['before' => 1000, 'after' => ['jpg/png' => 900, 'avif' => 400, 'webp' => 600]],
            ['before' => 500, 'after' => ['jpg/png' => 500, 'avif' => 200]],
            'meta corrompue',
            ['before' => 'x'],
        ]);

        self::assertSame(2, $totals['processed']);
        self::assertSame(1500, $totals['before']);
        self::assertSame(['jpg/png' => 1400, 'avif' => 600, 'webp' => 600], $totals['after']);
    }

    public function testReadsMetaWrittenByProcessor(): void
    {
        $this->attachment(1, $this->jpeg('2026/06/photo.jpg'));
        (new Processor([new GdEncoder('webp', 80)], []))->processAttachment(1);

        $totals = Stats::summarize(array_column(Wp::$meta, Processor::META));

        self::assertSame(1, $totals['processed']);
        self::assertArrayHasKey('webp', $totals['after']);
    }
}
