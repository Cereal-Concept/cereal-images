<?php

declare(strict_types=1);

namespace CerealImages\Tests;

use CerealImages\Cli\Command;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * WP-CLI lit les options et leurs valeurs par défaut dans le docblock le plus proche de la méthode :
 * un second docblock (ex. ajouté pour PHPStan) les masque et casse la commande.
 */
final class CommandTest extends TestCase
{
    /** @return list<array{string, string}> */
    public static function commands(): array
    {
        return [
            ['convert',             'default: 50'],
            ['restore',             '<id>...'],
            ['shortPixelLeftovers', '@subcommand shortpixel-leftovers'],
        ];
    }

    #[DataProvider('commands')]
    public function testDocblockDeclaresTheCommand(string $method, string $expected): void
    {
        $doc = (string) (new ReflectionMethod(Command::class, $method))->getDocComment();

        self::assertStringContainsString('@when after_wp_load', $doc);
        self::assertStringContainsString($expected, $doc);
    }
}
