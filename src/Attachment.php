<?php

declare(strict_types=1);

namespace CerealImages;

final class Attachment
{
    private const EXTENSIONS = '/\.(jpe?g|png)$/i';

    /** @return list<string> */
    public static function files(int $attachmentId): array
    {
        $main = get_attached_file($attachmentId);

        if (! $main || ! preg_match(self::EXTENSIONS, $main)) {
            return [];
        }

        $directory = dirname($main);
        $sizes = wp_get_attachment_metadata($attachmentId)['sizes'] ?? [];

        $files = [$main, ...array_map(fn (array $size) => "{$directory}/{$size['file']}", $sizes)];

        return array_values(array_unique(array_filter(
            $files,
            fn (string $file) => preg_match(self::EXTENSIONS, $file) && is_file($file),
        )));
    }
}
