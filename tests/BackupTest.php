<?php

declare(strict_types=1);

namespace CerealImages\Tests;

use CerealImages\Backup;

final class BackupTest extends TestCase
{
    public function testCreatesBackupCopy(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');

        self::assertSame('created', Backup::ensure($file));
        self::assertFileEquals($file, $this->backupPath('2026/06/photo.jpg'));
        self::assertTrue(Backup::isPristine($file));
    }

    public function testNeverOverwritesExistingBackup(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $original = $this->jpeg('cereal-images-backup/2026/06/photo.jpg', quality: 95);
        $before = md5_file($original);

        self::assertSame('existing', Backup::ensure($file));
        self::assertSame($before, md5_file($original));
        self::assertFalse(Backup::isPristine($file));
    }

    public function testMovesShortPixelBackupWithSameDimensions(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg', quality: 50);
        $shortPixel = $this->jpeg('ShortpixelBackups/app/uploads/2026/06/photo.jpg', quality: 95);
        $content = md5_file($shortPixel);

        self::assertSame('shortpixel', Backup::ensure($file));
        self::assertFileDoesNotExist($shortPixel);
        self::assertSame($content, md5_file($this->backupPath('2026/06/photo.jpg')));
        self::assertFalse(
            Backup::isPristine($file),
            'Le fichier compressé par ShortPixel est considéré comme déjà optimisé.',
        );
    }

    public function testIgnoresShortPixelBackupWithOtherDimensions(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        $shortPixel = $this->jpeg('ShortpixelBackups/app/uploads/2026/06/photo.jpg', 800, 600);

        self::assertSame('created', Backup::ensure($file));
        self::assertFileExists($shortPixel);
        self::assertFileEquals($file, $this->backupPath('2026/06/photo.jpg'));
    }

    public function testRotatedShortPixelBackupIsRefusedForConvertedFiles(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg', 300, 400);
        $shortPixel = $this->jpeg('ShortpixelBackups/app/uploads/2026/06/photo.jpg', 400, 300);

        self::assertSame('created', Backup::ensure($file));
        self::assertFileExists($shortPixel);
    }

    public function testRefusesFileOutsideUploads(): void
    {
        self::assertSame('error', Backup::ensure('/elsewhere/photo.jpg'));
        self::assertNull(Backup::path('/elsewhere/photo.jpg'));
    }

    public function testRestorePutsBackupBack(): void
    {
        $file = $this->jpeg('2026/06/photo.jpg');
        Backup::ensure($file);
        file_put_contents($file, 'modifié');

        self::assertTrue(Backup::restore($file));
        self::assertFileEquals($this->backupPath('2026/06/photo.jpg'), $file);
        self::assertFileExists($this->backupPath('2026/06/photo.jpg'), 'La sauvegarde est conservée.');
        self::assertSame([], $this->temporaryFiles());
    }

    public function testRestoreWithoutBackupFails(): void
    {
        self::assertFalse(Backup::restore($this->jpeg('2026/06/photo.jpg')));
    }
}
