<?php

/**
 * Plugin Name: Cereal Images
 * Description: Génère des versions AVIF et WebP des images et les sert via .htaccess.
 * Version: 0.8.0
 * Requires PHP: 8.1
 * Author: Cereal Concept
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/Cereal-Concept/cereal-images
 */

declare(strict_types=1);

namespace CerealImages;

defined('ABSPATH') || exit();

const VERSION = '0.8.0';

spl_autoload_register(function (string $class): void {
    if (! str_starts_with($class, __NAMESPACE__ . '\\')) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen(__NAMESPACE__) + 1));
    $path = __DIR__ . "/src/{$relative}.php";

    if (is_file($path)) {
        require $path;
    }
});

Hooks::register();
Updater::register(__FILE__);

// Les requêtes REST ne passent pas par is_admin().
Admin\Rest::register();

if (is_admin()) {
    Admin\Page::register();
}

register_activation_hook(__FILE__, [Hooks::class, 'activate']);
register_deactivation_hook(__FILE__, [Hooks::class, 'deactivate']);

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('cereal-images', Cli\Command::class);
}
