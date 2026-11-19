<?php

declare(strict_types=1);

namespace CerealImages\Tests;

final class Wp
{
    public static string $uploads = '';

    /** @var array<int, array{file: string, sizes: list<string>, original?: string}> */
    public static array $attachments = [];

    /** @var array<int, array<string, mixed>> */
    public static array $meta = [];

    /** @var array<string, callable> */
    public static array $hooks = [];

    /** @var array<string, int> */
    public static array $cron = [];

    public static function reset(string $uploads): void
    {
        self::$uploads = $uploads;
        self::$attachments = [];
        self::$meta = [];
        self::$cron = [];
    }
}
