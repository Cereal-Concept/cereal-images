<?php

declare(strict_types=1);

namespace CerealImages;

final class Attachment
{
    private const EXTENSIONS = '/\.(jpe?g|png)$/i';

    /**
     * Sélection par l'extension du fichier affiché, comme isProcessable(), et non par type MIME :
     * une photo HEIC convertie par WordPress garde le type image/heic alors que ses fichiers sont des JPG.
     */
    private const SELECT =
        'SELECT p.ID FROM %i p'
            . " JOIN %i f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'"
            . " WHERE p.post_type = 'attachment' AND (f.meta_value LIKE %s OR f.meta_value LIKE %s OR f.meta_value LIKE %s)";

    private const EXTENSION_PATTERNS = ['%.jpg', '%.jpeg', '%.png'];

    /**
     * Requêtes SQL directes plutôt que get_posts() : aucun filtre de requête (Polylang filtre les médias par langue)
     * ne doit écarter d'image.
     *
     * @return list<int>
     */
    public static function ids(): array
    {
        $wpdb = self::wpdb();

        if (! $wpdb) {
            return [];
        }

        return self::column($wpdb->prepare(
            self::SELECT . ' ORDER BY p.ID',
            $wpdb->posts,
            $wpdb->postmeta,
            ...self::EXTENSION_PATTERNS,
        ));
    }

    /** @return list<int> */
    public static function unprocessedIds(): array
    {
        $wpdb = self::wpdb();

        if (! $wpdb) {
            return [];
        }

        return self::column($wpdb->prepare(
            self::SELECT
            . ' AND NOT EXISTS (SELECT 1 FROM %i m WHERE m.post_id = p.ID AND m.meta_key = %s) ORDER BY p.ID',
            $wpdb->posts,
            $wpdb->postmeta,
            ...[...self::EXTENSION_PATTERNS, $wpdb->postmeta, Processor::META],
        ));
    }

    public static function isProcessable(int $attachmentId): bool
    {
        return (bool) preg_match(self::EXTENSIONS, (string) get_attached_file($attachmentId));
    }

    /** @return list<string> */
    public static function files(int $attachmentId): array
    {
        $main = get_attached_file($attachmentId);

        if (! $main || ! preg_match(self::EXTENSIONS, $main)) {
            return [];
        }

        $directory = dirname($main);
        $metadata = wp_get_attachment_metadata($attachmentId);
        $files = [$main];

        foreach (is_array($metadata) ? $metadata['sizes'] : [] as $size) {
            if (! is_array($size) || ! is_string($size['file'] ?? null)) {
                continue;
            }

            $files[] = "{$directory}/{$size['file']}";
        }

        return array_values(array_unique(array_filter(
            $files,
            fn (string $file) => preg_match(self::EXTENSIONS, $file) && is_file($file),
        )));
    }

    private static function wpdb(): ?\wpdb
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;

        return $wpdb instanceof \wpdb ? $wpdb : null;
    }

    /** @return list<int> */
    private static function column(?string $sql): array
    {
        $wpdb = self::wpdb();

        if (! $wpdb || $sql === null) {
            return [];
        }

        return array_values(array_map(intval(...), array_filter($wpdb->get_col($sql), is_numeric(...))));
    }

    /** Original non réduit conservé par WordPress à côté de la version -scaled, ou null s'il n'y en a pas. */
    public static function originalImage(int $attachmentId): ?string
    {
        $original = wp_get_original_image_path($attachmentId);

        return $original && $original !== get_attached_file($attachmentId) && is_file($original) ? $original : null;
    }
}
