<?php

declare(strict_types=1);

namespace CerealImages\Encoders;

use GdImage;

final class GdEncoder implements Encoder
{
    public function __construct(
        private readonly string $format,
        private readonly int $quality,
        private readonly int $speed = 8,
    ) {}

    public function format(): string
    {
        return $this->format;
    }

    public function isAvailable(): bool
    {
        return function_exists("image{$this->format}");
    }

    public function encode(string $source, string $destination): bool
    {
        $image = $this->load($source);

        if (! $image) {
            return false;
        }

        return match ($this->format) {
            'avif' => imageavif($image, $destination, $this->quality, $this->speed),
            'webp' => imagewebp($image, $destination, $this->quality),
        };
    }

    private function load(string $path): GdImage|false
    {
        $image = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($path),
            'png' => @imagecreatefrompng($path),
            default => false,
        };

        if (! $image) {
            return false;
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }
}
