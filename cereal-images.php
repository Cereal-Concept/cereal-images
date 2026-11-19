<?php

/**
 * Plugin Name: Cereal Images
 * Description: Génère des versions AVIF et WebP des images et les sert via .htaccess.
 * Version: 0.2.0
 * Requires PHP: 8.1
 * Author: Cereal Concept
 */

declare(strict_types=1);

namespace CerealImages;

defined('ABSPATH') || exit;

spl_autoload_register(function (string $class): void {
    if (! str_starts_with($class, __NAMESPACE__.'\\')) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen(__NAMESPACE__) + 1));
    $path = __DIR__."/src/{$relative}.php";

    if (is_file($path)) {
        require $path;
    }
});

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('cereal-images', Cli\Command::class);
}
