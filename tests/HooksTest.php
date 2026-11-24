<?php

declare(strict_types=1);

namespace CerealImages\Tests;

use CerealImages\Encoders\GdEncoder;
use CerealImages\Hooks;
use CerealImages\Optimizers\JpegoptimOptimizer;
use CerealImages\Processor;

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
        self::assertArrayHasKey('wp_delete_file', Wp::$hooks);
    }

    public function testPurgeRemovesSidecarsAndBackup(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $other = $this->jpeg('2026/06/autre.jpg');
        $this->attachment(1, $file);
        $this->attachment(2, $other);
        $processor = new Processor([new GdEncoder('webp', 80)], [new JpegoptimOptimizer(true)]);
        $processor->processAttachment(1);
        $processor->processAttachment(2);

        self::assertSame($file, Hooks::purge($file));

        self::assertFileDoesNotExist("{$file}.webp");
        self::assertFileDoesNotExist($this->backupPath('2026/06/photo.jpg'));
        self::assertFileExists($file, 'WordPress supprime le fichier lui-même.');
        self::assertFileExists("{$other}.webp");
        self::assertFileExists($this->backupPath('2026/06/autre.jpg'));
    }

    public function testPurgeIgnoresCancelledDeletion(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $this->attachment(1, $file);
        (new Processor([new GdEncoder('webp', 80)], [new JpegoptimOptimizer(true)]))->processAttachment(1);

        self::assertSame('', Hooks::purge(''));

        self::assertFileExists("{$file}.webp");
        self::assertFileExists($this->backupPath('2026/06/photo.jpg'));
    }

    public function testPurgeKeepsAttachmentNamedLikeSidecar(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $webp = "{$file}.webp";
        file_put_contents($webp, 'pièce jointe WebP');
        $this->attachment(2, $webp);

        Hooks::purge($file);

        self::assertFileExists($webp);
    }
}
