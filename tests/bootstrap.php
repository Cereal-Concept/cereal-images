<?php

declare(strict_types=1);

/*
 * Doublures minimales des fonctions WordPress utilisées par le plugin.
 * L'état vit dans CerealImages\Tests\Wp, remis à zéro par TestCase::setUp().
 */

use CerealImages\Tests\Wp;

require dirname(__DIR__) . '/vendor/autoload.php';

define('ABSPATH', sys_get_temp_dir() . '/');

putenv('PATH=' . __DIR__ . '/bin:' . getenv('PATH'));

/** @return array{basedir: string, baseurl: string} */
function wp_upload_dir(?string $time = null, bool $create = true): array
{
    return ['basedir' => Wp::$uploads, 'baseurl' => 'https://example.test/app/uploads'];
}

function wp_mkdir_p(string $target): bool
{
    return is_dir($target) || mkdir($target, 0o777, true);
}

function untrailingslashit(string $value): string
{
    return rtrim($value, '/\\');
}

function get_attached_file(int $attachmentId): string|false
{
    return Wp::$attachments[$attachmentId]['file'] ?? false;
}

/** @return array<string, mixed>|false */
function wp_get_attachment_metadata(int $attachmentId): array|false
{
    $attachment = Wp::$attachments[$attachmentId] ?? null;

    if ($attachment === null) {
        return false;
    }

    return ['sizes' => array_map(static fn (string $file) => ['file' => $file], $attachment['sizes'])];
}

function wp_get_original_image_path(int $attachmentId): string|false
{
    return Wp::$attachments[$attachmentId]['original'] ?? get_attached_file($attachmentId);
}

/** @return array<int|string, mixed>|false */
function wp_getimagesize(string $filename): array|false
{
    // Comme WordPress, qui masque l'avertissement de getimagesize() sur un fichier illisible.
    // @mago-expect lint:no-error-control-operator
    return @getimagesize($filename);
}

function update_post_meta(int $postId, string $key, mixed $value): bool
{
    Wp::$meta[$postId][$key] = $value;

    return true;
}

function get_post_meta(int $postId, string $key, bool $single = false): mixed
{
    return Wp::$meta[$postId][$key] ?? '';
}

function delete_post_meta(int $postId, string $key): bool
{
    unset(Wp::$meta[$postId][$key]);

    return true;
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    Wp::$hooks[$hook] = $callback;

    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}

/** @param list<mixed> $args */
function wp_next_scheduled(string $hook, array $args = []): int|false
{
    return Wp::$cron[$hook . serialize($args)] ?? false;
}

/** @param list<mixed> $args */
function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
{
    Wp::$cron[$hook . serialize($args)] = $timestamp;

    return true;
}

require dirname(__DIR__) . '/cereal-images.php';
