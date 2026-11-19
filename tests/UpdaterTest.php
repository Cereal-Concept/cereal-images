<?php

declare(strict_types=1);

namespace CerealImages\Tests;

use CerealImages\Updater;

final class UpdaterTest extends TestCase
{
    private const BASENAME = 'cereal-images/cereal-images.php';

    private function cacheRelease(string $version, string $notes = ''): void
    {
        Wp::$options['_site_transient_cereal_images_release'] = ['version' => $version, 'notes' => $notes];
    }

    public function testParseBuildsPackageUrlForThatVersion(): void
    {
        self::assertSame(
            [
                'version' => '1.2.3',
                'package' => 'https://github.com/Cereal-Concept/cereal-images/releases/download/v1.2.3/cereal-images.zip',
                'notes' => 'Corrections',
            ],
            Updater::parse(['version' => '1.2.3', 'notes' => 'Corrections']),
        );
    }

    public function testParseRejectsInvalidData(): void
    {
        self::assertNull(Updater::parse(null));
        self::assertNull(Updater::parse([]));
        self::assertNull(Updater::parse(['version' => 'v1.2']));
    }

    public function testAnnouncesReleaseForThisPluginOnly(): void
    {
        $this->cacheRelease('9.9.9');

        $update = Updater::update(false, [], self::BASENAME);

        self::assertIsArray($update);
        self::assertSame('9.9.9', $update['version']);
        self::assertStringEndsWith('/v9.9.9/cereal-images.zip', (string) $update['package']);
        self::assertFalse(Updater::update(false, [], 'autre/autre.php'));
    }

    public function testFailedCheckChangesNothing(): void
    {
        Wp::$options['_site_transient_cereal_images_release'] = [];

        self::assertFalse(Updater::update(false, [], self::BASENAME));
    }

    public function testDetailsShowEscapedNotes(): void
    {
        $this->cacheRelease('9.9.9', "Ligne 1\n<script>");

        $details = Updater::details(false, 'plugin_information', (object) ['slug' => 'cereal-images']);

        self::assertIsObject($details);
        self::assertStringContainsString('&lt;script&gt;', $details->sections['changelog']);
        self::assertFalse(Updater::details(false, 'plugin_information', (object) ['slug' => 'autre']));
    }
}
