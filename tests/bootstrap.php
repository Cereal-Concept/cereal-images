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

function delete_post_meta_by_key(string $key): bool
{
    foreach (array_keys(Wp::$meta) as $postId) {
        unset(Wp::$meta[$postId][$key]);
    }

    return true;
}

function attachment_url_to_postid(string $url): int
{
    foreach (Wp::$attachments as $id => $attachment) {
        if ($url === str_replace(Wp::$uploads, wp_upload_dir()['baseurl'], $attachment['file'])) {
            return $id;
        }
    }

    return 0;
}

function register_activation_hook(string $file, callable $callback): void
{
    Wp::$hooks['activate'] = $callback;
}

function register_deactivation_hook(string $file, callable $callback): void
{
    Wp::$hooks['deactivate'] = $callback;
}

/** Version simplifiée de celle de WordPress (même format, sans traduction ni filtre). */
function insert_with_markers(string $filename, string $marker, array|string $insertion): bool
{
    if (! is_file($filename) && ! is_writable(dirname($filename)) || is_file($filename) && ! is_writable($filename)) {
        return false;
    }

    $lines = is_array($insertion) ? $insertion : explode("\n", $insertion);

    if ($lines) {
        $lines = ["# Instructions {$marker}", ...$lines];
    }

    $existing = is_file($filename) ? explode("\n", (string) file_get_contents($filename)) : [];
    $before = $existing;
    $after = [];
    $start = array_search("# BEGIN {$marker}", $existing, true);
    $end = array_search("# END {$marker}", $existing, true);

    if ($start !== false && $end !== false && $end > $start) {
        $before = array_slice($existing, 0, $start);
        $after = array_slice($existing, $end + 1);
    }

    $content = implode("\n", [...$before, "# BEGIN {$marker}", ...$lines, "# END {$marker}", ...$after]);

    return file_put_contents($filename, $content) !== false;
}

/** @return list<string> */
function extract_from_markers(string $filename, string $marker): array
{
    $result = [];
    $inside = false;

    foreach (is_file($filename) ? explode("\n", (string) file_get_contents($filename)) : [] as $line) {
        if (str_contains($line, "# END {$marker}")) {
            $inside = false;
        }

        if ($inside && ! str_starts_with($line, '#')) {
            $result[] = $line;
        }

        if (str_contains($line, "# BEGIN {$marker}")) {
            $inside = true;
        }
    }

    return $result;
}

function is_admin(): bool
{
    return false;
}

function get_option(string $option, mixed $default = false): mixed
{
    return Wp::$options[$option] ?? $default;
}

function update_option(string $option, mixed $value, ?bool $autoload = null): bool
{
    Wp::$options[$option] = $value;

    return true;
}

function delete_option(string $option): bool
{
    unset(Wp::$options[$option]);

    return true;
}

function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
}

function get_site_transient(string $transient): mixed
{
    return Wp::$options["_site_transient_{$transient}"] ?? false;
}

function delete_site_transient(string $transient): bool
{
    unset(Wp::$options["_site_transient_{$transient}"]);

    return true;
}

function set_site_transient(string $transient, mixed $value, int $expiration = 0): bool
{
    Wp::$options["_site_transient_{$transient}"] = $value;

    return true;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES);
}

require dirname(__DIR__) . '/cereal-images.php';
