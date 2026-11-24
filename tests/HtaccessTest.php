<?php

declare(strict_types=1);

namespace CerealImages\Tests;

use CerealImages\Hooks;
use CerealImages\Htaccess;

final class HtaccessTest extends TestCase
{
    public function testInstallWritesBlockAndKeepsExistingRules(): void
    {
        file_put_contents(Htaccess::path(), "Options -Indexes\n");

        self::assertTrue(Htaccess::install());

        $content = (string) file_get_contents(Htaccess::path());
        self::assertStringStartsWith('Options -Indexes', $content);
        self::assertStringContainsString('# BEGIN Cereal Images', $content);
        self::assertStringContainsString('RewriteRule \.(jpe?g|png)$ %{REQUEST_URI}.avif [NC,L]', $content);
        self::assertTrue(Htaccess::isInstalled());
    }

    public function testInstallIsIdempotent(): void
    {
        Htaccess::install();
        $first = file_get_contents(Htaccess::path());

        Htaccess::install();

        self::assertSame($first, file_get_contents(Htaccess::path()));
        self::assertSame(1, substr_count((string) $first, '# BEGIN Cereal Images'));
    }

    public function testRemoveEmptiesBlockOnly(): void
    {
        file_put_contents(Htaccess::path(), "Options -Indexes\n");
        Htaccess::install();

        self::assertTrue(Htaccess::remove());

        $content = (string) file_get_contents(Htaccess::path());
        self::assertStringContainsString('Options -Indexes', $content);
        self::assertStringNotContainsString('RewriteRule', $content);
        self::assertFalse(Htaccess::isInstalled());
    }

    public function testManualCopyOfRulesIsReplacedByBlock(): void
    {
        $manual = implode("\n", array_map(static fn (string $line) => "\t" . trim($line), Htaccess::RULES));
        file_put_contents(Htaccess::path(), "Options -Indexes\n{$manual}\nHeader set X-Test \"ok\"\n");

        Htaccess::install();

        $content = (string) file_get_contents(Htaccess::path());
        self::assertSame(2, substr_count($content, 'RewriteRule'));
        self::assertStringStartsWith("Options -Indexes\nHeader set X-Test \"ok\"", $content);
        self::assertTrue(Htaccess::isInstalled());

        Htaccess::remove();
        self::assertSame(0, substr_count((string) file_get_contents(Htaccess::path()), 'RewriteRule'));
    }

    public function testRemoveWithoutFileCreatesNothing(): void
    {
        self::assertTrue(Htaccess::remove());
        self::assertFileDoesNotExist(Htaccess::path());
    }

    public function testActivationHooksAreRegistered(): void
    {
        self::assertSame([Hooks::class, 'activate'], Wp::$hooks['activate'] ?? null);
        self::assertSame([Hooks::class, 'deactivate'], Wp::$hooks['deactivate'] ?? null);

        Hooks::activate();
        self::assertTrue(Htaccess::isInstalled());

        Hooks::deactivate();
        self::assertFalse(Htaccess::isInstalled());
    }

    public function testUpgradeRewritesBlockFromPreviousVersion(): void
    {
        insert_with_markers(Htaccess::path(), 'Cereal Images', ['RewriteEngine On', 'Header merge Vary Accept']);

        Hooks::upgrade();

        self::assertTrue(Htaccess::isInstalled());
        self::assertSame(\CerealImages\VERSION, Wp::$options[Hooks::VERSION_OPTION] ?? null);
    }

    public function testUpgradeKeepsRemovedBlockRemoved(): void
    {
        file_put_contents(Htaccess::path(), "Options -Indexes\n");
        Htaccess::install();
        Htaccess::remove();

        Hooks::upgrade();

        self::assertFalse(Htaccess::isInstalled());
    }

    public function testUpgradeRunsOncePerVersion(): void
    {
        Wp::$options[Hooks::VERSION_OPTION] = \CerealImages\VERSION;
        insert_with_markers(Htaccess::path(), 'Cereal Images', ['RewriteEngine On']);

        Hooks::upgrade();

        self::assertFalse(Htaccess::isInstalled());
    }
}
