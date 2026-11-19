<?php

declare(strict_types=1);

namespace CerealImages;

final class Shell
{
    /** @var array<string, bool> */
    private static array $found = [];

    public static function has(string $binary): bool
    {
        // En PHP 8, une fonction listée dans disable_functions n'est plus définie.
        if (! function_exists('exec')) {
            return false;
        }

        return self::$found[$binary] ??= self::run('command', ['-v', $binary]);
    }

    /** @param list<string> $args */
    public static function run(string $binary, array $args): bool
    {
        exec($binary . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $output, $code);

        return $code === 0;
    }
}
