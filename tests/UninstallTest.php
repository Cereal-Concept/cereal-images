<?php

declare(strict_types=1);

namespace CerealImages\Tests;

use CerealImages\Encoders\GdEncoder;
use CerealImages\Hooks;
use CerealImages\Htaccess;
use CerealImages\Optimizers\JpegoptimOptimizer;
use CerealImages\Processor;

final class UninstallTest extends TestCase
{
    public function testRemovesEverythingButBackups(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $this->attachment(1, $file);
        (new Processor([new GdEncoder('webp', 80)], [new JpegoptimOptimizer(true)]))->processAttachment(1);
        $orphan = "{$this->uploads}/2026/06/photo-e123.jpg.avif";
        file_put_contents($orphan, 'ancien sidecar');
        $attachmentWebp = "{$this->uploads}/2026/06/autre.jpg.webp";
        file_put_contents($attachmentWebp, 'pièce jointe WebP');
        $this->attachment(2, $attachmentWebp);
        Htaccess::install();
        Wp::$options[Hooks::VERSION_OPTION] = \CerealImages\VERSION;

        if (! defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'cereal-images/cereal-images.php');
        }

        require dirname(__DIR__) . '/uninstall.php';

        self::assertFileDoesNotExist("{$file}.webp");
        self::assertFileDoesNotExist($orphan);
        self::assertFileExists($attachmentWebp);
        self::assertFileExists($this->backupPath('2026/06/photo.jpg'));
        self::assertFalse(Htaccess::isInstalled());
        self::assertArrayNotHasKey(Hooks::VERSION_OPTION, Wp::$options);
        self::assertArrayNotHasKey(Processor::META, Wp::$meta[1] ?? []);
    }
}
