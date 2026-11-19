<?php

declare(strict_types=1);

namespace CerealImages\Tests;

use CerealImages\Admin\Page;
use CerealImages\Processor;
use CerealImages\Settings;

final class SettingsTest extends TestCase
{
    public function testDefaultsWithoutSavedOption(): void
    {
        self::assertSame(Settings::DEFAULTS, Settings::get());
    }

    public function testSavedValuesAreRead(): void
    {
        Wp::$options[Settings::OPTION] = [
            'avif_quality' => '60',
            'webp_quality' => 75,
            'avif_speed' => '6',
            'lossless' => '1',
        ];

        self::assertSame(
            ['avif_quality' => 60, 'webp_quality' => 75, 'avif_speed' => 6, 'lossless' => true],
            Settings::get(),
        );
    }

    public function testInvalidValuesFallBackToDefaults(): void
    {
        $values = Settings::sanitize([
            'avif_quality' => '150',
            'webp_quality' => 'abc',
            'avif_speed' => -1,
            'lossless' => '0',
        ]);

        self::assertSame(Settings::DEFAULTS, $values);
        self::assertSame(Settings::DEFAULTS, Settings::sanitize('pas un tableau'));
    }

    public function testServerRowsFlagMissingTools(): void
    {
        $rows = Page::serverRows(new Processor([], []));
        $byLabel = array_column(array_map(static fn (array $row) => [$row[0], $row[2]], $rows), 1, 0);

        self::assertFalse($byLabel['Formats générés']);
        self::assertFalse($byLabel['Optimiseurs JPG/PNG']);
        self::assertFalse($byLabel['.htaccess des uploads']);
    }
}
