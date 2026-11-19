<?php

declare(strict_types=1);

namespace CerealImages\Tests;

use CerealImages\Hooks;

final class HooksTest extends TestCase
{
    public function testSchedulesProcessingOnceAndKeepsMetadata(): void
    {
        $this->attachment(1, $this->jpeg('2026/06/photo.jpg'));
        $metadata = ['width' => 400];

        self::assertSame($metadata, Hooks::schedule($metadata, 1));
        Hooks::schedule($metadata, 1);

        self::assertCount(1, Wp::$cron);
        self::assertGreaterThan(time(), wp_next_scheduled(Hooks::EVENT, [1]));
    }

    public function testIgnoresNonImageAttachments(): void
    {
        $this->attachment(1, "{$this->uploads}/2026/06/doc.pdf");

        Hooks::schedule([], 1);

        self::assertSame([], Wp::$cron);
    }

    public function testRegistersHooks(): void
    {
        self::assertArrayHasKey('wp_update_attachment_metadata', Wp::$hooks);
        self::assertArrayHasKey(Hooks::EVENT, Wp::$hooks);
    }
}
